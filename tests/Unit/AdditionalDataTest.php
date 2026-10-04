<?php

declare(strict_types=1);

namespace UnopimAdditionalPropsEditor\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnopimAdditionalPropsEditor\AdditionalData;

final class AdditionalDataTest extends TestCase
{
    #[DataProvider('emptyDocuments')]
    public function test_missing_sections_are_empty_without_creating_them(?string $raw): void
    {
        $data = new AdditionalData($raw);
        $snapshot = $data->snapshot();

        self::assertTrue($snapshot['attributes']['editable']);
        self::assertSame([], $snapshot['attributes']['rows']);
        self::assertSame([], $snapshot['features']['items']);
        self::assertSame([], $data->changes((object) ['attributes' => [], 'features' => []]));
    }

    public static function emptyDocuments(): array
    {
        return [[null], ['null'], ['{}'], ['{"metadata":{"nested":[]}}']];
    }

    public function test_strings_numeric_names_and_feature_order_survive(): void
    {
        $data = new AdditionalData('{"attributes":{"0":"zero","1":"one"," Size ":" 20 mm "},"features":["second","first",""]}');

        self::assertSame([
            ['name' => '0', 'value' => 'zero'],
            ['name' => '1', 'value' => 'one'],
            ['name' => ' Size ', 'value' => ' 20 mm '],
        ], $data->snapshot()['attributes']['rows']);
        self::assertSame(['second', 'first', ''], $data->snapshot()['features']['items']);
    }

    #[DataProvider('unsupportedDocuments')]
    public function test_incompatible_roots_are_not_editable(string $raw): void
    {
        $data = new AdditionalData($raw);
        self::assertFalse($data->snapshot()['attributes']['editable']);
        self::assertNotEmpty($data->snapshot()['attributes']['message']);

        $this->expectException(InvalidArgumentException::class);
        $data->changes((object) ['features' => ['Replacement']]);
    }

    public static function unsupportedDocuments(): array
    {
        return [['[]'], ['["existing"]'], ['"text"'], ['12'], ['false'], ['not json']];
    }

    #[DataProvider('unsupportedSections')]
    public function test_unsupported_sections_are_preserved_but_do_not_block_sibling(string $raw, string $blocked, string $open): void
    {
        $data = new AdditionalData($raw);
        self::assertFalse($data->snapshot()[$blocked]['editable']);
        self::assertTrue($data->snapshot()[$open]['editable']);
        $changes = $data->changes((object) [$open => $open === 'features' ? ['New'] : [(object) ['name' => 'Material', 'value' => 'Steel']]]);
        self::assertSame([$open], array_keys($changes));

        $this->expectException(InvalidArgumentException::class);
        $data->changes((object) [$blocked => []]);
    }

    public static function unsupportedSections(): array
    {
        return [
            ['{"attributes":{"Count":2}}', 'attributes', 'features'],
            ['{"attributes":{"Count":18446744073709551615}}', 'attributes', 'features'],
            ['{"features":[18446744073709551615]}', 'features', 'attributes'],
            ['{"attributes":[]}', 'attributes', 'features'],
            ['{"attributes":null}', 'attributes', 'features'],
            ['{"attributes":{"Nested":{"value":"x"}}}', 'attributes', 'features'],
            ['{"features":{"0":"a"}}', 'features', 'attributes'],
            ['{"features":[null]}', 'features', 'attributes'],
            ['{"features":null}', 'features', 'attributes'],
        ];
    }

    public function test_only_changed_sections_are_returned_as_correct_json_shapes(): void
    {
        $data = new AdditionalData('{"attributes":{"Material":"Steel"},"features":["A","B"],"metadata":{"number":18446744073709551615}}');
        $changes = $data->changes((object) [
            'attributes' => [(object) ['name' => '0', 'value' => '']],
            'features' => ['B', 'A'],
        ]);

        self::assertInstanceOf(stdClass::class, $changes['attributes']);
        self::assertSame('{"0":""}', json_encode($changes['attributes']));
        self::assertSame(['B', 'A'], $changes['features']);
        self::assertArrayNotHasKey('metadata', $changes);
    }

    public function test_unchanged_values_and_reordered_object_keys_are_no_ops(): void
    {
        $data = new AdditionalData('{"attributes":{"A":"01","B":"b"},"features":["x","y"]}');
        self::assertSame([], $data->changes((object) [
            'attributes' => [(object) ['name' => 'B', 'value' => 'b'], (object) ['name' => 'A', 'value' => '01']],
            'features' => ['x', 'y'],
        ]));
        self::assertArrayHasKey('attributes', $data->changes((object) [
            'attributes' => [(object) ['name' => 'A', 'value' => '1'], (object) ['name' => 'B', 'value' => 'b']],
        ]));
    }

    public function test_removal_stores_empty_object_and_array_not_null(): void
    {
        $data = new AdditionalData('{"attributes":{"A":"a"},"features":["x"]}');
        $changes = $data->changes((object) ['attributes' => [], 'features' => []]);
        self::assertSame('{}', json_encode($changes['attributes']));
        self::assertSame('[]', json_encode($changes['features']));
    }

    #[DataProvider('badChanges')]
    public function test_invalid_changes_fail_instead_of_coercing_or_dropping_rows(object $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AdditionalData('{}'))->changes($changes);
    }

    public static function badChanges(): array
    {
        return [
            [(object) ['attributes' => [(object) ['name' => 'Same', 'value' => 'a'], (object) ['name' => 'Same', 'value' => 'b']]]],
            [(object) ['attributes' => [(object) ['name' => '', 'value' => 'a']]]],
            [(object) ['attributes' => [(object) ['name' => "\0hidden", 'value' => 'a']]]],
            [(object) ['attributes' => [(object) ['name' => "embedded\0name", 'value' => 'a']]]],
            [(object) ['attributes' => [(object) ['name' => str_repeat('n', 256), 'value' => 'a']]]],
            [(object) ['attributes' => [(object) ['name' => 'A', 'value' => str_repeat('v', 65537)]]]],
            [(object) ['features' => [str_repeat('f', 65537)]]],
            [(object) ['features' => array_fill(0, 1001, 'f')]],
            [(object) ['attributes' => [(object) ['name' => '  ', 'value' => 'a']]]],
            [(object) ['attributes' => [(object) ['name' => 1, 'value' => 'a']]]],
            [(object) ['attributes' => [(object) ['name' => 'A', 'value' => 2]]]],
            [(object) ['attributes' => [(object) ['name' => 'A', 'value' => null]]]],
            [(object) ['attributes' => [(object) ['name' => 'A', 'value' => 'x', 'hidden' => 'y']]]],
            [(object) ['attributes' => (object) ['A' => 'a']]],
            [(object) ['features' => [42]]],
            [(object) ['features' => (object) ['0' => 'a']]],
            [(object) ['features' => null]],
            [(object) ['values' => (object) ['common' => []]]],
        ];
    }

    public function test_html_and_special_property_names_are_plain_strings(): void
    {
        $changes = (new AdditionalData('{}'))->changes((object) [
            'attributes' => [(object) ['name' => '__proto__', 'value' => '<script>alert(1)</script>']],
            'features' => ['<img src=x onerror=alert(1)>'],
        ]);
        self::assertSame('<script>alert(1)</script>', $changes['attributes']->__proto__);
    }

    public function test_version_covers_unrelated_data_and_distinguishes_missing(): void
    {
        self::assertNotSame((new AdditionalData('{}'))->version(), (new AdditionalData('{"metadata":1}'))->version());
        self::assertNotSame((new AdditionalData(null))->version(), (new AdditionalData('null'))->version());
        self::assertSame((new AdditionalData('{}'))->version(), (new AdditionalData('{}'))->version());
    }

    public function test_request_parsing_preserves_empty_and_whitespace_strings(): void
    {
        $version = str_repeat('a', 64);
        $request = AdditionalData::parseRequest('{"version":"'.$version.'","changes":{"attributes":[{"name":" Name ","value":""}],"features":[" padded "]}}');
        self::assertSame($version, $request->version);
        self::assertSame('', $request->changes->attributes[0]->value);
        self::assertSame(' padded ', $request->changes->features[0]);
    }

    #[DataProvider('badRequests')]
    public function test_invalid_request_envelopes_are_rejected(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);
        AdditionalData::parseRequest($json);
    }

    public static function badRequests(): array
    {
        $version = str_repeat('a', 64);

        return [
            ['invalid'], ['[]'], ['{}'], ['null'],
            ['{"version":"x","changes":{}}'],
            ['{"version":"'.$version.'","changes":[]}'],
            ['{"version":"'.$version.'","changes":{},"values":{}}'],
            ['{"version":"'.$version.'","changes":{},"additional":{}}'],
        ];
    }

    public function test_size_limits_include_the_boundary(): void
    {
        $changes = (new AdditionalData('{}'))->changes((object) [
            'attributes' => [(object) ['name' => str_repeat('n', 255), 'value' => str_repeat('v', 65536)]],
            'features' => array_fill(0, 1000, str_repeat('f', 65536)),
        ]);
        self::assertCount(1000, $changes['features']);
        self::assertSame(65536, strlen($changes['attributes']->{str_repeat('n', 255)}));
    }

    public function test_request_byte_limit_includes_boundary_and_rejects_one_more(): void
    {
        $json = '{"version":"'.str_repeat('a', 64).'","changes":{}}';
        $json .= str_repeat(' ', AdditionalData::MAX_REQUEST_BYTES - strlen($json));
        self::assertInstanceOf(stdClass::class, AdditionalData::parseRequest($json));
        $this->expectException(InvalidArgumentException::class);
        AdditionalData::parseRequest($json.' ');
    }

    public function test_audit_snapshots_distinguish_absence_and_empty(): void
    {
        self::assertSame('(absent)', (new AdditionalData('{}'))->historyValue('features'));
        self::assertSame('[]', (new AdditionalData('{"features":[]}'))->historyValue('features'));
        self::assertSame('{}', (new AdditionalData('{"attributes":{}}'))->historyValue('attributes'));
        self::assertSame('["0",""]', (new AdditionalData('{"features":["0",""]}'))->historyValue('features'));
    }
}
