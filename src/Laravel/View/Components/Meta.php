<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Site\Parts;
use FullStackStudio\Content\Laravel\SiteSettings;
use FullStackStudio\Content\Seo;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * `<x-content::meta :page="$page" path="/blog/hello" type="article" />` in your layout's `<head>`: title, description, canonical,
 * robots, Open Graph and Twitter from the project's SEO settings and the page or article. Without `page` it renders the site defaults.
 * Without an image on the page or in the SEO settings the share image of the site standards (`site.social.ogImage`) is used.
 */
final class Meta extends Component
{
    /** @param array<string, mixed>|null $page a page or article from the client */
    public function __construct(public readonly ?array $page = null, public readonly ?string $path = null, public readonly string $type = 'website')
    {
    }

    public function render(): View
    {
        if (Parts::seen('meta')) {
            return view('content::meta', ['html' => '']);
        }

        return view('content::meta', ['html' => self::compose($this->page, $this->path, $this->type)]);
    }

    /**
     * The meta HTML for one page or article. `$overrides` (`title`, `description`, `image`, `canonical`, `noindex`) are applied over the
     * Hub values, literally. Used by this component and by `<x-content::head>`.
     *
     * @param array<string, mixed>|null $page
     * @param array<string, mixed> $overrides
     */
    public static function compose(?array $page, ?string $path, string $type = 'website', array $overrides = []): string
    {
        return Seo::html(self::build($page, $path, $type, $overrides));
    }

    /**
     * The meta array behind {@see self::compose()}.
     *
     * @param array<string, mixed>|null $page
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function build(?array $page, ?string $path, string $type = 'website', array $overrides = []): array
    {
        $client = app(ContentClient::class);

        try {
            $seo = $client->getSeo();
        } catch (Throwable) {
            // An outage at Content must not break the page: the page's own fields still make useful meta.
            $seo = null;
        }

        try {
            $ogImage = SiteSettings::make()->current()['social']['ogImage'] ?? null;
        } catch (Throwable) {
            $ogImage = null;
        }

        $meta = Seo::meta($seo, $page, [
            'baseUrl' => $client->baseUrl(),
            'path' => $path,
            'type' => $type === 'article' ? 'article' : 'website',
            'fallbackImage' => is_array($ogImage) && is_string($ogImage['url'] ?? null) ? $ogImage : null,
        ]);

        $meta = self::absoluteCanonical($meta, $seo);

        if ($overrides !== []) {
            $meta = Seo::override($meta, $overrides, $client->baseUrl());
        }

        return $meta;
    }

    /**
     * Without the Hub's `canonicalOrigin` the canonical would be a bare path. Falls back to the last origin the Hub gave (remembered for 30
     * days), else the app's own origin (APP_URL, else the request). A canonical the Hub made absolute is remembered, never changed.
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed>|null $seo
     * @return array<string, mixed>
     */
    private static function absoluteCanonical(array $meta, ?array $seo): array
    {
        $key = 'fsc-seo:origin:v1';
        $origin = is_string($seo['canonicalOrigin'] ?? null) ? rtrim(trim($seo['canonicalOrigin']), '/') : '';

        try {
            if ($origin !== '') {
                Cache::put($key, $origin, 2_592_000);

                return $meta;
            }

            $canonical = $meta['canonical'] ?? null;

            if (! is_string($canonical) || ! str_starts_with($canonical, '/')) {
                return $meta;
            }

            $known = Cache::get($key);
            $base = is_string($known) && $known !== '' ? $known : rtrim((string) (config('app.url') ?: url('/')), '/');
            $meta['canonical'] = $base . $canonical;

            if (isset($meta['openGraph']['url']) && is_string($meta['openGraph']['url']) && str_starts_with($meta['openGraph']['url'], '/')) {
                $meta['openGraph']['url'] = $base . $meta['openGraph']['url'];
            }
        } catch (Throwable) {
            // No cache or no request: the meta stays as it was.
        }

        return $meta;
    }
}
