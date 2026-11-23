<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Devmode;
use FullStackStudio\Content\Laravel\RichStyle;
use FullStackStudio\Content\Laravel\Facades\Content;
use FullStackStudio\Content\Laravel\Site\Alternates;
use FullStackStudio\Content\Laravel\Site\PageContext;
use FullStackStudio\Content\Laravel\Site\Parts;
use FullStackStudio\Content\Laravel\SiteSettings;
use FullStackStudio\Content\Seo;
use FullStackStudio\Content\Site;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Throwable;

/**
 * `<x-content::head />` in the `<head>` of every layout. Prints, each part once per request (see {@see Parts}), in this order:
 * meta (title, description, canonical, robots, Open Graph, Twitter), hreflang from the published translations, JSON-LD (`Article`, `Event`),
 * the site standards (`@layer fsc-rich;`, dev panel tags, verification metas, favicon, theme-color, LocalBusiness JSON-LD and the owner's
 * consent-gated head code) and the browser error reporter.
 *
 * The page comes from `page`/`article` or else from the resolved resource of the request ({@see PageContext}). Meta overrides, highest first:
 * the `meta` prop (`title`, `description`, `image`, `canonical`, `noindex`), `Content::seo([...])`, the resource's own fields, the project's
 * SEO defaults. The canonical is `path` (else the resolved path, else the request path) and never carries a query string.
 *
 * The meta parts and the reporter print only when the request asks for them (a `page`, `article`, `meta` or `path` prop, a resolved
 * resource, a status or `Content::seo()` values) and no `<x-content::meta>` printed before. Otherwise this component prints only the site
 * standards, exactly as before, and the old `<x-content::meta>` and `<x-content::errors>` stay in charge. When the Hub is unreachable the
 * parts that need it print nothing; never throws. `nonce` defaults to Laravel's Vite CSP nonce for the parts added here.
 */
final class Head extends Component
{
    /**
     * @param array<string, mixed>|null $page a page from the client
     * @param array<string, mixed>|null $article an article from the client
     * @param array<string, mixed>|null $meta overrides: `title`, `description`, `image`, `canonical`, `noindex`; `[]` asks for the site defaults
     */
    public function __construct(
        public readonly ?string $nonce = null,
        public readonly ?array $page = null,
        public readonly ?array $article = null,
        public readonly ?array $meta = null,
        public readonly ?string $path = null,
    ) {
    }

    public function render(): HtmlString
    {
        try {
            return new HtmlString($this->html());
        } catch (Throwable) {
            return new HtmlString('');
        }
    }

    private function html(): string
    {
        // The full set only for a request that asks for it and has no meta yet: a bare `<x-content::head />`, or one after
        // `<x-content::meta>`, keeps its exact old output (the site standards).
        $full = $this->wantsMeta() && ! Parts::seen('meta');
        $html = '';

        if ($full) {
            $html .= $this->metaParts();
        }

        if (! Parts::seen('standards')) {
            $html .= self::standards($this->nonce);
        }

        if ($full && ! Parts::seen('errors')) {
            $html .= Errors::markup(Parts::nonce($this->nonce));
        }

        return $html;
    }

    /** True when the layout passed `page`, `article`, `meta` or `path`, or the request has a resolved resource, a status or `Content::seo()` values. */
    private function wantsMeta(): bool
    {
        if ($this->page !== null || $this->article !== null || $this->meta !== null || $this->path !== null) {
            return true;
        }

        try {
            $context = app(PageContext::class);

            return $context->resolved() !== null || $context->status() !== null || $context->seo() !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /** meta, hreflang and JSON-LD of this request's page. */
    private function metaParts(): string
    {
        $context = null;

        try {
            $context = app(PageContext::class);
        } catch (Throwable) {
            // Without a request context the props still work.
        }

        $resolved = $context?->resolved();
        [$type, $resource] = match (true) {
            $this->page !== null => ['page', $this->page],
            $this->article !== null => ['article', $this->article],
            $resolved !== null && in_array($resolved->type, ['page', 'article', 'event', 'legal'], true) => [$resolved->type, $resolved->resource],
            default => [null, null],
        };
        $path = self::cleanPath($this->path) ?? self::resolvedPath($resolved) ?? self::requestPath();
        $overrides = array_replace($context?->seo() ?? [], $this->meta ?? []);

        try {
            $meta = Meta::build($resource, $path, $type === 'article' ? 'article' : 'website', $overrides);
        } catch (Throwable) {
            return '';
        }

        $html = Seo::html($meta);

        if ($resource === null || $type === null) {
            return $html;
        }

        $baseUrl = self::baseUrl();
        $canonical = is_string($meta['canonical'] ?? null) && preg_match('#^https?://#i', $meta['canonical']) === 1 ? $meta['canonical'] : null;
        $nonce = Parts::nonce($this->nonce);

        if (in_array($type, ['page', 'article'], true) && ! Parts::seen('hreflang')) {
            $found = Alternates::for($type, $resource, $resolved?->locale);
            $tags = Seo::hreflang($found['alternates'], $found['default']);
            $html .= $tags !== '' ? "\n" . $tags : '';
        }

        if (in_array($type, ['article', 'event'], true) && ! Parts::seen('jsonld')) {
            $ld = Seo::jsonLd($type, $resource, $canonical, $baseUrl, $nonce);
            $html .= $ld !== '' ? "\n" . $ld : '';
        }

        return $html;
    }

    /** The site standards: the layer line, dev panel tags, verification metas, favicon, theme-color, LocalBusiness JSON-LD, own head code. */
    public static function standards(?string $nonce): string
    {
        $layer = RichStyle::layerDeclaration($nonce);
        $layer .= self::devmode($nonce);

        try {
            $site = SiteSettings::make()->current();

            if ($site === null) {
                return $layer;
            }

            return $layer . Site::head($site, SiteSettings::renderOptions($nonce));
        } catch (Throwable) {
            return $layer;
        }
    }

    /** The dev panel tags for a request with the devmode cookie, nothing otherwise. */
    private static function devmode(?string $nonce): string
    {
        try {
            return Devmode::tags(null, SiteSettings::renderOptions($nonce)['nonce']);
        } catch (Throwable) {
            return '';
        }
    }

    /** A path without query string or fragment, with a leading slash; null for an empty one. */
    private static function cleanPath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = preg_split('/[?#]/', $path, 2)[0] ?? '';

        return $path === '' ? null : '/' . ltrim($path, '/');
    }

    /** The public path of the resolved resource: `Resolved::$path` has no locale prefix, `Urls::path()` adds it and the trailing-slash setting. */
    private static function resolvedPath(?\FullStackStudio\Content\Laravel\Site\Resolved $resolved): ?string
    {
        if ($resolved === null || $resolved->path === '') {
            return null;
        }

        try {
            return Content::urls()->path($resolved->path, $resolved->locale);
        } catch (Throwable) {
            return $resolved->path;
        }
    }

    private static function requestPath(): ?string
    {
        try {
            return request()->getPathInfo();
        } catch (Throwable) {
            return null;
        }
    }

    private static function baseUrl(): string
    {
        try {
            return app(ContentClient::class)->baseUrl();
        } catch (Throwable) {
            return 'https://hub.fullstackstudio.nl';
        }
    }
}
