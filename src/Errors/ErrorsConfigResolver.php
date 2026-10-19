<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Errors;

use FullStackStudio\Content\Cache\Store;
use FullStackStudio\Content\ContentException;
use Throwable;

/**
 * Where the site gets its DSNs: an env override, else Content (`GET /api/v1/project/errors`), cached.
 *
 * - Fresh for `ttl` seconds (default 10 minutes; a rotated key or a switched-off module reaches the site within that time).
 * - Content unreachable: the last good answer stays usable for `staleTtl` (default 24 hours) and the next attempt waits `cooldown`
 *   seconds, so an outage never slows every request or error down.
 * - Module off (`module_disabled`) or token without the scope: cached as "off" like a normal answer.
 *
 * Never throws: monitoring must never break the site.
 */
final class ErrorsConfigResolver
{
    private const KEY = 'fsc:errors:config:v1';

    private const COOLDOWN_KEY = 'fsc:errors:config:cooldown';

    /** @var array{at: int, config: ?array<string, mixed>}|null */
    private ?array $memo = null;

    /** @var callable(): array<string, mixed> */
    private $fetch;

    /** @var callable(): int */
    private $clock;

    /**
     * @param callable(): array<string, mixed> $fetch returns the `errors` object of the API answer (throws ContentException on failure)
     * @param callable(): int|null $clock
     */
    public function __construct(
        callable $fetch,
        private readonly ?Store $store,
        private readonly ?ErrorsConfig $override = null,
        private readonly ?string $release = null,
        private readonly int $ttl = 600,
        private readonly int $staleTtl = 86_400,
        private readonly int $cooldown = 60,
        ?callable $clock = null,
    ) {
        $this->fetch = $fetch;
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function resolve(): ?ErrorsConfig
    {
        if ($this->override !== null) {
            return $this->override->isActive() ? $this->override : null;
        }

        try {
            $now = ($this->clock)();
            $cached = $this->memo ?? $this->read(self::KEY);

            if ($cached !== null && $now - $cached['at'] < $this->ttl) {
                $this->memo = $cached;

                return $this->config($cached);
            }

            if ($this->read(self::COOLDOWN_KEY) !== null) {
                return $cached === null ? null : $this->config($cached);
            }

            try {
                $config = ErrorsConfig::fromApi(($this->fetch)(), $this->release);
                $entry = ['at' => $now, 'config' => $config->isActive() ? $config->toArray() : null];
            } catch (ContentException $error) {
                if (! in_array($error->errorCode(), ['module_disabled', 'insufficient_scope', 'not_found'], true)) {
                    $this->store?->put(self::COOLDOWN_KEY, ['at' => $now], $this->cooldown);

                    return $cached === null ? null : $this->config($cached);
                }

                $entry = ['at' => $now, 'config' => null];
            }

            $this->memo = $entry;
            $this->store?->put(self::KEY, $entry, $this->staleTtl);

            return $this->config($entry);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{at: int, config: ?array<string, mixed>}|null */
    private function read(string $key): ?array
    {
        $value = $this->store?->get($key);

        if (! is_array($value) || ! is_int($value['at'] ?? null)) {
            return null;
        }

        return ['at' => $value['at'], 'config' => is_array($value['config'] ?? null) ? $value['config'] : null];
    }

    /** @param array{at: int, config: ?array<string, mixed>} $entry */
    private function config(array $entry): ?ErrorsConfig
    {
        if ($entry['config'] === null) {
            return null;
        }

        // The release is never cached: it belongs to the running build, not to the answer of Content.
        $stored = ErrorsConfig::fromArray($entry['config']);
        $config = new ErrorsConfig($stored->backendDsn, $stored->browserDsn, $stored->environment, ErrorsConfig::release($this->release));

        return $config->isActive() ? $config : null;
    }
}
