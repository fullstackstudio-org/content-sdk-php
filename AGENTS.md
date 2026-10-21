# AGENTS.md: fullstackstudio/content (PHP SDK)

Guide for AI coding agents working in a Laravel (or other PHP) site that uses this package. Read it before changing anything about content,
forms, SEO, the sitemap, redirects, caching or the webhook. Everything below matches the code of this version; if the code and this file
disagree, the code wins. Full reference: `README.md` next to this file.

## Altijd in elke site

Bouw dit in **elke** site die dit pakket gebruikt, ook als de module voor het project (nog) uit staat. Aanzetten in Content vraagt dan geen
codewijziging. Met de module uit renderen de componenten niets, geeft de paginaroute een gewone 404 en doen redirects niets, zonder exceptions
en zonder logregels boven `debug`.

**1. Layout** (`resources/views/layouts/app.blade.php`): in `<head>` eerst de meta, dan de site-standaarden en de foutrapportage; vlak vóór
`</body>` de eigen code en analytics. De componenten zetten zelf de CSP-nonce (`Vite::cspNonce()`) op elke `<script>` en `<style>`, of geef
`nonce="..."` mee.

```blade
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @isset($page)
        <x-content::meta :page="$page" :path="$path ?? null" />
    @else
        <x-content::meta :path="request()->getPathInfo()" />
    @endisset
    <x-content::head />      {{-- verificatie (Google, Bing, Facebook, Pinterest), favicon, apple-touch-icon, theme-color, LocalBusiness JSON-LD, eigen head-code --}}
    <x-content::errors />    {{-- browserfouten naar GlitchTip (module errors) --}}
    @vite(['resources/css/app.css'])
</head>
<body>
    ...
    <x-content::body-end />  {{-- eigen body-code en klik-events (tel:, mailto:, WhatsApp) naar Umami --}}
    <x-content::analytics /> {{-- Umami (module analytics) --}}
</body>
```

`<x-content::meta>` valt zonder eigen afbeelding terug op de deelafbeelding van de site (`social.ogImage`). Alles komt uit één `GET /api/v1/site`
per verzoek (cache-tag `content:site`, webhook `site.updated`). Valt Content uit, dan wordt het laatste goede antwoord gebruikt of niets, met één
waarschuwing in de log en een pauze van 15 s.

**2. Routes** (`routes/web.php`): sitemap, robots en als laatste de catch-all voor pagina's uit Content. Verwijder `public/robots.txt` (de
webserver serveert een statisch bestand eerst).

```php
use App\Http\Controllers\PageController;
use FullStackStudio\Content\Laravel\Robots;
use FullStackStudio\Content\Laravel\Sitemap;

Route::get('/sitemap.xml', fn () => Sitemap::response([
    'extra' => [['url' => url('/')]],                       // je eigen routes
]));
Route::get('/robots.txt', fn () => Robots::response());     // Disallow: / als robotsIndex uit staat (testsite), anders Allow + Sitemap

// ... je eigen routes ...

Route::get('/{path}', [PageController::class, 'show'])->where('path', '.*');   // ALTIJD als laatste
```

```php
public function show(string $path): View
{
    $path = trim($path, '/');
    if ($path === '' || strlen($path) > 200) { abort(404); }
    try {
        $page = $this->content->getPage($path);
    } catch (ContentException $e) {
        if ($e->isNotFound()) { abort(404); }      // ook 404 `module_disabled`: pagina's staan uit
        report($e);
        abort(503);
    }
    if (($page['status'] ?? 'published') !== 'published') { abort(404); }
    return view('page', ['page' => $page, 'path' => '/' . $path]);
}
```

`Sitemap::response()` geeft met de SEO-module uit alleen je `extra`-regels en bij een storing een 503 met `Retry-After`; zonder `canonicalOrigin`
in Content gebruikt hij de eigen origin van de app. `Robots::response()` staat met de module uit alles toe, en ook als Content onbereikbaar is (200,
`no-store`): een korte storing mag het crawlen niet stilleggen; een testsite blijft dan afgeschermd door de `noindex` van `<x-content::meta>`.

**3. Redirects** (`bootstrap/app.php`), vóór alles in de web-groep:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');
    $middleware->web(prepend: [\FullStackStudio\Content\Laravel\RedirectMiddleware::class]);
})
```

Met de module uit pauzeert de lookup 60 s per keer; bij een storing 15 s. De querystring (utm, click ids) blijft behouden.

**4. Webhook**: `FSC_WEBHOOK_ENABLED=true` en `FSC_WEBHOOK_SECRET=fsc_whsec_...` (runtime-env). De route `POST /content/webhook` leegt de cache-tags,
ook `content:site` bij `site.updated`. Maak in Content een webhook naar `https://<site>/content/webhook` met `events: ["*"]` (alle events), of
neem `site.updated` expliciet op naast de andere events: zonder dat event zie je een wijziging van de site-standaarden pas na de cache-TTL.

**5. Content-Security-Policy.** Heeft de site een CSP, voeg dan de bronnen van de eigen code en analytics toe. De componenten gebruiken de nonce;
gebruik dus een nonce-gebaseerde `script-src` (en `style-src` voor eigen `<style>`-blokken):

```php
// app/Http/Middleware/ContentSecurityPolicy.php, in de web-groep
use FullStackStudio\Content\Laravel\Facades\Content;
use Illuminate\Support\Facades\Vite;

public function handle(Request $request, Closure $next): Response
{
    Vite::useCspNonce();                                      // vóór het renderen: de componenten nemen deze nonce over
    $response = $next($request);
    $nonce = Vite::cspNonce();
    $csp = "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}'; "
        . "img-src 'self' data: https://content.fullstackstudio.nl; connect-src 'self'; frame-ancestors 'self'";
    $csp = Content::analyticsCspMerge(Content::siteCspMerge($csp));   // string in, string uit; een array kan ook
    $response->headers->set('Content-Security-Policy', $csp);

    return $response;
}
```

`siteCspMerge()` neemt een array (geeft een array terug) of een CSP-string (geeft een string terug). Een ontbrekende directive begint bij
`default-src`; zonder `default-src` blijft hij weg (toevoegen zou de rest verbieden). Een bestaande `script-src-elem`/`style-src-elem` krijgt de
bronnen ook. Hosts die een script pas later zelf laadt staan niet in de snippet: voeg die met de hand toe. Voor Google Analytics 4 / Google Tag Manager
is dat in `connect-src` (en `img-src`): `https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com`.

**6. Cookiebanner: het toestemmingscontract.** Eigen code met toestemming `necessary` draait altijd. Code voor `analytics`, `marketing` of
`preferences` staat als inerte `<template data-fsc-code data-consent="...">` in de pagina en draait pas na toestemming voor die categorie; heatmaps en
session replay van analytics volgen hetzelfde contract. De banner van de site doet bij elke keuze twee dingen:

```js
const choice = { choice: 'custom', categories: ['analytics'] };          // of { choice: 'all' } / { choice: 'none' }
localStorage.setItem('fsc-consent', JSON.stringify(choice));             // volgende pagina's lezen dit
window.dispatchEvent(new CustomEvent('fsc:consent', { detail: choice })); // deze pagina activeert meteen
```

- Sleutel en event: `fsc-consent` en `fsc:consent` (aan te passen met `FSC_CONSENT_KEY` / `FSC_CONSENT_EVENT`; geldt voor site en analytics).
- Begrepen waarden: `{categories: [...]}`, `{choice: 'all'|'none'|'custom', categories}`, `{analytics: true, marketing: false, preferences: true}`,
  `'accepted'`/`'all'`/`true` (alles), `'declined'`/`'necessary'`/`'none'`/`false` (niets), ook als JSON-tekst. Onbekende categorieën tellen niet.
- Geen cookie: de keuze staat alleen in `localStorage` (de server rendert altijd dezelfde HTML; de browser beslist). Geen keuze = niets dan `necessary`.
- Intrekken werkt vanaf de volgende pagina: code die al draaide kan niet worden uitgeladen. Herlaad de pagina na intrekken als dat nodig is.
- Leg de keuze ook vast als bewijs: `recordConsent(['choice' => ..., 'categories' => [...]], ['clientIp' => ..., 'userAgent' => ...])` vanuit je
  eigen route (`POST /api/v1/consent`). De categorieën die de banner moet tonen komen uit `getConsentConfig()['categories']`.
- Eigen code met `document.write` werkt niet na toestemming (de pagina is dan al geladen); gebruik de asynchrone variant van de leverancier.
- Scripts draaien in de volgorde van de bron: een extern script zonder `async` wordt afgewacht (geladen of fout, hooguit 15 s) voordat het volgende
  script erin gaat, ook over fragmenten heen. Een inline `Lib.init()` na `<script src=".../lib.js">` werkt dus gewoon.

Checklist: `<x-content::meta>`, `<x-content::head />`, `<x-content::errors />` in `<head>`; `<x-content::body-end />` en `<x-content::analytics />` vóór
`</body>`; catch-all als laatste route; `RedirectMiddleware` vooraan in de web-groep; `/sitemap.xml` en `/robots.txt` via het pakket; webhook aan;
CSP gemerged; de banner schrijft `fsc-consent` en stuurt `fsc:consent`.

## What it is

`fullstackstudio/content` is the PHP client for **Fullstack Content**, a headless CMS (`https://content.fullstackstudio.nl`, API `/api/v1`).
The site renders; Content stores pages (blocks), articles, categories, forms and their submissions, media, SEO settings and redirects.
It also has webshop, members, appointments, tickets, invoices, newsletter, chat, FAQ, events and legal modules (see the end).

- PHP >= 8.2 (`ext-curl`, `ext-json`). Laravel 11, 12 or 13 for the integration (service provider, facade, Blade components, routes); the client itself works in any PHP.
- Responses are plain associative arrays (JSON as the API sends it, single objects unwrapped: `$article['title']`, not `->title`).
- Every failure is a `FullStackStudio\Content\ContentException`. The token never appears in a message.

## Install

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/fullstackstudio-org/content-sdk-php" }],
    "require": { "fullstackstudio/content": "^0.8.0" }
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

**Missing a scope? Do not mint a new token.** The rights of an existing token (scopes, and for `fsc_pub_` its allowed origins) can be changed in
place: Content admin (project, tab Tokens, "Rechten wijzigen"), FSS admin (project, tab Content), Content MCP `update_api_token` or FSS MCP
`update_content_api_token`. The secret stays the same, so `FSC_SERVER_TOKEN` does not change; the next request gets the new rights. A `403
insufficient_scope` on one route (for example `errors:read`) is fixed this way.

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
`getConsentConfig`, `recordConsent`, `getSite`, `getAnalyticsConfig`, `suggestAddresses`, `lookupAddress`. Params go first, call options last: `timeoutMs`, `clientIp`, `cache` (false skips the cache), `tags`, `ttl`.

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

### Address autocomplete (Dutch addresses, no module)

```blade
<x-content::address-field name="address" required />   {{-- inside your own <form>; inputs address[street], address[houseNumber], address[addition], address[postcode], address[city], address[country] --}}
```

One search field with suggestions (PDOK through Content), then a one-line summary with "Change"; "Enter manually" shows the separate fields (also the only UI
without JavaScript). The fields are ordinary inputs, so they travel with a normal Laravel POST. For a flat Content form pass your field names:
`:names="['street' => 'straat', 'houseNumber' => 'huisnummer', 'addition' => 'toevoeging', 'postcode' => 'postcode', 'city' => 'plaats', 'country' => 'land']"`
(`lat`/`lon` with `:geo="true"`). Start values: `:value="[...]"`, `old()` wins after a validation error. `addition` holds the house letter and addition (`A`, `2`, `A-2`).
Validate server-side like any other input (the browser values are user input); `Address::normalizePostcode()` helps. Do not call Content from the browser
and do not put the server token in a view: the browser only talks to this site's routes `GET /_fsc/address/suggest|lookup|field.js|field.css`
(`content.address.*`, on by default, no session or cookies, rate limit `content.address.rate_limit` per visitor IP per minute; `FSC_ADDRESS_ENABLED=false` switches them off).
Server side: `$content->suggestAddresses($q, 8, ['clientIp' => $ip])` and `$content->lookupAddress($postcode, $number)` (exact match or `null`). Both give an empty
result when PDOK is down (fail-soft); only invalid input (query under 3 or over 100 characters, bad postcode) throws `ContentException` 400. Addresses are
personal data: do not log queries or results. Put `FSC_IP_HEADER` right behind your proxy so the per-visitor limits work. The script and styles are separate
requests from your own domain (CSP `'self'`); add your nonce with `nonce="..."` if you use one.

### Media and images

`<x-content::image :media="$media" sizes="(min-width: 768px) 50vw, 100vw" formats="avif,webp" :eager="true" alt="" />` renders `<img srcset>` or `<picture>`
from Content's on-demand variants (never upscaled). `alt` defaults to the media record's alt, `alt=""` marks a decorative image, `eager` only for the image
above the fold. Plain URLs: `$content->mediaUrl($id)`, `$content->assetUrl($path)`; `listMedia`/`getMedia` for the library.

### Blade components (namespace `content::`)

`meta`, `head` and `body-end` (see "Altijd in elke site"), `analytics` (see Analytics), `errors` (see Error monitoring), `article-body` (prints `bodyHtml` unescaped: safe only for HTML from Content, which sanitizes on save; never pass other HTML), `page-blocks`,
`image`, `form`, `address-field` (see Address autocomplete). Override a block type with your own view: `:views="['articleList' => 'blocks.articles']"` or `config('content.blocks')`; your view receives
`$block`, `$index`, `$baseUrl`, `$resolve($mediaId)`. The `articleList` block only says what to show: fetch the articles in your view. There is no cookie banner
component (needs JS): build one on `getConsentConfig()` / `recordConsent()` and follow the consent contract in "Altijd in elke site".

### Analytics (module `analytics`, Umami): pageviews, events, heatmaps, session replay, campaign attribution

Turn the module on for the project in Content (staff do this; Content then creates the Umami website itself) and put one tag in the layout, at the end of
`<body>` (or in `<head>`). Nothing else is needed: no keys, no ids, no env vars.

```blade
<x-content::analytics />
```

What it renders (from `getAnalyticsConfig()`, cached 5 minutes; nothing at all while the module is off or not ready, so it is safe in every layout):

- The Umami tracker, deferred, `data-do-not-track="true"`, `data-domains` (only the site's own hosts count), WITHOUT `data-exclude-search` so `utm_*` reach Umami.
- The helper script `/_fsc/a/fsc.js`: starts heatmaps and session replay (Umami `recorder.js`) only after cookie consent, and keeps first-touch attribution.

Binding consent rules (do not change them):

| What | Consent needed |
|---|---|
| Pageviews and custom events (Umami is cookieless) | No, they always run |
| Heatmaps (clicks, scroll) and session replay | Yes. Loaded only after the visitor accepted cookies; revoking stops every upload at once |

Replay always masks form input (password fields and every other input); Content can also mask all text (mask level `strict`). Add `data-rr-block`/class
`rr-block` to an element that must never be recorded, `rr-mask` to mask its text.

**Wiring your cookie banner** (the recorder never starts without one of these). When the visitor accepts or declines:

```js
window.dispatchEvent(new CustomEvent('fsc:consent', { detail: 'accepted' })); // or 'declined'; also true/false, { analytics: true },
                                                                              // { categories: ['analytics', ...] }
localStorage.setItem('fsc-consent', 'accepted');                              // so later pages know it (or 'declined')
```

This is the same contract as for the site's own code (section "Altijd in elke site"); prefer `{ choice, categories }` there, the helper understands
both. `window.fscAnalytics.setConsent(true|false)` does the same as the event. If your banner already stores its choice under another key, set
`FSC_CONSENT_KEY=<that key>` (values like `accepted`/`declined`, `true`/`false` or JSON `{"analytics":true}` are understood). Do not name heatmaps or replay
in the banner itself; the privacy text covers them (below). Custom events: `data-umami-event="signup"` on an element, or `window.fscAnalytics.track('signup', {...})`.

**First-party mode (default).** Script and collect calls go through this site: `/_fsc/a/script.js`, `/_fsc/a/recorder.js`, `/_fsc/a/api/send`,
`/_fsc/a/api/record`, `/_fsc/a/api/websites/<id>/recorder`, proxied to `analytics.fullstackstudio.nl`. Ad blockers that block the analytics host still count
pageviews, and no third-party host appears in the page. The proxy only accepts this site's own website id, forwards the visitor IP (`FSC_IP_HEADER`, default
`cf-connecting-ip`; it must be a header your proxy overwrites) and the user agent, never cookies, uses no session middleware, answers `{}` when Umami is slow or down
(3 s timeout), and limits each visitor IP (`FSC_ANALYTICS_RATE_LIMIT`, 240 per minute). Do not put these routes behind auth, CSRF or a `web` group, and do not
cache `/_fsc/a/api/*` in a CDN. **Direct mode** (`FSC_ANALYTICS_MODE=direct`) loads everything from `analytics.fullstackstudio.nl` and adds a preconnect;
only use it when the site cannot run the routes.

**Content-Security-Policy.** First-party needs nothing beyond `'self'` for `script-src` and `connect-src`, plus `worker-src 'self' blob: data:` for the replay
worker. Direct mode needs `https://analytics.fullstackstudio.nl` in `script-src` and `connect-src`. Get the sources for the configured mode with
`Content::analyticsCsp()` or merge them into your policy: `Content::analyticsCspMerge($policy)`. With a nonce-based CSP pass it: `<x-content::analytics :nonce="$nonce" />`
(Laravel's `Vite::cspNonce()` is used automatically when set).

**Attribution.** On the first page of a visit the helper keeps `utm_source/medium/campaign/term/content`, the ad click ids (`gclid`, `gbraid`, `wbraid`, `dclid`,
`gclsrc`, `fbclid`, `msclkid`, `ttclid`, `li_fat_id`, `twclid`, `rdt_cid`, `epik`, `sccid`), the landing URL and an external referrer in `sessionStorage`. Every
`<x-content::form>` sends them along (hidden field `_fsc_attribution`, read by the form route) and Content stores them with the submission (`meta.utm`,
`meta.clickIds`, `meta.landingUrl`, `meta.referrer`). For your own form add `data-fsc-attribution` to the `<form>`, then in the controller:
`$content->submitForm($key, $values, ['meta' => Forms::attributionMeta($request->input('_fsc_attribution'))])`; a fetch-based form can send
`window.fscAnalytics.attribution()` as `_meta`.

**Redirects must keep the query string**, or campaigns are lost: `RedirectMiddleware` keeps it (and keeps at least the utm and click id parameters when the
target has its own query or is absolute). Your own redirects (`redirect()->to(...)`, route redirects, `www` to apex in nginx/Traefik) must pass the query along too
(`$request->getQueryString()`; nginx `return 301 https://example.nl$request_uri;`).

**Privacy text** (privacy page, not the banner): "We measure visits with Umami, a privacy-friendly statistics tool without cookies; IP addresses are not stored.
Only if you accept cookies do we also analyse clicks and navigation anonymously (heatmaps and session recordings, with everything you type hidden)."

Checklist "analytics correct ingericht":

1. Module `analytics` on in Content and ready (`$content->getAnalyticsConfig()['ready'] === true`; `websiteId` set, `domains` = the live hosts).
2. `<x-content::analytics />` in the layout used by every public page (not in admin/account pages if you do not want them counted).
3. `GET /_fsc/a/script.js` returns JavaScript on the live site; in the browser network tab `/_fsc/a/api/send` answers 200 on a page view.
4. Without consent there is no `recorder.js` request; after accepting there is, and `/_fsc/a/api/record` posts appear while clicking. After declining they stop.
5. The cookie banner dispatches `fsc:consent` and stores its choice (`fsc-consent` or `FSC_CONSENT_KEY`).
6. CSP (if any) allows the sources above; no CSP errors in the console.
7. `FSC_IP_HEADER` matches the proxy in front of the site (Cloudflare: `cf-connecting-ip`).
8. Open `/?utm_source=test&gclid=abc`, submit a form: the submission in Content shows the utm and click id. Redirects keep `?utm_...`.
9. The privacy page mentions Umami and the after-consent heatmaps/recordings.

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

Properties on the client, inputs/outputs plain arrays, call options last: `products`, `cart`, `orders`, `payments`, `discounts`, `shipping`
(webshop: price with `cart->applyCode`, then `orders->place`, then `payments->startForOrder` and redirect to `payment.checkoutUrl`; pass `expectedTotalCents`; keep `accessToken` out of URLs),
`members` (login incl. two-factor; keep the session token in an httpOnly cookie), `appointments`, `tickets` (uploads via `Http\UploadFile`), `customers`, `invoices`, `newsletter`
(`subscribe`/`confirm`/`unsubscribe`), `returns`, `reviews`, `downloads`, `events`, `faq`, `legal`, `chat` (store the conversation token), `admin` (write routes; server token + scopes).
Details and examples: README sections "Modules", "Webshop", "Members", "Chat", "Files and uploads".

### Error monitoring (GlitchTip, module `errors`)

One switch: FullStack Studio turns the module `errors` on for the project in Content. Content then creates (or takes over the existing) GlitchTip
project and hands the DSNs to the site via `GET /api/v1/project/errors` (server token, scope `errors:read`). The SDK does the rest:

- **Backend**: on by default. Every exception Laravel reports (your `dontReport`/`report()` rules apply) goes to GlitchTip after the response. Nothing to
  register. If the site still has its own `sentry/sentry-laravel` with a DSN (`sentry.dsn`), the SDK stays quiet: remove that package/DSN when switching.
- **Browser**: put `<x-content::errors />` in the `<head>` of the layout (add `nonce="{{ $nonce }}"` if your CSP uses nonces). It renders a JSON config block and
  `<script src="/_fsc/e/reporter.js" defer>` from your own domain, and the reporter posts to the first-party tunnel `POST /_fsc/e/envelope` on your own
  domain, which forwards server-side. **CSP needs nothing extra** (`script-src 'self'`, `connect-src 'self'` are enough) and ad blockers do not block it.
- Never in `local`/`testing`. The release is the full Git SHA from the image build (`FSS_RELEASE`, else `APP_BUILD_SHA`, else `SENTRY_RELEASE`).

What is sent: exception class, the message after the scrubber (emails, phone numbers, tokens/keys, uuids, IBAN, postcode, IP, long numbers, SQL and
bindings, URL query strings and anything in quotes are removed; an empty result becomes `[message removed]`), source locations (relative path, line,
function; vendor marked), the route **template** (`/blog/{slug}`, never the path), environment, release. In the browser only uncaught errors and unhandled
rejections whose stack has a frame of one of your own scripts. What is never sent: request URL/query/body, headers, cookies, session, user, IP, form
values, breadcrumbs, context, stack variables, source code.

- Never put personal data (names, emails, addresses, order contents) in exception messages: write `throw new RuntimeException('Order not paid')`, log the id
  yourself if needed. Names cannot be recognised by the scrubber.
- Do not add `sentry/sentry-laravel` or `@sentry/browser` next to this; do not report from the browser to another host; do not change the tunnel path.
- Env (optional): `FSC_ERRORS_ENABLED=false` switches everything off, `FSC_ERRORS_BACKEND` / `FSC_ERRORS_BROWSER` one side, `FSC_ERRORS_ENVIRONMENT` (e.g.
  `staging`), `FSC_ERRORS_DSN` / `FSC_ERRORS_BROWSER_DSN` override Content, `FSC_ERRORS_TUNNEL_TARGET=public` when the pod has no egress to the private ingest.
- Network: the backend and the tunnel send to the private ingest `errors.int0.net` (needs an egress NetworkPolicy to `100.64.0.1/32` TCP 443 on the FSS
  cluster) and fall back to the public ingest `errors.fullstackstudio.nl` when that does not answer.

Checklist for a site:
1. `composer update fullstackstudio/content` (>= 0.6.0); `FSC_SERVER_TOKEN` has the scope `errors:read` (a new server token has it; an older token gets it via "Rechten wijzigen", no new token needed).
2. Ask FullStack Studio to switch the module `errors` on (or check `GET /api/v1/project/errors` answers `status: ready`).
3. `<x-content::errors />` in the `<head>` of every layout; remove any old Sentry/GlitchTip code and `SENTRY_*` env.
4. The image build passes the commit as `FSS_RELEASE`; the egress policy to `errors.int0.net` exists (else set `FSC_ERRORS_TUNNEL_TARGET=public`).
5. Verify once with a synthetic exception from the console (`report(new RuntimeException('canary'))`) and a thrown error in a test browser; never via a
   public debug route or a real form.

## Rules (do not)

- Keep URLs 1:1 with what Content knows. Do not invent routes for content that Content serves, and do not change slugs/paths in code.
- Never commit or bake in secrets (`FSC_SERVER_TOKEN`, `FSC_WEBHOOK_SECRET`, `APP_KEY`); they are runtime environment variables. Do not echo them in logs or views.
- No SMTP/mail sending for forms in the site, no copy of managed content in the repo.
- Always handle `ContentException`; 404 -> 404, anything else -> `report($e)` + 503. Do not catch and silently render empty content for a failing detail page.
- Do not show drafts (see above). Do not pass user input as `baseUrl`; do not use `http://` base URLs except localhost.
- Do not render HTML from other sources through `article-body`/`page-blocks` (they print Content's sanitized HTML unescaped).
- Do not expose the server token or call write routes (`admin`) from user-reachable code paths without your own authorization.
- Do not use `Http::fake()` for the SDK: it uses its own cURL transport.
- Do not call the address endpoints from the browser with a token, and do not log addresses or address queries.
- Do not load Umami's `recorder.js` yourself or start it before consent, do not add cookies or an id of your own to analytics, and do not drop the query string on redirects.

## Testing

No network in tests. Give the client a fake `FullStackStudio\Content\Http\Transport` (`send(Request): Response`; `new Response($status, $headersLowercase, $jsonBody)`) and bind it:

```php
$this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_test', 'transport' => $fake]));
```

`ClientOptions` keys: `token`, `baseUrl`, `timeoutMs`, `clientIp`, `transport`, `cache`, `cacheTtl`. `WebhookVerifier::sign($secret, $rawBody)` computes a valid
`X-FSC-Signature` for webhook tests. The package's own test fake lives in the package repository (`tests/Support/FakeTransport.php`) and is not installed with it.

## Version and docs

This guide: version 0.8.0 of the package (see `CHANGELOG.md`). Full docs: `README.md` (Quick start, Modules, Tokens, Cache and webhooks, Laravel, Blade components,
Errors, Transports). API reference: `docs/api-v1.md` in the Content repository. `php artisan fsc:agents` (re)writes the pointer block in the site's `AGENTS.md`.
