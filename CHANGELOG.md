# Changelog

## Unreleased

- Blade components (`<x-content::...>`): `article-body`, `page-blocks` (all block types, override per type), `image` (srcset / `<picture>`), `form`
  (server-side, works without JavaScript, opt-in route behind `Content::formRoutes()`). Publishable stylesheet (`content-assets`) and views (`content-views`).
- `Forms`, `Images` and `Html` helpers (ports of `form-utils.ts`, `buildImageSrcSet` and the href guard).
- `examples/laravel`: a minimal Laravel 12 site and a production Dockerfile (php-fpm + nginx, port 3000).

## 0.4.0

First release: client core (project, articles, categories, translations, pages, forms, media, redirects, SEO, sitemap, search,
consent, newsletter, `admin.*`), tag-based cache, webhook verification, Laravel integration. Version follows the JS SDK.
