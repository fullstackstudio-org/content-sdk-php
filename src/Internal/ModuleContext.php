<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Internal;

use FullStackStudio\Content\Cache\ContentCache;
use FullStackStudio\Content\ClientOptions;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Http\CurlTransport;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Transport;
use FullStackStudio\Content\Http\TransportException;

/**
 * The request machinery that the client and every module class share (mirror of `ctx.ts`). NOT part of the public API
 * of the package, but it is the contract that module classes are built on: a module takes a `ModuleContext` in its constructor
 * and calls only the methods below. Nothing else (no transport, no token, no cache) is reachable from a module.
 *
 * Call options (the last `array $options` argument of every public method):
 *   - `timeoutMs` (int)   overrides the client's timeout for this call.
 *   - `clientIp` (string) the visitor's IP, only used where a method forwards it (see `visitorIp`).
 *   - `cache` (bool)      false = skip the cache for this read call.
 *   - `tags` (string[])   extra cache tags on top of the call's own.
 *   - `ttl` (int)         cache lifetime in seconds for this call.
 *
 * `execute()` takes a spec array:
 *   - `path`    (string, required) after `/api/v1`, e.g. `/articles/hello`. Encode segments with `seg()`.
 *   - `method`  GET (default) | POST | PATCH | DELETE
 *   - `query`   array of name => scalar|null; null, "" and missing values are dropped; bool becomes "true"/"false".
 *   - `body`    array|object => JSON. `null` = no body; an empty array is sent as `{}`.
 *   - `form`    array of name => string|UploadFile => multipart/form-data (instead of `body`).
 *   - `headers` extra request headers.
 *   - `tags`    list<string>: present = a read call that the cache may hold (GET only).
 *   - `binary`  true = resolve with the raw bytes (see `download()`).
 *   - `options` the call options above.
 */
final class ModuleContext
{
    private readonly string $token;
    private readonly string $baseUrl;
    private readonly Transport $transport;
    private readonly ?string $configuredIp;
    private readonly bool $serverToken;

    public function __construct(private readonly ClientOptions $options)
    {
        $token = trim($options->token);

        if ($token === '') {
            throw new ContentException(0, 'invalid_config', 'The `token` option is missing.');
        }

        $this->token = $token;
        $this->serverToken = str_starts_with($token, 'fsc_srv_');
        $this->baseUrl = self::normalizeBaseUrl($options->baseUrl);
        $this->configuredIp = self::validClientIp($options->clientIp);

        if ($options->transport !== null) {
            $this->transport = $options->transport;
        } elseif (extension_loaded('curl')) {
            $this->transport = new CurlTransport();
        } else {
            throw new ContentException(0, 'invalid_config', 'ext-curl is not available: pass a `transport` option.');
        }
    }

    /** The token is a server token (`fsc_srv_...`). */
    public function isServerToken(): bool
    {
        return $this->serverToken;
    }

    /** The base URL without a trailing slash. */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** The cache, when one is configured. */
    public function cache(): ?ContentCache
    {
        return $this->options->cache;
    }

    /** URL-encodes one path segment (`encodeURIComponent`). */
    public static function seg(string|int $value): string
    {
        return rawurlencode((string) $value);
    }

    /** @param array<string, mixed> $spec see the class docs */
    public function execute(array $spec): Reply
    {
        $callOptions = $spec['options'] ?? [];
        $method = strtoupper((string) ($spec['method'] ?? 'GET'));
        $url = $this->baseUrl . '/api/v1' . $spec['path'] . self::queryString($spec['query'] ?? null);
        $tags = $spec['tags'] ?? null;
        $cache = $this->options->cache;

        if ($cache !== null && $tags !== null && $method === 'GET' && empty($spec['binary']) && ($callOptions['cache'] ?? true) !== false) {
            $allTags = array_values(array_unique([...$tags, ...($callOptions['tags'] ?? [])]));
            $key = 'req:' . sha1($this->baseUrl . '|' . sha1($this->token) . '|' . $url . '|' . ($spec['headers']['X-FSC-Client-Ip'] ?? ''));
            $stored = $cache->remember($key, $allTags, (int) ($callOptions['ttl'] ?? $this->options->cacheTtl), fn (): array => $this->send($spec, $method, $url)->toArray());

            return Reply::fromArray($stored);
        }

        return $this->send($spec, $method, $url);
    }

    /**
     * A read call that the cache may hold.
     *
     * @param list<string> $tags
     * @param array<string, mixed>|null $query
     * @param array<string, mixed> $options
     */
    public function get(string $path, array $tags, ?array $query = null, array $options = []): Reply
    {
        return $this->execute(['path' => $path, 'query' => $query, 'tags' => $tags, 'options' => $options]);
    }

    /**
     * A write call: resolves with the parsed body. Never cached.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $options
     */
    public function write(string $method, string $path, ?array $body = null, array $options = []): mixed
    {
        return $this->execute(['method' => $method, 'path' => $path, 'body' => $body, 'options' => $options])->data;
    }

    /**
     * The body as an array with `total` from the body, otherwise from `X-Total-Count`.
     *
     * @return array<string, mixed>
     */
    public function withTotal(Reply $reply): array
    {
        $data = is_array($reply->data) ? $reply->data : [];
        $header = $reply->headers['x-total-count'] ?? null;

        if (!is_int($data['total'] ?? null) && !is_float($data['total'] ?? null) && $header !== null && is_numeric($header)) {
            $data['total'] = (int) $header;
        }

        return $data;
    }

    /**
     * The bytes of a download (PDF, attachment).
     *
     * @param array<string, mixed> $spec as `execute()`, without `binary`
     * @return array{bytes: string, contentType: string, fileName: string|null}
     */
    public function download(array $spec): array
    {
        $reply = $this->execute([...$spec, 'binary' => true]);

        return $reply->data;
    }

    /** The visitor IP to forward: only with a server token, from the call options or the client's default. */
    public function visitorIp(?string $clientIp = null): ?string
    {
        return $this->serverToken ? (self::validClientIp($clientIp) ?? $this->configuredIp) : null;
    }

    /**
     * `X-FSC-Member-Session` and `X-FSC-Client-Ip` (plus `$extra`) for a call, or null when there are none.
     *
     * @param array<string, string> $extra
     * @return array<string, string>|null
     */
    public function visitorHeaders(?string $memberSession = null, ?string $clientIp = null, array $extra = []): ?array
    {
        $headers = $extra;
        $ip = $this->visitorIp($clientIp);

        if ($memberSession !== null && $memberSession !== '') {
            $headers['X-FSC-Member-Session'] = $memberSession;
        }

        if ($ip !== null) {
            $headers['X-FSC-Client-Ip'] = $ip;
        }

        return $headers === [] ? null : $headers;
    }

    /** @param array<string, mixed>|null $query */
    public static function queryString(?array $query): string
    {
        if ($query === null) {
            return '';
        }

        $params = [];

        foreach ($query as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $params[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $params === [] ? '' : '?' . http_build_query($params);
    }

    /** @param array<string, mixed> $spec */
    private function send(array $spec, string $method, string $url): Reply
    {
        $callOptions = $spec['options'] ?? [];
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json', 'User-Agent' => 'fullstack-content-php/' . ContentClient::VERSION, ...($spec['headers'] ?? [])];
        $body = null;
        $multipart = null;

        if (isset($spec['form'])) {
            $multipart = $spec['form'];
        } elseif (array_key_exists('body', $spec) && $spec['body'] !== null) {
            $body = json_encode($spec['body'] === [] ? new \stdClass() : $spec['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        }

        $request = new Request($method, $url, $headers, $body, $multipart, (int) ($callOptions['timeoutMs'] ?? $this->options->timeoutMs));

        try {
            $response = $this->transport->send($request);
        } catch (TransportException $error) {
            if ($error->timedOut) {
                throw new ContentException(0, 'timeout', 'Fullstack Content did not respond in time.', null, null, $error);
            }

            throw new ContentException(0, 'network_error', 'Fullstack Content is unreachable.', null, null, $error);
        }

        $requestId = $response->header('x-fsc-request-id');

        if ($response->status < 200 || $response->status >= 300) {
            $retryAfter = $response->header('retry-after');
            $code = 'http_error';
            $message = "Fullstack Content responded with {$response->status}.";
            $decoded = json_decode($response->body, true);

            // Not JSON (an error page from a proxy or Cloudflare): the default message stays, the body is not passed on.
            if (is_array($decoded) && is_array($decoded['error'] ?? null)) {
                if (is_string($decoded['error']['code'] ?? null)) {
                    $code = $decoded['error']['code'];
                }

                if (is_string($decoded['error']['message'] ?? null)) {
                    $message = str_replace($this->token, '[token]', $decoded['error']['message']);
                }
            }

            throw new ContentException(
                $response->status,
                $code,
                $message,
                $requestId,
                $retryAfter !== null && is_numeric($retryAfter) && (int) $retryAfter > 0 ? (int) $retryAfter : null,
            );
        }

        if (!empty($spec['binary'])) {
            $disposition = $response->header('content-disposition') ?? '';
            $fileName = preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $found) === 1 ? rawurldecode($found[1]) : null;

            return new Reply(
                ['bytes' => $response->body, 'contentType' => $response->header('content-type') ?? 'application/octet-stream', 'fileName' => $fileName],
                $response->headers,
            );
        }

        $data = json_decode($response->body, true);

        if (!is_array($data)) {
            throw new ContentException($response->status, 'invalid_response', 'Fullstack Content did not return a valid JSON response.', $requestId);
        }

        return new Reply($data, $response->headers);
    }

    private static function validClientIp(?string $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 100 && preg_match('/^[0-9a-fA-F:.]+$/D', $value) === 1 ? $value : null;
    }

    private static function normalizeBaseUrl(string $raw): string
    {
        $parts = parse_url($raw);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ContentException(0, 'invalid_config', "Invalid baseUrl: {$raw}");
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $local = $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]' || $host === '::1' || str_ends_with($host, '.localhost');

        // The token is sent with every request: never over an unencrypted connection to any host other than localhost.
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            throw new ContentException(0, 'invalid_config', 'baseUrl must be https (http is only allowed for localhost).');
        }

        $port = isset($parts['port']) && !(($scheme === 'https' && $parts['port'] === 443) || ($scheme === 'http' && $parts['port'] === 80)) ? ':' . $parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }
}
