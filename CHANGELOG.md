# Changelog

## Unreleased

- Marketing consent of a member (plan ootb-integrations D11). `Members::getMarketingConsent(['session' => …])` (`GET /members/me/marketing`) returns `{marketingOptIn, marketingOptInAt}` (`null` = the member never chose) and `Members::setMarketingConsent(['session' => …, 'marketingOptIn' => true|false])` (`PUT /members/me/marketing`) stores the member's own choice (`changed: false` when nothing changed). Show it as an unticked checkbox on the account page; never set it on the member's behalf. The Hub only sends marketing messages, such as the abandoned-checkout reminder, to a member who opted in or to a confirmed newsletter subscriber.
- Webhook event type `order.abandoned` in `Webhooks\Events::ALL` (an unpaid checkout older than the shop's `abandonedReminder.afterMinutes`; data: `ref` (order id), `number`, `recognized`, `consent` (`newsletter`, `member` or `null`) and `channel` (`email`, `whatsapp`, `both` or `null`); never personal data).

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
