# Changelog

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
