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
        // A base query avoids Product saving observers that normalize native values.
        $query = $connection->table($product->getTable())
            ->where($product->getKeyName(), $product->getKey());
        $raw = $product->getRawOriginal('additional');

        // Only initialize a valid empty root, after validation and under the row lock.
        if ($raw === null || trim($raw) === 'null') {
            $query->update(['additional' => '{}']);
        }

        foreach ($changes as $section => $value) {
            // The validated section is allowlisted. Let Laravel compile the JSON
            // selector and value cast; bind encoded JSON without casting objects to
            // arrays (which would lose {} and numeric-looking specification keys).
            $values = [
                'additional->'.$section => $connection->raw($grammar->compileJsonValueCast('?')),
                $product->getUpdatedAtColumn() => $product->freshTimestampString(),
            ];

            // One path per statement avoids assigning the same column twice on
            // PostgreSQL. All paths and native history share the locked transaction.
            // This bounded query has only value, timestamp, and primary-key bindings.
            $connection->update($grammar->compileUpdate($query, $values), [
                AdditionalData::encode($value),
                $values[$product->getUpdatedAtColumn()],
                ...$query->getBindings(),
            ]);
        }
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
