# Changelog

## Unreleased

- Languages follow the project's enabled list in the Hub (`GET /project` `locales`, set by FullStack Studio). `Content::urls()` gets `multilingual()` and `prefixes()`; only an enabled, non-default language gets a path prefix, so a single-language site never has one. hreflang (`<x-content::head />`) is printed only on a multilingual site, only for enabled languages and only with two or more versions. The automatic `sitemap.xml` leaves out entries in a language that is not enabled. Form texts follow the language of the rendered Hub page also when that is `en` (before, `en` was skipped and the English version of a multilingual site got Dutch form texts); Laravel's default app locale `en` alone still counts as not set. Behaviour change: until the Hub returns the enabled list (Hub release with `projects.locales`), a project whose Hub answer has a single locale gets no prefixes.
- Out of the box site, step 9: the default cookie banner. `<x-content::scripts />` now prints it (partial `content::partials.banner`) from `getConsentConfig()`: Hub texts, categories, policy link and version, Dutch fallbacks; "Alleen noodzakelijk" and "Alles accepteren" are two identical buttons, "Instellen" opens the category checkboxes. It stores `fsc-consent`, dispatches `fsc:consent`, posts the proof, reopens from any `data-fsc-consent-open` link and asks again when the Hub's version changed; it sets no cookie and runs nothing before a choice. Nothing is printed when the module is off, the Hub is down, `FSC_AUTO_CONSENT=false` or in the portal preview. New routes (no middleware group): `GET /_fsc/c/consent.js` (cacheable, `?v=` hash, nonce on the tag) and `POST /_fsc/c` (validated, 20 a minute per visitor IP, `recordConsent()` with IP and browser string, 204 also when the Hub fails). Styles are inline in `:where()` with `--fsc-*` (system colours as light/dark default, `--fsc-consent-bg` and `--fsc-consent-text` to override). Publish the partial with `--tag=content-consent`. A site with its own banner sets `FSC_AUTO_CONSENT=false` (its own `fsc:consent` banner keeps working unchanged).
- Out of the box site, step 10: trailing slash option. With `content.auto.trailing_slash` on (`FSC_AUTO_TRAILING_SLASH`, off by default) the new global middleware `Laravel\Site\TrailingSlash` (registered by `AutoPlatform`, state in `AutoPlatform::trailingSlash()`) answers GET/HEAD page paths without a trailing slash with a 301 to the slashed form (query kept, leading slashes collapsed so `//host` stays on the site), the same form `Urls` builds. Left alone: `/`, files (a dot in the last segment), the first segments `api`, `_fsc`, `content`, `build`, `storage`, the Hub visitor page paths (`a`, `e`, `n`, `t`, `r`) and the portal preview request (`POST /_fsc/p` and its internal forward); extra first segments through `content.auto.trailing_slash_skip`. Replaces the `TrailingSlash` middleware that rebuilt WordPress sites copied (hanami4you.nl).
- Out of the box site, step 8: platform basics (`Laravel\Auto\AutoPlatform`, switches `FSC_AUTO_PLATFORM` and `FSC_AUTO_REDIRECTS`). After all providers booted, and only where the site set nothing itself: `platform` trusts all proxies (`TrustProxies::at('*')`, not when the app or `config/trustedproxy.php` set proxies) and forces `https` URLs in production (not when the app forced a scheme); `redirects` puts `RedirectMiddleware` first in the `web` group (not when the group already has it; the rest of an app-defined group keeps its order; in the console it waits until the HTTP kernel is resolved). A POST redirect behind the ingress is now `https://`. `AutoPlatform::state()` tells the doctor what was `applied`, what the `app` had set, or `off`/`skipped`/`pending`. Behaviour change: a site that has neither set up gets these three things by itself; set `FSC_AUTO_PLATFORM=false` for a site hosted outside the FSS cluster.
- Out of the box site, step 5: the site controller and default views. `SiteController` resolves the path (`PathResolver`), fills `PageContext` and renders `Auto::view(<type>)` (`content::site.{page,article,articles,category,event,legal}`, Dutch texts, `$page`/`$article`/`$articles`/`$category`/`$event`/`$document`/`$path`/`$resolved`), or calls the invokable class in `content.auto.handlers.<type>` with the `Request` and the `Resolved`; unknown path 404, Hub failure 503, a feature that is off 404. The views extend `content.auto.layout`, else the site's `layouts.app`, else the package layout `content::layouts.site`; they fill `content.auto.section` and sit in `content.auto.wrap` or `.fsc-wrap`; one `<h1>` per page. Inline `site.css` (generated from `site-source.css` by `php scripts/build-block-css.php`, all in `:where()`, `--fsc-*` only, once per request with the CSP nonce, off with `content.blocks.inline_styles`). Publish a single view with `--tag=content-site-<type>`. `Urls` is now bound per request (one lookup of the project and SEO settings per page).
- Out of the box site, step 7: error handling (`Laravel\Auto\AutoErrors`, switch `FSC_AUTO_ERRORS`). A `ContentException` that escapes site code (also wrapped in a Blade `ViewException`) renders as 404 when the Hub said not found, else as 503 with `Retry-After: 300` and `Cache-Control: no-store`, instead of a 500; it is reported once by Laravel's normal report path and a Hub 404 is never reported. Every HTML 404 and 503 uses the site's `errors/{status}` (or `errors/4xx`, `errors/5xx`) view when it exists, else the new defaults `content::errors.404` (Dutch, inside `content.auto.layout` (else `layouts.app`) and its section, status in `PageContext` so the tracker counts it, `fscStatus` passed for older layouts) and `content::errors.503` (standalone, `noindex`, no components, so no second Hub wait); both themed by `--fsc-*`. A missing or failing layout or error view ends in a standalone page, never in a loop. No stack trace, Hub message or token in a response. JSON and `api/*` requests get a fixed JSON body for an escaped Hub failure (503 `{"error":"unavailable"}` with `Retry-After: 300` and `no-store`, or 404 `{"error":"not_found"}`) and keep Laravel's rendering for everything else; render callbacks the site registers (`withExceptions`, a provider) and, with `APP_DEBUG=true`, Laravel's debug page for a Hub failure are left alone. Publish tags `content-errors-404` and `content-errors-503` (to `resources/views/errors/`). Behaviour change: an uncaught `ContentException` is a 404 or 503 instead of a 500, and a site without `errors/404` or `errors/503` views gets the package's.
- Out of the box site, step 6: automatic `sitemap.xml` and `robots.txt`. The sitemap lists the site's own static pages by itself (every parameterless GET route in the `web` group, minus `_fsc/*`, `content/*`, `api/*`, files such as `feed.xml`, redirects, routes behind `auth`/`can`/`verified`, `content.auto.sitemap.exclude` patterns and routes marked `->noSitemap()`, a new route macro whose default survives `route:cache`) plus the Hub entries, all URLs built by `Content::urls()` (locale prefix, trailing slash) and de-duplicated by URL. While `robotsIndex` is off the `urlset` is empty and carries an XML comment saying so; a Hub failure keeps the 503 with `Retry-After`. Answers are `public, max-age=900` (`no-store` when the Hub failed), without session or cookie. `content.auto.robots` is now `['enabled' => ..., 'extra' => []]` (a plain bool still works) and `extra` adds lines below `User-agent: *`. `Sitemap::response()` and `Robots::response()` are unchanged; `Sitemap::ownPaths()` gives the own page list (for `fsc:doctor`).
- Out of the box site, step 4: `<x-content::head />` and the new `<x-content::scripts />` print everything a layout needs from the request scoped `PageContext`. `head` prints meta (title, description, canonical, robots, Open Graph, Twitter), hreflang from the published translations (via `Content::urls()`, with self and `x-default`), JSON-LD (`Article`, `Event`, only fields that exist), the site standards and the error reporter; props `page`, `article`, `meta` (`title`, `description`, `image`, `canonical`, `noindex`), `path`, `nonce`. Meta precedence: `meta` prop, then `Content::seo()`, then the resource, then the project defaults; the canonical never carries a query string. `scripts` prints the banner slot (empty until the banner task), the body code and the trackers, with the status of the request (a 404 reaches the native tracker without `status`); props `nonce`, `consent`. Each part (`meta`, `standards`, `errors`, `body-end`, `analytics`, `banner`) prints once per request, whichever component asks first, so a half-migrated layout never prints two titles or two trackers (behaviour change: a layout that renders the same component twice in one request now prints it once). A bare `<x-content::head />`, or one after `<x-content::meta>`, and the old five components print exactly what they did before (byte-identical, tested).
- Out of the box site, step 3: `Laravel\Site\PathResolver` turns a request path into a `Resolved` (legal document, event, article, category and article list, page, root level article/event after a page 404; locale prefix of a non-default project locale stripped, `app()->setLocale()`; unsafe paths rejected without a Hub call; published only; `?page=` on lists), `Laravel\Site\Urls` builds every content URL (`Content::urls()`: `page`, `article`, `event`, `legal`, `path`, `absolute`; locale prefix, `trailing_slash`, `{locale}` in the article template) and `Laravel\Site\Hub` (`call` maps a 404 to null, `orAbort` to 404/503). The article link of the `articleList` block now goes through `Urls` (a non-default article language gets its prefix). No routes yet.
- Form block fixed (found on the hanami4you test page): the form routes (`POST /content/forms/{key}`) are now registered by default (`FSC_FORMS_ENABLED=false` switches them off), so a rendered `form` block no longer posts into a 404. The form texts follow `content.forms.locale`, then the page locale, then the app locale, and fall back to Dutch (Laravel's default `en` counts as not set: English sites set `FSC_FORMS_LOCALE=en` or pass `locale="en"`); a `submitLabel` in the Hub form definition replaces the default submit text. `fsc:doctor` fails with a clear message when a form or a page-blocks view renders but the form route is off. Behaviour change: forms are Dutch by default.
- Out of the box site, step 1: `content.auto` config block (master switch `FSC_AUTO`, one switch per feature `FSC_AUTO_<FEATURE>`, `trailing_slash`, `layout`, `section`, `wrap`, `views`, `handlers`, `legal_paths`, `articles.{category_segment,per_page}`, `sitemap.exclude`), deep-merged over the package defaults so a sparse app config keeps new keys; `Auto::on()` and friends; request scoped `PageContext` with `Resolved`; `Content::context()` and `Content::seo()`. No routes or behaviour yet.
- Out of the box site, step 2: automatic routes, registered after the site's own routes (`$app->booted()`): `GET /sitemap.xml` (`content.auto.sitemap`), `GET /robots.txt` (`content.auto.robots`), `GET /api/health` (`content.auto.health`, `{"ok":true}`, `Cache-Control: no-store`, no Hub call) without a middleware group, and the fallback route `GET {fscPath}` (`content.auto.page`, `web` group) for Hub content. The site's code always wins: a route with the same method and URI is not registered (also `api/health` from `routes/api.php`), a site catch-all or `Route::fallback()` matches first, and with `route:cache` nothing is added at runtime. Each route follows its switch (`FSC_AUTO_SITEMAP`, `FSC_AUTO_ROBOTS`, `FSC_AUTO_HEALTH`); the content route stays with `FSC_AUTO_PAGES=false` so the `web` group (Hub redirects) still runs, and goes only with `FSC_AUTO=false`. Sitemap, robots and the content route answer 404 until the next steps fill them in. `AutoRoutes::owner($method, $path)` tells the doctor who answers a path (`sdk`, `site`, `none`). Behaviour change: on a site without its own catch-all, a POST to a path that has no POST route now answers 405 instead of 404 (the same as with Laravel's `Route::fallback()`).
- Page blocks work out of the box. `<x-content::page-blocks>` prints the base CSS of the default block views inline, once per page, with the CSP nonce (`Laravel\BlockStyle`, same mechanism as `RichStyle`), only when a block renders with a default SDK view. Before, columns, gallery, video and the module blocks stacked and ran edge to edge on a site that did not load the optional `content.css`. All rules sit in `:where()` (zero specificity: the site's CSS always wins). `resources/assets/blocks.css` is generated from `content.css` by `php scripts/build-block-css.php` (a test checks it). Switch off with `content.blocks.inline_styles` (env `FSC_BLOCKS_INLINE_STYLES`) when the site loads the published `content.css`.
- Theme layer: every visual value of the block CSS goes through a `--fsc-*` variable with a neutral default, declared once in `:root`. New: `--fsc-font`, `--fsc-heading-font`, `--fsc-text`, `--fsc-muted`, `--fsc-surface`, `--fsc-content-width`, `--fsc-content-pad`, `--fsc-button-bg`, `--fsc-button-text`, `--fsc-button-radius`, `--fsc-embed-ratio` (plus `--fsc-hero-text` and `--fsc-hero-shadow-color`); the existing names are unchanged. AGENTS.md has a "Site design" section.
- `<x-content::page-blocks wrap="wrap max-w-5xl">`: blocks rendered by a default view are wrapped in `<div class="{wrap}">` (the site's content container); blocks rendered by a site view are not. Without `wrap` they sit in `<div class="fsc-wrap">` (`max-width: var(--fsc-content-width)`, 72rem, `padding-inline: var(--fsc-content-pad)`). A default block that renders nothing adds no wrapper. Behaviour change: default blocks now always sit in a wrapper div, and without `wrap` they are limited to 72rem with 1rem side padding; pass `wrap` with the site's own container classes. The hero fallback is wrapped like every other default block.
- Typography for the default blocks (found on the hanami4you.nl test page): a CSS reset such as Tailwind's preflight strips heading sizes, list markers and paragraph spacing, so block headings, card titles and rich text (`.fsc-article-body`, `.fsc-column`: headings, paragraph rhythm, lists, links, quotes) now have sizes and spacing of their own. New variables `--fsc-h2-size`, `--fsc-h3-size`, `--fsc-line-height`, `--fsc-form-width` (the form block keeps a 40rem measure instead of the full content width). Event and product cards keep their content at the top of a stretched card. `scripts/build-block-css.php` no longer splits a selector at the comma inside `:is(...)`/`:not(...)`.
- Defaults that were missing without `content.css`: gallery and columns grid (one column on a phone), gallery images at a fixed 3:2 with `object-fit: cover`, the video (`embed`) block at full width and `--fsc-embed-ratio` (16 / 9), image radius, muted captions.
- `fsc:doctor` warns (`LET OP`, never `FOUT`) when a view has `<x-content::page-blocks>` without `wrap` and the site has no view for every block type with a default view. AGENTS.md: new item A18 (wrap, default styling, the test page with every block type at phone and desktop width) and the "Bouwvolgorde" points to it.
- New global middleware `ContentResponseHeaders`, on for every site with no site code: successful HTML pages (not JSON, redirects, the webhook,
  `/_fsc/*` or streamed and binary responses) get `Link: <Hub origin>; rel=preconnect` (origin from `FSC_API_URL`, no `crossorigin`, appended to
  an existing `Link` header) and `X-FSC-SDK: <version>`. Switches `content.response_headers.preconnect` (`FSC_PRECONNECT`) and
  `content.response_headers.version` (`FSC_VERSION_HEADER`), both default true. `fsc:doctor` shows whether both are on.
- The default `FSC_API_URL` is now `https://hub.fullstackstudio.nl`. `https://content.fullstackstudio.nl` is the same Hub and keeps working.

- The default client IP (`X-FSC-Client-Ip` with a server token) now reads the visitor header the site's proxy sets (`content.forms.ip_header`,
  default `cf-connecting-ip`, env `FSC_IP_HEADER`) when it holds a valid IP, else `request()->ip()`. Behind Cloudflare and Traefik the request IP
  could be a proxy hop, so all visitors of a shop shared one per-visitor limit at the Hub.
- `events()->register()` sends the visitor IP as `X-FSC-Client-Ip` too (`clientIp` in the options, else the client's IP), like `orders()->place()`.
- Event cover image: every event the Hub returns now has `coverMediaId` and `cover` (`{id, url, contentType, alt, width, height}` with an absolute `url`, or `null`), and `events->create()`/`update()` take `coverMediaId` (a media id of an image in the project, or `null` to clear). The default `eventList` and `event` block views show the cover when there is one: `<x-content::image>` with the media's `alt`, `width` and `height` (no layout shift), a `srcset` of the Hub's variants and `loading="lazy"`, in `.fsc-eventcard__media` and `.fsc-event__media`. Events without a cover render as before.
- Webhook event types `appointment.no_show` and `appointment.no_show_undone` in `Webhooks\Events::ALL` (staff recorded, or took back, that a customer did not show up; ids, status and times only).

## 0.28.0

Page builder phase 2 and 3 on the site (plan page-builder C and D, tasks 2.2 and 3.2; released as 0.28.0, the Hub registry offers these
types from `minSdk` 0.28.0).

- Default views for the module blocks, so they render on any site without site code: `eventList` (upcoming published events with date, time,
  location and price or "Gratis", `emptyText` when nothing is planned), `event` (one event by `eventRef`, optional description, a button while
  registering is open), `appointmentBooking` (heading, text and a button), `faq` (`<details>` list, optional category and limit, FAQPage JSON-LD
  with the page nonce when `structuredData`), `productList` (cards with photo, name, summary and price, optional category) and
  `newsletterSignup` (e-mail field, honeypot, CSRF). Each reads through the tag cached client (`Laravel\ModuleBlockData`, `@internal`), renders
  nothing on a Hub error, with the module off, when the referenced item is gone or the list is empty, and logs that once per block type and
  reason per process. Dates in the app locale and `content.timezone` (default `Europe/Amsterdam`). Everything is escaped; only the Hub sanitized
  `descriptionHtml` and `answerHtml` go through `<x-content::article-body>`. The preview markers of 0.27 wrap them like every block.
- Links (new config `content.links`, env `FSC_LINK_EVENT`, `FSC_LINK_BOOKING`, `FSC_LINK_PRODUCT`; same-site paths only, a full URL is ignored):
  events go to `links.event` (`{slug}`, `{id}`), else to the Hub registration page (`registrationUrl`); the booking button to `links.booking`
  (`{service}` or `?service=<id>`), else to the Hub booking page (`bookingPageUrl`, plus `/<serviceId>`), else the block renders nothing;
  products to `links.product`, else the product path from the Hub sitemap (the products `pathTemplate`), else `/producten/{slug}`.
- `Laravel\NewsletterController` behind `POST {content.newsletter.path}` (route `content.newsletter`, default `content/newsletter`, `web`
  middleware), registered by default (`content.newsletter.enabled`, env `FSC_NEWSLETTER_ENABLED`) or with `Content::newsletterRoutes()`: checks
  the address, drops a filled honeypot silently, `content.newsletter.rate_limit` tries per visitor IP per minute (default 10), subscribes with
  `newsletter->subscribe()` and the visitor IP (`content.newsletter.ip_header`, default `cf-connecting-ip`), and redirects back to the block with a
  Dutch message (double opt-in by the Hub, the same answer for a known address). The block's list id is encrypted with the app key in the form;
  a tampered value is refused. No Turnstile (the Hub's subscribe call has none). Without the route the block renders nothing; in a preview the
  form is disabled.
- The `html` block ("HTML-code", `Laravel\HtmlBlock`, bound `scoped`): the code is never sanitized or rewritten. An isolated block (`isolate`
  anything but `false`) renders `<iframe sandbox="allow-scripts allow-popups allow-forms allow-popups-to-escape-sandbox" loading="lazy">`
  without `allow-same-origin`, whose `src` is the new route `GET /_fsc/h/{pageId}/{key}` (`Laravel\HtmlBlockController`, route
  `content.html-block`, `content.html_blocks.path`, always on, no middleware group; `key` = block id or `~<index>`). It reads the published page
  through the cached client and serves the block as its own document (code unchanged, no page nonce, plus the height reporter
  `html-block-frame.js`) with its own `Content-Security-Policy` (permissive sources for that block, `sandbox` as above, `frame-ancestors
  'self'`), `X-Robots-Tag: noindex, nofollow`, `nosniff` and `Cache-Control: public, max-age=60, stale-while-revalidate=300`; 404 for a
  malformed path, an unknown or unpublished page and a missing, non-html, inline or empty block, 503 (not cached) when the Hub fails. The
  block finds its page through the pages `getPage()` returned (`ContentClient::htmlBlockLocation()`, `@internal`); without that it renders
  nothing (logged once). In a portal preview the frame is a `srcdoc` document (unsaved blocks), with the preview response's nonce. The page's
  sizer (`html-block.js`, once per page, with the nonce) resizes only the iframe whose `contentWindow` sent the message, only from origin
  `null`, at most 20000 px, with damping (the same growth step five times in a row stops growth until the frame shrinks; at most 4000 px growth
  per second, the rest a second later). `isolate: false` prints the code in place (`Site::withNonce`). Consent other than `necessary` wraps
  either in `<template data-fsc-code data-consent>` with a neutral placeholder and prints the snippets' consent loader once per page. Empty
  code, code over 20,000 characters and gated inline code containing `</template` are not printed.
- CSP: `Content::siteCsp()` and `siteCspMerge()` now include what the rendered `html` blocks need in the PAGE policy: `frame-src 'self'` for
  isolated blocks and the hosts of inline (`isolate: false`) code only (`Site::cspForHtml()`, the extraction of `Site::csp()` for any HTML),
  never the hosts of isolated code. `Site::consentLoader()` and `Site::asset()` are public.
- `StrictHtml::sanitize()`: a strict, `DOMDocument` based allowlist (`p, br, strong, em, u, s, a, ul, ol, li, h2-h4, blockquote`; only `href`
  with http, https or mailto, always `rel="noopener nofollow"`; scripts, styles, frames, media and forms dropped with their content). The
  `event` block runs the event description through it before `<x-content::article-body>` (defence in depth: the Hub served that field raw
  before Hub PR #158).
- The consent loader (`fsc-code.js`) removes an element with `data-fsc-code-placeholder` right before a template when it activates it; site
  snippets are unchanged.
- `preview.js`: the portal can grant consent categories inside the preview frame (`{type: 'consent', categories}`, through
  `window.fscCode.grant`, nothing stored); after a soft swap the loader scans the new blocks again; a swap that brings an `html` block or a gated
  template while the page lacks the sizer or the loader becomes a full reload.
- `$content->events->list()` / `get()` cache their answer under the new tag `content:events` when called with `['cache' => true]` (default
  unchanged: not cached); `list()` passes `status`. The event blocks cache for 300 s (`ModuleBlockData::EVENTS_TTL`; sales states change
  without a webhook) and ignore inactive ticket types for the price. `Tags::forEvent()` clears `content:events` on every `event.*` webhook except
  `event.checked_in`. `$content->appointments->bookingPageUrl()` returns the Hub booking page from the (cached) services answer.
- `content.css`: mobile first styles for the module blocks, the newsletter form, the HTML-code frame and placeholder, after the unchanged JS SDK
  base.
- `fsc:doctor`: the newsletter route (`OK`, or `INFO` when switched off), `LET OP` for a `Content-Security-Policy` in `app/`,
  `bootstrap/app.php`, `routes/` or `config/` without `siteCspMerge()`/`siteCsp()`, `LET OP` for a CSP middleware appended to the global
  stack (it would replace the policy of `/_fsc/h`), and `LET OP` for one in `docker/`, `nginx.conf` or `public/.htaccess`.
- AGENTS.md: the module blocks, `content.links`, the newsletter route, the HTML-code block (isolation, consent, preview) and its CSP in A13,
  the doctor lines, and pointers in the sections `faq`, `newsletter`, the webshop, `appointments` and `events`.

## 0.27.0

- Live preview from the portal's page editor (plan page-builder B, D3 to D8). New global middleware `Laravel\Preview\PreviewRequest` (registered by the provider while `content.preview.enabled`, env `FSC_PREVIEW_ENABLED`, default on, and a server token is set) handles only `POST {content.preview.path}` (default `/_fsc/p`) with the form field `token` (`fscpv_` + 43 base64url characters; never read from the query string). It checks the shape, rate limits per preview token (`content.preview.rate_limit`, default 60 a minute, 429 with `Retry-After` above it) and refused tokens per visitor IP (30 a minute), asks the Hub with `ContentClient::getPagePreview($token)` (`GET /api/v1/pages/_preview`, header `X-FSC-Preview`, 8 s timeout, never cached; `@internal`) and answers a bare 404 on any refusal; any other method on the path is a 404 too. The Hub answer is checked before use: `path` a plain same-site path (no scheme, host, query, `//`, `..` or the preview path itself), `embedOrigin` one exact https origin (http only for localhost outside production), `page` an object with a list of block objects. Then the request scoped `Laravel\Preview\Preview` is activated and the request is rewritten to an anonymous `GET <path>` on the same host (no cookies, body, credentials or query; `session.driver` switches to `array`, every `cache.stores.*` points at a fresh array store and resolved stores are forgotten, and known full-page cache switches such as `responsecache.enabled` go off, so nothing is written to a session and a site or response cache on any store is neither read nor written) and handed to the site's own routes. A same-host redirect from the site (home on `/`, trailing slash, locale prefix) is rendered inside the preview as well, at most two hops; `content.preview.page_path` (a template with `{path}`, `{slug}`, `{locale}`, or an invokable class) maps the Hub path to the site route when the site does not redirect. `ContentClient::getPage($ref)` returns the preview page when `$ref` is its id, the preview path or the page's own `path`/`slug` (`ContentClient::overridePages()`, `@internal`, set for that one request only). On the way out, and again in a `RequestHandled` listener: `preview.js` is inserted right after `<head>` (external, same-origin, with the Vite nonce when set), `data-fsc-preview` on `<html>`, `Cache-Control: private, no-store`, `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer`, and `Set-Cookie`, `ETag`, `Last-Modified`, `Expires`, `X-Frame-Options` and CDN cache headers are removed; every CSP loses its `frame-ancestors` and the first one gets `frame-ancestors <embedOrigin>` (a site without CSP gets only that directive).
- `preview.js` (`GET /_fsc/p/preview.js`): sets `html[data-fsc-preview]` and `window.fscPreviewMode` at once, keeps the token out of the DOM, blocks every form submit (and, when framed, every link click), and talks to the portal with `postMessage` (`{ns: 'fsc-preview', v: 1}`, exact origin and source checks): `ready`, `rendered`, `select`, `error` (with `retryAfter` for `rate_limited`) out; `refresh`, `highlight` in. A refresh re-posts the token same-origin (`credentials: omit`) and swaps the `[data-fsc-preview-blocks]` roots when their count matches (scroll kept), else reloads with a form POST (scroll restored from `sessionStorage`). Works without a parent or opener.
- Preview markup: `<x-content::page-blocks>` adds `data-fsc-preview-blocks` and wraps each block in `<div data-fsc-block="{id or index}" data-fsc-block-index="{index}" style="display:contents">`; `<x-content::analytics>` prints nothing; `<x-content::form>` puts its fields in a disabled fieldset and loads no Turnstile. Outside a preview the markup of both is byte identical to 0.26.0 (pinned by fixtures).
- `Content::isPreview()`: true while the request renders a portal preview, to keep third-party scripts in a layout out of it.
- Every Hub request carries `X-FSC-SDK: php/<version>` (the Hub records the site's SDK version per project to offer only block types and the preview a site can render).
- `<x-content::page-blocks>` has no type list any more: a block renders when a view resolves (`views[type]`, `config('content.blocks.<type>')`, or an existing `content::blocks.<type>`), else it is skipped or goes to the `unknown` view as before. Type names are checked against `PageBlocks::TYPE_PATTERN` (`^[A-Za-z][A-Za-z0-9]{0,39}$`) before any lookup. `PageBlocks::TYPES` stays, deprecated.
- Default views for the two blocks that only describe what to show: `articleList` shows published articles (`status=published`, the block's category, locale and limit) as cards with title, date, excerpt and cover, linked through the SEO `articlePathTemplate` (fallback `/blog/{slug}`); `legalDocument` shows the current version of its `kind` (module `legal`). Both fetch through the cached client (`Laravel\BlockData`, `@internal`) and render nothing on an error, an empty list or a missing document. A site view for either keeps winning, without a Hub call. `content.css` gets the card and legal styles after the unchanged JS SDK base.
- `fsc:doctor`: a `Voorbeeld vanuit het portaal` line (`OK` when on with a server token, `INFO` when switched off, `LET OP` without a server token), `LET OP` when a cookie banner exists but no view, CSS or JS mentions `data-fsc-preview` or `fscPreviewMode`, `LET OP` for an installed full-page cache package (`spatie/laravel-responsecache`, `silber/page-cache`, `renatomarinho/laravel-page-speed`), and `LET OP` for `X-Frame-Options` or `frame-ancestors` in `docker/`, `nginx.conf` or `public/.htaccess`.
- AGENTS.md: A17 "Voorbeeld vanuit het portaal" (what it does, nothing to configure, banner rule, CSP note, kill switch), the banner rule in A10, the preview in A13 and A14, the `pages` section without the type list, two common mistakes. README: "Portal preview" and the `page-blocks` text.
- `examples/laravel`: the cookie banner stays hidden in the preview (CSS rule and an early return in its script); the example passes `fsc:doctor`.

## 0.26.0

- Query parameters kept away from Umami: the Hub config `analytics.stripParams` (`Analytics::stripParams()`: plain names, at most 30, default none; `utm_*` and the ad click ids are never stripped) makes `Analytics::tags()` add `data-before-send="fscUmamiBeforeSend"` and `data-fsc-strip-params` to the Umami tracker tag. The helper script (`fsc.js`) defines `fscUmamiBeforeSend`, which the Umami tracker calls before every send: the page `url` and a same-site `referrer` lose those parameters (pageviews, events, identify, performance). The recorder (heatmaps, replay) has no such hook, so the helper's fetch guard strips them from every URL in its `/api/record` uploads (only after consent, as before). The first-party proxy strips them again server-side (`api/send`: url and same-site referrer; `api/record`: the whole body), so a page where the helper did not run is covered too. The address bar is never changed. Without the setting nothing changes (no attribute, body forwarded byte for byte). `Analytics::stripQuery()` is the shared rule.
- Webhook event type `appointment.rescheduled` in `Webhooks\Events::ALL` (an appointment was moved to another time; ids, status and times only).

## 0.25.0

- `fsa.js` (rebuilt `fsa.min.js`): video tracking (plan analytics-complete I1, feature 37). `data-fsa-video` on a `<video>` sends the `event`s `video_start`, `video_progress` (`percent` 25/50/75, seeks are not counted) and `video_complete` with `label` (the attribute value, else the source path without query) and `player`; a YouTube `<iframe data-fsa-video>` whose `src` has `enablejsapi=1` is followed through the player's `postMessage` channel (no YouTube script is loaded). No consent needed, nothing stored. Outbound links and downloads (feature 27) were already tracked (`outbound`, `download`); only documented now. AGENTS.md: "Video" and "Outbound links and downloads".

## 0.24.0

- `fsa.js` (rebuilt `fsa.min.js`): own-visit exclusion, site search and the 404 hook (plan analytics-complete D13/D14, features 80, 29, 28). `?fsa_exclude=1` stores `localStorage['fsa-exclude'] = '1'` (never a cookie) and the browser then sends nothing (also without a sitekey, so the flag can be set from any page); `?fsa_exclude=0` clears it; the parameter leaves the address bar. `fsc.js` and the first-party Umami tracker (`AnalyticsController::overlayGuard`) stand down for such a browser as well (`window.fsaExcluded`, the stored flag, or the parameter on the first visit). A search parameter (`data-search-params`, default `q, s, search, zoeken`) sends an `event` `site_search` with `term` (trimmed, lower case, max 80 characters, e-mail addresses, phone numbers, IBANs and long numbers replaced); a new term on the same page (SPA) is a new event; the parameter stays out of the page URL as before. `data-fsa-404` on `<html>` or `<body>` (besides `data-status="404"` and the navigation status) sends one `not_found` with `path` and `referrer_host`.
- `Analytics::tags()` renders `data-search-params` from the Hub config `analytics.searchParams` (`Analytics::searchParams()`: plain names only, at most 10, default `q, s, search, zoeken`); the Hub exposes the list in `GET /api/v1/analytics` and takes it from the module config `analytics.searchParams`.
- `fsc:doctor`: new line `errors/404 meldt zich bij de tracker` (`LET OP` when the 404 view has neither `fscStatus`, `status="404"` nor `data-fsa-404`). AGENTS.md: the 404 hook in A12 (with `data-fsa-404`), "Your own visits" (A15) and "Site search".
- `fsa.js` (rebuilt `fsa.min.js`): form tracking for the Hub forms view (plan analytics-complete E3). `data-fsa-form` names a form (before `data-fsc-form`, `data-form-key`, id, name, action path); `form_submit` carries `seconds` since the form's `form_start` (`num.seconds` in the Hub; none when the form was not started on this page or after a day); with analytics consent and without Global Privacy Control an `event` named `form_field` follows the field a visitor enters (`form`, `field` = the name or id attribute cut down to `[a-z0-9_.-]`, `seconds`; at most 40 per form and page view, only on a change of field). Never a field value; password and hidden inputs, names that hold an address or phone number and generated ids are not reported. The collector drops `form_field` without consent. The proxy forwards the `Sec-GPC` request header to the collector, which treats such a batch as without consent. `fsa.track()` refuses the reserved name `form_field`; form state resets on an SPA pageview. AGENTS.md: "Form tracking" and the privacy sentence for the site owner.
- Webhook event types `analytics.report.weekly`, `analytics.alert` and `analytics.alert.resolved` in `Webhooks\Events::ALL` (native analytics monitoring: a weekly summary of the previous ISO week and alerts for a spike, dip, no data or a goal that stops converting; aggregates only, never a session, visitor or IP).
- `fsa.js` (rebuilt `fsa.min.js`): the pageview of a landing that had `?fscn=` sets `nl: 1` (a boolean, never the token; plan analytics-complete D6). The collector turns it into the `newsletter` channel. Only the landing view carries it, not later views of the visit.

## 0.23.0

- New command `php artisan fsc:doctor` (`Laravel\Doctor`, `Laravel\Console\DoctorCommand`): checks a site against the always-required items and prints one Dutch line per check (`OK`, `LET OP`, `FOUT`, `INFO`); exit code 1 on any `FOUT`. It checks `FSC_SERVER_TOKEN` (a server token), `FSC_WEBHOOK_SECRET`, `APP_KEY`, `FSC_API_URL` https, `SESSION_DRIVER` not `cookie`, `APP_URL` https and `URL::forceScheme('https')` (failures in production), the routes `/sitemap.xml`, `/robots.txt` and `/api/health` (dispatched through the app's own kernel, must answer 200 `{"ok":true}`), the webhook and form routes (a view with `<x-content::form>` needs the latter), a catch-all, `RedirectMiddleware` first in the web group, `trustProxies`, the five layout components in the right place in every layout (outside `errors/` and mail views), a cookie banner (`fsc:consent`), `errors/404` and `errors/503`, `fullUrl()` in views, a static `public/robots.txt` or `public/sitemap.xml`, `.env` in `.gitignore`, `fsc_srv_`/`fsc_whsec_` in `resources/`, `public/` or the views, and closures in config (`config:cache`). Read-only and offline; `--online` also asks the Hub once (token, project name, modules that are on, `canonicalOrigin`), `--skip-env` skips the secret checks, `--strict` fails on warnings.
- `fsc:agents` now ends with a pointer to the build order and `fsc:doctor`; the block it writes mentions both.
- AGENTS.md restructured for a builder starting from an empty Laravel app: "Bouwvolgorde voor een nieuwe site", "Altijd verplicht" (A1 to A16: package, env, error handling, https/proxy/sessions, layout, routes, redirects, webhook, forms, cookie banner, legal pages with the privacy-statement sentences per feature, error pages, CSP, what is built in, staff exclusions, hosting), "Als de module X aanstaat" per Hub module (including multilingual sites and hreflang, which the SDK does not render) and "Controleer je site" (doctor, curl checks, a webhook signature test, common mistakes). Outdated bits fixed (install constraint, the guide's version note, robots.txt advice).
- `examples/laravel`: cookie banner partial with `POST /api/consent` (proof via `recordConsent`), `/privacyverklaring` from the legal module (falling back to a Hub page), `errors/404` (extends the layout, analytics status 404) and a standalone `errors/503`, footer links, `/api/health` answers `{"ok":true}`, and `.env.example` has an https `APP_URL`. The example passes `fsc:doctor`.

## 0.22.0

- Chat forwards the account-session handle (plan ootb-a5-account-data S1). `POST /_fsc/i/chat` reads the HttpOnly cookie named by `content.intelligence.session_cookie` (default `fsi_sess`, env `FSC_INTELLIGENCE_SESSION_COOKIE`, empty = off) from the raw `Cookie` header and passes it to the Hub as `session`, under the same conditions as `recognition` (Intelligence on, not an excluded IP, not a staff browser, not a crawler, same site). Only a value of at most 128 characters `[A-Za-z0-9._-]` travels, anything else is dropped; it never reaches page JavaScript. `Chat::open` accepts `session` in its input with the same check. The package never sets the cookie (the site's own portal does) and exempts the name from Laravel's cookie encryption. The Hub ignores `session` until its account-data support is live.
- AGENTS.md: section "Accountgegevens via de chat" with the provider contract (signed call, request and answer shapes) for sites that run their own customer portal.

## 0.21.0

- Newsletter click links (plan ootb-integrations D9). A page view with `?fscn=<token>` (added by the Hub's newsletter redirect `/n/l/<token>` to links to the project's own site) links a CONSENTING browser to the newsletter subscriber: new global middleware `Laravel\NewsletterClickLink` (registered by the provider, a no-op unless `content.intelligence.enabled` is on and the parameter is present) calls `IntelligenceProxy::newsletterClickLater()`, which after the response makes one `ContentClient::linkNewsletterClick($pid, $token)` (`POST /intelligence/link {pid, newsletterToken}`, server token) per browser and token (cached 30 days). Only with the `fsa_pid` cookie; never under `Sec-GPC: 1`, for crawlers and prefetches, staff browsers or `exclude_ips`; silent on failure. The Hub stores a claimed link with source `newsletter` only with Intelligence `recognition` on and never creates a profile.
- `fsa.js` (rebuilt `fsa.min.js`) and `fsc.js` remove `fscn` from the address bar with `history.replaceState` at load, before the first pageview and before the landing URL is kept for attribution; other parameters and the fragment stay.
- Webhook event type `newsletter.link.clicked` in `Webhooks\Events::ALL` (data: `campaignId`, `linkHash`; never the subscriber, address or browser).
- AGENTS.md: section "Newsletter click links" with the privacy statement text site owners must add, and the rules to load fsa.js before other analytics and never to use the full request URL (with `fscn`) in canonical, og:url or share links.
- Product recommendations as ids (plan ootb-integrations C9, D12). `Products::recommended(?string $pid = null, int $limit = 8, array $options = [])` returns product ids ranked in the Hub (`GET /api/v1/products/recommended`, server token): the hot topics of the consenting visitor mapped to the shop's product categories first, then the best sellers of the last 30 days, then the newest products; only active products that can be bought. `Products::recommendations()` returns the whole answer (`active`, `source`, `ids`) and `Products::recommendedProducts()` hydrates the ids with `get()` (a product that disappeared or is out of stock is skipped; the reads run one after the other). Only a well-formed browser id is sent, in the header `X-FSI-Pid` (never in the URL); option `gpc: true` forwards `Sec-GPC: 1`, `locale` picks the language, `limit` is clamped to 1..24. Not cached by the SDK (the Hub caches per topic set for 60 s, never per browser id). With Intelligence off the Hub answers `active: false` with the fallback ids. Blade example in `AGENTS.md`.
- Marketing consent of a member (plan ootb-integrations D11). `Members::getMarketingConsent(['session' => …])` (`GET /members/me/marketing`) returns `{marketingOptIn, marketingOptInAt}` (`null` = the member never chose) and `Members::setMarketingConsent(['session' => …, 'marketingOptIn' => true|false])` (`PUT /members/me/marketing`) stores the member's own choice (`changed: false` when nothing changed). Show it as an unticked checkbox on the account page; never set it on the member's behalf. The Hub only sends marketing messages, such as the abandoned-checkout reminder, to a member who opted in or to a confirmed newsletter subscriber.
- Webhook event type `order.abandoned` in `Webhooks\Events::ALL` (an unpaid checkout older than the shop's `abandonedReminder.afterMinutes`; data: `ref` (order id), `number`, `recognized`, `consent` (`newsletter`, `member` or `null`), `channel` (`email`, `whatsapp`, `both` or `null`) and `whatsappConsent` (always `false`: newsletter or member consent covers e-mail only); never personal data). Member consent only counts for the member's own verified address, and every reminder has a one-click opt-out.

## 0.20.0

- Chat and FullStack Intelligence (plan ootb-integrations D4/D5). `Chat::open` takes optional `pid` (the consented `fsa_pid` browser id) and `recognition` (the recognition cookie value) in its input; the Hub links the conversation to the visitor's profile and VERIFIED identity (module intelligence with `recognition`; a bad value is ignored, never an error).
- New route `POST {content.intelligence.path}/chat` (default `/_fsc/i/chat`, registered while `content.intelligence.enabled` is on; `ChatOpenController`): the chat widget opens its conversation through the site with `<fsc-chat token="fsc_pub_…" open-url="/_fsc/i/chat">`. The site adds the consented browser id and the HttpOnly recognition cookie server-side (never under `Sec-GPC: 1` for the pid, never for crawlers, staff browsers or `exclude_ips`), calls the Hub with its server token, the visitor IP and an optional `X-FSC-Member-Session`, and passes the Hub's answer through (`Cache-Control: no-store`). Another site (`Origin` or `Sec-Fetch-Site`) gets 403. Every later chat call goes straight to the Hub with the conversation token. A widget without `open-url`, or a site without this route, opens at the Hub as before.

## 0.19.0

- Conversion events for the Hub's default goals and funnels (plan ootb-integrations C2). Three Blade components print one hidden marker that the analytics helper (`fsc.js`) fires once per page through every active tracker, without consent and without personal data:
  `<x-content::begin-checkout :items :value />` (event `begin_checkout`, checkout page), `<x-content::appointment-booked :service :reference page />` (event `appointment_booked`, after a successful booking; `page` is the booking page path, sent as `booking_path`, default the current path) and `<x-content::purchase :order />` (revenue event `purchase` with the order number, from `Orders::get` or `number`, `total-cents`, `currency`; nothing is printed for a zero total). A reference (booking id, order number) makes the event once per browser session too, so a reload of the confirmation or thank-you page does not count twice. Nothing happens while the analytics module is off.
- `fsc.js`: `window.fscAnalytics.revenue(amount, currency, props)` (native `fsa.revenue`, and on Umami `track(name, {revenue, currency})`), `window.fscAnalytics.scan()` (fires markers added later) and the marker scan itself; a marker waits for a tracker that loads late.
- `Laravel\ConversionMarker`: builds the marker; props pass a safe-key and no-`@` filter (names, e-mail, phone, address and note keys are refused). Documented for custom sites in `AGENTS.md` and `docs/client-integration.md`.
- Webhook event types `intelligence.visitor.warm` and `intelligence.visitor.signal` in `Webhooks\Events::ALL` (FullStack Intelligence for automations; data: `profileRef`, `warmth`, `stage`, `topic`, `signal`, `count`, `windowDays`, `known`, `strength` and the identity kind/source/ref of a verified recognition; never personal data).

## 0.18.0

- Member recognition cookie. `Laravel\MemberRecognition` turns the `recognition: {cookie, value, maxAge}` of the member login answers (`login`, `loginTotp`, `refreshSession`; `null` on `logout` and `logoutAll`) into the cookie `content.intelligence.recognition_cookie` on the site's own domain: HttpOnly, Secure on https (request or `app.url`), SameSite=Lax, path `/`, `maxAge` from the answer (capped at 400 days), domain from the new `content.intelligence.recognition_cookie_domain` (`FSC_INTELLIGENCE_RECOGNITION_COOKIE_DOMAIN`, empty = this host). API: `cookie($answer, $request)` (null when `recognition` is absent, null or malformed), `forget()`, `fromAnswer()` (set, remove or nothing), `queue($answer, $request)` (cookie jar, never throws), `pid($request)`, `cookieName()`. Crawlers and prefetches never get the cookie. The provider exempts the cookie from `EncryptCookies`, so the Hub and the proxy read it as plain text.
- `Members::login`, `loginTotp`, `verifyEmail` and `refreshSession` take an optional `pid` in their call options (the consenting browser's id, only a well-formed id travels) and send it in the body; the Hub links the browser to the member at once. Without it the requests are what they were.
- `IntelligenceProxy::pid($request)`: the consented `fsa_pid` cookie, read from the raw `Cookie` header; null under `Sec-GPC: 1`, for crawlers and prefetches, and for a malformed value. `<x-content::form>` (FormController) adds it as `_meta.pid` only when it is present, so a submission with an e-mail address links the visitor (Hub forms linking).
- Default of `content.intelligence.recognition_cookie` is now `fsi_rec` (was empty; the Hub verifies per project). Set `FSC_INTELLIGENCE_RECOGNITION_COOKIE=` (empty) to switch the cookie off. A site that never calls the helpers sets no cookie; the proxy and the server-side slots only read `fsi_rec` when it exists. The identify call stays once per `identify_ttl` per browser and cookie.
- Out of the box: the SDK ships no member routes of its own, so the member calls do the work. Inside a Laravel request `Members::login/loginTotp/verifyEmail/refreshSession` take the pid from the request (explicit `pid` option wins, `false` = none) and the answers of `login`, `loginTotp`, `confirmTotp` (setup token), `refreshSession`, `logout` and `logoutAll` queue the recognition cookie themselves (option `queue: false` opts out). Switch: `content.intelligence.member_recognition` (`FSC_INTELLIGENCE_MEMBER_RECOGNITION`, default true). Queuing twice is harmless. Most sites need zero code; only a fully custom HTTP client uses the helpers.
- The Laravel example has a `MemberController` with the login and logout code. Site owners must name recognition in their privacy statement (AGENTS.md, section "Member recognition and form linking") and the Hub project needs Intelligence with `recognition: true`.

## 0.17.1

- Devmode switch: the redirect after `?fsc_devmode=1|0` is relative (path plus the remaining query), so a site behind a proxy or CDN that sees the request as http never downgrades to http; the cookie is Secure when the request is secure or `app.url` is https.

## 0.17.0

- Dev panel for every site, OFF for everybody until a browser asks for it. `?fsc_devmode=1` on any GET page sets the cookie `fsc_devmode=1` (a year,
  path `/`, SameSite=Lax, Secure on https) and 302s to the same URL without the parameter (`Cache-Control: no-store`); `?fsc_devmode=0` clears it. Only for
  a request with that cookie (and not a crawler or prefetch) `<x-content::head />` (or the new `<x-content::devmode />` for a layout without it) writes two
  tags into the `<head>`: a blocking `flags.js` and a deferred `devmode.js`, both served by the SDK under `content.devmode.path` (default `/_fsc/d`). Without the
  cookie the HTML has no devmode bit at all. A page that got the tags is sent `Cache-Control: private, no-cache` (same mechanism as a server-decided slot); a
  CDN in front must skip its cache when the cookie header contains `fsc_devmode=1`. The middleware that handles the switch is registered globally by the provider.
- The panel (`resources/assets/fsc-devmode.js`): a pill "Dev" that opens a card (a bottom sheet on a phone) with three sections. Bezoeker: browser id with a
  copy button ("Gekopieerd"), consent, cookie `fsa_pid`, and the Intelligence profile of `POST /_fsc/i/me` (only while `content.intelligence.enabled`) with
  an "Open in portaal" link built from `content.devmode.portal_url` (`{ref}` placeholder; no link when empty). Weergave: switches for Reduced motion,
  Save-Data, Geen WebGL and Zwak toestel, stored in localStorage `fsc-dev-flags` and applied on the next load by `flags.js` (this browser only): `<html data-fsc-motion="reduce">`,
  `data-fsc-save-data`, `data-fsc-no-webgl`, `data-fsc-weak`, `window.fscDev.has(name)`, plus overrides of `matchMedia('(prefers-reduced-motion: reduce)')`,
  `navigator.connection.saveData`, `deviceMemory`, `hardwareConcurrency` and WebGL contexts, and an end to all CSS animations and transitions for reduce. Deze pagina: path,
  language, SDK version and the slot decisions (`data-fsi-*`). Dutch texts, English under `<html lang="en">`.
- Extension point: `content.devmode.scripts` lists same-origin scripts the panel loads; they call `(window.fscDevExtensions = window.fscDevExtensions || []).push({ id, title?, flags?, mount?, render? })`
  to add switches (stored with the others, read through `fscDev.has(id)`), a section or a hook (see the header of `fsc-devmode.js`).
  `content.devmode.aliases` adds console names for `devmode()` (defined only while the panel is loaded; `devmode(false)` turns the mode off, same as the footer button).
- Position: top right on desktop (below `--fsc-dev-top`, default 12px, and stopping `--fsc-dev-bottom` above the bottom edge, default 12px), a bottom sheet raised
  by `--fsc-dev-bottom` on a phone and a pill `--fsc-dev-pill-bottom` from the bottom: a site sets these three CSS variables so its floating buttons stay free.
- Config `content.devmode`: `enabled` (env `FSC_DEVMODE_ENABLED`, default on), `cookie`, `path`, `portal_url`, `scripts`, `aliases`.

## 0.16.0

- `fsa.js`: the consented browser-id cookie `fsa_pid` can live on a parent domain: config `content.analytics.pid_cookie_domain`
  (env `FSC_PID_COOKIE_DOMAIN`, e.g. `.fullstackstudio.nl`), rendered as `data-pid-domain` on the tracker script. Default empty = host-only,
  exactly as before. The domain is only used when the page's host lies under it (else host-only); a malformed value is ignored. Revoking
  consent, Global Privacy Control or removing the id deletes the cookie with the same domain AND a host-only copy (left over from before the
  domain was set); setting it with a domain drops that host-only copy first. Purpose: a sibling host (FullStack Studio's customer portal) reads
  the id at registration or login so the earlier FullStack Intelligence profile is linked to the account at once. A site that sets it says so
  in its privacy statement. `fsa.min.js` rebuilt.
## 0.15.0

- `ContentClient::intelligenceMe(?string $pid, ?string $recognition = null)`: the visitor's own FullStack Intelligence profile
  (`POST /api/v1/intelligence/me` on the Hub, server token, read-only, never returns the persistent id).
- `POST /_fsc/i/me` (new, registered while `content.intelligence.enabled` is on, same prefix and no-middleware setup as `/_fsc/i/decide`): reads the
  browser id from the consented `fsa_pid` cookie (never under Sec-GPC) and the recognition cookie, asks the Hub and answers
  `{ consent, profile, recognized }` (+ `unavailable: true` when the Hub could not answer). Crawlers get the empty answer. `Cache-Control: no-store`.
  Meant for a site's dev panel (`devmode()`); a visitor only ever sees their own profile.

## 0.14.0

- FullStack Intelligence slots are now decided ON THE SERVER, so a visitor never sees the default jump to a variant (the browser path swapped after paint
  and flickered on the home page). Nothing to add to a layout: a site only needs this version. Applies to sites with
  `content.intelligence.personalization` on; with it off nothing changes.
  - `<x-content::slot>`: when the request carries a SIGNAL (a same-site `Referer` on another path, the portal recognition cookie
    (`recognition_cookie`), or the consented browser-id cookie `fsa_pid`) the SDK asks the Hub ONCE per request for all slots of the page
    (`ssr_timeout_ms`, env `FSC_INTELLIGENCE_SSR_TIMEOUT_MS`, default 250) and renders the variant (fields, links, the variant's own markup or
    `hide`) straight into the HTML. Without a signal there is no Hub call and the default renders: zero added latency. Same control-group bucket,
    answer cache and 10 s breaker as the browser path, so the groups are identical; a Hub that is away or slow leaves the default.
  - The slot names of a page are learned (cache, per path): the first signal-carrying view of a page that was never rendered before decides only the
    slots already seen, the next view all of them (one call). A slot not part of the call renders its default.
  - A server-decided slot is marked `data-fsi-variant`, `data-fsi-group` (`personal`, `control`, `customer`, `none`) and, for an applied variant,
    `data-fsi-fields`. `slots.js` never asks for nor swaps such a slot, it only exposes it (`fsiSlots.get`) and measures `fsi_slot_view` /
    `fsi_slot_click` with the server's variant and group.
  - 🚨 Caching: a response that used a signal gets `Cache-Control: private, no-cache` (set after the whole middleware stack through the
    `RequestHandled` event, over the site's own `public, s-maxage`; `Expires`, `Surrogate-Control` and `CDN-Cache-Control` are removed). Default
    responses keep their headers. The HTML must therefore NOT be cached at the edge for personalisation to reach visitors; a site that does cache HTML
    sets `FSC_INTELLIGENCE_CLIENT_DECIDE=true` (config `client_decide`, default off) to keep the old browser path for slots the server did not decide,
    or `FSC_INTELLIGENCE_SSR=false` for the old behaviour only.
  - `fsa.js`: with consent the browser id is mirrored into a first-party cookie `fsa_pid` (SameSite=Lax, Secure on https, 13 months, path `/`),
    created together with the id and removed at once when consent is revoked, under Global Privacy Control or when the id is removed. The server
    reads it only as the `pid` of a decision (its presence implies consent); the Hub contract is unchanged. `fsa.min.js` rebuilt. The cookie is read
    from the raw `Cookie` header, so Laravel's cookie encryption does not get in the way. Sites list cookies for the privacy statement: `fsa_pid` is
    an analytics cookie, set only after analytics consent.
  - Hardening: learned slot lists are stored per route pattern after a 2xx response only (404s write nothing; renamed slots drop out); the server
    path shares the `/decide` rate limit (120/min per IP, over it = default); a referrer only counts when it maps to a real route and such answers
    are not cached; nested `<template x-if/x-for>` inside a variant is kept intact; `fsa_pid` is ignored under `Sec-GPC: 1`; Hub answers slower than
    150 ms count toward the breaker; the header is `private, no-cache` (keeps bfcache); optional middleware `PrivatiseSlotResponses` for streamed
    responses and server-side response caches.
  - No cloaking: crawlers, bots (Googlebot, Bing, link previews, AI crawlers, Lighthouse, curl and the like), headless browsers, requests without a
    User-Agent and prefetch/prerender requests (`Sec-Purpose` / `Purpose`) are never personalised, on the server (`Crawler`: no Hub call, default HTML,
    headers untouched, also the `/decide` route) and in the browser (`navigator.webdriver` or a bot user agent: no ask, no swap, no measurement).
    A site appends its own regex fragment with `content.intelligence.crawler_pattern` (env `FSC_INTELLIGENCE_CRAWLER_PATTERN`).
- `content.intelligence.decide_timeout_ms` default 50 -> 400 (50 ms was shorter than a real round trip, every request fell back).
  `swap_deadline_ms` stays 1500 and only matters for the browser path.

## 0.13.0

- FullStack Intelligence personalisation (plan analytics-engine B.7, phase 7), OFF by default (`content.intelligence.personalization`, env
  `FSC_INTELLIGENCE_PERSONALIZATION`, on top of `FSC_INTELLIGENCE_ENABLED`). With it off nothing changes: a slot renders exactly its element with
  the default content, without attributes, templates, script or route.
  - `<x-content::slot name="…" as="div" :data="false">` and `<x-content::variant key="…">`: the default content always stays in the HTML (SEO, edge
    cache, no layout shift); variants are inert `<template>`s; `data-fsi-field` / `data-fsi-href` take the plain-text fields of a variant.
  - `GET /_fsc/i/slots.js` (new, `resources/assets/fsi-slots.js`): asks the site after the page loaded and swaps only when the page cannot move
    (a slot below the viewport may change height; in or above it only keep or lose height, the lost height stays reserved) and only within
    `swap_deadline_ms` (1500). Late or refused swaps are measured as `fallback` in the personalised and the control group alike. Measures
    `fsi_slot_view` / `fsi_slot_click` through `fsa.track` (queued until the tracker is there). API `window.fsiSlots` and event `fsi:slots`.
    Nothing runs in heatmap overlay mode. CSP-safe (CSSOM, textContent, no innerHTML).
  - `POST /_fsc/i/decide` (new, no session/cookies/CSRF): asks the Hub (`ContentClient::decideSlots()`, `POST /api/v1/intelligence/decide`) within
    `decide_timeout_ms` (50), caches the answer `decide_ttl` (60 s), applies the control group with a stable bucket (with consent from the
    tracker's browser id; without consent from a keyed daily hash of IP and user agent, nothing stored) and never holds back the functional
    customer variant. The browser id is forwarded only with consent; the portal recognition cookie always (the Hub uses it only as "is a
    logged-in customer"). Any problem answers `{d: {}}`: the default stays. 120 requests per minute per visitor IP. `Cache-Control: no-store`.
  - Needs Hub routes of Intelligence phase 7; an older Hub answers 404, which keeps the default.
  - Control group PER SLOT (the bucket hashes the slot key too): every slot has its own independent control group.
  - Only anonymous decide answers are cached (no browser id, no recognition cookie); a failure, timeout or 429 of the Hub opens a 10 s
    circuit breaker per site. The page language (`<html lang>`) goes along (`ContentClient::decideSlots(..., $locale)`).
  - Field variants change the existing nodes in place (text, links), so listeners and animations survive; `fsiSlots.chatMessage(name)` measures
    the first chat message of a chat slot (`fsi_slot_chat_message`).
- `CurlTransport`: on PHP 8.5+ a persistent curl share (connections, DNS, TLS sessions), so a PHP-FPM worker keeps its connection to the Hub.

## 0.12.0

- FullStack Intelligence (plan analytics-engine part B, phase 6), OFF by default (`content.intelligence.enabled`, env `FSC_INTELLIGENCE_ENABLED`):
  the native proxy marks visits from `exclude_ips` with the property `fsi_ex` and, for a consenting browser only, calls the new
  `ContentClient::identifyVisitor()` (`POST /api/v1/intelligence/identify`, server token) after the response, optionally with the portal recognition
  cookie named in `recognition_cookie`. Nothing changes for a site that does not switch it on.
- `fsa.js`: `fsa.pid()` returns the browser id with consent (null without consent or under Global Privacy Control), for site forms that link a
  submission to the visitor.
  In heatmap overlay mode `fsa.pid()` is null and nothing is sent, so a staff view never identifies or links anyone.
- The native proxy also marks a browser with the admin's staff cookie (`staff_cookie`, default `fsi_staff`) as `fsi_ex` and never identifies it.

## 0.11.0

- Heatmap overlay (plan analytics-engine, phase 4 reports). The portal opens a page in its own window (390/820/1280 px for mobile/tablet/desktop) with
  `#fsa_overlay=<token>`: a short-lived (10 min) token the Hub mints for one page, viewport class and period of this site's project.
  - `fsa.js`: with that fragment the view is not measured at all (no pageview, heatmap click, heartbeat or signal; nothing stored) and
    `/_fsc/a/overlay.js` is loaded instead.
  - `GET /_fsc/a/overlay.js` (new, `resources/assets/fsa-overlay.js`): removes the token from the address bar, posts it to the site and draws the heat over the
    CURRENT box of each element (`querySelector` + `getBoundingClientRect`, so it survives layout changes), with click counts per element, rage/dead marks,
    scroll-reach lines every 20% and a panel (consent share, legend, a warning when the window width or the page does not match, elements that are gone).
    CSP-safe: CSSOM styles, `textContent`, no inline `<style>`, no `innerHTML`, no eval.
  - `POST /_fsc/a/overlay` (new, no session/cookies/CSRF): checks the token shape, 30 requests per visitor IP per minute, forwards it with the server token to the
    Hub (`ContentClient::getAnalyticsOverlay()`, `@internal`, header `X-Fsa-Overlay`, never cached) and returns the aggregates (elements with 2+ visits,
    clicks per cell of a 10 × 10 grid over each element, scroll reach), 403 when the Hub refuses the token, 502 on an outage. `Cache-Control: no-store`.
  - The token leaves the address bar synchronously in `fsa.js` (before any other script can read the URL) and reaches `overlay.js` in memory
    only. `fsa.js` sets `window.fsaOverlayMode`; `fsc.js` then loads no recorder, keeps no attribution and counts nothing (its API stays as no-ops),
    and the first-party Umami `script.js` and `recorder.js` are served wrapped in a guard that also checks the fragment (that tag runs before
    `fsa.js`): the staff view is counted nowhere and never replayed. In `direct` mode Umami cannot be wrapped (the overlay is a native-engine feature).
  - The token is single use and lives 10 minutes; open the heatmap again from the portal for a new one.
  - `POST /_fsc/a/overlay` refuses a declared `Content-Length` above 2 KB before reading the body.
  - Needs Hub routes of analytics phase 4 (`GET /api/v1/analytics/overlay`); an older Hub answers 404, which the overlay shows as an expired link.

## 0.10.2

- `fsa.js` (plan analytics-engine, phase 4 ingest): scroll map, frustration signals and richer heatmap clicks. Needs the matching collector release
  (schema `0008_heatmap_frustration.sql`); an older collector drops the new event types as invalid and keeps counting everything else.
  - `scroll_max`: one event per page view with the deepest point reached, rounded down to 10% (sent at `pagehide` and on SPA navigation, not when the tab is
    merely hidden; a bfcache restore starts a new measurement; cookieless). A page that fits the screen counts as 100. The 25/50/75/100 `scroll` marks and the
    heartbeat depth stay.
  - `frustration` (cookieless), name `rage_click` (3+ clicks within 700 ms and 30 px), `dead_click` (click on a non-interactive element, no DOM change or
    navigation within 1 s; not while text is selected), `error_click` (a JS error within 1 s after a click) and `quick_back` (back within 5 s, via the Navigation
    API; without it a popstate to another page). Max 20 per page view. **Without consent a signal is its name only**; with consent it also carries `sel` (the
    element selector) and, for `error_click`, `p.err` (error class) and `p.eh` (8-character hash of the message before its first `:`, personal data removed, mixed
    with the sitekey; never the message, URL or stack; residual risk: a guessable 32-bit hash of a generic message).
  - Heatmap clicks (still consent only) carry the position in the document (`px`, `py` as 0..1) and its height (`dh`), at most 10 per element and 100 per
    page view, keyboard/script clicks are skipped; rage bursts are `hk: rage`, dead clicks `hk: dead`. Rage and dead detection run without consent too (as `frustration` events).
    Selectors prefer `#id`, then an allow-listed data attribute (`data-fsa-id`, `data-testid`, ...), then a short tag.class path with `:nth-of-type`;
    hashed class names are skipped. No element text, ever.
  - `POST /_fsc/a/e` still strips heatmap clicks without consent, and removes `sel` and `p` from frustration signals; `scroll_max` passes (cookieless).
  - Selectors follow a fixed grammar (tag, `#id`, `.class`, allow-listed `[data-x="v"]`, `:nth-of-type(n)`, joined by ` > `, max 4 parts); the tracker and the collector both reject anything else.
  - Minified tracker grew about 1.2 kB gzipped (6.2 kB).

## 0.10.1

- `fsa.js` sends a pageview at once (next tick) instead of after the 1 s batch window, so a visit that ends within a second, or a page
  that is killed without `pagehide`, still counts. Other events keep the 1 s batching.

## 0.10.0

- Native analytics engine (Hub module `analytics`, `engine: native|both`, plan analytics-engine phase 1). `<x-content::analytics />` picks the tracker(s)
  from the Hub: `umami` (default, also for a Hub without the field), `native` (the new tracker `/_fsc/a/fsa.js`) or `both` (Umami and native side by side,
  for the double-measure period). `fsa.js` (no dependencies, no cookies): pageviews incl. SPA navigation, engaged time, scroll depth 25/50/75/100,
  outbound links, downloads, form start/submit, 404 (`status="404"` on the component), `fsa.track(name, props)`, `fsa.revenue(amount, currency, props)`
  `data-fsa-event` / `data-fsc`, and Web Vitals (LCP, INP, CLS, FCP, TTFB; once per page load). Served minified (`fsa.min.js`, built by
  `scripts/build-fsa.sh`). A persistent visitor id (`localStorage` `fsa-id`) and heatmap clicks only after consent (same banner contract; never with Global
  Privacy Control for the id; deleted on load without consent); e-mail addresses, phone numbers and secret-looking path segments (`[token]`) are
  removed from paths, referrers, titles and props; paths max 500 characters; only utm/click id parameters stay in URLs.
- `POST /_fsc/a/e`: checks the sitekey, strips the persistent id and heatmap clicks without consent, signs the visitor IP with the per-site key from the
  Hub (`X-Fsc-Client-Ip`, `X-Fsc-Ts`, `X-Fsc-Sig`) and forwards to the in-cluster collector (`content.analytics.collector_url`,
  `FSC_ANALYTICS_COLLECTOR_URL`; `collector_timeout_ms`). Nothing is forwarded without a usable visitor IP (the collector refuses unsigned
  batches). Always `202 {}` to the browser. Registered in every mode, no session, no cookies.
- `getAnalyticsConfig()` never returns `native.signingKey`; the proxy uses the internal `getAnalyticsProxyConfig()`.
- `Analytics::engine()`, `nativeSitekey()`, `nativeSigningKey()`, `signHeaders()`; `Analytics::csp()`/`mergeCsp()` take `$native` (direct mode adds `'self'`);
  `AnalyticsSettings::engine()`, `nativeSitekey()`, `nativeSigningKey()`, `collectorUrl()`. `window.fscAnalytics.track()`/`setConsent()` reach the native
  tracker too. With engine `native` the Umami proxy no longer forwards (no website id).
- Needs the matching Hub release (`GET /api/v1/analytics` with `engine` and `native`). An older SDK keeps working on `umami` and `both` (Umami only) and
  renders nothing for `native`.

## 0.9.0

- Hub rich editor support. `<x-content::article-body>` and the `richText` and `columns` blocks of `<x-content::page-blocks>` print one small inline
  `<style>` (once per request, only when the HTML holds an `fsc-rt-*` block, a native `table` or an `hr`) with default styles for those blocks.
  The rules sit in the cascade layer `fsc-rich`, all selectors in `:where()` (specificity 0), fonts and colours are inherited, so a site's own CSS wins.
  `<x-content::head />` now always prints `<style>@layer fsc-rich;</style>` first (also with the module off), which ranks the layer below the site's layers
  (Tailwind v4) when `head` comes before the stylesheet in `<head>`; unlayered CSS wins either way. Accents follow `--fsc-accent` and the
  optional `--fsc-rt-info|success|warning`. The nonce defaults to Laravel's Vite CSP nonce (`nonce` prop on `article-body`). HTML without such content renders
  exactly as before. New `Site::richCss()`, `Site::hasRichBlocks()` and `Laravel\RichStyle`. Needs the matching Content release (its article and page sanitizer
  now keeps `hr`, `table`, `thead`, `tbody`, `tr`, `th`, `td` with `colspan`/`rowspan`/`scope`, and `tel:` links).
- `FormController`: on `SESSION_DRIVER=cookie` the flashed old input is capped (300 characters per value, 900 in total), so a failed submit with a long message cannot push the Set-Cookie past nginx's fastcgi buffer (502 "upstream sent too big header"). Server-side session drivers keep the full input. Use `SESSION_DRIVER=file` for sites.

## 0.8.0

- Site standards (Content module `site`). `getSite()` (`GET /api/v1/site`, any token, tag `content:site`; the webhook event `site.updated` clears it)
  and two layout components: `<x-content::head />` (verification metas for Google, Bing, Facebook and Pinterest, favicon, apple-touch-icon,
  theme-color, LocalBusiness JSON-LD, the customer's own head code) and `<x-content::body-end />` (own body code, and with click events on and the
  analytics module active a delegated listener that sends `contact-click` for tel:, mailto: and WhatsApp links to Umami). They never throw: a failure
  renders the last good answer or nothing (one warning, a 15 s pause), the module being off renders nothing (debug log only). One lookup per request.
- Own code with consent `necessary` is printed with the page nonce on every `<script>`/`<style>` (script text is never touched); other categories
  are inert `<template data-fsc-code>` elements activated by an inline loader once the visitor accepted that category (`localStorage['fsc-consent']`
  and the `fsc:consent` event, the same contract as analytics; `{choice, categories}` is now understood by the analytics helper too).
- Consent-gated scripts run one at a time in source order (an external script without `async` is awaited, at most 15 s), so an inline init after
  its library works. Click events send mailto: and WhatsApp hrefs without their query. The last good site answer is only written to the cache
  when its version changes.
- JSON-LD is encoded with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`.
- `<x-content::meta>` (and `Seo::meta()` with the new `fallbackImage` option) falls back to the site's share image when the page and the SEO
  settings have none.
- CSP: `Content::siteCsp()` / `siteCspMerge($policy)` (string or array) and `Site::csp()` / `Site::mergeCsp()`. A missing directive starts from
  `default-src`, so merging never narrows a policy; an existing `script-src-elem`/`style-src-elem` is extended too. The nonce is only added to
  real `<script>`/`<style>` start tags (never inside attribute values, comments or tags such as `<script-x>`).
- Always in every site, also with the module off: `Sitemap::response()` no longer throws (module off = your `extra` entries, Content down = 503 with
  `Retry-After`, no `canonicalOrigin` = the app's own origin); new `Robots::response()` for `robots.txt` (allows crawling when the module is off
  or Content is down; `Disallow: /` only when `robotsIndex` is off); the redirect lookup pauses 60 s on
  `module_disabled` without calling `onError`; `ContentException::isModuleDisabled()`. AGENTS.md has a new first section "Altijd in elke site".
- `ContentClient::VERSION` was still `0.6.0`; it is now `0.8.0`.

## 0.7.0

- Dutch address autocomplete (PDOK Locatieserver, through Content; any token, no module). `suggestAddresses($q, $limit = 8)` and `lookupAddress($postcode, $number)` on the client,
  and `<x-content::address-field name="address" />`: one search field with suggestions (debounced, keyboard support), a one-line summary with "Change", and "Enter manually"
  with separate street, number, addition, postcode, city and country fields as the fallback (the only UI without JavaScript). The inputs are plain inputs of your own form;
  `names`, `value`, `geo`, `countries`, `labels`, `locale`, `styles` and `nonce` configure it. Postcode + number in manual mode fills an empty street and city.
- The browser never sees a token: it asks the site's own routes `GET /_fsc/address/suggest|lookup|field.js|field.css` (`content.address.*`, `FSC_ADDRESS_ENABLED`, `FSC_ADDRESS_PATH`,
  `FSC_ADDRESS_RATE_LIMIT`; no session or cookies, rate limit per visitor IP, fail-soft). Needs Content with the `v1.address` routes (`GET /api/v1/address/suggest|lookup`).
- `Address` helpers (labels nl/en, field names, postcode and query normalisation).

## 0.6.0

- Analytics (Content module `analytics`, Umami). `getAnalyticsConfig()` and `<x-content::analytics />`: the cookieless tracker (pageviews and events without
  consent) and a helper script (`/_fsc/a/fsc.js`, shipped in `resources/assets/fsc-analytics.js`) that starts heatmaps and session replay only after cookie
  consent (`fsc:consent` event or the `fsc-consent` storage key), blocks every replay upload after revoking, and keeps first-touch attribution (utm, ad click ids,
  landing URL, external referrer).
- First-party mode by default: `/_fsc/a/script.js`, `recorder.js`, `api/send`, `api/record` and the recorder settings are proxied to the analytics host
  (visitor IP and user agent forwarded, no cookies, no session, short timeout, rate limit per IP, only the site's own website id). `FSC_ANALYTICS_MODE=direct` opts out.
- `Analytics::csp()`/`mergeCsp()` and `Content::analyticsCsp()`/`analyticsCspMerge()` for the Content-Security-Policy.
- Forms: `<x-content::form>` sends the first-touch attribution as `_meta` (`Forms::attributionMeta()` for your own forms).
- Redirects keep the utm and ad click id parameters also when the target has its own query or is absolute.
- Error monitoring (GlitchTip) for the Content module `errors`: the DSNs come from Content (`GET /api/v1/project/errors`, cached 10 minutes, the last good
  answer survives an outage). Backend exceptions are reported through Laravel's exception handler (`reportable`), after the response; the browser reporter
  is `<x-content::errors />` with `GET /_fsc/e/reporter.js` and the first-party tunnel `POST /_fsc/e/envelope`. Events are rebuilt from an allowlist, messages
  go through the scrubber of fss-core (shared test vectors). No new dependencies (a minimal envelope sender instead of sentry-laravel). See AGENTS.md.

## 0.5.2

- Laravel 13 support: `illuminate/support|http|cache` constraints widened to `^11.0|^12.0|^13.0` (and `orchestra/testbench` to `^11.0`). Tests pass on Laravel 12 and 13; no code changes were needed. Laravel 11 stays supported.
- Example site (`examples/laravel`) now runs on Laravel 13 (PHP 8.3+).

## 0.5.1

- `submitForm` leaves out an empty list, like the JS SDK (an optional file field without files no longer breaks a multipart request; in JSON
  the field is no longer sent as `[]`).
- Multipart: field and file names lose quotes, backslashes and every control character, so a name can never end its header parameter or line.
- Example image (`examples/laravel`): uploads were capped at 2 MB. The limit is now `FSC_UPLOAD_MAX_MB` (default 20, 1-55) and sets nginx
  `client_max_body_size`, PHP `upload_max_filesize`/`post_max_size` and `memory_limit` together.

## 0.5.0

- Hardening: the Laravel `FormController` no longer drops uploads that PHP rejected (`UPLOAD_ERR_*`, new `Forms::uploadErrors()`): size errors give the `fileTooLarge` label, other errors
  the general error, and a POST emptied by `post_max_size` is never submitted. `Request::multipartBody()` strips `\` from file names and falls back to `application/octet-stream`
  for a content type that is not a plain `type/subtype`.
- Form file uploads. `submitForm` accepts `SplFileInfo` (incl. Laravel/Symfony `UploadedFile`), `Http\UploadFile` or a list of them as the value of a `file`
  field and then sends `multipart/form-data` (text parts, `_meta` as JSON, repeated file parts); without files the request is unchanged JSON.
  New `downloadSubmissionFile($formKey, $submissionId, $fileId)` (server token) returns the bytes, content type and file name.
- `CurlTransport` now builds the multipart body with the same encoder as `Psr18Transport` (`Request::multipartBody()`) instead of `CURLStringFile`.
- Laravel: `FormController` forwards the uploads of the form's file fields (413/415 give a message), and `<x-content::form>` renders `type="file"`
  (`accept`, `name[]` + `multiple`, `enctype`). New helpers `Forms::acceptAttribute()`, `Forms::hasFileField()`; `Forms::values()` takes the uploaded files as a third argument.

## 0.4.1

- `AGENTS.md` (integration guide for AI coding agents, English) and `CLAUDE.md` (imports it). `php artisan fsc:agents` writes an idempotent marked pointer block into the
site's `AGENTS.md` and adds `@AGENTS.md` to an existing `CLAUDE.md`.
- All remaining modules of the JS SDK, on the same core: `products`, `cart`, `orders`, `payments`, `discounts`, `shipping`, `members`
(login, two-factor, account flows, admin), `customers`, `appointments`, `tickets` (with uploads), `invoices`, `newsletter->lists/subscribers/campaigns`,
`legal` (+ `documents`), `chat`, `returns`, `reviews`, `downloads`, `faq` (+ `categories`, `items`) and `events` (+ `ticketTypes`).
Multipart requests can repeat a field (`Request::$multipart` accepts a list per field), which ticket attachments need.
`SdkCoverageTest` compares the API paths of the JS SDK sources with the PHP sources, both ways, and fails on drift.
- Blade components (`<x-content::...>`): `article-body`, `page-blocks` (all block types, override per type), `image` (srcset / `<picture>`), `form`
  (server-side, works without JavaScript, opt-in route behind `Content::formRoutes()`). Publishable stylesheet (`content-assets`) and views (`content-views`).
- `Forms`, `Images` and `Html` helpers (ports of `form-utils.ts`, `buildImageSrcSet` and the href guard).
- `examples/laravel`: a minimal Laravel 12 site and a production Dockerfile (php-fpm + nginx, port 3000).

## 0.4.0

First release: client core (project, articles, categories, translations, pages, forms, media, redirects, SEO, sitemap, search,
consent, newsletter, `admin.*`), tag-based cache, webhook verification, Laravel integration. Version follows the JS SDK.
