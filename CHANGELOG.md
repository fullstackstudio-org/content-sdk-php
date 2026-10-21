# Changelog

## 0.8.0

- Site standards (Content module `site`). `getSite()` (`GET /api/v1/site`, any token, tag `content:site`; the webhook event `site.updated` clears it)
  and two layout components: `<x-content::head />` (verification metas for Google, Bing, Facebook and Pinterest, favicon, apple-touch-icon,
  theme-color, LocalBusiness JSON-LD, the customer's own head code) and `<x-content::body-end />` (own body code, and with click events on and the
  analytics module active a delegated listener that sends `contact-click` for tel:, mailto: and WhatsApp links to Umami). They never throw: a failure
  renders the last good answer or nothing (one warning, a 15 s pause), the module being off renders nothing (debug log only). One lookup per request.
- Own code with consent `necessary` is printed with the page nonce on every `<script>`/`<style>` (script text is never touched); other categories
  are inert `<template data-fsc-code>` elements activated by an inline loader once the visitor accepted that category (`localStorage['fsc-consent']`
  and the `fsc:consent` event, the same contract as analytics; `{choice, categories}` is now understood by the analytics helper too).
- JSON-LD is encoded with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`.
- `<x-content::meta>` (and `Seo::meta()` with the new `fallbackImage` option) falls back to the site's share image when the page and the SEO
  settings have none.
- CSP: `Content::siteCsp()` / `siteCspMerge($policy)` (string or array) and `Site::csp()` / `Site::mergeCsp()`. A missing directive starts from
  `default-src`, so merging never narrows a policy.
- Always in every site, also with the module off: `Sitemap::response()` no longer throws (module off = your `extra` entries, Content down = 503 with
  `Retry-After`, no `canonicalOrigin` = the app's own origin); new `Robots::response()` for `robots.txt`; the redirect lookup pauses 60 s on
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
