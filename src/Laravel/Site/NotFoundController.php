<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Action of the fallback route `content.auto.not-found` (POST, PUT, PATCH, DELETE on any path): answers what Laravel answers without this
 * package. The GET content route would otherwise turn every other method on an unknown path into a 405; here a path that no route serves
 * is a 404, and a path a non-fallback route serves with another method stays a 405 with its `Allow` header. No middleware group, no Hub call.
 */
final class NotFoundController
{
    public function __invoke(Request $request, Router $router): never
    {
        $allowed = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (! $route->isFallback && $route->matches($request, false)) {
                array_push($allowed, ...$route->methods());
            }
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException(array_values(array_unique($allowed)));
        }

        throw new NotFoundHttpException();
    }
}
