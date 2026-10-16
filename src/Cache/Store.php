<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Cache;

/** The smallest key-value store the tag cache needs. Adapters: {@see Psr16Store}, {@see LaravelStore}, {@see ArrayStore}. */
interface Store
{
    /** The value, or null on a miss. */
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, int $ttlSeconds): void;
}
