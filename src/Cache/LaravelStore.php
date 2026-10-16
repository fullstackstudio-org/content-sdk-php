<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Cache;

/** Wraps a Laravel cache repository (`Cache::store('redis')`). Duck-typed so this file loads without Laravel. */
final class LaravelStore implements Store
{
    /** @param object $repository \Illuminate\Contracts\Cache\Repository */
    public function __construct(private readonly object $repository)
    {
    }

    public function get(string $key): mixed
    {
        return $this->repository->get($key);
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->repository->put($key, $value, $ttlSeconds);
    }
}
