<?php

declare(strict_types=1);

namespace UnopimAdditionalPropsEditor;

use InvalidArgumentException;
use JsonException;
use stdClass;

/** Read the managed sections without round-tripping unrelated JSON. */
final class AdditionalData
{
    public const MAX_REQUEST_BYTES = 1048576;

    private mixed $document;

    public function __construct(private readonly ?string $raw)
    {
        try {
            $this->document = $raw === null ? new stdClass : json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            $this->document ??= new stdClass;
        } catch (JsonException) {
            $this->document = false;
        }
    }

    public function version(): string
    {
        return hash('sha256', $this->raw === null ? 'sql-null' : 'json:'.$this->raw);
    }

    public function snapshot(): array
    {
        $attributesMessage = $this->limitation('attributes');
        $featuresMessage = $this->limitation('features');
        $rows = [];

        if ($attributesMessage === null) {
            foreach ($this->document->attributes ?? new stdClass as $name => $value) {
                $rows[] = ['name' => (string) $name, 'value' => $value];
            }
        }

        return [
            'version' => $this->version(),
            'attributes' => ['editable' => $attributesMessage === null, 'rows' => $rows, 'message' => $attributesMessage],
            'features' => ['editable' => $featuresMessage === null, 'items' => $featuresMessage === null ? ($this->document->features ?? []) : [], 'message' => $featuresMessage],
        ];
    }

    public static function parseRequest(string $json): stdClass
    {
        if (strlen($json) > self::MAX_REQUEST_BYTES) {
            throw new InvalidArgumentException('Additional data requests must be no larger than 1 MiB.');
        }

        try {
            $input = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The request must contain valid JSON.');
        }

        if (! $input instanceof stdClass
            || count(get_object_vars($input)) !== 2
            || ! isset($input->version, $input->changes)
            || ! is_string($input->version)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $input->version)
            || ! $input->changes instanceof stdClass) {
            throw new InvalidArgumentException('Send only a version token and a changes object.');
        }

        return $input;
    }

    /** @return array<string, stdClass|array> Validated, actually changed sections only. */
    public function changes(stdClass $input): array
    {
        $changes = [];

        foreach ($input as $section => $rows) {
            if (! in_array($section, ['attributes', 'features'], true)) {
                throw new InvalidArgumentException('Only attributes and features may be edited.');
            }

            if (($message = $this->limitation($section)) !== null) {
                throw new InvalidArgumentException($message);
            }

            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 1000) {
                throw new InvalidArgumentException("{$section} must be an array with at most 1,000 rows.");
            }

            $value = $section === 'attributes' ? $this->specifications($rows) : $this->features($rows);
            $current = $this->document->{$section} ?? ($section === 'attributes' ? new stdClass : []);

            if ($this->comparable($current) !== $this->comparable($value)) {
                $changes[$section] = $value;
            }
        }

        return $changes;
    }

    public function historyValue(string $section): string
    {
        return $this->document instanceof stdClass && property_exists($this->document, $section)
            ? self::encode($this->document->{$section})
            : '(absent)';
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function limitation(string $section): ?string
    {
        if (! $this->document instanceof stdClass) {
            return 'The stored additional data is not a JSON object. It is preserved and cannot be edited here.';
        }

        if (! property_exists($this->document, $section)) {
            return null;
        }

        $value = $this->document->{$section};
        $valid = $section === 'attributes' ? $value instanceof stdClass : is_array($value);

        if ($valid) {
            foreach ($value as $item) {
                if (! is_string($item)) {
                    $valid = false;
                    break;
                }
            }
        }

        return $valid ? null : "The stored {$section} has an unsupported shape or non-string values. It is preserved and cannot be edited here.";
    }

    private function specifications(array $rows): stdClass
    {
        $result = new stdClass;

        foreach ($rows as $row) {
            if (! $row instanceof stdClass || count(get_object_vars($row)) !== 2
                || ! isset($row->name, $row->value) || ! is_string($row->name) || ! is_string($row->value)) {
                throw new InvalidArgumentException('Each specification must contain only a string name and string value.');
            }

            if (trim($row->name) === '' || strlen($row->name) > 255 || str_contains($row->name, "\0")) {
                throw new InvalidArgumentException('Specification names must be nonblank, contain no NUL bytes, and be no longer than 255 bytes.');
            }

            if (property_exists($result, $row->name)) {
                throw new InvalidArgumentException('Specification names must be unique; duplicate names are not saved.');
            }

            $this->validateText($row->value);
            $result->{$row->name} = $row->value;
        }

        return $result;
    }

    private function features(array $items): array
    {
        foreach ($items as $item) {
            $this->validateText($item);
        }

        return $items;
    }

    private function validateText(mixed $value): void
    {
        if (! is_string($value) || strlen($value) > 65536) {
            throw new InvalidArgumentException('Specification values and features must be strings no longer than 64 KiB each.');
        }
    }

    private function comparable(stdClass|array $value): string
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $value = (object) $properties;
        }

        return self::encode($value);
    }
}
