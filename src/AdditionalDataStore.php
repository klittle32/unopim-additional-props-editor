<?php

declare(strict_types=1);

namespace UnopimAdditionalPropsEditor;

use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Events\AuditCustom;
use stdClass;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductProxy;

final class AdditionalDataStore
{
    public function read(int $id): AdditionalData
    {
        return new AdditionalData(ProductProxy::findOrFail($id)->getRawOriginal('additional'));
    }

    public function update(int $id, stdClass $input): AdditionalData
    {
        $modelClass = ProductProxy::modelClass();
        $connection = (new $modelClass)->getConnection();

        return $connection->transaction(function () use ($modelClass, $id, $input): AdditionalData {
            $product = $modelClass::query()->lockForUpdate()->findOrFail($id);
            $current = new AdditionalData($product->getRawOriginal('additional'));

            abort_unless(hash_equals($current->version(), $input->version), 409,
                'Additional data changed elsewhere. Reload and review the current data before saving.');

            $changes = $current->changes($input->changes);

            if ($changes === []) {
                return $current;
            }

            $this->requireTransactionalHistory($product);
            $lastAuditId = $product->audits()->max('id') ?? 0;

            $this->writeSections($product, $changes);
            $this->recordHistory($product, $current, $changes);

            // Auditing can be disabled or vetoed by a listener. Never report a
            // successful save if its attributed, native history was not recorded.
            $recorded = $product->audits()
                ->where('id', '>', $lastAuditId)
                ->where('user_id', auth('admin')->id())
                ->where('history_id', $product->getKey())
                ->where('event', 'updated')
                ->exists();

            abort_unless($recorded, 503, 'The save was rolled back because native product history was not recorded.');

            return new AdditionalData($product->fresh()->getRawOriginal('additional'));
        });
    }

    private function requireTransactionalHistory(Product $product): void
    {
        abort_unless($product->getConnection()->getDriverName() === 'mysql', 503,
            'This editor currently requires MySQL JSON support.');

        abort_unless(config('audit.enabled', true)
            && $product->getAuditDriver() === 'database'
            && ! config('audit.queue.enable', false)
            && $product->audits()->getRelated()->getConnection() === $product->getConnection(), 503,
            'Saving requires synchronous native database history on the product database connection.');
    }

    private function writeSections(Product $product, array $changes): void
    {
        $connection = $product->getConnection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($product->getTable());
        $column = $grammar->wrap('additional');
        $key = $grammar->wrap($product->getKeyName());
        $updatedAt = $grammar->wrap($product->getUpdatedAtColumn());
        $paths = [];
        $bindings = [];

        foreach ($changes as $section => $value) {
            // Section names are an allowlisted contract, never user-supplied SQL.
            $paths[] = '?, CAST(? AS JSON)';
            $bindings[] = '$.'.$section;
            $bindings[] = AdditionalData::encode($value);
        }

        $bindings[] = $product->freshTimestampString();
        $bindings[] = $product->getKey();

        // JSON_SET preserves unrelated JSON in MySQL itself, including large
        // numbers and empty objects that PHP's array cast cannot round-trip.
        // A base query intentionally avoids Product saving observers which can
        // normalize native measurement values even on additional-only saves.
        $connection->update(
            "UPDATE {$table} SET {$column} = JSON_SET(CASE WHEN {$column} IS NULL OR JSON_TYPE({$column}) = 'NULL' THEN JSON_OBJECT() ELSE {$column} END, "
            .implode(', ', $paths)."), {$updatedAt} = ? WHERE {$key} = ?",
            $bindings,
        );
    }

    private function recordHistory(Product $product, AdditionalData $before, array $changes): void
    {
        $old = $new = [];

        foreach ($changes as $section => $value) {
            $label = $section === 'attributes' ? 'Additional specifications' : 'Additional features';
            $old[$label] = $before->historyValue($section);
            $new[$label] = AdditionalData::encode($value);
        }

        $product->auditEvent = 'updated';
        $product->isCustomEvent = true;
        $product->auditCustomOld = ['common' => $old];
        $product->auditCustomNew = ['common' => $new];

        try {
            Event::dispatch(new AuditCustom($product));
        } finally {
            $product->isCustomEvent = false;
            $product->auditCustomOld = [];
            $product->auditCustomNew = [];
        }
    }
}
