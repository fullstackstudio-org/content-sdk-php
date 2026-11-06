# AGENTS.md: fullstackstudio/content (PHP SDK)

Guide for AI coding agents that build or change a Laravel site on **FullStack Hub** (the CMS at `https://content.fullstackstudio.nl`, API `/api/v1`;
older names: Fullstack Content, "Content"). Assume this file and the installed package are all you have: everything a site needs is in here, from an
empty Laravel app to a site that is ready to go live. Everything below matches the code of this version; if the code and this file disagree, the code
wins. Full API reference of the package: `README.md` next to this file.

Contents:

1. **Bouwvolgorde voor een nieuwe site**: the order in which to build a fresh site.
2. **Altijd verplicht**: items every site has, whatever modules are on (each with what, where, how to verify).
3. **Als de module X aanstaat**: what to add per Hub module.
4. **Controleer je site**: `php artisan fsc:doctor`, curl checks and the mistakes that already broke live sites.
5. Reference: install, environment, client basics and the detailed recipes per feature (from "What it is" on).

## Bouwvolgorde voor een nieuwe site

Build in this order; each step points at the item in "Altijd verplicht" (A1 to A16) or a module section. Do not skip an item because a module is
off today: every component and route below is safe with its module off (renders nothing, 404s or does nothing, without exceptions), so switching a
module on in the Hub later needs no code change.

1. A fresh Laravel 13 app (PHP >= 8.2; FullStack Studio images use 8.5), Blade + Vite. No database unless the site really needs one.
2. Install the package and run `php artisan fsc:agents` (A1). Set the environment (A2) in `.env` locally and in the runtime env of the host.
3. `bootstrap/app.php`: trust the proxy and prepend the redirect middleware (A4, A7). `AppServiceProvider::boot()`: force https in production (A4).
4. The layout `resources/views/layouts/app.blade.php` with the five components (A5) and the cookie banner partial (A10).
5. `routes/web.php` in this order: sitemap and robots (A6), your own routes, the legal pages (A11), the catch-all for Hub pages LAST (A6).
   `routes/api.php`: `/health` (A6) and `/consent` (A10).
6. `PageController` with the error handling of A6, `resources/views/page.blade.php` with `<x-content::page-blocks>`.
7. Error pages `errors/404` and `errors/503` (A12).
8. Webhook (A8) and forms (A9).
9. Privacy statement with the required sentences (A11) and a footer link to it plus "Cookie-instellingen".
10. Ask which modules are on (`php artisan fsc:doctor --online` lists them, or `$content->getProject()['modules']`) and build the section
    "Als de module X aanstaat" for each.
11. If the site has a Content-Security-Policy: merge the SDK sources (A13).
12. Run `php artisan fsc:doctor` until it shows no `FOUT` line, then the checks in "Controleer je site".

Every snippet you need is in this file. FullStack Studio staff also have the working reference `examples/laravel` in the (private) Hub repository
`fullstackstudio-org/fullstack-content`, branch `laravel`: it passes `fsc:doctor` and contains the code of A4 to A12.

## Altijd verplicht

Every site on this package has all of these. Format per item: **what**, then where (file and snippet), then how to verify.

### A1. Package and agent pointer

```json
// composer.json
"repositories": [{ "type": "vcs", "url": "https://github.com/fullstackstudio-org/content-sdk-php" }],
"require": { "fullstackstudio/content": "^0.22" }
```

```bash
composer update fullstackstudio/content
php artisan fsc:agents    # writes a pointer block into the site's AGENTS.md (and @AGENTS.md into an existing CLAUDE.md)
```

The repository is public (no token or SSH key). The service provider and the `Content` facade are auto-discovered. Publishing the config is **not**
needed: every switch has an env var. Publish `config/content.php` (`php artisan vendor:publish --tag=content-config`) only for settings without one
(`blocks`, `devmode.scripts`, `devmode.aliases`, `forms.labels`, middleware lists). Verify: `php artisan list fsc` shows `fsc:agents` and `fsc:doctor`.

### A2. Environment (runtime only, never in the repository)

| Variable | Value | Note |
|---|---|---|
| `FSC_SERVER_TOKEN` | `fsc_srv_...` | Required. Server token of the Hub project. |
| `FSC_API_URL` | `https://content.fullstackstudio.nl` | The default; keep it, also on the FSS cluster (media URLs are built from it). |
| `FSC_WEBHOOK_ENABLED` | `true` | Registers `POST /content/webhook` (A8). |
| `FSC_WEBHOOK_SECRET` | `fsc_whsec_...` | Shown once when the webhook is created in the Hub. |
| `FSC_FORMS_ENABLED` | `true` | Registers `POST /content/forms/{key}` (A9). |
| `APP_KEY` | `base64:...` | One per site: `php artisan key:generate --show`. |
| `APP_ENV` / `APP_DEBUG` / `APP_URL` | `production` / `false` / `https://<live host>` | `APP_URL` must be https (A4). |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `file` / `file` / `sync` | Never `SESSION_DRIVER=cookie` (A4). |
| `LOG_CHANNEL` | `stderr` | Container logs. |
| `FSC_IP_HEADER` | `cf-connecting-ip` | The default. Only a header your proxy overwrites; empty = Laravel's `$request->ip()`. |

Optional: `FSC_UPLOAD_MAX_MB` (forms with file fields, only read by the FSS Docker image), the `FSC_INTELLIGENCE_*` switches (module
`intelligence`), `FSC_CACHE_STORE=redis` with several replicas. The full list is under "Environment" below.

- Secrets (`FSC_SERVER_TOKEN`, `FSC_WEBHOOK_SECRET`, `APP_KEY`) live only in the runtime env: never in the repo, a committed `.env`, the Docker
  image, a build arg, a view, JavaScript or a response. `.env` is in `.gitignore`. At FullStack Studio they go into the secrets vault first.
- The FSS image runs `php artisan config:cache` when the container STARTS (the env only exists at runtime). So: call `env()` only inside `config/*.php`,
  read `config(...)` everywhere else, and put no closures in config files.
- Missing a scope? Change the rights of the existing token in the Hub (see "Environment"), do not mint a new one.

Verify: `php artisan fsc:doctor` (lines `FSC_SERVER_TOKEN`, `FSC_WEBHOOK_SECRET`, `APP_KEY`, `FSC_API_URL`); `git ls-files | grep -x .env` prints nothing.

### A3. Error handling for every Hub call

A 404 from the Hub is a 404 on the site; anything else is `report($e)` and a 503, never a stack trace and never a silently empty page. A server
token reads drafts, so public pages show `status: published` only.

```php
try {
    $page = $this->content->getPage($path);
} catch (ContentException $e) {
    if ($e->isNotFound()) { abort(404); }   // also 404 `module_disabled`
    report($e);
    abort(503);
}
```

Verify: with a wrong `FSC_API_URL` locally a Hub page answers 503 (not 500); an unknown path answers 404.

### A4. https, proxy and sessions

Behind Cloudflare and the ingress every request arrives as plain http. Without these three, `redirect('/bedankt')` became `http://...`, the CSP
`form-action 'self'` blocked it and forms hung on "versturen"; and a cookie session gave 502 "upstream sent too big header" on every form error.

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');
    $middleware->web(prepend: [\FullStackStudio\Content\Laravel\RedirectMiddleware::class]);   // A7
})
```

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\URL;

public function boot(): void
{
    if ($this->app->environment('production')) {
        URL::forceScheme('https');
    }
}
```

`.env`: `SESSION_DRIVER=file`, `APP_URL=https://...`. nginx in front of PHP-FPM: `fastcgi_buffer_size 32k; fastcgi_buffers 16 16k;` in the PHP
`location` and `large_client_header_buffers 4 16k;` in the `server` block (the `examples/laravel/docker/nginx.conf` has them).
Verify: `fsc:doctor` lines `SESSION_DRIVER`, `APP_URL`, `forceScheme`, `trustProxies`; `curl -sI -X POST https://<site>/content/forms/x` never shows
`Location: http://`.

### A5. Layout: five components

`resources/views/layouts/app.blade.php`; every public page extends it. Order matters: meta first in `<head>`, analytics last in `<body>`.

```blade
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @isset($page)
        <x-content::meta :page="$page" :path="$path ?? null" />
    @elseif (isset($article))
        <x-content::meta :page="$article" :path="'/blog/' . $article['slug']" type="article" />
    @else
        <x-content::meta :path="request()->getPathInfo()" />
    @endisset
    <x-content::head />      {{-- site standards: verification metas, favicon, theme colour, LocalBusiness JSON-LD, the owner's head code (consent-gated), dev panel --}}
    <x-content::errors />    {{-- browser errors to GlitchTip through /_fsc/e (module errors) --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    ...
    @include('partials.consent-banner')                          {{-- A10 --}}
    <x-content::body-end />                                      {{-- the owner's body code (consent-gated) and tel:/mailto:/WhatsApp click events --}}
    <x-content::analytics :status="$fscStatus ?? null" />        {{-- analytics (module analytics); load before any other tag script --}}
</body>
</html>
```

| Component | Renders | With its module off |
|---|---|---|
| `<x-content::meta>` | title (with the project template), description, canonical, robots (`noindex` on a test site), Open Graph, Twitter | the page's own fields |
| `<x-content::head />` | `@layer fsc-rich;`, verification, favicon, apple-touch-icon, theme-color, LocalBusiness JSON-LD, own head code, dev panel tags (only with the devmode cookie) | only the layer line |
| `<x-content::errors />` | config block + `/_fsc/e/reporter.js` | nothing |
| `<x-content::body-end />` | own body code, click events | nothing |
| `<x-content::analytics />` | Umami and/or the native tracker, attribution, conversion helper | nothing |

- The canonical comes from the `path` you pass, never from the request URL. Never use `request()->fullUrl()` or `url()->full()` for canonical,
  `og:url` or share links: the query can hold `?fscn=` (newsletter click token) and utm values.
- hreflang is **not** rendered by the SDK. A multilingual site adds `<link rel="alternate" hreflang="...">` itself (see "Meertalige site" under the
  module sections).
- The components put the CSP nonce (`Vite::cspNonce()`) on every `<script>`/`<style>` they print, or pass `nonce="..."`.
- All five survive a Hub outage: the last good answer or nothing, one warning in the log and a short pause (15 s) before the next try.

Verify: `fsc:doctor` line `Layout ... heeft meta, head, errors, body-end en analytics`; `curl -s https://<site>/ | grep -c 'rel="canonical"'` is 1.

### A6. Routes: sitemap, robots, health, catch-all

```php
// routes/web.php
use App\Http\Controllers\PageController;
use FullStackStudio\Content\Laravel\Robots;
use FullStackStudio\Content\Laravel\Sitemap;

Route::get('/sitemap.xml', fn () => Sitemap::response([
    'extra' => [['url' => url('/')], ['url' => url('/blog')], ['url' => url('/contact')]],   // your own routes; Hub pages and articles come by themselves
]));
Route::get('/robots.txt', fn () => Robots::response());   // `Disallow: /` while the project's robotsIndex is off (test site), else Allow + Sitemap

// ... your own routes, legal pages (A11) ...

Route::get('/{path}', [PageController::class, 'show'])->where('path', '.*');   // ALWAYS LAST: pages from the Hub
```

```php
// routes/api.php (the api group: no session, no CSRF, prefix /api)
Route::get('/health', fn () => response()->json(['ok' => true]));   // container HEALTHCHECK: no session, no cache, no Hub call
```

```php
// app/Http/Controllers/PageController.php
public function show(string $path): View
{
    $path = trim($path, '/');
    if ($path === '' || strlen($path) > 200) { abort(404); }
    try {
        $page = $this->content->getPage($path);          // by path; 404 when missing or unpublished
    } catch (ContentException $e) {
        if ($e->isNotFound()) { abort(404); }
        report($e);
        abort(503);
    }
    if (($page['status'] ?? 'published') !== 'published') { abort(404); }
    return view('page', ['page' => $page, 'path' => '/' . $path]);
}
```

- Delete `public/robots.txt` (and never add `public/sitemap.xml`): the web server serves a static file before Laravel sees the route.
- The home page: a Hub page with path `/` (`getPage('/')`), with your own fallback when it is missing (`examples/laravel` `PageController::home`).
- `Sitemap::response()` uses `canonicalOrigin` from the Hub's SEO settings (else the app origin); article paths follow the path template of the
  project's SEO settings, so your article route must match it (default `/blog/{slug}`).
- Keep every URL 1:1 with the Hub (and with the old site when rebuilding one, trailing slashes included); redirects for what disappears go in the
  Hub (A7), not in code.

Verify: `curl -s https://<site>/sitemap.xml | head -3` (XML), `curl -s https://<site>/robots.txt` (has `Sitemap:`), `curl -s https://<site>/api/health`
gives `{"ok":true}`, `curl -s -o /dev/null -w '%{http_code}' https://<site>/bestaat-niet` gives 404; `fsc:doctor` lines for each route.

### A7. Redirects from the Hub

`RedirectMiddleware` first in the web group (snippet in A4). It looks up GET/HEAD paths (skips `/api`, `/build`, `/storage` and files with an
extension), caches every answer (also "no redirect"), keeps the query string (utm, click ids) and pauses 15 s when the Hub is down.
Your own redirects (route redirects, `www` to apex) must keep the query string too (`$request->getQueryString()`; nginx `return 301
https://example.nl$request_uri;`). Verify: `fsc:doctor` line `RedirectMiddleware staat vooraan`; a redirect made in the Hub answers
`curl -sI https://<site>/oud?utm_source=x` with `Location: .../nieuw?utm_source=x`.

### A8. Webhook (cache invalidation)

Reads are cached under tags (default TTL 3600 s). The webhook clears them at once. `FSC_WEBHOOK_ENABLED=true` + `FSC_WEBHOOK_SECRET` registers
`POST /content/webhook` (api group, no CSRF); in the Hub create a webhook to `https://<site>/content/webhook` with `events: ["*"]` (or at least
include `site.updated`). Do not build your own cache layer or webhook handler; listen to `FullStackStudio\Content\Laravel\Events\ContentWebhookReceived`
for extra work. With several replicas use a shared cache store (`FSC_CACHE_STORE=redis`).
Verify: `fsc:doctor` lines `Webhookroute` and `FSC_WEBHOOK_SECRET`; the signature test under "Controleer je site" answers 200; an unsigned POST 401.

### A9. Forms through the Hub

`FSC_FORMS_ENABLED=true`, then `<x-content::form form-key="contact" />` where the form goes. The package route validates, submits server-side with
the visitor IP, keeps the honeypot and Turnstile, sends the attribution (utm, click ids) and the consented browser id along, and redirects back with
errors or the success message. **The site sends no mail and has no SMTP**: the Hub mails the owner and confirms to the visitor. Custom form UI:
"Forms" in the recipes below.

- 🚨 Never send a test submission that reaches the customer. Point the form's notify recipients in the Hub to an address of your own (FullStack
  Studio: `info@fullstackstudio.nl`) and check that before a single test; a confirmation to the visitor goes to the address you type.
- File fields need nothing extra (the component makes the form multipart); raise the upload limit of the image (`FSC_UPLOAD_MAX_MB`) when needed.

Verify: `fsc:doctor` line `Formulierroute`; the form page shows the fields; submitting it empty shows Dutch field errors and no 502.

### A10. Cookie banner (consent contract)

There is no banner component in the package (it needs page JavaScript). Every site has one anyway: without a stored choice nothing that needs
consent runs (the owner's own `analytics`/`marketing`/`preferences` code, heatmaps, session replay, the consented browser id), so a site without a
banner silently loses all of that. The contract:

```js
const choice = { choice: 'custom', categories: ['analytics'], version: '1' };   // or { choice: 'all' } / { choice: 'none' }
localStorage.setItem('fsc-consent', JSON.stringify(choice));                    // later pages read this
window.dispatchEvent(new CustomEvent('fsc:consent', { detail: choice }));        // this page activates at once
```

Texts, labels, `policyUrl` and the offered categories come from the Hub (`$content->getConsentConfig()`, module `privacy`); record each choice as proof
through your own route (`$content->recordConsent($choice, ['clientIp' => ..., 'userAgent' => ...])`). A working pair to copy (style the classes
yourself):

`resources/views/partials/consent-banner.blade.php`: renders nothing when the config is unavailable; shows the banner when there is no stored choice
or the stored `version` differs from the Hub's; all / none / custom; writes `fsc-consent`, dispatches `fsc:consent`, posts to `/api/consent`; any link
with `data-fsc-consent-open` (footer "Cookie-instellingen") reopens it.

```blade
{{--
    Cookie banner on the consent contract of the package (AGENTS.md, "Cookiebanner"): texts and categories come from the Hub (module `privacy`),
    the choice goes to localStorage `fsc-consent` and the window event `fsc:consent` (consent-gated own code, heatmaps and replay start at once),
    and is recorded as proof through this site's POST /api/consent. Without the module (or with the Hub down) nothing renders and nothing that
    needs consent ever runs. A link with `data-fsc-consent-open` (footer "Cookie-instellingen") opens the banner again.
--}}
@php
    try {
        $consent = app(\FullStackStudio\Content\ContentClient::class)->getConsentConfig();
    } catch (\Throwable) {
        $consent = [];
    }
    $categoryLabels = ['analytics' => 'Statistieken', 'marketing' => 'Marketing', 'preferences' => 'Voorkeuren'];
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp
@if (($consent['bannerText'] ?? '') !== '')
    <div id="fsc-consent" class="consent" role="dialog" aria-labelledby="fsc-consent-title" data-version="{{ $consent['version'] ?? '1' }}" hidden>
        <p id="fsc-consent-title" class="consent__title">{{ $consent['bannerTitle'] ?? 'Cookies' }}</p>
        <p>
            {{ $consent['bannerText'] }}
            @if (! empty($consent['policyUrl']))
                <a href="{{ $consent['policyUrl'] }}">{{ $consent['policyLabel'] ?? 'Privacyverklaring' }}</a>
            @endif
        </p>
        <fieldset class="consent__choices" data-fsc-choices hidden>
            <label><input type="checkbox" checked disabled> Noodzakelijk</label>
            @foreach ($consent['categories'] ?? [] as $category)
                <label><input type="checkbox" value="{{ $category }}" data-fsc-category> {{ $categoryLabels[$category] ?? ucfirst($category) }}</label>
            @endforeach
        </fieldset>
        <div class="consent__buttons">
            <button type="button" data-fsc-choice="none">{{ $consent['rejectAllLabel'] ?? 'Alleen noodzakelijk' }}</button>
            <button type="button" data-fsc-customize>{{ $consent['customizeLabel'] ?? 'Instellen' }}</button>
            <button type="button" data-fsc-choice="custom" hidden>{{ $consent['saveLabel'] ?? 'Opslaan' }}</button>
            <button type="button" data-fsc-choice="all">{{ $consent['acceptAllLabel'] ?? 'Alles accepteren' }}</button>
        </div>
    </div>
    <script @if ($nonce) nonce="{{ $nonce }}" @endif>
        (() => {
            const box = document.getElementById('fsc-consent');
            const version = box.dataset.version;
            let stored = null;
            try { stored = JSON.parse(localStorage.getItem('fsc-consent') || 'null'); } catch (e) { stored = null; }
            if (!stored || stored.version !== version) box.hidden = false;

            const save = (choice) => {
                const categories = choice === 'custom'
                    ? [...box.querySelectorAll('[data-fsc-category]:checked')].map((input) => input.value)
                    : [];
                const detail = choice === 'custom' ? { choice, categories, version } : { choice, version };
                localStorage.setItem('fsc-consent', JSON.stringify(detail));
                window.dispatchEvent(new CustomEvent('fsc:consent', { detail }));
                box.hidden = true;
                fetch('/api/consent', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify(detail),
                    keepalive: true,
                }).catch(() => {});
            };

            box.querySelectorAll('[data-fsc-choice]').forEach((button) => button.addEventListener('click', () => save(button.dataset.fscChoice)));
            box.querySelector('[data-fsc-customize]').addEventListener('click', (event) => {
                box.querySelector('[data-fsc-choices]').hidden = false;
                box.querySelector('[data-fsc-choice="custom"]').hidden = false;
                event.currentTarget.hidden = true;
            });
            document.querySelectorAll('[data-fsc-consent-open]').forEach((link) => link.addEventListener('click', (event) => {
                event.preventDefault();
                box.hidden = false;
            }));
        })();
    </script>
@endif
```

`app/Http/Controllers/ConsentController.php`, routed in `routes/api.php` as `Route::post('/consent', ConsentController::class)->middleware('throttle:20,1');`:

```php
<?php

namespace App\Http\Controllers;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `POST /api/consent`: records the visitor's cookie choice in the Hub as proof (no IP is stored there; it only counts for the rate limit). */
class ConsentController extends Controller
{
    public function __construct(private readonly ContentClient $content)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'choice' => ['required', 'in:all,none,custom'],
            'categories' => ['array', 'max:10'],
            'categories.*' => ['string', 'max:40'],
            'version' => ['nullable', 'string', 'max:40'],
        ]);

        $body = ['choice' => $input['choice']];
        if ($input['choice'] === 'custom') {
            $body['categories'] = array_values($input['categories'] ?? []);
        }
        if (! empty($input['version'])) {
            $body['version'] = $input['version'];
        }

        try {
            $this->content->recordConsent($body, [
                'clientIp' => $request->header('cf-connecting-ip') ?: $request->ip(),
                'userAgent' => (string) $request->userAgent(),
            ]);
        } catch (ContentException $e) {
            // The choice already works in the browser; a failed proof is not the visitor's problem.
            if (! $e->isRateLimited() && $e->status() !== 400 && $e->status() !== 404) {
                report($e);
            }
        }

        return response()->json(['ok' => true], 202);
    }
}
```

Rules: no cookie for the choice (localStorage only, the server always renders the same HTML); do not name heatmaps or replay in the banner (the
privacy statement does); bump `version` in the Hub when texts or categories change. More detail: "Cookiebanner: the consent contract" below.
Verify: `fsc:doctor` line `Cookiebanner gevonden`; in a private window the banner shows; after "Alleen noodzakelijk" no `recorder.js` request and
no consent-gated `<script>` runs; after "Alles accepteren" they do; `localStorage['fsc-consent']` holds the choice.

### A11. Legal pages and the privacy statement

Every site has a privacy statement (and, when it sells or books, terms) linked from the footer and set as `policyUrl` of the banner. The text lives
in the Hub, not in the repo: module `legal` (`$content->legal->get('privacy_statement')`, kinds `terms`, `privacy_statement`, `cookie_statement`, ...;
render `bodyHtml` with `<x-content::article-body>`) or an ordinary Hub page. Route it before the catch-all,
`Route::get('/privacyverklaring', [LegalController::class, 'privacy'])->name('privacy');`, with a controller that tries the legal document first and
falls back to a Hub page with the same path:

```php
<?php

namespace App\Http\Controllers;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use Illuminate\Contracts\View\View;

/**
 * Legal texts from the Hub (module `legal`): the current version of a document kind. Without the module (or before a version is in force)
 * the same path falls back to an ordinary page from the Hub, so the owner can also keep the text as a page.
 */
class LegalController extends Controller
{
    public function __construct(private readonly ContentClient $content)
    {
    }

    public function privacy(): View
    {
        return $this->show('privacy_statement', 'privacyverklaring');
    }

    private function show(string $kind, string $path): View
    {
        try {
            $document = $this->content->legal->get($kind);

            return view('legal', ['document' => $document, 'path' => '/' . $path]);
        } catch (ContentException $e) {
            if (! $e->isNotFound()) {
                report($e);
                abort(503);
            }
        }

        try {
            $page = $this->content->getPage($path);
        } catch (ContentException $e) {
            if ($e->isNotFound()) {
                abort(404);
            }
            report($e);
            abort(503);
        }

        return view('page', ['page' => $page, 'path' => '/' . $path]);
    }
}
```

`resources/views/legal.blade.php` extends the layout and prints `<h1>{{ $document['title'] }}</h1>` and
`<x-content::article-body :html="$document['bodyHtml'] ?? null" />`; give the layout's meta a title for it
(`@elseif (isset($document)) <x-content::meta :page="['title' => $document['title'] ?? null]" :path="$path ?? null" />`).

The statement must name what this package does on the site. Give the owner these sentences (Dutch sites; translate for others) for every
feature that is on:

| When | Sentence |
|---|---|
| Always (forms) | "Wat je via een formulier stuurt, slaan we op in ons websitebeheer (FullStack Hub) en sturen we per e-mail naar ons door. We bewaren daarbij ook via welke campagne of link je op de site kwam, zonder cookies." |
| Module `analytics`, engine Umami | "We meten bezoeken met Umami, een privacyvriendelijke statistiekdienst zonder cookies; IP-adressen worden niet opgeslagen. Alleen als je cookies accepteert, analyseren we ook anoniem klikken en navigatie (heatmaps en sessie-opnames, waarbij alles wat je typt verborgen blijft)." |
| Module `analytics`, engine native | "We meten bezoeken met onze eigen statistieken, zonder cookies; IP-adressen worden niet opgeslagen. Alleen als je cookies accepteert, onthouden we je browser met een willekeurig nummer, zodat we terugkerende bezoeken zien, en analyseren we klikken (heatmaps). We maken geen opnames." (engine `both`: both sentences) |
| Module `analytics`, engine native, with forms | "Bij formulieren meten we of je het formulier start en verstuurt en hoe lang dat duurt, zonder wat je invult. Heb je toestemming gegeven voor statistieken, dan zien we ook bij welk veld je afhaakt (alleen de naam van het veld, nooit wat je typt)." |
| Module `errors` | "Technische fouten op deze site melden we aan onze foutmonitoring, zonder persoonsgegevens." |
| Intelligence recognition (members or chat) | "Log je in, dan herkennen we je bij latere bezoeken (cookie fsi_rec, 6 maanden) om relevante inhoud te tonen. Dat doen we alleen als je toestemming hebt gegeven voor statistieken." |
| Personalisation or recommendations | "Heb je toestemming gegeven voor statistieken, dan kunnen de inhoud en producten die je ziet afhangen van de pagina's die je eerder bekeek." |
| Newsletter click links | "Klik je op een link in onze nieuwsbrief, dan registreren we welke link in welke verzending is aangeklikt, om te zien welke onderwerpen aanslaan. Heb je op deze site toestemming gegeven voor statistieken, dan koppelen we je bezoek ook aan je inschrijving voor de nieuwsbrief, zodat we je relevantere inhoud kunnen tonen." |
| Account data via the chat (own portal) | "Ben je ingelogd, dan kan de chat op jouw vraag gegevens uit je eigen account opzoeken (zoals facturen of tickets) om je vraag te beantwoorden. De chat bewaart die gegevens niet." |
| `FSC_PID_COOKIE_DOMAIN` set | name the shared cookie `fsa_pid` and the sibling host that reads it |

Verify: `curl -s https://<site>/privacyverklaring` answers 200 with the sentences above; the footer and the banner link to it.

### A12. Error pages

- `resources/views/errors/404.blade.php` in the site's style: `@extends('layouts.app', ['fscStatus' => 404])` (the layout passes it to
  `<x-content::analytics :status>`, so the native tracker counts a 404).
- `resources/views/errors/503.blade.php` **standalone**: plain HTML with `<meta name="robots" content="noindex">`, no layout and no `content::`
  components. A 503 usually means the Hub is unreachable; a layout would call it again and wait for its timeouts.
- `APP_DEBUG=false` in production: no stack traces.

Verify: `fsc:doctor` lines `errors/404` and `errors/503`; `curl -s -o /dev/null -w '%{http_code}' https://<site>/bestaat-niet` is 404 and shows
your page.

### A13. Content-Security-Policy (only when the site sends one)

A site without a CSP needs nothing here. With one, use a nonce-based `script-src`/`style-src` and merge the SDK sources:

```php
// app/Http/Middleware/ContentSecurityPolicy.php, appended to the web group
Vite::useCspNonce();                                      // before rendering: the components take this nonce
$response = $next($request);
$nonce = Vite::cspNonce();
$csp = "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}'; "
    . "img-src 'self' data: https://content.fullstackstudio.nl; connect-src 'self'; worker-src 'self' blob: data:; "
    . "form-action 'self'; frame-ancestors 'self'";
$response->headers->set('Content-Security-Policy', Content::analyticsCspMerge(Content::siteCspMerge($csp)));
```

- `form-action` also applies to the redirect after a POST: `'self'` only works with A4 (https). A payment form redirects to the checkout of
  the payment provider: add that host (look at one `payment.checkoutUrl`).
- The chat widget (module `chatbot`) needs `https://content.fullstackstudio.nl` in `script-src` and `connect-src`.
- First-party analytics, error reporting, the address field and the dev panel need nothing beyond `'self'`.

`siteCspMerge()` takes an array (returns an array) or a CSP string (returns a string). A missing directive starts from `default-src`; without
`default-src` it stays out (adding it would forbid the rest). An existing `script-src-elem`/`style-src-elem` gets the sources too. Hosts that a script
loads later by itself are not in the snippet: add those by hand. For Google Analytics 4 / Google Tag Manager that is `connect-src` (and `img-src`):
`https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com`.

Verify: the browser console shows no CSP errors on a page, after accepting cookies and after submitting a form.

### A14. Built in: nothing to do, do not break it

The provider registers these itself; do not copy, wrap or block them:

| What | Where | Note |
|---|---|---|
| Analytics proxy and native collector | `/_fsc/a/*` | no session, no CSRF; do not put auth or a CDN cache on `/_fsc/a/api/*` and `/_fsc/a/e` |
| Error reporter and tunnel | `/_fsc/e/reporter.js`, `POST /_fsc/e/envelope` | also reports backend exceptions through Laravel's handler; do not add `sentry/sentry-laravel` |
| Address autocomplete | `/_fsc/address/*` | for `<x-content::address-field>` |
| Dev panel | `?fsc_devmode=1`, `/_fsc/d/*` | off for everybody; a CDN that caches HTML must bypass it for the cookie `fsc_devmode=1` |
| Newsletter click links | global middleware `NewsletterClickLink` | never read, strip or store `?fscn=` yourself |
| Cookie encryption exceptions | `fsi_rec`, `fsi_sess` | the provider calls `EncryptCookies::except()`; a cookie of your own that JavaScript writes and PHP reads needs `$middleware->encryptCookies(except: [...])` too |
| Intelligence routes | `/_fsc/i/*` | only while `FSC_INTELLIGENCE_ENABLED=true` |

### A15. Staff and office traffic

Office IPs and staff browsers must not build Intelligence profiles. With module `intelligence` on: `FSC_INTELLIGENCE_EXCLUDE_IPS=<IPs/CIDRs, comma-separated>`;
the staff marker cookie `fsi_staff` (set by the FSS admin at staff login on `.fullstackstudio.nl`) is honoured automatically. Their visits still count in
analytics, marked `fsi_ex`. Nothing to do while Intelligence is off.

### A16. Hosting checklist (FSS platform)

One container with nginx + PHP-FPM on port 3000, `HEALTHCHECK` on `/api/health`, `config:cache route:cache view:cache` at start, no secrets or `.env`
in the image: copy `examples/laravel/Dockerfile` and `docker/`. Details: "Deploying on the FSS platform" in `README.md`.

## Als de module X aanstaat

Which modules are on: `php artisan fsc:doctor --online` (line `Modules aan:`) or `$content->getProject()['modules']`. A module that is off answers
404 `module_disabled` (`isNotFound()` is true). Each section: what to build, where, how to verify. Modules without a section (`email`, `webhooks`,
`customers`, `invoicing` for staff use) need nothing on a public site.

### `site` (site standards) and `seo`

Nothing beyond A5/A6: `<x-content::head />` and `<x-content::meta>` read them. Make sure `canonicalOrigin` in the SEO settings is the live https origin
and the article path template matches your article route. Verify: `fsc:doctor --online` line `canonicalOrigin`; `curl -s https://<site>/ | grep -E
'og:title|rel="canonical"|application/ld\+json'`.

### `pages`

The catch-all (A6) and `resources/views/page.blade.php`:

```blade
@extends('layouts.app')
@section('content')
    <x-content::page-blocks :blocks="$page['blocks'] ?? []" :media="$page['media'] ?? null" />
@endsection
```

Two block types need your own view (set `config('content.blocks')` after publishing the config, or `:views="[...]"`): `articleList` (fetch the
articles in your view) and `legalDocument` (`$content->legal->get($block['kind'])`). Verify: a published Hub page opens at its path; a draft is 404.

### `articles`

`/blog` (list) and `/blog/{slug}` (detail), matching the SEO path template; add `url('/blog')` to the sitemap `extra`. Recipe: "Articles and categories"
below. Verify: the article URL in `/sitemap.xml` opens; `<link rel="canonical">` on it is that URL.

### `media`

`<x-content::image :media="$media" sizes="..." />` for every Hub image (responsive variants, never upscaled); `:eager="true"` only for the LCP image.
Recipe: "Media and images". Verify: `<img srcset=` in the HTML.

### `forms`

A9. Use the form keys from the Hub (`<x-content::form form-key="...">`). Verify as in A9.

### `redirects`

A7. Nothing else.

### `analytics`

A5 (`<x-content::analytics />`), A10 (banner), A11 (privacy sentence). Webshop and appointments also fire conversion events (sections below).
Full checklist: "Analytics" in the recipes. Verify: `curl -s https://<site>/ | grep -E '_fsc/a/(fsc|fsa|script)\.js'` and in the browser
network tab `POST /_fsc/a/api/send` (Umami) or `POST /_fsc/a/e` (native) answers 200/202 on a page view.

### `errors`

A5 (`<x-content::errors />`); the server token needs scope `errors:read`; the image passes the Git SHA as `FSS_RELEASE`. Verify: `curl -s
https://<site>/_fsc/e/reporter.js | head -c 80` returns JavaScript. Recipe: "Error monitoring".

### `privacy`

Supplies the banner texts and categories (A10) and stores the proof (`GET`/`POST /api/v1/consent`, scope `privacy:consent`). Set `policyUrl` in the
Hub to the privacy statement path. While the module is off the banner partial renders nothing and nothing consent-gated ever runs (privacy-safe, but
heatmaps, replay and the owner's marketing code stay off): ask for it to be switched on for every site with such code. Verify: the banner shows the
Hub's texts.

### `legal`

A11 routes for each document kind in force (`$content->legal->list()` lists them). At checkout or registration record acceptance with
`$content->legal->accept([...])`. Verify: each legal URL answers 200 and shows the current version.

### `search`

A search page (for example `GET /zoeken?q=`) that calls `$content->search(['q' => $q], ['clientIp' => $ip])` and links each hit to its URL. Mark it
`noindex`. Verify: a known word returns hits.

### `faq`

`$content->faq->list()` (categories and items) on a FAQ page or in a section. Verify: the FAQ texts of the Hub appear.

### `newsletter`

A subscribe form posting to your own route that calls `$content->newsletter->subscribe(['email' => ..., ...], ['clientIp' => $ip])` (double opt-in:
the Hub mails the confirmation; it answers the same for a known address, so show one neutral success message). Optional own pages for
`confirmUrl`/`unsubscribeUrl` (then `confirm($token)` / `unsubscribe($token)`, both via POST); without them the Hub's hosted pages are used.
Click links in campaign mails need no code (A14) but do need the privacy sentence (A11). Verify: subscribing shows the message and the Hub lists a
pending subscriber.

### `members`

Login, logout, register, password reset and an account page (README "Members"; `examples/laravel` `MemberController`). Keep the member session token in
an httpOnly, Secure, SameSite=Lax cookie, never in a URL or the page. Recognition (`fsi_rec`) is automatic when Intelligence recognition is on
(section "Member recognition"); add its privacy sentence. Verify: login sets your session cookie (HttpOnly), logout removes it, a wrong password shows an error, no 502.

### Webshop (`products`, `orders`, `payments`, `discounts`, `shipping`, `returns`, `reviews`, `downloads`)

Product list and detail, cart, checkout and a thank-you page (README "Webshop": `cart->applyCode`, `orders->place` with `expectedTotalCents`,
`payments->startForOrder`, redirect to `payment.checkoutUrl`; keep `accessToken` out of URLs). With `analytics` on, place the conversion markers:
`<x-content::begin-checkout>` on the checkout page and `<x-content::purchase :order="$order" />` on the thank-you page ("Conversion events").
Optional "Misschien ook interessant": `$content->products->recommendedProducts($pid, 4)` ("Recommended products"). Verify: a test order in a test
project reaches the payment provider's test checkout; the thank-you page fires `purchase` once.

### `appointments`

A booking page (`appointments->services`, `availability`, `book`) and a confirmation; with `analytics` on `<x-content::appointment-booked
:service :reference page="/afspraak-maken" />` on the confirmation. Verify: free times load; a booking in a test project shows the confirmation.

### `events`

Event list and detail with registration (`$content->events`). Verify: a published event shows; registration in a test project succeeds.

### `tickets`

A support form or a member area that creates tickets (`$content->tickets->create(..., ['files' => [...], 'idempotencyKey' => ..., 'clientIp' => ...])`).
Verify: a test ticket appears in the Hub (test project only).

### `chatbot`

The widget on every page where the bot should be, with a publishable token (`fsc_pub_...`, scope `chat:converse`, the site's origin in its
`allowedOrigins`). A publishable token may be in the page; read it via `config()` (for example `config('services.fsc.publishable_token')` filled
from `FSC_PUBLISHABLE_TOKEN` in `config/services.php`), never `env()` in a view.

```blade
<script src="https://content.fullstackstudio.nl/widget/chat.v1.js" defer></script>
<fsc-chat token="{{ config('services.fsc.publishable_token') }}" lang="nl" @if (config('content.intelligence.enabled')) open-url="/_fsc/i/chat" @endif></fsc-chat>
```

Set `siteOrigins` in the bot settings to the site's origin (else links in answers stay text). Logged-in members: `window.FscChat?.init({ memberSession })`
from a server route of yours. Verify: the bubble opens and answers; with Intelligence on the network tab shows `POST /_fsc/i/chat`.

### `intelligence` (paid add-on, off by default)

Only when FullStack Studio switched it on for the project. `.env`: `FSC_INTELLIGENCE_ENABLED=true`, `FSC_INTELLIGENCE_EXCLUDE_IPS` (A15); for slots
also `FSC_INTELLIGENCE_PERSONALIZATION=true`. Then, as the project needs: personalisation slots (`<x-content::slot>`), chat `open-url` (above),
member recognition (automatic), newsletter click links (automatic), recommended products, and `content.devmode.portal_url` for the dev panel. Add the
privacy sentences (A11). Recipes: "FullStack Intelligence" and its subsections. Verify: `POST /_fsc/i/me` answers JSON; with consent the
`fsa_pid` cookie exists; a page with a slot sends `Cache-Control: private, no-cache` when a variant was decided.

### Account data via the chat (only for a site with its own customer portal)

Only when the site runs its own customer login with invoices, tickets or services. Build the signed provider route of "Accountgegevens via de chat"
below and the session handle cookie `fsi_sess`; add the privacy sentence. A brochure site skips this.

### Meertalige site (project `locales` has more than one language)

Route prefix per language (for example `/en/...`), pass `['locale' => ...]` to reads, `localePrefix` in `Sitemap::response()`. hreflang yourself
in the layout from the page's `translations` (`[{ id, locale, slug, status }]`) or `$content->getTranslations($page['translationGroupId'])`:

```blade
@foreach (($page['translations'] ?? []) as $t)
    @if (($t['status'] ?? null) === 'published')
        <link rel="alternate" hreflang="{{ $t['locale'] }}" href="{{ url(($t['locale'] === 'nl' ? '' : '/' . $t['locale']) . '/' . ltrim($t['slug'], '/')) }}">
    @endif
@endforeach
```

Verify: each language version links to the others and to itself.

## Controleer je site

### `php artisan fsc:doctor`

Checks the "Altijd verplicht" items it can see from the app and prints one Dutch line per check (`OK`, `LET OP`, `FOUT`, `INFO`), exit code 1 on any
`FOUT`. Read-only; the only request it makes is `GET /api/health` through the app's own kernel.

| Option | Use |
|---|---|
| (none) | full check, needs the real env (`FSC_SERVER_TOKEN`, `FSC_WEBHOOK_SECRET`, `APP_KEY`) |
| `--skip-env` | skip the secret checks (CI, a checkout without `.env`) |
| `--online` | also call the Hub once: token valid, project name, `Modules aan: ...`, `canonicalOrigin` |
| `--strict` | exit 1 on `LET OP` too |

It checks: env keys and token type, `FSC_API_URL` https, `SESSION_DRIVER` not cookie, `APP_URL` https and `URL::forceScheme('https')` (FOUT in
production), routes `/sitemap.xml`, `/robots.txt`, `/api/health` (200 `{"ok":true}`), the webhook and form routes, the catch-all, `RedirectMiddleware`
first in the web group, `trustProxies`, every layout with `</head>` (outside `errors/` and mail views) has the five components in the right place, a
cookie banner (`fsc:consent`), `errors/404` and `errors/503`, no `fullUrl()` in views, no `public/robots.txt`/`public/sitemap.xml`, `.env` in
`.gitignore`, no `fsc_srv_`/`fsc_whsec_` in `resources/`, `public/` or the views, and that `config:cache` will work.

### Checks against the running site

Run these against the test address (`*.fullstackonline.nl`) and again on the live domain. `S=https://<site>`.

```bash
curl -s $S/ | grep -E 'rel="canonical"|<title>|og:title|_fsc/a/|_fsc/e/reporter'      # meta, analytics, errors
curl -s $S/ | grep -c 'fsc_srv_\|fsc_whsec_'                                          # 0: no secrets in HTML
curl -s $S/sitemap.xml | head -5                                                      # XML with live https URLs
curl -s $S/robots.txt                                                                 # Allow + Sitemap (Disallow: / on a test site)
curl -s $S/api/health                                                                 # {"ok":true}
curl -s -o /dev/null -w '%{http_code}\n' $S/bestaat-niet                              # 404
curl -sI "$S/?utm_source=test" | grep -i '^location' || true                           # no redirect, or one that keeps ?utm_source
curl -sI http://<host>/ | grep -i location                                            # http goes to https (Cloudflare)
curl -s "$S/?fscn=x" | grep -c 'fscn'                                                 # 0: the token never reaches canonical/og:url
```

Webhook signature (locally against `php artisan serve`, or on the site):

```bash
BODY='{"id":"evt_check","type":"site.updated","createdAt":"2026-01-01T00:00:00Z","projectId":"check","data":{}}'
SIG=$(php -r 'require "vendor/autoload.php"; echo FullStackStudio\Content\Webhooks\WebhookVerifier::sign(getenv("FSC_WEBHOOK_SECRET"), $argv[1]);' "$BODY")
curl -s -o /dev/null -w '%{http_code}\n' -X POST $S/content/webhook -H 'Content-Type: application/json' -H "X-FSC-Signature: $SIG" -d "$BODY"   # 200 {"received":true,...,"revalidated":["content:site"]}
curl -s -o /dev/null -w '%{http_code}\n' -X POST $S/content/webhook -H 'Content-Type: application/json' -d "$BODY"                             # 401
```

Consent, in a private browser window: the banner shows; "Alleen noodzakelijk" stores `{"choice":"none",...}` in `localStorage['fsc-consent']` and no
`recorder.js`/`fsa_pid` appears; "Alles accepteren" starts them without a reload; the footer link reopens the banner. Forms: submit empty (Dutch
errors, no 502), then once to a Hub form whose recipients you checked (A9).

### Common mistakes (each broke a live site)

| Mistake | Symptom | Fix |
|---|---|---|
| `SESSION_DRIVER=cookie` | 502 "upstream sent too big header" on a form error | `file` (A4) |
| No `URL::forceScheme('https')` behind the proxy | form hangs on "versturen"; `Location: http://` | A4 |
| `public/robots.txt` left in place | robots.txt ignores the Hub (a test site gets indexed) | delete it (A6) |
| Catch-all not last | own routes 404 or show a Hub page | move it to the end (A6) |
| `request()->fullUrl()` in canonical/og:url | `?fscn=` and utm values in canonical and shares | build from the path (A5) |
| Layout without `<x-content::head />` | no favicon, verification or owner code; dev panel missing | A5 |
| No cookie banner | heatmaps, replay and owner's marketing code never run | A10 |
| Secrets in `.env` committed or baked into the image | leaked token | runtime env only, rotate in the Hub (A2) |
| `env()` outside config files | empty values after `config:cache` | `config()` (A2) |
| 503 page extends the layout | 503 takes the Hub's timeouts again | standalone page (A12) |
| SMTP or mail code in the site | double mails, spam, leaked credentials | the Hub mails (A9) |
| Own redirect drops the query string | campaigns lost | keep `$request->getQueryString()` (A7) |
| Test submission to the customer's address | the customer gets test mail | recipients to your own address first (A9) |
| `Http::fake()` in tests | real network calls | fake `Transport` ("Testing") |

## Cookiebanner: the consent contract

The owner's own code with consent `necessary` always runs. Code for `analytics`, `marketing` or `preferences` sits in the page as an inert
`<template data-fsc-code data-consent="...">` and runs only after consent for that category; heatmaps and session replay follow the same contract.
The site's banner does two things on every choice:

```js
const choice = { choice: 'custom', categories: ['analytics'] };          // or { choice: 'all' } / { choice: 'none' }
localStorage.setItem('fsc-consent', JSON.stringify(choice));             // later pages read this
window.dispatchEvent(new CustomEvent('fsc:consent', { detail: choice })); // this page activates at once
```

- Key and event: `fsc-consent` and `fsc:consent` (change with `FSC_CONSENT_KEY` / `FSC_CONSENT_EVENT`; applies to site code and analytics).
- Understood values: `{categories: [...]}`, `{choice: 'all'|'none'|'custom', categories}` (extra keys such as `version` are ignored),
  `{analytics: true, marketing: false, preferences: true}`, `'accepted'`/`'all'`/`true` (everything), `'declined'`/`'necessary'`/`'none'`/`false`
  (nothing), also as JSON text. Unknown categories do not count.
- No cookie: the choice lives only in `localStorage` (the server always renders the same HTML; the browser decides). No choice = only `necessary`.
- Revoking works from the next page: code that already ran cannot be unloaded. Reload the page after revoking if needed.
- Record the choice as proof: `recordConsent(['choice' => ..., 'categories' => [...]], ['clientIp' => ..., 'userAgent' => ...])` from your own route
  (`POST /api/v1/consent`). The categories the banner must offer come from `getConsentConfig()['categories']`.
- Own code with `document.write` does not work after consent (the page has loaded by then); use the vendor's async snippet.
- Scripts run in source order: an external script without `async` is awaited (loaded or failed, at most 15 s) before the next one goes in, also
  across fragments. An inline `Lib.init()` after `<script src=".../lib.js">` therefore just works.

## What it is

`fullstackstudio/content` is the PHP client for **FullStack Hub**, a headless CMS (`https://content.fullstackstudio.nl`, API `/api/v1`).
The site renders; Content stores pages (blocks), articles, categories, forms and their submissions, media, SEO settings and redirects.
It also has webshop, members, appointments, tickets, invoices, newsletter, chat, FAQ, events and legal modules (see the end).

- PHP >= 8.2 (`ext-curl`, `ext-json`). Laravel 11, 12 or 13 for the integration (service provider, facade, Blade components, routes); the client itself works in any PHP.
- Responses are plain associative arrays (JSON as the API sends it, single objects unwrapped: `$article['title']`, not `->title`).
- Every failure is a `FullStackStudio\Content\ContentException`. The token never appears in a message.

## Install

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/fullstackstudio-org/content-sdk-php" }],
    "require": { "fullstackstudio/content": "^0.22" }
}
```

The repository is public (no token or SSH key needed). Once the package is on Packagist, plain `composer require fullstackstudio/content` is
enough and the `repositories` entry can go. The service provider and the `Content` facade are auto-discovered.

```bash
php artisan vendor:publish --tag=content-config   # optional: config/content.php
php artisan vendor:publish --tag=content-assets   # optional: public/vendor/content/content.css (the fsc-* classes)
php artisan vendor:publish --tag=content-views    # optional: copy the Blade views to resources/views/vendor/content
php artisan fsc:agents                            # writes the short pointer block to AGENTS.md (and @AGENTS.md to an existing CLAUDE.md)
php artisan fsc:doctor                            # checks the "Altijd verplicht" items (see "Controleer je site")
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

Route, controller and error handling: A6 and A3 above. In the views:

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
Article paths come from the path template in the project's SEO settings (it must match your real URLs). robots.txt: always `Robots::response()` (A6), never a
static `public/robots.txt`; `$content->getSeo()['robotsIndex']` tells whether the project allows indexing. With the SEO module off the sitemap has only your
`extra` lines; during a Hub outage it answers 503 with `Retry-After`. `Robots::response()` allows everything with the module off and also when the Hub is
unreachable (200, `no-store`): a short outage must not stop crawling, and a test site stays shielded by the `noindex` of `<x-content::meta>`.

### Redirects

Wiring and query-string rules: A7. Answers are cached under the `content:redirects` tag; a lookup times out after `content.redirects.timeout_ms` (1500)
and pauses `failure_cooldown_seconds` (15) after a failure, 60 s while the module is off. Redirects are managed in the Hub, never in the repo.

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

`meta`, `head` and `body-end` (see A5), `analytics` (see Analytics), `errors` (see Error monitoring), `article-body` (prints `bodyHtml` unescaped: safe only for HTML from Content, which sanitizes on save; never pass other HTML), `page-blocks`,
`image`, `form`, `address-field` (see Address autocomplete), `begin-checkout`, `appointment-booked`, `purchase` (see "Conversion events"). Override a block type with your own view: `:views="['articleList' => 'blocks.articles']"` or `config('content.blocks')`; your view receives
`$block`, `$index`, `$baseUrl`, `$resolve($mediaId)`. The `articleList` block only says what to show: fetch the articles in your view. There is no cookie banner
component (needs JS): build one on `getConsentConfig()` / `recordConsent()` (A10, "Cookiebanner: the consent contract").

### Analytics (module `analytics`, Umami and/or the Hub's native engine): pageviews, events, heatmaps, session replay, campaign attribution

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

This is the same contract as for the site's own code (A10); prefer `{ choice, categories }` there, the helper understands
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

**Native engine (Hub `engine: native` or `both`).** The Hub decides per project which tracker runs; the site changes nothing. `umami` (default):
the Umami tags above. `native`: the Hub's own tracker `/_fsc/a/fsa.js` instead. `both` (the double-measure period): both, each counting on its own.
`fsa.js` sends pageviews (also SPA navigation), engaged time, scroll depth 25/50/75/100, outbound links, downloads (mailto/tel/WhatsApp only as the kind,
never the address), form started/submitted (never a field value), 404 (`<x-content::analytics status="404" />` on the error page), Web Vitals
(LCP, INP, CLS, FCP, TTFB, once per page load), the deepest scroll point per page view (`scroll_max`, 10% steps), frustration signals (`frustration`: rage, dead
and error clicks, quick back; the name only, and after consent also an element selector and for errors the error class and a hash; never text, values, messages or stacks) and your own events:

```js
window.fsa.track('signup', { plan: 'pro' });          // keys [a-z0-9_.], max 30; strings max 200; numbers; booleans
window.fsa.revenue(49.95, 'EUR', { order: 'A-1' });  // webshops
// or declarative: <button data-fsa-event="cta_click" data-fsa-prop-location="hero">  (data-fsc / data-fsc-prop-* work the same)
// window.fscAnalytics.track(name, props) reaches Umami AND the native tracker, whichever runs.
```

**Form tracking (`form_start`, `form_submit`, `form_field`).** The tracker names a form by `data-fsa-form="offerte-aanvraag"` (best: a stable, readable
name; then `data-fsc-form`, `data-form-key`, the form's `id`, `name`, or the path of its `action`). Give every form that matters a `data-fsa-form`; a form
whose name looks like an address, a token or a long number shows as `…` in the Hub, and `data-fsa-ignore` on a form opts it out completely.
Without consent the Hub learns only that a form was started or sent and how many seconds it took (`form_submit` carries `seconds` since the first
interaction): no field, no value. **With consent** (category `analytics`, no Global Privacy Control) the tracker also reports which field a visitor
entered (`event` `form_field`: the form name, the field's `name` or `id` attribute cut down to `[a-z0-9_.-]`, seconds since the start), so the Hub can
show after which field visitors leave a form unsent. Never a value, never the label, never password or hidden inputs; at most 40 fields per form and
page view. Give fields real `name` attributes (`telefoon`, `bericht`); generated ids (`input_8f3a9c1d2e4b`) are not reported. Do not put personal data in
a `name`/`id` or in `data-fsa-form` (the tracker drops names that hold an address or phone number, but you are the first line).
**Privacy statement (the site owner must add this when forms and the native engine are on):** "Bij formulieren meten we of je het formulier start en
verstuurt en hoe lang dat duurt, zonder wat je invult. Heb je toestemming gegeven voor statistieken, dan zien we ook bij welk veld je afhaakt (alleen de
naam van het veld, nooit wat je typt)."

No cookies. Without consent nothing is stored on the device (the collector counts with a daily rotating hash); after consent (same banner contract as
above) a random id in `localStorage` (`fsa-id`, never with Global Privacy Control) and heatmap clicks (element selector and position, never text or field values); without consent any stored id is deleted. Paths,
referrers, titles and props lose e-mail addresses, phone numbers and secret-looking segments (`/reset-password/<token>` becomes `/reset-password/[token]`;
UUIDs, long hex, long random strings); URLs keep only the path (max 500 characters) and the utm/click id parameters. Events go to `POST /_fsc/a/e` on this site; the
SDK checks the sitekey (no open relay), strips the id and heatmap clicks again when there is no consent, signs the visitor IP with the per-site key the Hub
gives the server token (`X-Fsc-Client-Ip`/`X-Fsc-Ts`/`X-Fsc-Sig`) and forwards to the collector inside the cluster (`FSC_ANALYTICS_COLLECTOR_URL`, default
`http://collector.analytics.svc.cluster.local/e`, 1.5 s timeout `FSC_ANALYTICS_COLLECTOR_TIMEOUT_MS`). It always answers `202 {}`; a collector outage never
reaches the page. Without a signing key (publishable token, or a Hub that is not set up) the native tracker is not rendered; the collector refuses
every unsigned batch.

- 🚨 **The signing key is a secret.** `getAnalyticsConfig()` never returns it; only the SDK's proxy reads it (`getAnalyticsProxyConfig()`, `@internal`).
  Never call that from site code, print it, log it or put it in a view.
- 🚨 **The visitor IP header must be trustworthy.** The proxy signs the IP from `FSC_IP_HEADER` (default `cf-connecting-ip`). That is only correct when
  every request reaches the pod through Cloudflare and nothing else can set the header (FSS cluster: the nodes' ports 80/443 are closed, only the
  Cloudflare tunnel and the tailnet reach Traefik). A site that can be reached directly must set `FSC_IP_HEADER` to a header its own proxy overwrites,
  or to an empty value (then Laravel's `$request->ip()` counts).
- **Heatmap overlay** (0.11.0): the portal opens a page in a window of the right width with `#fsa_overlay=<token>` (a 10-minute token from the Hub
  for one page of this project, single use). `fsa.js` then takes the token out of the address bar at once, does not measure that view, sets
  `window.fsaOverlayMode` (so `fsc.js` and the first-party Umami tracker and recorder stand down too: no count, no replay) and loads
  `/_fsc/a/overlay.js`, which posts the token to `POST /_fsc/a/overlay` (body, never a URL) and draws the heat over the current box of each element. The SDK forwards the token with the
  server token to the Hub (`getAnalyticsOverlay()`, `@internal`); the Hub only answers for a token of this project. Nothing to configure: same-origin
  script and fetch, styles through the CSSOM only, so `script-src 'self'`, `connect-src 'self'` and `frame-ancestors 'self'` stay as they are (the
  overlay runs in its own window, never in an iframe). Needs a server token (`fsc_srv_`).
- `fsa.js` is served minified (`resources/assets/fsa.min.js`); after changing `resources/assets/fsa.js` run `scripts/build-fsa.sh` (bun) and commit both.
  The JS tests run against both and fail on a stale build. Always first-party, also in
direct mode (then `Content::analyticsCsp()` adds `'self'`). The pod needs network egress to the collector (FSS cluster: NetworkPolicy, done by the platform).

**Attribution.** On the first page of a visit the helper keeps `utm_source/medium/campaign/term/content`, the ad click ids (`gclid`, `gbraid`, `wbraid`, `dclid`,
`gclsrc`, `fbclid`, `msclkid`, `ttclid`, `li_fat_id`, `twclid`, `rdt_cid`, `epik`, `sccid`), the landing URL and an external referrer in `sessionStorage`. Every
`<x-content::form>` sends them along (hidden field `_fsc_attribution`, read by the form route) and Content stores them with the submission (`meta.utm`,
`meta.clickIds`, `meta.landingUrl`, `meta.referrer`). For your own form add `data-fsc-attribution` to the `<form>`, then in the controller:
`$content->submitForm($key, $values, ['meta' => Forms::attributionMeta($request->input('_fsc_attribution'))])`; a fetch-based form can send
`window.fscAnalytics.attribution()` as `_meta`.

**Redirects must keep the query string**, or campaigns are lost: `RedirectMiddleware` keeps it (and keeps at least the utm and click id parameters when the
target has its own query or is absolute). Your own redirects (`redirect()->to(...)`, route redirects, `www` to apex in nginx/Traefik) must pass the query along too
(`$request->getQueryString()`; nginx `return 301 https://example.nl$request_uri;`).

**Privacy text** (privacy page, not the banner): the Umami and native sentences in the table of A11.

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
10. Native engine (`engine` native/both): `GET /_fsc/a/fsa.js` and `GET /_fsc/a/overlay.js` return JavaScript, `POST /_fsc/a/e` answers 202 on a page view, `getAnalyticsConfig()['native']`
    has `ready: true` and a `signingKey` (server token), and the pod reaches the collector.

#### Conversion events (0.19.0): `begin_checkout`, `appointment_booked`, `purchase`

The Hub's default goals and funnels ("Afspraak geboekt", "Aankoop", the shop funnel product, checkout, purchase, the appointment funnel) count three events
that the site has to fire. Place one component on the right page; it prints one hidden element and the analytics helper (`fsc.js`, part of
`<x-content::analytics />`) fires it once per page through every active tracker (native `fsa.js` and/or Umami). Cookieless and without consent
(session-level events); nothing is printed or sent when the analytics module is off.

```blade
{{-- checkout page (the page where the visitor fills in the order) --}}
<x-content::begin-checkout :items="count($cart['lines'])" :value="$cart['totalCents'] / 100" />

{{-- confirmation shown after Appointments::book succeeded (render it only on success) --}}
<x-content::appointment-booked :service="$serviceSlug" :reference="$appointment['id']" page="/afspraak-maken" />

{{-- order thank-you page: the order from Orders::get; pass number, total-cents and currency yourself if you have no order array --}}
<x-content::purchase :order="$order" />
```

- `begin_checkout`: optional `items` and `value`. `appointment_booked`: optional `service`, `reference` (booking id: a reload of the confirmation does not count
  twice in the same browser session) and `page`, the path of the booking page, sent as `booking_path` (default: the current path). **Always pass `page`
  when the confirmation is shown on another URL than the booking form**; the Hub reads `booking_path` to learn where the booking page is.
- `purchase` is a revenue event named `purchase`: `fsa.revenue(total, currency, {name: 'purchase', order: <number>})`. Total = `totals.totalCents / 100`
  (VAT included), currency from the order (default `EUR`); the order number is the reference, so a reload of the thank-you page does not count the order
  again. A zero or unknown total prints nothing. On the Umami engine the same call is `umami.track('purchase', {revenue, currency, order})`.
- 🚨 No personal data in props: the components accept only short scalar values, refuse keys such as name, mail, phone, address, note and drop any value with an `@`.
  Never put a name, an e-mail address or an address in a custom event either.
- Custom or non-Blade sites fire the same three by hand (names and shapes are the contract with the Hub, keep them exact):

```js
window.fsa.track('begin_checkout', { items: 2, value: 49.95 });                          // once, when the checkout page is shown
window.fsa.track('appointment_booked', { service: 'knippen', booking_path: '/afspraak' }); // once, after a successful booking
window.fsa.revenue(104.85, 'EUR', { name: 'purchase', order: '0042' });                   // once per order, on the thank-you page
// or for every engine: window.fscAnalytics.track(...) / window.fscAnalytics.revenue(amount, currency, props)
```

### FullStack Intelligence (module `intelligence`, paid add-on, 0.12.0, slots 0.13.0): OFF by default

Do nothing unless FullStack Studio switched Intelligence on for the site. Then, in `.env`: `FSC_INTELLIGENCE_ENABLED=true`, optionally
`FSC_INTELLIGENCE_EXCLUDE_IPS=<office IPs/CIDRs>` (their visits are marked `fsi_ex` and never build a profile) and, on fullstackstudio.nl only,
`FSC_INTELLIGENCE_RECOGNITION_COOKIE=fsi_rec` (the portal recognition cookie). The native proxy then links a CONSENTING browser to its profile
(`ContentClient::identifyVisitor`, after the response, once per browser per 12 h). Without consent: no identify, no cookie read, nothing on the device.
A site form that should link the submission to the visitor may send `window.fsa.pid()` along (null without consent). Never show the visitor
what is known about them ("you viewed SMS 3 times").

#### Member recognition and form linking (0.18.0)

The cookie name `content.intelligence.recognition_cookie` now defaults to `fsi_rec` (empty = off; `FSC_INTELLIGENCE_RECOGNITION_COOKIE_DOMAIN` for a shared
domain). When the Hub project has Intelligence on with the `recognition` opt-in (the site owner switches it on in the Hub), a logged-in member is recognised on
every later visit with a consenting browser. Most sites need ZERO code: inside a Laravel request `$content->members->login/loginTotp/verifyEmail/refreshSession`
send the consented browser id themselves, and the answers of login, loginTotp, refreshSession, logout and logoutAll set or remove the cookie on the response
(switch `content.intelligence.member_recognition`, default on; per call `['pid' => false]` or `['queue' => false]`). Only a site with a fully custom HTTP client
(or the switch off) does it by hand:

```php
use FullStackStudio\Content\Laravel\MemberRecognition;

$answer = $content->members->login($credentials, ['clientIp' => $request->ip(), 'pid' => MemberRecognition::pid($request)]);
MemberRecognition::queue($answer, $request);   // idempotent, also fine next to the automatic path
```

`MemberRecognition::pid($request)` reads the consented `fsa_pid` cookie (null without consent, under `Sec-GPC: 1`, for crawlers); `queue()` sets the cookie from
`recognition: {cookie, value, maxAge}` (HttpOnly, Secure on https, SameSite=Lax, site domain) or removes it when the answer holds `recognition: null` (logout), and does
nothing when the answer has no `recognition` (module or opt-in off) or for a crawler. Never throws. The package exempts the cookie from Laravel's cookie encryption itself.
Forms built with `<x-content::form>` send `_meta.pid` the same way (consenting browsers only), so a submission links the visitor to the e-mail address with no code.
The site's privacy statement must name this: "if you log in, we recognise you on later visits (cookie `fsi_rec`, 6 months) to show relevant content", and it only runs
with analytics consent for the pid part. Never show the visitor what is known about them.

#### Chat widget with recognition (0.20.0)

With `content.intelligence.enabled` on, the package serves `POST /_fsc/i/chat`. Add `open-url` to the widget and the chat is linked to the visitor's
Intelligence profile and, for a logged-in or recognised visitor, to who they are (Direct shows it beside the conversation; the Hub needs Intelligence with
`recognition`). The site adds the consented browser id and the HttpOnly `fsi_rec` cookie server-side, so never print either value into the page:

```blade
<script src="https://content.fullstackstudio.nl/widget/chat.v1.js" defer></script>
<fsc-chat token="fsc_pub_…" lang="nl" open-url="/_fsc/i/chat"></fsc-chat>
```

Without `open-url` (or on a site without the route) the widget opens at the Hub as before. The cookie only tells the Hub WHO the visitor is; it never
unlocks a member's own data in the chat (orders, appointments, requests, invoices): that still takes the member session (`FscChat.init({ memberSession })`).
The privacy statement text above covers it.

#### Accountgegevens via de chat (Unreleased)

For a site that runs its OWN customer portal (login, invoices, tickets, services) the chat bot can answer a logged-in visitor's questions about their own
account data. The Hub never sees the portal's login: the portal hands out an opaque HttpOnly session handle and answers a signed server-to-server call.

1. **Handle cookie.** Your portal sets an HttpOnly, Secure, SameSite=Lax cookie named `content.intelligence.session_cookie` (default `fsi_sess`, env
   `FSC_INTELLIGENCE_SESSION_COOKIE`) holding a random handle of at most 128 characters `[A-Za-z0-9._-]`; store only its hash next to the portal session, and
   end it with the session. The package never sets it. With `content.intelligence.enabled` on, `POST /_fsc/i/chat` forwards it to the Hub as `session` under the
   same conditions as `recognition` (not an excluded IP, not staff, not a crawler, same site only; a malformed value is dropped). Never print it into the page.
2. **Hub configuration.** In the project's chatbot module settings give `accountData.url` (https) and `accountData.topics` (subset of `invoices`, `tickets`,
   `services`, `appointments`) and the module secret `accountDataSecret` (32-64 characters, generate it in the vault). The data is only used at identity level
   (a recognised portal login) with Intelligence `recognition` on.
3. **Signed call.** The Hub sends `POST <url>` with `Content-Type: application/json`, `X-FSC-Signature` (the webhook scheme, `WebhookSignature`, timestamp = now),
   `X-FSC-Request-Id` (uuid v4) and `User-Agent: FullStackHub-AccountData/1`. Verify the signature over the raw bytes with a 300 s window, dedupe the request id
   and the signature itself for 10 minutes, and check `projectId` against your own Hub project. Register the route outside the `web` group (no session, no CSRF) with a throttle.
   A bad signature is 401 and a malformed body 400 (no detail in the body).
4. **Request body.** `{"version":1,"projectId":"<hub project uuid>","conversation":"<first 16 hex of sha256(conversationId)>","identity":{"source":"fss",
   "kind":"contact","ref":"portal_user:<uuid>"},"session":"<handle>","topic":"invoices","ref":null,"limit":5,"locale":"nl","sentAt":"<iso, milliseconds>","requestId":"<same uuid as X-FSC-Request-Id>"}`. Look the handle up,
   refuse when it is expired, impersonated, or when `identity.ref` is not the session's user (`identity_mismatch`), and answer for THAT user's customer only.
5. **Answer (HTTP 200).** `{"ok":true,"topic":"invoices","items":[{"ref":"F2026-0123","title":"Factuur F2026-0123","status":"open","date":"2026-10-01",
   "amount":"EUR 121,00","open":"EUR 121,00","due":"2026-10-15","note":"..."}],"total":7,"more":true,"link":"https://portal.example.nl/facturen","hint":"..."}` or
   `{"ok":false,"code":"session_invalid|identity_mismatch|unsupported_topic|forbidden|rate_limited|unavailable"}`. The Hub cuts at 10 items, 160 characters per
   title or note, a `ref` of 40 characters `[A-Za-z0-9._-]`, 3072 bytes in total and keeps `link` only when it is https on the same host as `url`; it never stores
   the items. Return only what the portal itself shows (no payment tokens, public links, internal ids, message bodies, other people's data). After
   `session_invalid` or `identity_mismatch` the Hub stops asking for that conversation. The privacy statement must say the chat can look up the account data of a logged-in visitor.

#### Newsletter click links (Unreleased)

The Hub's newsletter rewrites links in every campaign mail to a short redirect (`/n/l/<token>`, on by default per project; the Hub switch is
`clickTracking`). A click counts in the campaign stats (per send and link; the stats show totals only). For a link to THIS site the redirect adds `?fscn=<token>`; with
`content.intelligence.enabled` on, the package then links a consenting browser (the `fsa_pid` cookie; never under `Sec-GPC: 1`, never for crawlers,
staff or `exclude_ips`) to the subscriber's address: one server-side `POST /intelligence/link {pid, newsletterToken}` after the response, once per
browser and token (global middleware `NewsletterClickLink`, `ContentClient::linkNewsletterClick()`). fsa.js and fsc.js remove `fscn` from the address bar
before the first pageview. ZERO code: never read, store, forward or print `fscn` yourself, and do not strip it in your own redirects before the page
renders. The Hub only links with Intelligence `recognition` on, as a claim (a forwarded mail can be clicked by someone else), and never creates a profile.

Rules: load `<x-content::analytics />` (fsa.js) before any other analytics or tag script, so `fscn` is gone before they read the URL; and never
use the full request URL (with its query) for `canonical`, `og:url`, share links or anything stored: build those from the route or the path.

The site's privacy statement must name this, e.g.: "Klik je op een link in onze nieuwsbrief, dan registreren we welke link in welke verzending is aangeklikt, om te zien
welke onderwerpen aanslaan. Heb je op deze site toestemming gegeven voor statistieken, dan koppelen we je bezoek ook aan je inschrijving voor de
nieuwsbrief, zodat we je relevantere inhoud kunnen tonen." (Dutch sites; translate for others.)

#### Personalisation slots (0.13.0, server-side decision 0.14.0): OFF by default

Wrap a part of a page that FullStack Intelligence may personalise. Write the DEFAULT content as always; it stays in the HTML for everyone
(search engines, the edge cache, visitors without JavaScript), so a slot never changes SEO or caching:

```blade
<x-content::slot name="home-hero" as="div" class="hero__copy">
    <h1 data-fsi-field="title">Websites die werken</h1>
    <a href="/contact" data-fsi-href="cta_url" data-fsi-field="cta_label">Plan een kennismaking</a>
    <x-content::variant key="voip">{{-- optional own markup for the variant "voip" --}}</x-content::variant>
</x-content::slot>
<x-content::slot name="chat-opening" :data="true" />   {{-- no visible content: read window.fsiSlots.get('chat-opening') --}}
```

- `name` = the slot key in the Hub (portal: Intelligence, Personalisatie); `as` + other attributes go on the element itself, so the slot can BE the
  existing element (no extra wrapper). Keep the element; do not put the slot around layout grid items that must stay direct children.
- A variant replaces the text of `data-fsi-field="<field>"` elements and the `href` of `data-fsi-href="<field>"` elements (plain text and safe links
  only), or uses its `<x-content::variant key="…">` markup when the site has one. `hide` hides the slot (logged-in customers: no "word klant").
- Off (default): the slot is exactly its element with the default content, no attributes, templates, script or route. On: `FSC_INTELLIGENCE_ENABLED=true`
  and `FSC_INTELLIGENCE_PERSONALIZATION=true` in `.env`, plus the switches in the Hub (global, module setting `personalization`, each slot).
- Since 0.14.0 the decision is rendered on the SERVER: with a signal (same-site Referer on another path, the recognition cookie, or the consented
  `fsa_pid` cookie that fsa.js sets) the slot asks the Hub once per request (`FSC_INTELLIGENCE_SSR_TIMEOUT_MS`, 250) and the variant is in the HTML,
  marked `data-fsi-variant` / `data-fsi-group`; no signal = no Hub call. Such a response is sent `private, no-cache`, so do NOT cache the HTML of
  personalised pages at the edge. A site that edge-caches HTML sets `FSC_INTELLIGENCE_CLIENT_DECIDE=true` (browser path for slots the server did not
  decide). Slot names are learned per page: the first signal view of a new page decides what it already knows.
- Since 0.15.0 `POST /_fsc/i/me` (while `FSC_INTELLIGENCE_ENABLED` is on) answers `{consent, profile, recognized}` for the visitor's own consented browser id (`fsa_pid`) and
  recognition cookie, for a dev panel (`devmode()`); `ContentClient::intelligenceMe()`. Never cached.
- Since 0.16.0 `FSC_PID_COOKIE_DOMAIN` (e.g. `.fullstackstudio.nl`, default empty = host-only) puts the `fsa_pid` cookie on a parent domain so a
  sibling host of the same organisation can read it (FullStack Studio's portal links the earlier profile to the account at registration/login).
  Only set it when such a host exists, and mention it in the site's privacy statement.
- Crawlers, bots, headless browsers, prefetches and requests without a User-Agent never get a variant (default HTML, no Hub call, headers untouched); extend with `FSC_INTELLIGENCE_CRAWLER_PATTERN`.
- Privacy and limits: the `fsa_pid` cookie is ignored under `Sec-GPC: 1`; a referrer only counts when it maps to a real route of the site (answers
  with a referrer are never cached); the decision shares the 120/min per visitor IP limit of `/decide` (over it = default, no Hub call); Hub answers
  slower than 150 ms count toward the 10 s breaker (three within 30 s). Learned slot lists are stored per ROUTE after a 2xx response only.
- Two gaps of the `private, no-cache` header: it is set by an event after the middleware stack, so (1) a view rendered INSIDE a streamed
  response (`response()->stream`, `streamView`) is sent before it can be set, and (2) a server-side response cache middleware (e.g. spatie
  responsecache) stores the page before the header exists. For both, put `FullStackStudio\Content\Laravel\PrivatiseSlotResponses` as the
  OUTERMOST middleware of those routes (same effect, applied while the response travels back), or do not cache/stream pages with slots.
- The browser step (`/_fsc/i/slots.js`, loaded once) only measures server-decided slots. Its own path (client_decide) swaps only when the page cannot move (no CLS) and within `FSC_INTELLIGENCE_SWAP_DEADLINE_MS`
  (1500); a 10% control group keeps the default. It measures `fsi_slot_view` / `fsi_slot_click` through the tracker; the Hub reports uplift per variant.
- Variant content in the Hub must never tell the visitor what we saw (the Hub refuses obvious phrases). Give images in a variant fixed dimensions.
- JS: `fsiSlots.ready(fn)`, `fsiSlots.get(name)` -> `{variant, group, fields}` or null, `fsiSlots.view(name)` (data slots: report when shown).

#### Recommended products (unreleased): ids ranked in the Hub, the site renders

"You might also like" without any tracking code on the site. The Hub ranks product ids (the hot topics of the consenting visitor, mapped to your product
categories first, then the best sellers of the last 30 days, then the newest products; only active products that can be bought) and the site renders them:

```php
$ids = $content->products->recommended($pid, 8);              // list<string>, best first
$products = $content->products->recommendedProducts($pid, 8); // the same, hydrated with products->get() (a gone or sold-out product is skipped; one `get()` per id, one after the other, so keep the limit small)
$answer = $content->products->recommendations($pid, 8);       // {active, source, ids}
```

- Server token only (a pub token gets 403). `$pid` is the consented browser id, sent in the header `X-FSI-Pid` (never in the URL): in Laravel `IntelligenceProxy::pid($request)` (the `fsa_pid` cookie, null without
  consent, for crawlers and under `Sec-GPC: 1`). No `pid`, an unknown one or a suppressed browser all give the same fallback list. Pass `['gpc' => true]` in the
  options when the request carried `Sec-GPC: 1`; `['locale' => 'en']` for another language than the project default. `$limit` is 1 to 24 (default 8).
- Works with Intelligence OFF too: `active` is then `false` and the ids are the fallback (best sellers, newest), so the block never has to hide. Interest ranking
  needs the module `intelligence` with `personalization: true` and the global switches of the Hub; it only uses categories derived from the shop's own product
  categories (`drv:product:<slug>`), so there is nothing to configure.
- Not cached by the SDK (the answer depends on the visitor). The Hub caches the id list for 60 seconds per topic set, never per browser id, so a product that sells out
  can show for up to a minute. Limit: 600 calls per minute per token (429 `rate_limited`: show your own list).
- Name it in the privacy statement when you personalise ("products shown to you may depend on the pages you viewed, only with your consent"). Never show why.

```blade
@php
    $pid = \FullStackStudio\Content\Laravel\IntelligenceProxy::pid(request());
    try {
        $recommended = app(\FullStackStudio\Content\ContentClient::class)->products->recommendedProducts($pid, 4, ['gpc' => request()->header('Sec-GPC') === '1']);
    } catch (\FullStackStudio\Content\ContentException) {
        $recommended = []; // the Hub is away or the module is off: render nothing
    }
@endphp
@if ($recommended !== [])
    <section class="recommended">
        <h2>Misschien ook interessant</h2>
        @foreach ($recommended as $product)
            <a href="{{ route('product', $product['slug']) }}">
                @if ($product['cover'] ?? null)<x-content::image :media="$product['cover']" :alt="$product['name']" />@endif
                <span>{{ $product['name'] }}</span>
            </a>
        @endforeach
    </section>
@endif
```

### Dev panel (0.17.0): `?fsc_devmode=1`

Every site gets a dev panel for free; it is off for everybody. Visit any page with `?fsc_devmode=1` (sets the cookie `fsc_devmode`, redirects) and `?fsc_devmode=0` to
leave. Only a request with the cookie gets two script tags in the `<head>` (from `<x-content::head />`, or `<x-content::devmode />` when a layout has no head component),
and that response is `private, no-cache`; no devmode bit exists in the HTML otherwise. **A CDN that caches HTML must bypass its cache when the Cookie header contains
`fsc_devmode=1`** (Cloudflare: add `not http.cookie contains "fsc_devmode=1"` to the cache rule), or the panel is cached into the page / the page is served without it.
The panel shows the visitor's ids and Intelligence profile, display switches (reduced motion, Save-Data, no WebGL, weak device; this browser only, via `html[data-fsc-*]`
attributes and `window.fscDev.has()`) and the page's slot decisions.

Wiring per site (all in `config/content.php` -> `devmode`): `portal_url` (e.g. `https://portal.example.nl/websites/<id>/intelligence/bezoeker/{ref}`, no link when empty),
`scripts` (same-origin scripts that add your own switches or sections: `(window.fscDevExtensions = window.fscDevExtensions || []).push({ id, title, flags: [{ id, label, hint }], mount(ctx), render(host, ctx) })`),
`aliases` (extra console names for `devmode()`), and three CSS variables to keep your floating buttons free: `--fsc-dev-top` (desktop offset from the top, below a sticky header),
`--fsc-dev-bottom` (space kept free at the bottom: the desktop panel stops above it, the phone sheet is raised by it) and `--fsc-dev-pill-bottom` (pill on a phone).

### Cache and webhook invalidation

Reads are cached under tags (`content:articles`, `content:article:<slug>`, `content:page:<path>`, `content:seo`, ... see `FullStackStudio\Content\Tags`) in your Laravel
cache store (default TTL 3600 s as safety net). Writes and `submitForm` are never cached. For "immediate" updates:

1. In Content create a webhook to `https://<your-site>/content/webhook`; put its secret in `FSC_WEBHOOK_SECRET`.
2. Set `FSC_WEBHOOK_ENABLED=true` (or call `Content::routes()` in a routes file). The route verifies the signature against the raw body, invalidates the tags,
   dispatches `FullStackStudio\Content\Laravel\Events\ContentWebhookReceived` (`->event`, `->tags`) and answers 200 (401 bad signature, 500 secret missing).
   It uses the `api` middleware group (no CSRF). If you register it in `web`, exempt it: `$middleware->validateCsrfTokens(except: ['content/webhook'])`.

Do not write your own cache layer on top and do not cache Content responses in views for long. With a file cache and several replicas the webhook only clears the replica it
reaches: use a shared store (`FSC_CACHE_STORE=redis`) or accept the TTL.

### Other modules (brief; what to build per module: "Als de module X aanstaat")

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

## Laravel-site pitfalls

Moved to A4 (https, proxy, sessions, nginx buffers) and "Common mistakes" under "Controleer je site".

## Testing

No network in tests. Give the client a fake `FullStackStudio\Content\Http\Transport` (`send(Request): Response`; `new Response($status, $headersLowercase, $jsonBody)`) and bind it:

```php
$this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_test', 'transport' => $fake]));
```

`ClientOptions` keys: `token`, `baseUrl`, `timeoutMs`, `clientIp`, `transport`, `cache`, `cacheTtl`. `WebhookVerifier::sign($secret, $rawBody)` computes a valid
`X-FSC-Signature` for webhook tests. The package's own test fake lives in the package repository (`tests/Support/FakeTransport.php`) and is not installed with it.

## Version and docs

This guide describes the installed version of the package (see `CHANGELOG.md`, newest first). Full docs: `README.md` (Quick start, Modules, Tokens, Cache and webhooks, Laravel, Blade components,
Errors, Transports). API reference: `docs/api-v1.md` in the Content repository. `php artisan fsc:agents` (re)writes the pointer block in the site's `AGENTS.md`;
`php artisan fsc:doctor` checks a site against "Altijd verplicht".
