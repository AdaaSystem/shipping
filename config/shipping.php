<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Static carrier registry
    |--------------------------------------------------------------------------
    | Carrier metadata (countries, services, declared capabilities) merged
    | with live capabilities reported by each driver connection.
    */
    'carriers' => require __DIR__.'/carriers.php',

    /*
    |--------------------------------------------------------------------------
    | Token cache prefix
    |--------------------------------------------------------------------------
    | Per-connection tokens are cached as {prefix}{connection_id} so two
    | merchants using the same carrier never share a token.
    */
    'token_cache_prefix' => env('SHIPPING_TOKEN_CACHE_PREFIX', 'shipping:token:'),

    /*
    |--------------------------------------------------------------------------
    | Webhook route
    |--------------------------------------------------------------------------
    */
    'webhook' => [
        'prefix' => env('SHIPPING_WEBHOOK_PREFIX', 'webhooks/shipping'),
        'middleware' => ['api', 'throttle:webhooks'],
    ],

];
