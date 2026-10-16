# fullstackstudio/content (PHP)

PHP SDK for [Fullstack Content](https://content.fullstackstudio.nl): a typed-by-convention client for `/api/v1`, a tag-based read cache,
webhook verification and a Laravel integration. It follows the same contract as the JavaScript SDK (`@fullstackstudio/content`).

- PHP 8.2 or newer, `ext-curl` and `ext-json`. No other runtime dependency: Laravel, PSR-18 and PSR-16 are optional.
- Responses are plain associative arrays (the JSON as the API sends it, single objects unwrapped).
- Every failure is a `ContentException`. The token never appears in a message.

## Install

The package is not on Packagist. Add the repository and require it:

```json
{
    "repositories": [{ "type": "vcs", "url": "git@github.com:fullstackstudio-org/content-sdk-php.git" }],
    "require": { "fullstackstudio/content": "^0.4" }
}
```

## Quick start (any PHP)

```php
use FullStackStudio\Content\ContentClient;

$content = ContentClient::create(['token' => getenv('FSC_SERVER_TOKEN')]);

$article = $content->getArticle('hello', ['locale' => 'en']);          // the article (404 throws)
$list = $content->listArticles(['status' => 'published', 'limit' => 10]); // ['articles' => [...], 'page', 'limit', 'total']
$page = $content->getPage('/services/web-design');                       // by id or path
$content->submitForm('contact', ['name' => 'Jan', 'email' => 'jan@example.nl'], ['clientIp' => $_SERVER['REMOTE_ADDR']]);
$content->admin->publishArticle($article['id']);                         // write routes (server token + scopes)
```

Options go in the last array argument of every method: `timeoutMs`, `clientIp`, `cache` (false skips the cache), `tags`, `ttl`.
`submitForm` also takes `meta` and `turnstileToken`. `listCategories` takes its params first (the JS SDK has options first).

Available now: `getProject`, `listArticles`, `getArticle`, `listCategories`, `getTranslations`, `listPages`, `getPage`, `getForm`,
`submitForm`, `restartFormPayment`, `listMedia`, `getMedia`, `mediaUrl`, `assetUrl`, `listRedirects`, `resolveRedirect`, `getSeo`,
`getSitemap`, `search`, `getConsentConfig`, `recordConsent`, `newsletter->subscribe/confirm/unsubscribe` and everything under `admin`.
The other modules (products, orders, members, ...) follow in a later release.

## Tokens

- `fsc_srv_...` is a **server token**: full API within its scopes. This SDK always runs on your server; never ship it to a browser.
- `fsc_pub_...` only reads published content and submits forms.
- `X-FSC-Client-Ip` (the visitor's IP, for per-visitor limits) is sent **only with a server token**, from the `clientIp` call option or
  the client's `clientIp` option. Values that are not an IP are dropped.
- `baseUrl` must be `https` (plain `http` is only accepted for `localhost`/`127.0.0.1`), because the token travels with every request.

## Cache and webhooks

Pass a cache and every read is cached under tags (`content:articles`, `content:article:<slug>`, ...; see `Tags`). Writes and
`submitForm` are never cached. The default lifetime is one hour, a safety net: the webhook provides "immediate".

```php
use FullStackStudio\Content\Cache\{LaravelStore, Psr16Store, VersionedTagCache};

$cache = new VersionedTagCache(new Psr16Store($anyPsr16Cache)); // or new LaravelStore(Cache::store('redis'))
$content = ContentClient::create(['token' => $token, 'cache' => $cache, 'cacheTtl' => 3600]);
```

`VersionedTagCache` keeps a version per tag inside the key, so it works on file, database and Redis stores without cache-tag support.
Stale entries expire on their own.

Create the webhook in the admin (or `$content->admin->createWebhook([...])`; the secret is returned only once) and put it in
`FSC_WEBHOOK_SECRET`. In any framework:

```php
use FullStackStudio\Content\{Tags};
use FullStackStudio\Content\Webhooks\WebhookVerifier;

$result = WebhookVerifier::verify($rawBody, $_SERVER['HTTP_X_FSC_SIGNATURE'] ?? null, $secret); // RAW body, not decoded JSON
if (!$result->ok) { http_response_code(401); exit; }
$cache->invalidateTags(Tags::forEvent($result->event));
```

Verification is constant-time, accepts a timestamp within 5 minutes (`$tolerance`), and tries every `v1=` (secret rotation).
`WebhookVerifier::sign()` computes the header for tests. `Events::ALL` lists the event types.

## Laravel (11 and 12)

The service provider and the `Content` facade are auto-discovered.

```bash
composer require fullstackstudio/content
php artisan vendor:publish --tag=content-config   # optional
```

```dotenv
FSC_SERVER_TOKEN=fsc_srv_...
FSC_WEBHOOK_SECRET=fsc_whsec_...
# optional: FSC_API_URL, FSC_CACHE_STORE, FSC_CACHE_TTL, FSC_CACHE_ENABLED, FSC_WEBHOOK_ENABLED, FSC_WEBHOOK_PATH
```

```php
use FullStackStudio\Content\Laravel\Facades\Content;

$articles = Content::listArticles(['limit' => 5]);
```

The client is a singleton (`app(ContentClient::class)`) that caches reads in your Laravel cache and forwards the request's IP.

**Webhook route** (opt-in): set `FSC_WEBHOOK_ENABLED=true`, or call `Content::routes()` in a routes file. It registers
`POST content/webhook` (name `content.webhook`) which verifies the signature, invalidates the tags, dispatches
`FullStackStudio\Content\Laravel\Events\ContentWebhookReceived` and answers 200 (401 for a bad signature). The route uses the `api`
middleware group, which has no CSRF protection. If you register it in the `web` group, exempt it:
`$middleware->validateCsrfTokens(except: ['content/webhook']);`.

**Redirects**: add `FullStackStudio\Content\Laravel\RedirectMiddleware` early in the `web` group. It looks up GET/HEAD paths (skipping
`/api`, `/build`, `/storage` and files with an extension), caches the answers under the `content:redirects` tag and pauses for 15
seconds if Content is down.

**SEO**: `<x-content::meta :page="$article" path="/blog/{{ $article['slug'] }}" type="article" />` in `<head>` (title, description,
canonical, robots, Open Graph, Twitter). Without Blade: `Seo::meta($seo, $page, [...])` and `Seo::html($meta)`.

**Sitemap**: `Route::get('sitemap.xml', fn () => FullStackStudio\Content\Laravel\Sitemap::response(['localePrefix' => fn ($l) => $l === 'nl' ? '' : "/$l"]));`

## Blade components

The same building blocks as the React subpath of the JS SDK, as Blade components under the `content::` namespace (Laravel only). The markup uses the
`fsc-*` class names of the optional stylesheet:

```bash
php artisan vendor:publish --tag=content-assets   # public/vendor/content/content.css (identical to the JS SDK's styles.css)
php artisan vendor:publish --tag=content-views    # optional: copy every view to resources/views/vendor/content to edit them
```

```blade
<x-content::article-body :html="$article['bodyHtml']" class="prose" />

<x-content::page-blocks :blocks="$page['blocks']" :media="$page['media']" />

<x-content::image :media="$article['cover']" sizes="(min-width: 768px) 50vw, 100vw" />

<x-content::form form-key="contact" />
```

- **`article-body`** prints `bodyHtml` unescaped. That is safe only because Content sanitizes it on save (allowlisted tags, no scripts, no event handlers, no
  `javascript:` links); never pass it HTML from anywhere else. `tag="article"` changes the wrapping element.
- **`page-blocks`** renders all block types (`richText`, `image`, `gallery`, `cta`, `hero`, `columns`, `articleList`, `form`, `embed`; `legalDocument`
  renders nothing until you give it a view). Only the HTML of `richText` and `columns` is printed unescaped; everything else is escaped and links are checked
  against `javascript:`/`data:`. Pass `:media="$page['media']"` so blocks whose file was deleted are left out; without it the media URL is derived from the id.
  An unknown block type is skipped.
  **Override a block type** with a view of your own, per call (`:views="['articleList' => 'blocks.articles']"`) or globally in `config/content.php`
  (`'blocks' => ['articleList' => 'blocks.articles']`; `'unknown'` handles types this version does not know). Your view receives `$block`, `$index`,
  `$baseUrl` and `$resolve($mediaId)` (media info or null). Because the `articleList` block only says *what* to show, you fetch the articles in that view
  (`@php($articles = \Content::listArticles([...]))`). To restyle a default view instead, publish the views.
- **`image`** renders `<img>` with a `srcset` of Content's on-demand variants (png, jpeg, still webp; widths 320-1920, never upscaled) and `sizes`
  (default `100vw`). `formats="avif,webp"` renders a `<picture>`. `alt` defaults to the media record's alt; `alt=""` marks a decorative image;
  `:eager="true"` for the image above the fold. Gif, svg and other types render the original without `srcset`.
- **`form`** fetches the definition (cached) and renders the form on the server: it works without JavaScript. It posts to a route of the package, which
  validates required fields and lengths, submits to Content with the visitor's IP and redirects back to the form with a message, the old input and the
  error per field (error bag `content_form_{key}`, flash key `content_form.{key}`). The honeypot is rendered off-screen. A form with a payment field
  redirects to the checkout (a `choice` amount is entered in euros and sent as cents). With a Turnstile site key the widget is rendered (that needs
  JavaScript; without a token the submit is refused with a message).
  The route is opt-in and needs the `web` middleware group (session and CSRF): set `FSC_FORMS_ENABLED=true` or call `Content::formRoutes()`
  (`POST content/forms/{key}`, name `content.form`). `locale` (`en`/`nl`, default `content.forms.locale` or the app locale) and `:labels="[...]"` change the
  texts. The visitor's IP is read from `content.forms.ip_header` (default `cf-connecting-ip`; set it to the header your proxy overwrites, or `null` to use
  the IP Laravel sees) and sent as `X-FSC-Client-Ip`, so Content's per-visitor limits work behind your server.

There is no cookie banner component: the banner needs JavaScript and `localStorage`, so use the JS one (`@fullstackstudio/content`) or build it on
`getConsentConfig()` / `recordConsent()`.

## Example site

`examples/laravel` is a small but real Laravel 12 site on this package (composer path repository): home, `/blog` and `/blog/{slug}` (published only, 404 for
the rest), every other path as a page of blocks, a contact form (`<x-content::form>`), `sitemap.xml`, the webhook route, the redirect middleware,
`<x-content::meta>` and `GET /api/health`. When Content is down the site answers 503 instead of a stack trace, and `/api/health` stays up.

```bash
cd examples/laravel
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate   # then set FSC_SERVER_TOKEN and FSC_WEBHOOK_SECRET
php artisan serve
```

## Deploying on the FSS platform

`examples/laravel/Dockerfile` is the template for customer sites: multi-stage (composer `--no-dev`, vite build), then `php:8.4-fpm-alpine` with nginx in
one container, listening on port 3000, running as `www-data`, `HEALTHCHECK` on `/api/health`. Tuned for a small idle footprint: OPcache without timestamp
validation, PHP-FPM `pm = ondemand` (`pm.max_children = 4`, `pm.process_idle_timeout = 30s`), one nginx worker, `memory_limit = 64M`, and a tiny shell
entrypoint instead of supervisord. Measured with `docker stats`: about 32-35 MiB for the whole container at idle.

```bash
docker build -f examples/laravel/Dockerfile -t my-site .      # from the repository root, the example uses a path repository
```

The image contains no secrets and no `.env`. At start the entrypoint runs `php artisan config:cache route:cache view:cache` (the environment only exists
then) and refuses to start without `APP_KEY`. Environment variables:

| Variable | Purpose |
| --- | --- |
| `APP_KEY` | required (`php artisan key:generate --show`) |
| `FSC_SERVER_TOKEN` | server token (`fsc_srv_...`) |
| `FSC_WEBHOOK_SECRET` | secret of the webhook you created in the admin; the webhook URL is `https://<site>/content/webhook` |
| `FSC_API_URL` | defaults to `https://content.fullstackstudio.nl` |

Optional: `FSC_IP_HEADER`, `FSC_CACHE_TTL`, `CACHE_STORE=redis` (the default file cache is per container, so with several replicas the webhook only
clears the cache of the replica it reaches; the TTL is the safety net). A real customer site has no path repository: require the package from its VCS
repository (see Install) and build with the site as context. On the platform: `create_runtime` with `internalPort` 3000 and the variables above in `envText`.

## Errors

```php
use FullStackStudio\Content\ContentException;

try {
    $content->getArticle('nope');
} catch (ContentException $e) {
    $e->status();      // HTTP status, 0 when there was no response
    $e->errorCode();   // 'not_found', 'rate_limited', ... or SDK codes below
    $e->requestId();   // X-FSC-Request-Id, quote it to support
    $e->retryAfter();  // seconds, on a 429
    $e->isNotFound(); $e->isRateLimited();
}
```

Branch on `errorCode()`, never on the message. API codes are listed in `docs/api-v1.md`. The SDK adds, with status 0: `network_error`,
`timeout`, `invalid_response` (the body is never included in the message) and `invalid_config`.

## Transports

The default is cURL (no redirects, per-call timeout). `Psr18Transport` wraps any PSR-18 client; implement
`FullStackStudio\Content\Http\Transport` to bring your own (tests use a fake that records requests).

## Develop

```bash
composer install
vendor/bin/phpunit
```

Tests never touch the network.
