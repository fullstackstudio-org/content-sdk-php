<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Laravel\ContentServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The routes of the default cookie banner (partial `content::partials.banner`):
 *
 * - `GET /_fsc/c/consent.js`: the banner script, cacheable (`?v=` carries a hash of the file, so an update busts every cache);
 * - `POST /_fsc/c`: records the visitor's choice at the Hub as proof (`recordConsent()` with the visitor IP and browser string). Always 204
 *   when the input is valid, also when the Hub is down: the choice already works in the browser, a failed proof is not the visitor's problem.
 *
 * No middleware group (no session, cookie or CSRF). Limit: 20 requests a minute per visitor IP (the `content.forms.ip_header` header, else the
 * request IP). Invalid input is a 422 JSON body, a limit hit a 429 with `Retry-After`.
 */
final class ConsentController
{
    public const PATH = '/_fsc/c';

    public const LIMIT_PER_MINUTE = 20;

    /** Registers both routes. Called once from the service provider; skipped when the whole automatic layer is off. */
    public static function registerRoutes(): void
    {
        if (! \FullStackStudio\Content\Laravel\Auto\Auto::on('enabled')) {
            return;
        }

        Route::get(self::PATH . '/consent.js', [self::class, 'script'])->name('content.consent.script');
        Route::post(self::PATH, [self::class, 'record'])->name('content.consent.record');
    }

    /** Script URL with the cache buster. */
    public static function scriptUrl(): string
    {
        return self::PATH . '/consent.js?v=' . self::version();
    }

    /** Hash of the script file (first 10 characters). */
    public static function version(): string
    {
        return substr(md5_file(self::asset('fsc-consent.js')) ?: '0', 0, 10);
    }

    /** The banner stylesheet. */
    public static function styles(): string
    {
        return (string) file_get_contents(self::asset('fsc-consent.css'));
    }

    public function script(Request $request): Response
    {
        $current = (string) $request->query('v') === self::version();

        return new Response((string) file_get_contents(self::asset('fsc-consent.js')), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function record(Request $request, ContentClient $content): Response
    {
        $key = 'fsc-consent:' . hash('sha256', (string) ContentServiceProvider::visitorIp($request, config('content.forms.ip_header')));

        if (RateLimiter::tooManyAttempts($key, self::LIMIT_PER_MINUTE)) {
            return new Response('{"error":"rate_limited"}', 429, [
                'Content-Type' => 'application/json',
                'Retry-After' => (string) max(1, RateLimiter::availableIn($key)),
                'Cache-Control' => 'no-store',
            ]);
        }

        RateLimiter::hit($key, 60);

        $validator = Validator::make($request->json()->all() ?: $request->all(), [
            'choice' => ['required', 'in:all,none,custom'],
            'categories' => ['nullable', 'array', 'max:10'],
            'categories.*' => ['string', 'max:40'],
            'version' => ['nullable', 'string', 'max:40'],
        ]);

        if ($validator->fails()) {
            return new Response('{"error":"invalid_request"}', 422, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
        }

        $input = $validator->validated();
        $body = ['choice' => $input['choice']];

        if ($input['choice'] === 'custom') {
            $body['categories'] = array_values($input['categories'] ?? []);
        }

        if (! empty($input['version'])) {
            $body['version'] = $input['version'];
        }

        try {
            $content->recordConsent($body, [
                'clientIp' => ContentServiceProvider::visitorIp($request, config('content.forms.ip_header')),
                'userAgent' => (string) $request->userAgent(),
            ]);
        } catch (ContentException $e) {
            if (! $e->isRateLimited() && $e->status() !== 400 && $e->status() !== 404) {
                try {
                    report($e);
                } catch (Throwable) {
                    // Reporting must never break the answer.
                }
            }
        } catch (Throwable) {
            // Hub unreachable: nothing to tell the visitor.
        }

        return new Response('', 204, ['Cache-Control' => 'no-store']);
    }

    private static function asset(string $file): string
    {
        return __DIR__ . '/../../../resources/assets/' . $file;
    }
}
