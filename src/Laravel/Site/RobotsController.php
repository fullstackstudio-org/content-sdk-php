<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Placeholder action of `GET /robots.txt` (route `content.auto.robots`). Replaced by the automatic robots of task 6.
 * Until then it answers 404, so registering the route changes nothing a visitor can see.
 */
final class RobotsController
{
    public function __invoke(): never
    {
        throw new NotFoundHttpException();
    }
}
