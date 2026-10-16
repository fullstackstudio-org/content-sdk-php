<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Cache;

/** What the client needs from a cache: remember a value under tags, and invalidate tags (webhook). */
interface ContentCache
{
    /**
     * Returns the cached value for `$key` under `$tags`, or calls `$compute`, stores its result for `$ttlSeconds` and returns it.
     *
     * @param list<string> $tags
     * @param callable(): mixed $compute
     */
    public function remember(string $key, array $tags, int $ttlSeconds, callable $compute): mixed;

    /** Makes everything that was cached under one of these tags unreachable. @param list<string> $tags */
    public function invalidateTags(array $tags): void;
}
