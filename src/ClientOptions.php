<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

use FullStackStudio\Content\Cache\ContentCache;
use FullStackStudio\Content\Http\Transport;

/** Settings of a {@see ContentClient}. */
final class ClientOptions
{
    public const DEFAULT_BASE_URL = 'https://content.fullstackstudio.nl';
    public const DEFAULT_TIMEOUT_MS = 10_000;
    public const DEFAULT_CACHE_TTL = 3600;

    /**
     * @param string $token `fsc_srv_...` (server-side; the PHP SDK always runs server-side) or `fsc_pub_...` (read and forms only).
     * @param string $baseUrl https, or http only for localhost.
     * @param int $timeoutMs Per call; override with the `timeoutMs` call option.
     * @param string|null $clientIp The visitor's IP, forwarded as `X-FSC-Client-Ip` (only with a server token) for the per-visitor limits.
     * @param Transport|null $transport Defaults to {@see \FullStackStudio\Content\Http\CurlTransport}.
     * @param ContentCache|null $cache Caches read calls under tags; null = no caching. Writes and `submitForm` are never cached.
     * @param int $cacheTtl Safety net in seconds for cached reads (the webhook provides "immediate").
     */
    public function __construct(
        public readonly string $token,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        public readonly ?string $clientIp = null,
        public readonly ?Transport $transport = null,
        public readonly ?ContentCache $cache = null,
        public readonly int $cacheTtl = self::DEFAULT_CACHE_TTL,
    ) {
    }

    /** @param array<string, mixed>|self $options keys = constructor parameter names */
    public static function from(array|self $options): self
    {
        if ($options instanceof self) {
            return $options;
        }

        return new self(
            token: (string) ($options['token'] ?? ''),
            baseUrl: (string) ($options['baseUrl'] ?? self::DEFAULT_BASE_URL),
            timeoutMs: (int) ($options['timeoutMs'] ?? self::DEFAULT_TIMEOUT_MS),
            clientIp: isset($options['clientIp']) ? (string) $options['clientIp'] : null,
            transport: $options['transport'] ?? null,
            cache: $options['cache'] ?? null,
            cacheTtl: (int) ($options['cacheTtl'] ?? self::DEFAULT_CACHE_TTL),
        );
    }
}
