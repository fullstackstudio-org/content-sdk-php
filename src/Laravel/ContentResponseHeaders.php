<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Closure;
use FullStackStudio\Content\ContentClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Global middleware (registered by the service provider): two headers on every successful HTML page, with no site code.
 *
 * - `Link: <{Hub origin}>; rel=preconnect` lets the browser open the connection to the Hub while it parses the page, because images and
 *   files keep loading directly from the Hub (no proxy). Switch: `content.response_headers.preconnect` (`FSC_PRECONNECT`).
 * - `X-FSC-SDK: {version}` answers with the SDK version (the one the client sends to the Hub), nothing else.
 *   Switch: `content.response_headers.version` (`FSC_VERSION_HEADER`).
 *
 * Only for a plain HTML response below 400: never JSON, assets, redirects, the webhook, `/_fsc/*` routes, or streamed and binary responses.
 */
final class ContentResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->applies($request, $response)) {
            return $response;
        }

        if ((bool) config('content.response_headers.preconnect', true)) {
            $this->addPreconnect($response);
        }

        if ((bool) config('content.response_headers.version', true)) {
            $response->headers->set('X-FSC-SDK', ContentClient::VERSION);
        }

        return $response;
    }

    /**
     * The scheme, host and port of the configured API URL, without path, query or credentials; null when it cannot be read.
     */
    public static function hubOrigin(): ?string
    {
        $url = (string) config('content.base_url', 'https://content.fullstackstudio.nl');
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function applies(Request $request, Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 400 || $response->isRedirection()) {
            return false;
        }

        if (! str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            return false;
        }

        // The Hub's visitor pages answer with the Hub's own headers only (plan sdk-out-of-the-box, V9).
        if (\FullStackStudio\Content\Laravel\Visitor\VisitorRoutes::handles($request)) {
            return false;
        }

        $path = '/' . ltrim($request->path(), '/');

        if ($path === '/_fsc' || str_starts_with($path, '/_fsc/')) {
            return false;
        }

        $webhook = '/' . trim((string) config('content.webhook.path', 'content/webhook'), '/');

        return $path !== $webhook;
    }

    private function addPreconnect(Response $response): void
    {
        $origin = self::hubOrigin();

        if ($origin === null) {
            return;
        }

        // No `crossorigin`: images, `<img srcset>` and file downloads from the Hub are no-CORS requests that use the default (anonymous-less)
        // connection pool. A preconnect WITH `crossorigin` opens a separate pool that those requests would not reuse. Add it only if a site
        // loads fonts or `fetch()`/XHR calls from the Hub.
        $value = '<' . $origin . '>; rel=preconnect';
        $existing = array_values(array_filter(array_map('strval', $response->headers->all('Link'))));

        foreach ($existing as $header) {
            if (str_contains($header, '<' . $origin . '>') && stripos($header, 'preconnect') !== false) {
                return;
            }
        }

        // Append to a Link header the site or another middleware already set, never overwrite it.
        $response->headers->set('Link', implode(', ', [...$existing, $value]));
    }
}
