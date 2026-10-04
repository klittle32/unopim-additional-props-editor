<?php

use Illuminate\Support\Facades\Route;
use UnopimAdditionalPropsEditor\Http\AdditionalDataController;

Route::middleware(['web', 'admin'])
    ->prefix(config('app.admin_url').'/catalog/products')
    ->group(function (): void {
        Route::get('{id}/additional-data', [AdditionalDataController::class, 'show'])
            ->whereNumber('id')->name('additional-props.show');
        Route::patch('{id}/additional-data', [AdditionalDataController::class, 'update'])
            ->whereNumber('id')->name('additional-props.update');
    });
