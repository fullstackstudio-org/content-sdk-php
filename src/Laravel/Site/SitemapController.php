<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\Laravel\Sitemap;
use Illuminate\Http\Response;

/**
 * `GET /sitemap.xml` (route `content.auto.sitemap`): {@see Sitemap::automatic()}. No middleware group, so no session and no cookie.
 */
final class SitemapController
{
    public function __invoke(): Response
    {
        return Sitemap::automatic();
    }
}
