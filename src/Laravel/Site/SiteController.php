<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\Laravel\Auto\Auto;
use Illuminate\Http\Request;

/**
 * Action of the fallback route `content.auto.page` (`GET {fscPath}`): asks the {@see PathResolver} what the path is, remembers it in the
 * request scoped {@see PageContext} (the head component reads it) and renders it. A path that is nothing is a 404 and a Hub failure a 503
 * ({@see Hub::orAbort()}). A feature that is off is a 404 here too, whatever the resolver found.
 *
 * Per type, a handler of the site (`content.auto.handlers.<type>`, an invokable class that receives the `Request` and the `Resolved`) takes
 * over; else the view `Auto::view(<type>)` is rendered with the variable names sites use in their own views: `$page`, `$article`,
 * `$articles` (a list: the payload of the Hub list), `$category`, `$event`, `$document`, plus `$path` and `$resolved`.
 */
final class SiteController
{
    public function __invoke(Request $request, PageContext $context): mixed
    {
        // Nothing here is served when pages, articles and legal are all off: answer 404 without building the resolver, which needs a
        // Hub client (and so a token that a site without Hub content does not have).
        if (! Auto::on('pages') && ! Auto::on('articles') && ! Auto::on('legal')) {
            abort(404);
        }

        $resolver = app(PathResolver::class);
        $resolved = Hub::orAbort(fn (): ?Resolved => $resolver->resolve($request->getPathInfo()));

        if (! self::allowed($resolved)) {
            abort(404);
        }

        $context->resolve($resolved);
        $context->setStatus(200);

        $handler = Auto::handler($resolved->type);

        if ($handler !== null) {
            return app($handler)($request, $resolved);
        }

        return view(Auto::view($resolved->type), self::data($resolved));
    }

    /** The feature that serves a type must be on (the resolver already skips it; this keeps a changed config from leaking a cached type). */
    private static function allowed(Resolved $resolved): bool
    {
        return match ($resolved->type) {
            'page' => Auto::on('pages'),
            'article', 'articles', 'category' => Auto::on('articles'),
            'legal' => Auto::on('legal'),
            default => true,
        };
    }

    /** @return array<string, mixed> */
    private static function data(Resolved $resolved): array
    {
        $data = ['path' => $resolved->path, 'resolved' => $resolved];

        return $data + match ($resolved->type) {
            'page' => ['page' => $resolved->resource],
            'article' => ['article' => $resolved->resource],
            'articles' => ['articles' => $resolved->resource['articles'] ?? [], 'category' => null],
            'category' => ['articles' => $resolved->resource['articles'] ?? [], 'category' => $resolved->extra['category'] ?? null],
            'event' => ['event' => $resolved->resource],
            'legal' => ['document' => $resolved->resource],
            default => [],
        };
    }
}
