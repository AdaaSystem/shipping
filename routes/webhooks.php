<?php

declare(strict_types=1);

use Adaa\Shipping\Laravel\Http\Controllers\ShippingWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/{carrier}', ShippingWebhookController::class)
    ->where('carrier', '[A-Za-z0-9_\-]+')
    ->name('shipping.webhook');
