<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Sitemap as SitemapBuilder;
use Illuminate\Http\Response;

/** `Route::get('sitemap.xml', fn () => Sitemap::response())`: the sitemap of the published content as XML. */
final class Sitemap
{
    /** @param array<string, mixed> $options see {@see SitemapBuilder::entries()} */
    public static function response(array $options = []): Response
    {
        $entries = SitemapBuilder::entries(app(ContentClient::class), $options);

        return new Response(SitemapBuilder::xml($entries), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
