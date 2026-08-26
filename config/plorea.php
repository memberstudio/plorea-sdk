<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | Your Plorea API key. Test keys are prefixed with "plr_test_". A separate
    | key is issued for the live environment.
    |
    */

    'api_key' => env('PLOREA_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Sent as the X-Environment header on every request. Supported values are
    | "test" and "live". Plorea defaults to "test" when the header is omitted.
    |
    */

    'environment' => env('PLOREA_ENVIRONMENT', 'test'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    */

    'base_url' => env('PLOREA_BASE_URL', 'https://payments.plorea.no'),

    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    |
    | The tenant identifier assigned to you by Plorea (e.g. "acme-2026").
    | It is attached automatically to every request that requires one, and
    | can be overridden per request via the fluent builders.
    |
    */

    'tenant_id' => env('PLOREA_TENANT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Platform
    |--------------------------------------------------------------------------
    |
    | Optional platform identifier included when creating payment links.
    |
    */

    'platform' => env('PLOREA_PLATFORM'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout' => env('PLOREA_TIMEOUT', 30),
        'connect_timeout' => env('PLOREA_CONNECT_TIMEOUT', 10),

        // Retry transient failures (connection errors and 5xx responses).
        'retry' => [
            'times' => env('PLOREA_RETRY_TIMES', 0),
            'sleep' => env('PLOREA_RETRY_SLEEP', 100),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | When enabled, the package registers a POST route that receives webhook
    | calls from Plorea and dispatches events your application can listen to.
    |
    | Webhook registration with Plorea is manual: hand them the resulting URL
    | (e.g. https://your-app.test/plorea/webhook) together with a secret you
    | generate yourself, via a secure channel. Plorea sends that secret back
    | verbatim in the Authorization header on every webhook call. Incoming
    | webhooks are rejected until the secret is configured here.
    |
    */

    'webhooks' => [
        'enabled' => env('PLOREA_WEBHOOKS_ENABLED', true),
        'path' => env('PLOREA_WEBHOOK_PATH', 'plorea/webhook'),
        'secret' => env('PLOREA_WEBHOOK_SECRET'),
        'middleware' => [],
    ],

];
