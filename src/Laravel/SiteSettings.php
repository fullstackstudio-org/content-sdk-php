<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Vite;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The site standards of this site (`ContentClient::getSite()`), shared by `<x-content::head />`, `<x-content::body-end />` and
 * `<x-content::meta>`. One instance per request (scoped binding), so the three components cost one lookup.
 *
 * The answer itself is cached by the client under the tag `content:site` (cleared by the webhook event `site.updated`). On top of that:
 * - A failure (Content down, timeout, missing scope) never throws: the last good answer is used when there is one, otherwise nothing.
 *   It is logged once as a warning and lookups pause for `content.site.failure_cooldown_seconds`, so an outage never slows pages down.
 * - The module being off (empty shape, or 404 `module_disabled` from an older server) is normal: logged at debug level only.
 */
final class SiteSettings
{
    public const LAST_GOOD_KEY = 'fsc-site:last-good:v1';
    public const PAUSE_KEY = 'fsc-site:pause:v1';

    private bool $loaded = false;

    /** @var array<string, mixed>|null */
    private ?array $site = null;

    public function __construct(
        private readonly ContentClient $client,
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function make(): self
    {
        $app = app();

        if ($app->bound(self::class)) {
            return $app->make(self::class);
        }

        $store = $app['config']->get('content.cache.store');

        return new self($app->make(ContentClient::class), $app['cache']->store($store ?: null), $app['config'], $app->bound('log') ? $app['log'] : null);
    }

    /** Lets the next request ask Content again right away (the webhook calls this on `site.updated`). Never throws. */
    public static function forgetPause(): void
    {
        try {
            $app = app();
            $store = $app['config']->get('content.cache.store');
            $app['cache']->store($store ?: null)->forget(self::PAUSE_KEY);
        } catch (Throwable) {
        }
    }

    /**
     * The render options of the site components: the nonce (given, else Laravel's Vite CSP nonce) and the consent key/event the cookie
     * banner uses (shared with analytics).
     *
     * @return array{nonce: string|null, consentKey: string, consentEvent: string}
     */
    public static function renderOptions(?string $nonce = null): array
    {
        if ($nonce === null || $nonce === '') {
            try {
                $nonce = class_exists(Vite::class) ? Vite::cspNonce() : null;
            } catch (Throwable) {
                $nonce = null;
            }
        }

        return [
            'nonce' => is_string($nonce) && $nonce !== '' ? $nonce : null,
            'consentKey' => (string) config('content.analytics.consent_key', 'fsc-consent'),
            'consentEvent' => (string) config('content.analytics.consent_event', 'fsc:consent'),
        ];
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('content.site.enabled', true);
    }

    /**
     * The site config from Content, or null when there is nothing to render (switched off here, module off, or Content unreachable
     * without an earlier good answer). Never throws.
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        if ($this->loaded) {
            return $this->site;
        }

        $this->loaded = true;

        if (!$this->enabled()) {
            return $this->site = null;
        }

        if ($this->safeGet(self::PAUSE_KEY) !== null) {
            return $this->site = $this->lastGood();
        }

        try {
            $site = $this->client->getSite(['timeoutMs' => max(100, (int) $this->config->get('content.site.timeout_ms', 2000))]);
        } catch (ContentException $error) {
            if ($error->isModuleDisabled() || ($error->isNotFound() && $error->errorCode() === 'not_found')) {
                $this->log('debug', 'Content site standards are not available for this project (module off).', $error);
                $this->safePut(self::PAUSE_KEY, 1, max(1, (int) $this->config->get('content.site.module_off_cooldown_seconds', 60)));
                $this->safeForget(self::LAST_GOOD_KEY);

                return $this->site = null;
            }

            return $this->site = $this->failed($error);
        } catch (Throwable $error) {
            return $this->site = $this->failed($error);
        }

        if (($site['version'] ?? '') === '') {
            $this->log('debug', 'Content site standards are empty for this project (module off or nothing configured).');
        }

        // Written only when it differs (version), so a normal page view does not write to the cache.
        $stored = $this->lastGood();

        if ($stored === null || ($stored['version'] ?? null) !== ($site['version'] ?? null)) {
            $this->safePut(self::LAST_GOOD_KEY, $site, 604_800);
        }

        return $this->site = $site;
    }

    /** @return array<string, mixed>|null */
    private function failed(Throwable $error): ?array
    {
        $this->log('warning', 'Content site standards could not be loaded; rendering the last good answer or nothing.', $error);
        $this->safePut(self::PAUSE_KEY, 1, max(1, (int) $this->config->get('content.site.failure_cooldown_seconds', 15)));

        return $this->lastGood();
    }

    /** @return array<string, mixed>|null */
    private function lastGood(): ?array
    {
        $site = $this->safeGet(self::LAST_GOOD_KEY);

        return is_array($site) ? $site : null;
    }

    private function log(string $level, string $message, ?Throwable $error = null): void
    {
        try {
            $context = $error === null ? [] : [
                'status' => $error instanceof ContentException ? $error->status() : null,
                'code' => $error instanceof ContentException ? $error->errorCode() : $error::class,
            ];
            $this->logger?->log($level, $message, $context);
        } catch (Throwable) {
            // Logging never breaks a page.
        }
    }

    private function safeGet(string $key): mixed
    {
        try {
            return $this->cache->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function safePut(string $key, mixed $value, int $ttl): void
    {
        try {
            $this->cache->put($key, $value, $ttl);
        } catch (Throwable) {
            // Without a cache the next request asks again.
        }
    }

    private function safeForget(string $key): void
    {
        try {
            $this->cache->forget($key);
        } catch (Throwable) {
        }
    }
}
