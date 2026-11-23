<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Visitor;

/** The Hub's answer to a forwarded visitor request. Header names are lower case; a repeated header keeps every value. */
final class UpstreamResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /** The first value of a header, or null. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
