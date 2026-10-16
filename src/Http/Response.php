<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Http;

final class Response
{
    /** @param array<string, string> $headers lowercase name => value */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
