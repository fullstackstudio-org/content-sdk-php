<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\Analytics;
use FullStackStudio\Content\ContentClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * The analytics config of this site, cached in the Laravel cache (`content.analytics.config_ttl`, default 5 minutes). A failure (module
 * off, not provisioned, Content unreachable) is cached for one minute too, so a site without analytics makes no call per page view.
 * Never throws.
 */
final class AnalyticsSettings
{
    public const CACHE_KEY = 'fsc-analytics:config:v1';

    public function __construct(private readonly ContentClient $client, private readonly Cache $cache, private readonly Config $config)
    {
    }

    public static function make(): self
    {
        $app = app();
        $store = $app['config']->get('content.cache.store');

        return new self($app->make(ContentClient::class), $app['cache']->store($store ?: null), $app['config']);
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('content.analytics.enabled', true);
    }

    public function mode(): string
    {
        return $this->config->get('content.analytics.mode') === Analytics::MODE_DIRECT ? Analytics::MODE_DIRECT : Analytics::MODE_FIRST_PARTY;
    }

    public function path(): string
    {
        return trim((string) $this->config->get('content.analytics.path', '_fsc/a'), '/') ?: '_fsc/a';
    }

    /** The upstream Umami host (https, no trailing slash). */
    public function host(): string
    {
        $host = rtrim((string) $this->config->get('content.analytics.host', Analytics::DEFAULT_HOST), '/');

        return preg_match('#^https://[a-z0-9.-]+(?::\d{1,5})?$#iD', $host) === 1 ? $host : Analytics::DEFAULT_HOST;
    }

    /**
     * The config from Content, or null when analytics is not available for this site.
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        try {
            $cached = $this->cache->get(self::CACHE_KEY);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached) && array_key_exists('config', $cached)) {
            return is_array($cached['config']) ? $cached['config'] : null;
        }

        try {
            $config = $this->client->getAnalyticsProxyConfig(['cache' => false]);
            $config = ($config['ready'] ?? false) === true ? $config : null;
        } catch (Throwable) {
            $config = null;
        }

        try {
            $ttl = $config !== null ? max(30, (int) $this->config->get('content.analytics.config_ttl', 300)) : 60;
            $this->cache->put(self::CACHE_KEY, ['config' => $config], $ttl);
        } catch (Throwable) {
            // No cache: the next page asks again.
        }

        return $config;
    }

    /** The website id the first-party proxy accepts, or null (also when the engine is `native`). */
    public function websiteId(): ?string
    {
        $config = $this->current();
        $id = $config['websiteId'] ?? null;

        return is_string($id) && Analytics::engine($config ?? []) !== 'native' ? $id : null;
    }

    /** `umami`, `native` or `both` (D9); `umami` without a config. */
    public function engine(): string
    {
        return Analytics::engine($this->current() ?? []);
    }

    /**
     * The native sitekey this site may forward events for, or null: engine `umami`, native not ready in the Hub, or no signing key
     * (a publishable token, or a Hub without the master secret). Without a signing key every visitor would reach the collector with
     * this pod's IP, so the native tracker is not rendered at all.
     */
    public function nativeSitekey(): ?string
    {
        $config = $this->current() ?? [];

        if (Analytics::engine($config) === 'umami' || Analytics::nativeSigningKey($config) === null) {
            return null;
        }

        return Analytics::nativeSitekey($config);
    }

    /** The per-site signing key for the collector proxy (never sent to a browser). */
    public function nativeSigningKey(): ?string
    {
        return $this->nativeSitekey() !== null ? Analytics::nativeSigningKey($this->current() ?? []) : null;
    }

    /** The collector's ingest URL inside the cluster (`content.analytics.collector_url`), http(s) only. */
    public function collectorUrl(): ?string
    {
        $url = trim((string) $this->config->get('content.analytics.collector_url', Analytics::DEFAULT_COLLECTOR_URL));

        return preg_match('#^https?://[A-Za-z0-9.-]+(?::\d{1,5})?(?:/[A-Za-z0-9._~/-]*)?$#D', $url) === 1 ? $url : null;
    }
}
