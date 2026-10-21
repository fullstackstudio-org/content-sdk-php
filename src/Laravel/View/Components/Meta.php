<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\SiteSettings;
use FullStackStudio\Content\Seo;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
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

        $meta = Seo::meta($seo, $this->page, [
            'baseUrl' => $client->baseUrl(),
            'path' => $this->path,
            'type' => $this->type === 'article' ? 'article' : 'website',
            'fallbackImage' => is_array($ogImage) && is_string($ogImage['url'] ?? null) ? $ogImage : null,
        ]);

        return view('content::meta', ['html' => Seo::html($meta)]);
    }
}
