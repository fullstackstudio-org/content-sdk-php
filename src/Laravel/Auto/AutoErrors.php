<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Auto;

use Closure;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Laravel\Site\PageContext;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ViewException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Error handling of the out of the box site (switch `content.auto.errors`, env `FSC_AUTO_ERRORS`):
 *
 * - A {@see ContentException} that escapes site code renders as 404 (the Hub said "not found") or 503 (any other Hub failure, with
 *   `Retry-After: 300` and `Cache-Control: no-store`) instead of a 500. Laravel's normal report path reports it once; a Hub 404 is never
 *   reported. With `APP_DEBUG=true` a Hub failure keeps Laravel's debug page.
 * - Every HTML 404 and 503 renders the site's `errors/{status}` view (or `errors/4xx`, `errors/5xx`) when it exists, else the package's
 *   `content::errors.{status}`. The status is put in the {@see PageContext} first, so the layout's tracker counts a 404.
 * - JSON and `api/*` requests keep Laravel's own rendering, except an escaped Hub failure: a JSON 503 `{"error":"unavailable"}` (or 404
 *   `{"error":"not_found"}`). Render callbacks the site registered (`withExceptions`, its own handler or
 *   a provider) run first: this hook is added after every provider booted.
 * - Never recurses and never throws: a failing error view ends in standalone markup without layout or components.
 *
 * No stack trace, Hub message or token reaches a response: the views get an exception without message.
 */
final class AutoErrors
{
    public const RETRY_AFTER = 300;

    /** Statuses this hook renders; every other status is left to Laravel. */
    private const STATUSES = [404, 503];

    private static bool $rendering = false;

    /**
     * Called once from the service provider's `boot()`. `$publish` is the provider's `publishes()` (protected there).
     *
     * @param Closure(array<string, string>, string): void $publish
     */
    public static function register(Application $app, Closure $publish): void
    {
        $views = __DIR__ . '/../../../resources/views/errors';

        foreach (self::STATUSES as $status) {
            $publish([$views . '/' . $status . '.blade.php' => $app->resourcePath('views/errors/' . $status . '.blade.php')], 'content-errors-' . $status);
        }

        // After every provider booted, so render callbacks of the site (registered when the handler resolves, or in a provider) come first.
        $app->booted(static function (Application $app): void {
            $hook = static function (object $handler): void {
                self::hook($handler);
            };

            if ($app->resolved(ExceptionHandler::class)) {
                $hook($app->make(ExceptionHandler::class));
            }

            $app->afterResolving(ExceptionHandler::class, $hook);
        });
    }

    /** Adds the render callback and the "do not report a Hub 404" rule to Laravel's handler (once per handler instance). */
    public static function hook(object $handler): void
    {
        static $hooked = null;
        $hooked ??= new \WeakMap();

        if (isset($hooked[$handler]) || ! method_exists($handler, 'renderable')) {
            return;
        }

        $hooked[$handler] = true;

        if (method_exists($handler, 'dontReportWhen')) {
            $handler->dontReportWhen(static fn (Throwable $e): bool => Auto::on('errors') && self::contentException($e)?->isNotFound() === true);
        }

        $handler->renderable(static fn (Throwable $e, $request) => self::render($handler, $e, $request));
    }

    /** The render callback: a response for an HTML 404/503 or an escaped {@see ContentException}, else null (Laravel renders). */
    public static function render(object $handler, Throwable $e, mixed $request): Response|JsonResponse|null
    {
        if (self::$rendering) {
            return self::standalone(503);
        }

        self::$rendering = true;

        try {
            if (! $request instanceof Request || ! Auto::on('errors')) {
                return null;
            }

            if (self::wantsJson($handler, $request, $e)) {
                return self::json($e);
            }

            $http = self::httpException($e);

            if ($http === null) {
                return null;
            }

            return self::respond($http);
        } catch (Throwable) {
            return self::standalone(503);
        } finally {
            self::$rendering = false;
        }
    }

    /**
     * A 503 for a failure outside Laravel's exception flow (the visitor page proxy): the site's `errors/503` (or `errors/5xx`) view when it
     * exists, else the package's standalone `content::errors.503`, else plain markup. `Retry-After: 300` and `Cache-Control: no-store`.
     */
    public static function unavailable(): Response
    {
        try {
            return self::respond(new HttpException(503, '', null, ['Retry-After' => (string) self::RETRY_AFTER, 'Cache-Control' => 'no-store']));
        } catch (Throwable) {
            return self::standalone(503);
        }
    }

    /** The standalone page (no layout, no components, no site view) with this status; 503 adds `Retry-After` and `no-store`. */
    public static function standaloneResponse(int $status): Response
    {
        return self::standalone($status);
    }

    /** Layout the package's 404 view extends: the same as the default site views ({@see \FullStackStudio\Content\Laravel\Site\Layout::name()}). */
    public static function layout(): string
    {
        return \FullStackStudio\Content\Laravel\Site\Layout::name();
    }

    /**
     * JSON and `api/*` requests: only an escaped {@see ContentException} is answered here (404 `not_found`, else 503 `unavailable` with
     * `Retry-After` and `no-store`), with a fixed body and nothing of the Hub's message. Everything else keeps Laravel's JSON rendering.
     */
    private static function json(Throwable $e): ?JsonResponse
    {
        $content = self::contentException($e);

        if ($content === null) {
            return null;
        }

        if ($content->isNotFound()) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        if ((bool) config('app.debug')) {
            return null;
        }

        return new JsonResponse(['error' => 'unavailable'], 503, ['Retry-After' => (string) self::RETRY_AFTER, 'Cache-Control' => 'no-store']);
    }

    /** The HTTP exception to render, or null when this hook leaves the exception to Laravel. */
    private static function httpException(Throwable $e): ?HttpExceptionInterface
    {
        $content = self::contentException($e);

        if ($content !== null) {
            if ($content->isNotFound()) {
                return new NotFoundHttpException('', $content);
            }

            // A developer sees the real cause; visitors of a production site get the calm 503.
            if ((bool) config('app.debug')) {
                return null;
            }

            return new HttpException(503, '', $content, [
                'Retry-After' => (string) self::RETRY_AFTER,
                'Cache-Control' => 'no-store',
            ]);
        }

        if ($e instanceof HttpExceptionInterface && in_array($e->getStatusCode(), self::STATUSES, true)) {
            return $e;
        }

        return null;
    }

    private static function respond(HttpExceptionInterface $e): Response
    {
        $status = $e->getStatusCode();
        $headers = $e->getHeaders();

        if ($status === 503) {
            $headers += ['Retry-After' => (string) self::RETRY_AFTER, 'Cache-Control' => 'no-store'];
        }

        try {
            app(PageContext::class)->setStatus($status);
        } catch (Throwable) {
            // No context: the page renders without it.
        }

        $view = self::siteView($status) ?? 'content::errors.' . $status;
        $html = self::renderView($view, $e, $status);

        return new Response($html ?? self::standaloneHtml($status), $status, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** The site's own error view, as Laravel would find it (`errors/404`, then `errors/4xx`), or null. */
    private static function siteView(int $status): ?string
    {
        foreach (['errors.' . $status, 'errors.' . intdiv($status, 100) . 'xx'] as $view) {
            if (view()->exists($view)) {
                return $view;
            }
        }

        return null;
    }

    /** Renders an error view to a string, or null when it (or its layout) failed. */
    private static function renderView(string $view, HttpExceptionInterface $e, int $status): ?string
    {
        // The package's 404 extends the site layout: without one it is the standalone page, no exception needed.
        if ($view === 'content::errors.404' && ! view()->exists(self::layout())) {
            return null;
        }

        try {
            return view($view, ['errors' => new ViewErrorBag(), 'exception' => $e, 'fscStatus' => $status])->render();
        } catch (Throwable $failure) {
            // A broken layout or view is a bug worth seeing; a Hub failure inside it was already reported (or is a 404).
            if (self::contentException($failure) === null) {
                try {
                    report($failure);
                } catch (Throwable) {
                    // Reporting must not break the error page.
                }
            }

            return null;
        }
    }

    private static function standalone(int $status): Response
    {
        $headers = ['Content-Type' => 'text/html; charset=UTF-8'];

        if ($status === 503) {
            $headers += ['Retry-After' => (string) self::RETRY_AFTER, 'Cache-Control' => 'no-store'];
        }

        return new Response(self::standaloneHtml($status), $status, $headers);
    }

    /** The standalone page (no layout, no components); plain markup when even that view fails. */
    private static function standaloneHtml(int $status): string
    {
        try {
            return view('content::errors.standalone', self::texts($status) + ['status' => $status])->render();
        } catch (Throwable) {
            $texts = self::texts($status);

            return '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<meta name="robots" content="noindex"><title>' . e($texts['title']) . '</title></head><body><main><h1>' . e($texts['title'])
                . '</h1><p>' . e($texts['message']) . '</p></main></body></html>';
        }
    }

    /** @return array{title: string, message: string, home: bool} */
    private static function texts(int $status): array
    {
        return $status === 404
            ? ['title' => 'Pagina niet gevonden', 'message' => 'Deze pagina bestaat niet (meer) of is verplaatst.', 'home' => true]
            : ['title' => 'Even niet bereikbaar', 'message' => 'De website is tijdelijk niet bereikbaar. Probeer het over een paar minuten opnieuw.', 'home' => false];
    }

    /** JSON and `api/*` requests keep Laravel's rendering; asks the handler itself, so the site's `shouldRenderJsonWhen` counts. */
    private static function wantsJson(object $handler, Request $request, Throwable $e): bool
    {
        if ($request->is('api', 'api/*')) {
            return true;
        }

        if (method_exists($handler, 'shouldReturnJson')) {
            try {
                return (bool) (new \ReflectionMethod($handler, 'shouldReturnJson'))->invoke($handler, $request, $e);
            } catch (Throwable) {
                // Fall through to the request's own answer.
            }
        }

        return $request->expectsJson();
    }

    /** The {@see ContentException} itself, or the one a Blade view wrapped in a {@see ViewException}. */
    private static function contentException(Throwable $e): ?ContentException
    {
        for ($depth = 0; $depth < 5; $depth++) {
            if ($e instanceof ContentException) {
                return $e;
            }

            if (! $e instanceof ViewException || $e->getPrevious() === null) {
                return null;
            }

            $e = $e->getPrevious();
        }

        return null;
    }
}
