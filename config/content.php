<?php

declare(strict_types=1);

return [
    /*
     * A server token (`fsc_srv_...`). The PHP SDK runs server-side, so this is fine; never expose it to a browser.
     */
    'token' => env('FSC_SERVER_TOKEN'),

    'base_url' => env('FSC_API_URL', 'https://content.fullstackstudio.nl'),

    'timeout_ms' => (int) env('FSC_TIMEOUT_MS', 10_000),

    /*
     * Read calls are cached under tags and cleared by the webhook. `store` = a Laravel cache store name (null = the default store).
     * `ttl` is the safety net in seconds: even without a webhook the data is at most this old.
     */
    'cache' => [
        'enabled' => (bool) env('FSC_CACHE_ENABLED', true),
        'store' => env('FSC_CACHE_STORE'),
        'ttl' => (int) env('FSC_CACHE_TTL', 3600),
    ],

    /*
     * The webhook receiver. The route is opt-in: set `enabled` to true, or call `Content::routes()` yourself.
     * Content calls it server to server, so it must not use CSRF protection: the default `api` middleware group has none.
     * If you put it in the `web` group, add the path to the CSRF exceptions (`$middleware->validateCsrfTokens(except: ['content/webhook'])`).
     */
    'webhook' => [
        'enabled' => (bool) env('FSC_WEBHOOK_ENABLED', false),
        'path' => env('FSC_WEBHOOK_PATH', 'content/webhook'),
        'secret' => env('FSC_WEBHOOK_SECRET'),
        'tolerance' => (int) env('FSC_WEBHOOK_TOLERANCE', 300),
        'middleware' => ['api'],
    ],

    /*
     * The redirect middleware (`FullStackStudio\Content\Laravel\RedirectMiddleware`): timeout per lookup and the pause after a failure.
     */
    'redirects' => [
        'timeout_ms' => 1500,
        'failure_cooldown_seconds' => 15,
    ],
];
