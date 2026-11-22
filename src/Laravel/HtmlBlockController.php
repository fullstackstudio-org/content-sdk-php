<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use Illuminate\Http\Response;
use Throwable;

/**
 * `GET {content.html_blocks.path}/{pageId}/{key}` (default `/_fsc/h/...`, route name `content.html-block`): one isolated `html` block of a
 * PUBLISHED page as its own document, the `src` of the sandboxed iframe that {@see HtmlBlock} prints. `key` is the block id, or `~<index>` for a
 * block without one.
 *
 * The page is read through the (tag cached) client, so the webhook that clears the page clears this too. The answer carries its own
 * `Content-Security-Policy` ({@see HtmlBlock::frameCsp()}: permissive sources for that block only, `sandbox allow-scripts allow-popups
 * allow-forms allow-popups-to-escape-sandbox`, `frame-ancestors 'self'`), `X-Robots-Tag: noindex, nofollow` and the cache header of the Hub's
 * published pages. A malformed path, an unknown or unpublished page, a block that is missing, not `html`, inline (`isolate: false`) or empty is a
 * bare 404; a Hub failure a 503 that is not cached. No middleware group: no session, no cookies.
 */
final class HtmlBlockController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    /** The route prefix (`/_fsc/h`). */
    public static function base(): string
    {
        return '/' . (trim((string) config('content.html_blocks.path', '_fsc/h'), '/') ?: '_fsc/h');
    }

    public function __invoke(ContentClient $client, string $pageId, string $key): Response
    {
        if (preg_match(self::UUID, $pageId) !== 1 || preg_match(HtmlBlock::KEY_PATTERN, $key) !== 1) {
            return self::notFound();
        }

        try {
            $page = $client->getPage($pageId);
        } catch (ContentException $e) {
            return $e->isNotFound() ? self::notFound() : self::unavailable();
        } catch (Throwable) {
            return self::unavailable();
        }

        // A server token can also see drafts: only a published page is served.
        if (!is_array($page) || $page === [] || ($page['status'] ?? 'published') !== 'published') {
            return self::notFound();
        }

        $block = self::find(is_array($page['blocks'] ?? null) ? array_values($page['blocks']) : [], $key);

        if ($block === null || ($block['type'] ?? null) !== 'html' || ($block['isolate'] ?? true) === false || !HtmlBlock::validCode($block['html'] ?? null)) {
            return self::notFound();
        }

        return new Response(HtmlBlock::document((string) $block['html']), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => HtmlBlock::frameCsp(),
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=300',
        ]);
    }

    /**
     * @param list<mixed> $blocks
     * @return array<string, mixed>|null
     */
    private static function find(array $blocks, string $key): ?array
    {
        if (str_starts_with($key, '~')) {
            $block = $blocks[(int) substr($key, 1)] ?? null;

            // An index only addresses a block without an id (a block with one is addressed by its id).
            return is_array($block) && !is_string($block['id'] ?? null) ? $block : null;
        }

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['id'] ?? null) === $key) {
                return $block;
            }
        }

        return null;
    }

    private static function notFound(): Response
    {
        return new Response('', 404, ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'public, max-age=60']);
    }

    private static function unavailable(): Response
    {
        return new Response('', 503, ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store', 'Retry-After' => '30']);
    }
}
