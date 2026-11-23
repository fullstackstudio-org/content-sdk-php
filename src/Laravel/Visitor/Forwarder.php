<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Visitor;

use CURLFile;
use FullStackStudio\Content\Laravel\Auto\Auto;
use FullStackStudio\Content\Laravel\ContentResponseHeaders;
use FullStackStudio\Content\Laravel\ContentServiceProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Builds the Hub request for one visitor request (plan sdk-out-of-the-box, section 8 "How a request travels") and sends it through the
 * {@see Upstream}.
 *
 * - Upstream: the origin of `content.base_url` + the same path and the raw query. Nothing else is reachable.
 * - From the browser only: method, path, query, body, `Accept`, `Accept-Language`, `Content-Type` (not for multipart: cURL sets its own
 *   boundary), the browser's client string, `If-None-Match`, `If-Modified-Since`, `Origin` and `Sec-Fetch-Site`. Every other header (cookies,
 *   `Authorization`, `X-FSC-*`, `X-Forwarded-*`, `Forwarded`) is dropped by construction.
 * - Added: the server token as bearer, `X-FSC-Proxy: 1`, `X-FSC-Client-Ip` (the validated visitor IP, {@see ContentServiceProvider::visitorIp()})
 *   and `X-FSC-Site-Origin` (scheme and host of the request after proxy trust).
 * - Body: url-encoded and other raw bodies as they came (at most 1 MB); multipart rebuilt from the parsed fields and the uploaded temp files,
 *   which cURL streams from disk with their original names and types.
 */
final class Forwarder
{
    /** Largest raw (non-multipart) body that is forwarded. */
    public const MAX_REQUEST_BYTES = 1024 * 1024;

    /** Largest Hub answer that is buffered; a larger one is aborted (502). */
    public const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;

    /** Largest query string that is forwarded. */
    public const MAX_QUERY_BYTES = 2048;

    /** Browser request headers that pass, in the Hub's spelling. */
    private const PASS_HEADERS = [
        'accept' => 'Accept',
        'accept-language' => 'Accept-Language',
        'content-type' => 'Content-Type',
        'user-agent' => 'User-Agent',
        'if-none-match' => 'If-None-Match',
        'if-modified-since' => 'If-Modified-Since',
        'origin' => 'Origin',
        'sec-fetch-site' => 'Sec-Fetch-Site',
    ];

    /** Longest header value that is forwarded. */
    private const MAX_HEADER_BYTES = 1024;

    public function __construct(private readonly Upstream $upstream)
    {
    }

    /**
     * @param string $path the validated raw path of the request (`/a/l/abc`)
     *
     * @throws VisitorProxyException
     */
    public function forward(Request $request, string $path): UpstreamResponse
    {
        $origin = ContentResponseHeaders::hubOrigin();
        $token = (string) config('content.token', '');

        if ($origin === null || $token === '') {
            throw new VisitorProxyException(VisitorProxyException::NOT_CONFIGURED);
        }

        $query = self::query($request);
        $method = strtoupper($request->getMethod());
        $multipart = $method === 'POST' && self::isMultipart($request);
        $headers = $this->headers($request, $token, $multipart);
        $body = null;
        $fields = null;

        if ($method === 'POST') {
            if ($multipart) {
                $fields = self::multipart($request);
            } else {
                $body = self::rawBody($request);
            }
        }

        return $this->upstream->send(new UpstreamRequest(
            method: $method,
            url: $origin . '/' . trim($path, '/') . ($query !== '' ? '?' . $query : ''),
            headers: $headers,
            body: $body,
            multipart: $fields,
            connectTimeoutMs: max(1, (int) Auto::option('visitor_pages', 'connect_timeout', 3)) * 1000,
            timeoutMs: max(1, (int) Auto::option('visitor_pages', 'timeout', 20)) * 1000,
            maxBytes: self::MAX_RESPONSE_BYTES,
        ));
    }

    /** The raw query string as the browser sent it (not re-ordered or re-encoded by Symfony). */
    public static function query(Request $request): string
    {
        $query = $request->server->get('QUERY_STRING');

        return is_string($query) ? $query : '';
    }

    /** @return array<string, string> */
    private function headers(Request $request, string $token, bool $multipart): array
    {
        $headers = [];

        foreach (self::PASS_HEADERS as $name => $spelling) {
            if ($multipart && $name === 'content-type') {
                continue;
            }

            $value = $request->headers->get($name);

            if (is_string($value) && $value !== '' && strlen($value) <= self::MAX_HEADER_BYTES && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
                $headers[$spelling] = $value;
            }
        }

        $headers['Authorization'] = 'Bearer ' . $token;
        $headers['X-FSC-Proxy'] = '1';
        $headers['X-FSC-Site-Origin'] = $request->getSchemeAndHttpHost();

        $ip = ContentServiceProvider::visitorIp($request, config('content.forms.ip_header'));

        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $headers['X-FSC-Client-Ip'] = $ip;
        }

        return $headers;
    }

    private static function isMultipart(Request $request): bool
    {
        return str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'multipart/form-data');
    }

    /** @throws VisitorProxyException */
    private static function rawBody(Request $request): string
    {
        $declared = $request->server->get('CONTENT_LENGTH');

        if (is_numeric($declared) && (int) $declared > self::MAX_REQUEST_BYTES) {
            throw new VisitorProxyException(VisitorProxyException::REQUEST_TOO_LARGE);
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_REQUEST_BYTES) {
            throw new VisitorProxyException(VisitorProxyException::REQUEST_TOO_LARGE);
        }

        return $body;
    }

    /**
     * The parsed form fields and uploaded files as one flat field list (`files[0]`, `meta[naam]`), which the Hub parses back into the same
     * arrays. A file input without a file, or an upload PHP refused, is left out.
     *
     * @return array<string, string|CURLFile>
     */
    private static function multipart(Request $request): array
    {
        $fields = [];
        self::flatten($request->request->all(), '', $fields);
        self::flatten($request->files->all(), '', $fields);

        return $fields;
    }

    /** @param array<string, string|CURLFile> $fields */
    private static function flatten(mixed $value, string $name, array &$fields): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::flatten($item, $name === '' ? (string) $key : $name . '[' . $key . ']', $fields);
            }

            return;
        }

        if ($name === '') {
            return;
        }

        if ($value instanceof UploadedFile) {
            if ($value->isValid()) {
                $fields[$name] = new CURLFile($value->getPathname(), $value->getClientMimeType(), $value->getClientOriginalName());
            }

            return;
        }

        if ($value === null || is_scalar($value)) {
            $fields[$name] = (string) $value;
        }
    }
}
