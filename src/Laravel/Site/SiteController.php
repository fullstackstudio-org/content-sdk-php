<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Placeholder action of the fallback route `content.auto.page` (`GET {fscPath}`). Replaced by the site controller of task 5.
 * Until then it answers 404, so registering the route changes nothing a visitor can see.
 */
final class SiteController
{
    public function __invoke(): never
    {
        throw new NotFoundHttpException();
    }
}
