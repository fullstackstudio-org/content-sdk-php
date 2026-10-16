# AGENTS.md: fullstackstudio/content (PHP SDK)

Guide for AI coding agents working in a Laravel (or other PHP) site that uses this package. Read it before changing anything about content,
forms, SEO, the sitemap, redirects, caching or the webhook. Everything below matches the code of this version; if the code and this file
disagree, the code wins. Full reference: `README.md` next to this file.

## What it is

`fullstackstudio/content` is the PHP client for **Fullstack Content**, a headless CMS (`https://content.fullstackstudio.nl`, API `/api/v1`).
The site renders; Content stores pages (blocks), articles, categories, forms and their submissions, media, SEO settings and redirects.
It also has webshop, members, appointments, tickets, invoices, newsletter, chat, FAQ, events and legal modules (see the end).

- PHP >= 8.2 (`ext-curl`, `ext-json`). Laravel 11 or 12 for the integration (service provider, facade, Blade components, routes); the client itself works in any PHP.
- Responses are plain associative arrays (JSON as the API sends it, single objects unwrapped: `$article['title']`, not `->title`).
- Every failure is a `FullStackStudio\Content\ContentException`. The token never appears in a message.

## Install

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/fullstackstudio-org/content-sdk-php" }],
    "require": { "fullstackstudio/content": "^0.5.1" }
}
```

The repository is public (no token or SSH key needed). Once the package is on Packagist, plain `composer require fullstackstudio/content` is
enough and the `repositories` entry can go. The service provider and the `Content` facade are auto-discovered.

```bash
php artisan vendor:publish --tag=content-config   # optional: config/content.php
php artisan vendor:publish --tag=content-assets   # optional: public/vendor/content/content.css (the fsc-* classes)
php artisan vendor:publish --tag=content-views    # optional: copy the Blade views to resources/views/vendor/content
php artisan fsc:agents                            # writes the short pointer block to AGENTS.md (and @AGENTS.md to an existing CLAUDE.md)
```

### Environment

| Variable | Default | Purpose |
|---|---|---|
| `FSC_SERVER_TOKEN` | none | Server token `fsc_srv_...`. Required. |
| `FSC_API_URL` | `https://content.fullstackstudio.nl` | Must be https (http only for localhost). |
| `FSC_WEBHOOK_SECRET` | none | Secret `fsc_whsec_...` of the webhook created in Content (shown once). |
| `FSC_WEBHOOK_ENABLED` | `false` | Registers `POST content/webhook` (route name `content.webhook`). |
| `FSC_WEBHOOK_PATH` / `FSC_WEBHOOK_TOLERANCE` | `content/webhook` / `300` | Path / allowed clock skew in seconds. |
| `FSC_FORMS_ENABLED` | `false` | Registers `POST content/forms/{key}` (route name `content.form`), needed by `<x-content::form>`. |
| `FSC_FORMS_PATH` / `FSC_FORMS_LOCALE` | `content/forms` / app locale | Form route path / `nl` or `en` labels. |
| `FSC_IP_HEADER` | `cf-connecting-ip` | Header holding the visitor's IP behind your proxy (`content.forms.ip_header`). Only a header your proxy overwrites. |
| `FSC_CACHE_ENABLED` / `FSC_CACHE_STORE` / `FSC_CACHE_TTL` | `true` / default store / `3600` | Read cache on/off, Laravel cache store, safety-net TTL in seconds. |
| `FSC_TIMEOUT_MS` | `10000` | Timeout per API call. |
| `FSC_UPLOAD_MAX_MB` | `20` | Only in the example Docker image: largest request body for form uploads (nginx + PHP limits, PHP memory follows). |

Tokens: `fsc_srv_...` is a **server token** (full API within its scopes, can read drafts). `fsc_pub_...` only reads published content and submits
forms. `X-FSC-Client-Ip` (visitor IP, for Content's per-visitor limits) is only sent with a server token. This SDK runs server-side, so the
server token is right, but it must exist **only** as an environment variable at runtime: never in the repository, a committed `.env`, a build
image, a view, JavaScript or a response.

## Basics

```php
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;

public function __construct(private readonly ContentClient $content) {}   // singleton; caches reads in Laravel's cache, forwards request()->ip()
```

`Content::getArticle('x')` (facade `FullStackStudio\Content\Laravel\Facades\Content`) forwards methods only. Modules are properties
(`$content->admin`, `$content->products`, ...), which the facade cannot reach: inject `ContentClient` for those.

Client methods: `getProject`, `listArticles`, `getArticle`, `listCategories`, `getTranslations`, `listPages`, `getPage`, `getForm`, `submitForm`,
`restartFormPayment`, `listMedia`, `getMedia`, `mediaUrl`, `assetUrl`, `listRedirects`, `resolveRedirect`, `getSeo`, `getSitemap`, `search`,
`getConsentConfig`, `recordConsent`. Params go first, call options last: `timeoutMs`, `clientIp`, `cache` (false skips the cache), `tags`, `ttl`.

A server token reads **drafts**. A public page must never show unpublished content: list with `'status' => 'published'` and check
`($item['status'] ?? null) === 'published'` on detail pages (`getPage` already 404s for unpublished pages).

## Recipes

### Error handling (use in every controller)

```php
try {
    $article = $this->content->getArticle($slug);
} catch (ContentException $e) {
    if ($e->isNotFound()) { abort(404); }
    report($e);
    abort(503);          // Content down or erroring: 503, never a stack trace
}
```

`ContentException`: `status()` (0 = no response), `errorCode()` (`not_found`, `rate_limited`, ... plus SDK codes `network_error`, `timeout`,
`invalid_response`, `invalid_config`), `requestId()` (quote it to support), `retryAfter()` (seconds, on 429), `isNotFound()`, `isRateLimited()`.
Branch on `errorCode()`/`status()`, never on the message.

### Pages by path: catch-all route, 404, SEO

```php
// routes/web.php: keep the catch-all LAST
Route::get('/{path}', [PageController::class, 'show'])->where('path', '.*');

public function show(string $path): View
{
    $path = trim($path, '/');
    if ($path === '' || strlen($path) > 200) { abort(404); }
    try {
        $page = $this->content->getPage($path);      // by id or path; throws 404 when missing or unpublished
    } catch (ContentException $e) {
        if ($e->isNotFound()) { abort(404); }
        report($e); abort(503);
    }
    return view('page', ['page' => $page, 'path' => '/' . $path]);
}
```

```blade
{{-- layout <head> --}}
<x-content::meta :page="$page" :path="$path" />                                  {{-- title (with the project's template), description, canonical, robots, Open Graph, Twitter --}}
{{-- article: <x-content::meta :page="$article" :path="'/blog/' . $article['slug']" type="article" /> --}}
{{-- no page (home, listings): <x-content::meta :path="request()->getPathInfo()" /> --}}

{{-- body --}}
<x-content::page-blocks :blocks="$page['blocks'] ?? []" :media="$page['media'] ?? null" />
```

`<x-content::meta>` survives a Content outage (falls back to the page's own fields). Without Blade: `Seo::meta($seo, $page, [...])` then `Seo::html($meta)`
(`FullStackStudio\Content\Seo`).

### Articles and categories

```php
$list = $this->content->listArticles(['status' => 'published', 'limit' => 12, 'page' => max(1, $request->integer('page', 1))]);
// ['articles' => [...], 'page', 'limit', 'total']   (no bodyHtml in lists)
$article = $this->content->getArticle($slug);              // with bodyHtml; also ['locale' => 'en', 'fallback' => true]
$tree = $this->content->listCategories();                  // tree with children and articleCount; listArticles(['category' => ...]) filters
```

```blade
<h1>{{ $article['title'] }}</h1>
@if (! empty($article['cover'])) <x-content::image :media="$article['cover']" :eager="true" /> @endif
<x-content::article-body :html="$article['bodyHtml'] ?? null" />
```

### SEO, sitemap.xml, robots

```php
use FullStackStudio\Content\Laravel\Sitemap;

Route::get('/sitemap.xml', fn () => Sitemap::response([
    'extra' => [['url' => url('/')], ['url' => url('/blog')]],                 // your own routes
    // 'localePrefix' => fn ($l) => $l === 'nl' ? '' : "/$l",                  // Content returns paths without a locale prefix
    // 'entryPath' => fn ($entry) => $entry['path'] ?? null,                   // own path per entry (null skips it)
]));
```

Empty when the project's `robotsIndex` is off (a test site). The origin comes from `canonicalOrigin` in Content's SEO settings or the `baseUrl` option.
Article paths come from the path template in the project's SEO settings (it must match your real URLs). robots.txt: serve your own static file or route;
`$content->getSeo()['robotsIndex']` tells whether the project allows indexing.

### Redirects

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(prepend: [\FullStackStudio\Content\Laravel\RedirectMiddleware::class]);
})
```

Looks up GET/HEAD paths (skips `/api`, `/build`, `/storage` and files with an extension), caches answers (including "no redirect") under the
`content:redirects` tag and pauses lookups for 15 s when Content is down (`content.redirects.*`). Redirects are managed in Content, not in the repo.

### Forms (server-side, works without JavaScript)

```env
FSC_FORMS_ENABLED=true
```

```blade
<x-content::form form-key="contact" />     {{-- optional: locale="nl", :labels="['submit' => '...']" --}}
```

The component fetches the definition (cached) and posts to the package route (`web` group: session + CSRF + `throttle:20,1`), which validates required
fields and lengths, submits to Content with the visitor IP (read from `content.forms.ip_header`, default `cf-connecting-ip`, falling back to Laravel's
`request()->ip()`) and redirects back to `#fsc-form-{key}` with the old input, a flash message (`content_form.{key}`) and field errors (error bag
`content_form_{key}`). The honeypot field is rendered off-screen, Turnstile is rendered when the form has a site key, a payment form redirects to the
checkout. Rules: **the site sends no mail and has no SMTP** (Content notifies the owner and confirms to the visitor); do not post forms from the browser
with your server token; do not re-implement validation. Custom UI: use `Forms::values/validate` and `$content->submitForm($key, $values, ['clientIp' => $ip,
'meta' => [...], 'turnstileToken' => $t])`, include the honeypot field (`definition.honeypotField`) unchanged, and handle 400 (field errors), 429
(`isRateLimited()`). Never retry a submit blindly.

**File uploads.** A field of type `file` (definition: `file: { maxFiles, maxSizeMb, accept }`, `accept` tokens such as `pdf`, `jpg`, `docx`) renders as
`<input type="file">` with `accept=".pdf,.jpg,.jpeg"` (`Forms::acceptAttribute($field)`), `name="x[]"` + `multiple` when `maxFiles > 1`, and the `<form>` gets
`enctype="multipart/form-data"` (`Forms::hasFileField($definition)`). The route forwards the uploads of the form's own file fields only (unknown file inputs are
dropped). Custom UI: `Forms::values($definition, $request->all(), $request->allFiles())`, then `submitForm($key, $values, ...)`: a value may be an `SplFileInfo`
(Laravel `UploadedFile` included), an `Http\UploadFile` or a list of them; with files the request is `multipart/form-data`, otherwise the usual JSON. A required
file field can only be satisfied through multipart. Content checks size (413), type (415, it looks at the first bytes) and count (400); 50 MB per submission
in total, and files are read into memory. Read an uploaded file back with a server token: submissions carry `files: [{ id, field, name, sizeBytes, contentType,
createdAt }]` and `$content->downloadSubmissionFile($formKey, $submissionId, $fileId)` returns `['bytes' => ..., 'contentType' => ..., 'fileName' => ...]`
(treat as untrusted, serve as a download). The webhook `form.submission.created` lists `files` without names or bytes.

### Media and images

`<x-content::image :media="$media" sizes="(min-width: 768px) 50vw, 100vw" formats="avif,webp" :eager="true" alt="" />` renders `<img srcset>` or `<picture>`
from Content's on-demand variants (never upscaled). `alt` defaults to the media record's alt, `alt=""` marks a decorative image, `eager` only for the image
above the fold. Plain URLs: `$content->mediaUrl($id)`, `$content->assetUrl($path)`; `listMedia`/`getMedia` for the library.

### Blade components (namespace `content::`)

`meta`, `article-body` (prints `bodyHtml` unescaped: safe only for HTML from Content, which sanitizes on save; never pass other HTML), `page-blocks`,
`image`, `form`. Override a block type with your own view: `:views="['articleList' => 'blocks.articles']"` or `config('content.blocks')`; your view receives
`$block`, `$index`, `$baseUrl`, `$resolve($mediaId)`. The `articleList` block only says what to show: fetch the articles in your view. There is no cookie banner
component (needs JS): build one on `getConsentConfig()` / `recordConsent()`.

### Cache and webhook invalidation

Reads are cached under tags (`content:articles`, `content:article:<slug>`, `content:page:<path>`, `content:seo`, ... see `FullStackStudio\Content\Tags`) in your Laravel
cache store (default TTL 3600 s as safety net). Writes and `submitForm` are never cached. For "immediate" updates:

1. In Content create a webhook to `https://<your-site>/content/webhook`; put its secret in `FSC_WEBHOOK_SECRET`.
2. Set `FSC_WEBHOOK_ENABLED=true` (or call `Content::routes()` in a routes file). The route verifies the signature against the raw body, invalidates the tags,
   dispatches `FullStackStudio\Content\Laravel\Events\ContentWebhookReceived` (`->event`, `->tags`) and answers 200 (401 bad signature, 500 secret missing).
   It uses the `api` middleware group (no CSRF). If you register it in `web`, exempt it: `$middleware->validateCsrfTokens(except: ['content/webhook'])`.

Do not write your own cache layer on top and do not cache Content responses in views for long. With a file cache and several replicas the webhook only clears the replica it
reaches: use a shared store (`FSC_CACHE_STORE=redis`) or accept the TTL.

### Other modules (brief)

Properties on the client, same names as the JS SDK, inputs/outputs plain arrays, call options last: `products`, `cart`, `orders`, `payments`, `discounts`, `shipping`
(webshop: price with `cart->applyCode`, then `orders->place`, then `payments->startForOrder` and redirect to `payment.checkoutUrl`; pass `expectedTotalCents`; keep `accessToken` out of URLs),
`members` (login incl. two-factor; keep the session token in an httpOnly cookie), `appointments`, `tickets` (uploads via `Http\UploadFile`), `customers`, `invoices`, `newsletter`
(`subscribe`/`confirm`/`unsubscribe`), `returns`, `reviews`, `downloads`, `events`, `faq`, `legal`, `chat` (store the conversation token), `admin` (write routes; server token + scopes).
Details and examples: README sections "Modules", "Webshop", "Members", "Chat", "Files and uploads".

## Rules (do not)

- Keep URLs 1:1 with what Content knows. Do not invent routes for content that Content serves, and do not change slugs/paths in code.
- Never commit or bake in secrets (`FSC_SERVER_TOKEN`, `FSC_WEBHOOK_SECRET`, `APP_KEY`); they are runtime environment variables. Do not echo them in logs or views.
- No SMTP/mail sending for forms in the site, no copy of managed content in the repo.
- Always handle `ContentException`; 404 -> 404, anything else -> `report($e)` + 503. Do not catch and silently render empty content for a failing detail page.
- Do not show drafts (see above). Do not pass user input as `baseUrl`; do not use `http://` base URLs except localhost.
- Do not render HTML from other sources through `article-body`/`page-blocks` (they print Content's sanitized HTML unescaped).
- Do not expose the server token or call write routes (`admin`) from user-reachable code paths without your own authorization.
- Do not use `Http::fake()` for the SDK: it uses its own cURL transport.

## Testing

No network in tests. Give the client a fake `FullStackStudio\Content\Http\Transport` (`send(Request): Response`; `new Response($status, $headersLowercase, $jsonBody)`) and bind it:

```php
$this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_test', 'transport' => $fake]));
```

`ClientOptions` keys: `token`, `baseUrl`, `timeoutMs`, `clientIp`, `transport`, `cache`, `cacheTtl`. `WebhookVerifier::sign($secret, $rawBody)` computes a valid
`X-FSC-Signature` for webhook tests. The package's own test fake lives in the package repository (`tests/Support/FakeTransport.php`) and is not installed with it.

## Version and docs

This guide: version 0.5.1 of the package (see `CHANGELOG.md`). Full docs: `README.md` (Quick start, Modules, Tokens, Cache and webhooks, Laravel, Blade components,
Errors, Transports). API reference: `docs/api-v1.md` in the Content repository. `php artisan fsc:agents` (re)writes the pointer block in the site's `AGENTS.md`.
