<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Internal;

/** A decoded response: the parsed body and the lowercase response headers. */
final class Reply
{
    /** @param array<string, string> $headers */
    public function __construct(public readonly mixed $data, public readonly array $headers)
    {
    }

    /** @return array{data: mixed, headers: array<string, string>} */
    public function toArray(): array
    {
        return ['data' => $this->data, 'headers' => $this->headers];
    }

    /** @param array{data: mixed, headers: array<string, string>} $value */
    public static function fromArray(array $value): self
    {
        return new self($value['data'], $value['headers']);
    }
}
