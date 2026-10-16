<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Cache;

/** Wraps any PSR-16 `CacheInterface` (duck-typed: `get($key)` and `set($key, $value, $ttl)`). */
final class Psr16Store implements Store
{
    /** @param object $cache \Psr\SimpleCache\CacheInterface */
    public function __construct(private readonly object $cache)
    {
    }

    public function get(string $key): mixed
    {
        return $this->cache->get($key);
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->cache->set($key, $value, $ttlSeconds);
    }
}
