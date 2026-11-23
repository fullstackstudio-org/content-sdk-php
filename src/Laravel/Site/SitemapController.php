<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Placeholder action of `GET /sitemap.xml` (route `content.auto.sitemap`). Replaced by the automatic sitemap of task 6.
 * Until then it answers 404, so registering the route changes nothing a visitor can see.
 */
final class SitemapController
{
    public function __invoke(): never
    {
        throw new NotFoundHttpException();
    }
}
