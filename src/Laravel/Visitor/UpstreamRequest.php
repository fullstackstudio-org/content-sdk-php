<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Visitor;

use CURLFile;

/**
 * A visitor request on its way to the Hub. `$body` is the raw body (url-encoded forms); `$multipart` (flat field name => string or file
 * on disk) replaces it for uploads, so a file is streamed from PHP's temp file and never read into memory.
 */
final class UpstreamRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string|CURLFile>|null $multipart
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body = null,
        public readonly ?array $multipart = null,
        public readonly int $connectTimeoutMs = 3_000,
        public readonly int $timeoutMs = 20_000,
        public readonly int $maxBytes = 2 * 1024 * 1024,
    ) {
    }
}
