<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\Address;
use FullStackStudio\Content\ContentClient;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * The address routes under `content.address.path` (default `/_fsc/address`), registered without the `web` group: no session, cookies or CSRF.
 *
 * - `GET suggest?q=&limit=` and `GET lookup?postcode=&number=`: the browser talks to this site, which asks Content with the server token, so
 *   no token ever reaches the browser. Invalid input is a 400 without a request to Content; a Content or address-source failure answers an
 *   empty result (never an error page) with `no-store`.
 * - `GET field.js` and `GET field.css`: the script and the optional styles behind `<x-content::address-field>`.
 *
 * The visitor's IP (`content.address.ip_header`, else the IP Laravel sees) goes to Content as `X-FSC-Client-Ip`; the rate limit per visitor IP
 * is `content.address.rate_limit` per minute. Nothing about a query or an address is logged.
 */
final class AddressController
{
    public function suggest(Request $request, ContentClient $content): Response
    {
        $q = $request->query('q');
        $limit = $request->query('limit');
        $query = is_string($q) ? Address::normalizeQuery($q) : null;

        if ($query === null || ($limit !== null && (!is_string($limit) || preg_match('/^(?:[1-9]|10)$/D', $limit) !== 1))) {
            return $this->json(['error' => 'invalid_request'], 400);
        }

        if ($this->tooMany($request)) {
            return $this->json(['addresses' => []], 429);
        }

        try {
            $addresses = $content->suggestAddresses($query, $limit === null ? 8 : (int) $limit, ['clientIp' => $this->visitorIp($request)]);
        } catch (Throwable) {
            return $this->json(['addresses' => []]);
        }

        return $this->json(['addresses' => $addresses], 200, 'private, max-age=300');
    }

    public function lookup(Request $request, ContentClient $content): Response
    {
        $postcode = $request->query('postcode');
        $number = $request->query('number');
        $normalized = is_string($postcode) ? Address::normalizePostcode($postcode) : null;

        if ($normalized === null || !is_string($number) || preg_match('/^\s*[1-9][0-9]{0,4}\s*[A-Za-z]?\s*[-\/ ]?\s*[0-9A-Za-z]{0,4}\s*$/D', $number) !== 1) {
            return $this->json(['error' => 'invalid_request'], 400);
        }

        if ($this->tooMany($request)) {
            return $this->json(['address' => null], 429);
        }

        try {
            $address = $content->lookupAddress($normalized, $number, ['clientIp' => $this->visitorIp($request)]);
        } catch (Throwable) {
            return $this->json(['address' => null]);
        }

        return $this->json(['address' => $address], 200, 'private, max-age=300');
    }

    public function script(): Response
    {
        return new Response((string) file_get_contents(__DIR__ . '/../../resources/assets/fsc-address.js'), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function styles(): Response
    {
        return new Response((string) file_get_contents(__DIR__ . '/../../resources/assets/fsc-address.css'), 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function tooMany(Request $request): bool
    {
        try {
            $limiter = app(RateLimiter::class);
            $key = 'fsc-address:' . hash('sha256', (string) $this->visitorIp($request));

            if ($limiter->tooManyAttempts($key, max(1, (int) config('content.address.rate_limit', 120)))) {
                return true;
            }

            $limiter->hit($key, 60);
        } catch (Throwable) {
            // No cache for the limiter: let it through rather than break the form.
        }

        return false;
    }

    private function visitorIp(Request $request): ?string
    {
        $header = config('content.address.ip_header');
        $candidate = is_string($header) && $header !== '' ? $request->headers->get($header) : null;

        if (is_string($candidate) && filter_var(trim($candidate), FILTER_VALIDATE_IP) !== false) {
            return trim($candidate);
        }

        return $request->ip();
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200, string $cacheControl = 'no-store'): Response
    {
        return new Response((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, [
            'Content-Type' => 'application/json',
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
