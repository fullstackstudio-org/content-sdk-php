<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Cache;

/** In-process store (tests, long-running workers). Honours the TTL. */
final class ArrayStore implements Store
{
    /** @var array<string, array{0: mixed, 1: int}> */
    private array $items = [];

    public function get(string $key): mixed
    {
        $item = $this->items[$key] ?? null;

        if ($item === null) {
            return null;
        }

        if ($item[1] <= time()) {
            unset($this->items[$key]);

            return null;
        }

        return $item[0];
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->items[$key] = [$value, time() + max(1, $ttlSeconds)];
    }

    public function count(): int
    {
        return count($this->items);
    }
}
