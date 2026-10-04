<?php

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;

final class AdditionalDataTest extends TestCase
{
    private Product $product;
    private Admin $admin;

    public function createApplication()
    {
        return additionalPropsFixtureApp();
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        // Laravel normally silently skips CSRF in tests. Run the real middleware.
        $this->app->instance('env', 'local');
        $this->admin = Admin::where('email', 'editor@example.test')->firstOrFail();
        $this->product = Product::factory()->withInitialValues()->create([
            'additional' => ['attributes' => ['Material' => 'Cotton'], 'features' => ['Soft']],
        ]);
        $this->actingAs($this->admin, 'admin');
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function url(): string
    {
        return '/admin/catalog/products/'.$this->product->id.'/additional-data';
    }

    private function version(): string
    {
        return $this->getJson($this->url())->assertOk()->json('version');
    }

    private function patchChanges(array $changes, ?string $version = null)
    {
        $version ??= $this->version();
        return $this->withSession(['_token' => 'fixture-csrf-token'])->patchJson($this->url(), [
            'version' => $version, 'changes' => $changes,
        ], ['X-CSRF-TOKEN' => 'fixture-csrf-token']);
    }

    private function row(): array
    {
        return (array) DB::table('products')->where('id', $this->product->id)->first();
    }

    public function test_read_is_authenticated_and_does_not_mutate(): void
    {
        $before = $this->row();
        $count = DB::table('audits')->count();
        $this->getJson($this->url())->assertOk()->assertJsonPath('attributes.editable', true)
            ->assertJsonPath('attributes.rows.0.name', 'Material')->assertJsonPath('features.items', ['Soft']);
        self::assertSame($before, $this->row());
        self::assertSame($count, DB::table('audits')->count());
        auth('admin')->logout();
        $response = $this->getJson($this->url());
        self::assertContains($response->status(), [302, 401, 403]);
    }

    public function test_real_csrf_rejects_missing_token(): void
    {
        $version = $this->version();
        $this->patchJson($this->url(), ['version' => $version, 'changes' => ['features' => ['Changed']]])->assertStatus(419);
    }

    public function test_preserves_whitespace_empty_values_and_unrelated_native_data(): void
    {
        DB::table('products')->where('id', $this->product->id)->update(['additional' => '{"attributes":{"Material":"Cotton"},"features":["Soft"],"opaque":{"huge":9223372036854775808,"empty":{}},"unknown":[{"x":1}]}']);
        $before = $this->row();
        $this->patchChanges(['attributes' => [['name' => ' Exact ', 'value' => ' value '], ['name' => 'Empty', 'value' => '']]])->assertOk();
        $after = $this->row();
        $data = json_decode($after['additional'], true, 512, JSON_BIGINT_AS_STRING);
        self::assertCount(2, $data['attributes']);
        self::assertSame(' value ', $data['attributes'][' Exact ']);
        self::assertSame('', $data['attributes']['Empty']);
        self::assertSame('9223372036854775808', $data['opaque']['huge']);
        self::assertStringContainsString('"empty": {}', $after['additional']);
        unset($before['additional'], $after['additional'], $before['updated_at'], $after['updated_at']);
        self::assertSame($before, $after);
    }

    public function test_out_of_band_change_conflicts_and_retains_new_data(): void
    {
        $version = $this->version();
        DB::table('products')->where('id', $this->product->id)->update(['additional' => DB::raw("JSON_SET(additional, '$.external', 'changed by API')")]);
        $before = $this->row();
        $this->patchChanges(['features' => ['Old tab']], $version)->assertStatus(409);
        self::assertSame($before, $this->row());
    }

    public function test_noop_does_not_write_or_audit(): void
    {
        $before = $this->row();
        $count = DB::table('audits')->count();
        $this->patchChanges(['features' => ['Soft']])->assertOk();
        self::assertSame($before, $this->row());
        self::assertSame($count, DB::table('audits')->count());
    }

    public function test_invalid_changes_are_atomic(): void
    {
        $invalid = [
            ['attributes' => [['name' => 'Same', 'value' => '1'], ['name' => 'Same', 'value' => '2']]],
            ['attributes' => [['name' => ' ', 'value' => '1']]],
            ['attributes' => [['name' => 'Number', 'value' => 7]]],
            ['attributes' => [['name' => 'Extra', 'value' => '1', 'native' => true]]],
            ['features' => ['ok', 12]], ['values' => ['common' => ['sku' => 'changed']]], ['status' => 0],
        ];
        $before = $this->row();
        foreach ($invalid as $changes) {
            $this->patchChanges($changes)->assertStatus(422);
            self::assertSame($before, $this->row());
        }
        $version = $this->version();
        $this->withSession(['_token' => 'fixture-csrf-token'])->patchJson($this->url(), [
            'version' => $version, 'changes' => ['features' => []], 'values' => [],
        ], ['X-CSRF-TOKEN' => 'fixture-csrf-token'])->assertStatus(422);
    }

    public function test_unsupported_sections_are_readonly_but_supported_sibling_is_editable(): void
    {
        DB::table('products')->where('id', $this->product->id)->update(['additional' => '{"attributes":{"Nested":{"x":1}},"features":["Soft"]}']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('attributes.editable', false)->assertJsonPath('features.editable', true);
        $this->patchChanges(['attributes' => []])->assertStatus(422);
        $this->patchChanges(['features' => ['Changed']])->assertOk();
        self::assertSame(['Nested' => ['x' => 1]], json_decode($this->row()['additional'], true)['attributes']);
    }

    public function test_null_and_missing_sections_can_be_added(): void
    {
        foreach ([null, '{}'] as $additional) {
            DB::table('products')->where('id', $this->product->id)->update(['additional' => $additional]);
            $this->patchChanges(['attributes' => [['name' => 'Added', 'value' => '']], 'features' => []])->assertOk();
        }
    }

    public function test_revoked_permission_and_disabled_account_cannot_write(): void
    {
        $version = $this->version();
        $this->admin->role->update(['permission_type' => 'custom', 'permissions' => []]);
        $this->admin->unsetRelation('role');
        $before = $this->row();
        $response = $this->patchChanges(['features' => ['Forbidden']], $version);
        self::assertContains($response->status(), [302, 401, 403]);
        self::assertSame($before, $this->row());
        $this->admin->role->update(['permission_type' => 'all']);
        $this->admin->update(['status' => 0]);
        $this->admin->unsetRelation('role');
        $response = $this->patchChanges(['features' => ['Forbidden']], $version);
        self::assertContains($response->status(), [302, 401, 403]);
    }

    public function test_audit_failure_rolls_back_product_change(): void
    {
        $before = $this->row();
        Event::listen(OwenIt\Auditing\Events\AuditCustom::class, function (): void {
            throw new RuntimeException('Synthetic audit failure');
        });
        $this->patchChanges(['features' => ['Must roll back']])->assertStatus(500);
        self::assertSame($before, $this->row());
    }

    public function test_history_is_attributed_and_visible_in_native_controller(): void
    {
        $this->patchChanges(['features' => ['History-visible']])->assertOk();
        $audit = DB::table('audits')->where('auditable_id', $this->product->id)->orderByDesc('id')->first();
        self::assertNotNull($audit);
        self::assertEquals($this->admin->id, $audit->user_id);
        $response = $this->app->make(Webkul\HistoryControl\Http\Controllers\HistoryController::class)
            ->getVersionHistoryView('product', $this->product->id, $audit->version_id);
        $history = $response->getData(true);
        self::assertNotEmpty($history['versionHistory']);
        self::assertStringContainsString('History-visible', json_encode($history));
    }
}
