<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Cache;

/**
 * Tag invalidation on top of a plain key-value store (file, database, redis, ...; no cache-tags support needed).
 *
 * Every tag has a version (`fsc:tagv:<tag>`). The real cache key contains the current version of each of its tags, so bumping a
 * tag's version makes every entry under it unreachable; the stale entries just expire on their own TTL. A tag version that
 * itself got evicted is recreated with a new value, which invalidates in the safe direction.
 */
final class VersionedTagCache implements ContentCache
{
    private const VERSION_TTL = 2_592_000; // 30 days, refreshed on every read of a version

    public function __construct(private readonly Store $store, private readonly string $prefix = 'fsc')
    {
    }

    public function remember(string $key, array $tags, int $ttlSeconds, callable $compute): mixed
    {
        $parts = [];

        foreach ($tags as $tag) {
            $parts[] = $tag . '@' . $this->version($tag);
        }

        sort($parts);

        $full = $this->prefix . ':c:' . sha1($key . '|' . implode(',', $parts));
        $hit = $this->store->get($full);

        if (is_string($hit)) {
            $value = @unserialize($hit, ['allowed_classes' => false]);

            if ($value !== false || $hit === 'b:0;') {
                return $value;
            }
        }

        $value = $compute();
        $this->store->put($full, serialize($value), $ttlSeconds);

        return $value;
    }

    public function invalidateTags(array $tags): void
    {
        foreach (array_unique($tags) as $tag) {
            $this->store->put($this->versionKey($tag), bin2hex(random_bytes(8)), self::VERSION_TTL);
        }
    }

    private function version(string $tag): string
    {
        $key = $this->versionKey($tag);
        $version = $this->store->get($key);

        if (!is_string($version) || $version === '') {
            $version = bin2hex(random_bytes(8));
        }

        $this->store->put($key, $version, self::VERSION_TTL);

        return $version;
    }

    private function versionKey(string $tag): string
    {
        return $this->prefix . ':tagv:' . $tag;
    }
}
