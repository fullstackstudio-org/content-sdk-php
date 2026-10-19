# Changelog

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
