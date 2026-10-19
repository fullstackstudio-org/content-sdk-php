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

    /*
     * Replace the default view of a block type of `<x-content::page-blocks>`, e.g. 'articleList' => 'blocks.articles' (a view of your own that
     * receives `$block`, `$index`, `$baseUrl` and `$resolve`). `unknown` renders block types this version does not know (default: skipped).
     */
    'blocks' => [
        // 'articleList' => 'blocks.articles',
    ],

    /*
     * The form route behind `<x-content::form>`. Opt-in: set `enabled` to true, or call `Content::formRoutes()` yourself. The `web` group is
     * needed for session and CSRF. `ip_header`: the header with the visitor's IP behind your proxy (Cloudflare sets `cf-connecting-ip`); only set
     * a header your proxy overwrites, otherwise a visitor can spoof it. null = use the IP Laravel sees. `locale` null = the app locale.
     */
    'forms' => [
        'enabled' => (bool) env('FSC_FORMS_ENABLED', false),
        'path' => env('FSC_FORMS_PATH', 'content/forms'),
        'middleware' => ['web', 'throttle:20,1'],
        'ip_header' => env('FSC_IP_HEADER', 'cf-connecting-ip'),
        'locale' => env('FSC_FORMS_LOCALE'),
        'labels' => [],
    ],

    /*
     * Analytics (Content module `analytics`, Umami). Place `<x-content::analytics />` in the layout (end of <body> or <head>); with the
     * module on in Content nothing else is needed. Pageviews and events are cookieless and run without consent; heatmaps and session
     * replay start only after cookie consent (see AGENTS.md for wiring a banner).
     *
     * `mode`: `first-party` (default) serves the Umami script and its collect calls from this site under `path` (/_fsc/a/...) and proxies
     * them to `host`, so ad blockers that block the analytics host still count pageviews and a CSP only needs 'self'. `direct` loads
     * everything from `host` (then the CSP needs that host, see `Content::analyticsCsp()`).
     * `ip_header`: the header with the visitor IP that this site receives (Cloudflare: `cf-connecting-ip`); it is forwarded to Umami so
     * countries and unique visitors stay right. Only a header your proxy overwrites; null = the IP Laravel sees.
     */
    'analytics' => [
        'enabled' => (bool) env('FSC_ANALYTICS_ENABLED', true),
        'mode' => env('FSC_ANALYTICS_MODE', 'first-party'),
        'path' => env('FSC_ANALYTICS_PATH', '_fsc/a'),
        'host' => env('FSC_ANALYTICS_HOST', 'https://analytics.fullstackstudio.nl'),
        'ip_header' => env('FSC_IP_HEADER', 'cf-connecting-ip'),
        'timeout_ms' => (int) env('FSC_ANALYTICS_TIMEOUT_MS', 3000),
        // Seconds the site config from Content is cached (also when the module is off, so a site without analytics makes no calls).
        'config_ttl' => (int) env('FSC_ANALYTICS_CONFIG_TTL', 300),
        // Seconds the proxied script.js / recorder.js are cached by the site (and by the browser).
        'script_ttl' => (int) env('FSC_ANALYTICS_SCRIPT_TTL', 3600),
        // Proxy requests per visitor IP per minute (collect calls; scripts are cached and not counted).
        'rate_limit' => (int) env('FSC_ANALYTICS_RATE_LIMIT', 240),
        // The localStorage key and window event your cookie banner uses (see AGENTS.md).
        'consent_key' => env('FSC_CONSENT_KEY', 'fsc-consent'),
        'consent_event' => env('FSC_CONSENT_EVENT', 'fsc:consent'),
    ],

    /**
     * Error monitoring (GlitchTip) through the Content module `errors`. Nothing to set when the module is on: the SDK fetches the DSNs from
     * Content (`GET /api/v1/project/errors`, cached `config_ttl` seconds) and reports backend exceptions through Laravel's exception handler.
     * For the browser, place `<x-content::errors />` in the `<head>`; it reports via the first-party `tunnel` route on your own domain.
     *
     * Only an allowlist is sent (exception class, scrubbed message, source locations, route template, release, environment): no request
     * data, user, IP, cookies, headers or bodies. Never in `local`/`testing`. `dsn`/`browser_dsn` override Content (e.g. without the module).
     * `release`: the full Git SHA of the image build. `tunnel.target`: `backend` (private ingest, needs egress to errors.int0.net) with a
     * fallback to the public ingest, or `public`.
     */
    'errors' => [
        'enabled' => (bool) env('FSC_ERRORS_ENABLED', true),
        'backend' => (bool) env('FSC_ERRORS_BACKEND', true),
        'browser' => (bool) env('FSC_ERRORS_BROWSER', true),
        'dsn' => env('FSC_ERRORS_DSN'),
        'browser_dsn' => env('FSC_ERRORS_BROWSER_DSN'),
        'environment' => env('FSC_ERRORS_ENVIRONMENT'),
        'release' => env('FSS_RELEASE') ?: env('APP_BUILD_SHA') ?: env('SENTRY_RELEASE'),
        'config_ttl' => (int) env('FSC_ERRORS_CONFIG_TTL', 600),
        'max_per_minute' => 60,
        'tunnel' => [
            'enabled' => (bool) env('FSC_ERRORS_TUNNEL', true),
            'path' => '_fsc/e',
            'target' => env('FSC_ERRORS_TUNNEL_TARGET', 'backend'),
            'max_bytes' => 65536,
            'per_minute_ip' => 10,
            'per_minute' => 120,
        ],
    ],
];
