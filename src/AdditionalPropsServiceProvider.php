<?php

declare(strict_types=1);

namespace UnopimAdditionalPropsEditor;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AdditionalPropsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'additional-props');
        $this->loadRoutesFrom(__DIR__.'/../routes/admin.php');

        $this->publishes([
            __DIR__.'/../resources/assets' => public_path('vendor/additional-props-editor'),
        ], 'additional-props-assets');

        Event::listen('unopim.admin.catalog.product.edit.after', function ($manager): void {
            if (bouncer()->hasPermission('catalog.products.edit')) {
                $manager->addTemplate('additional-props::panel');
            }
        });
    }
}
