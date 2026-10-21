<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Sitemap as SitemapBuilder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `Route::get('sitemap.xml', fn () => Sitemap::response([...]))`: the sitemap of the published content as XML.
 *
 * Never throws: with the SEO module off it lists only your `extra` entries; when Content is down it answers 503 with `Retry-After` (a
 * crawler tries again later) and logs a warning. Without a `canonicalOrigin` in Content and without `baseUrl`, the app's own origin is used.
 */
final class Sitemap
{
    /** @param array<string, mixed> $options see {@see SitemapBuilder::entries()} */
    public static function response(array $options = []): Response
    {
        $headers = ['Content-Type' => 'application/xml; charset=UTF-8'];

        try {
            $entries = SitemapBuilder::entries(app(ContentClient::class), ['fallbackBaseUrl' => url('/'), ...$options]);
        } catch (ContentException $error) {
            if (!$error->isModuleDisabled()) {
                return self::unavailable($error, $headers);
            }

            $entries = [];

            foreach ($options['extra'] ?? [] as $extra) {
                $entries[] = ['url' => $extra['url'], 'lastmod' => $extra['lastmod'] ?? null];
            }
        } catch (Throwable $error) {
            return self::unavailable($error, $headers);
        }

        return new Response(SitemapBuilder::xml($entries), 200, $headers);
    }

    /** @param array<string, string> $headers */
    private static function unavailable(Throwable $error, array $headers): Response
    {
        try {
            Log::warning('[content] sitemap.xml unavailable: Content could not be reached.', [
                'status' => $error instanceof ContentException ? $error->status() : null,
                'code' => $error instanceof ContentException ? $error->errorCode() : $error::class,
            ]);
        } catch (Throwable) {
        }

        return new Response('', 503, [...$headers, 'Retry-After' => '300', 'Cache-Control' => 'no-store']);
    }
}
