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
        'enabled' => (bool) env('SHIPPING_WEBHOOK_ENABLED', true),
        'prefix' => env('SHIPPING_WEBHOOK_PREFIX', 'webhooks/shipping'),
        // Built-in numeric throttle works in every app; hosts with a
        // named limiter (e.g. throttle:webhooks) should override this.
        'middleware' => ['api', 'throttle:60,1'],
        // Accept unsigned webhooks only in non-production development.
        // Prefer binding WebhookSecretResolver per connection instead.
        'allow_unsigned' => (bool) env('SHIPPING_WEBHOOK_ALLOW_UNSIGNED', false),
    ],

];
