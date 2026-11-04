<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

/**
 * Analytics (Content module `analytics`) without a framework: the tags for a page and the Content-Security-Policy sources they need.
 * The Laravel component `<x-content::analytics />` and the first-party proxy routes build on this.
 *
 * The Hub's `engine` decides the tracker: `umami` (default, also for a Hub without the field), `native` (the Hub's own engine, `fsa.js`)
 * or `both` (the double-measure period: both trackers, each counting on its own).
 *
 * Rules (from the config Content returns, see `ContentClient::getAnalyticsConfig()`):
 * - Both trackers are cookieless: pageviews and custom events run without consent.
 * - Heatmaps and session replay (Umami recorder.js) are loaded by the helper script only after the visitor accepted cookies; the native
 *   tracker keeps a persistent visitor id and sends heatmap clicks only after consent (never with Global Privacy Control for the id).
 * - First-touch attribution (utm_*, ad click ids, landing URL, external referrer) is kept per visit and sent with forms.
 * - The native tracker is always first-party: it posts to this site, which signs and forwards to the collector (no public collector).
 */
final class Analytics
{
    public const MODE_FIRST_PARTY = 'first-party';
    public const MODE_DIRECT = 'direct';

    public const DEFAULT_HOST = 'https://analytics.fullstackstudio.nl';

    /** The native collector inside the FSS cluster (Service `collector` in ns `analytics`; no public route). */
    public const DEFAULT_COLLECTOR_URL = 'http://collector.analytics.svc.cluster.local/e';

    public const TRACKER_ID = 'fsc-analytics';

    public const NATIVE_ID = 'fsa';

    public const ENGINES = ['umami', 'native', 'both'];

    /** Used when Content does not send a list (older server). */
    public const DEFAULT_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'gbraid', 'wbraid', 'dclid', 'gclsrc', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'twclid', 'rdt_cid', 'epik', 'sccid',
    ];

    /**
     * The HTML for the page: the tracker tag(s) for the engine (deferred) and the helper script. Empty when nothing is ready (module off,
     * not provisioned yet, or Content unreachable): a page must never get a broken tag.
     *
     * @param array<string, mixed> $config the result of `getAnalyticsConfig()`
     * @param array{mode?: string, base?: string, host?: string, nonce?: string|null, consentKey?: string, consentEvent?: string, helperSrc?: string, native?: bool, status?: int|null, version?: string} $options
     *        `base` = the first-party path prefix as a URL path (default `/_fsc/a`); `helperSrc` = URL of the helper script (default `{base}/fsc.js`);
     *        `native` = false keeps the native tracker out (the site has no signing key, so its proxy could not forward); `status` = the
     *        HTTP status of the page (404 marks a not-found page)
     */
    public static function tags(array $config, array $options = []): string
    {
        $engine = self::engine($config);
        $umami = self::umamiReady($config) && $engine !== 'native';
        $native = ($options['native'] ?? true) !== false && $engine !== 'umami' && self::nativeSitekey($config) !== null;

        if (!$umami && !$native) {
            return '';
        }

        $websiteId = (string) ($config['websiteId'] ?? '');
        $mode = ($options['mode'] ?? self::MODE_FIRST_PARTY) === self::MODE_DIRECT ? self::MODE_DIRECT : self::MODE_FIRST_PARTY;
        $base = '/' . trim((string) ($options['base'] ?? '/_fsc/a'), '/');
        $nonce = isset($options['nonce']) && is_string($options['nonce']) && $options['nonce'] !== '' ? ' nonce="' . self::e($options['nonce']) . '"' : '';
        $domains = array_values(array_filter(is_array($config['domains'] ?? null) ? $config['domains'] : [], 'is_string'));
        $params = is_array($config['attribution']['params'] ?? null) ? array_values(array_filter($config['attribution']['params'], 'is_string')) : self::DEFAULT_PARAMS;
        $consentKey = (string) ($options['consentKey'] ?? 'fsc-consent');
        $consentEvent = (string) ($options['consentEvent'] ?? 'fsc:consent');
        $html = [];

        if ($umami) {
            if ($mode === self::MODE_FIRST_PARTY) {
                $script = $base . '/script.js';
                $recorder = $base . '/recorder.js';
                $hostUrl = $base;
            } else {
                $host = self::host($config, $options);
                $script = self::httpsUrl($config['scriptUrl'] ?? null) ?? $host . '/script.js';
                $recorder = self::httpsUrl($config['recorderUrl'] ?? null) ?? $host . '/recorder.js';
                $hostUrl = $host;
                $html[] = '<link rel="preconnect" href="' . self::e($host) . '" crossorigin>';
                $html[] = '<link rel="dns-prefetch" href="' . self::e($host) . '">';
            }

            // No data-exclude-search: the query (utm_*) has to reach Umami. data-do-not-track: a browser with DNT on is not counted.
            $html[] = '<script id="' . self::TRACKER_ID . '" defer src="' . self::e($script) . '"'
                . ' data-website-id="' . self::e($websiteId) . '"'
                . ' data-host-url="' . self::e($hostUrl) . '"'
                . ' data-do-not-track="true"'
                . ($domains !== [] ? ' data-domains="' . self::e(implode(',', $domains)) . '"' : '')
                . ' data-fsc-recorder-src="' . self::e($recorder) . '"'
                . $nonce . '></script>';
        }

        if ($native) {
            $status = isset($options['status']) && is_int($options['status']) && $options['status'] >= 100 && $options['status'] <= 599 ? $options['status'] : null;
            $version = preg_replace('/[^0-9A-Za-z.-]/', '', (string) ($options['version'] ?? ContentClient::VERSION));
            // Always first-party (also in direct mode): the collector has no public route.
            $html[] = '<script id="' . self::NATIVE_ID . '" defer src="' . self::e($base . '/fsa.js?v=' . $version) . '"'
                . ' data-sitekey="' . self::e((string) self::nativeSitekey($config)) . '"'
                . ' data-endpoint="' . self::e($base . '/e') . '"'
                . ' data-consent-key="' . self::e($consentKey) . '"'
                . ' data-consent-event="' . self::e($consentEvent) . '"'
                . ' data-params="' . self::e(implode(',', $params)) . '"'
                . ($status !== null ? ' data-status="' . $status . '"' : '')
                . $nonce . '></script>';
        }

        $helper = (string) ($options['helperSrc'] ?? $base . '/fsc.js');
        $html[] = '<script defer src="' . self::e($helper) . '"'
            . ' data-tracker="' . self::TRACKER_ID . '"'
            . ' data-consent-key="' . self::e($consentKey) . '"'
            . ' data-consent-event="' . self::e($consentEvent) . '"'
            . ' data-params="' . self::e(implode(',', $params)) . '"'
            . $nonce . '></script>';

        return implode("\n", $html);
    }

    /** The engine of a Hub config: `umami` when the Hub predates the field or sends something unknown. @param array<string, mixed> $config */
    public static function engine(array $config): string
    {
        $engine = $config['engine'] ?? 'umami';

        return is_string($engine) && in_array($engine, self::ENGINES, true) ? $engine : 'umami';
    }

    /** The native sitekey when the Hub says native tracking is ready, else null. @param array<string, mixed> $config */
    public static function nativeSitekey(array $config): ?string
    {
        $native = is_array($config['native'] ?? null) ? $config['native'] : [];
        $key = $native['sitekey'] ?? null;

        return ($native['ready'] ?? false) === true && is_string($key) && preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $key) === 1 ? $key : null;
    }

    /** The per-site proxy signing key (only a server token receives it). @param array<string, mixed> $config */
    public static function nativeSigningKey(array $config): ?string
    {
        $key = is_array($config['native'] ?? null) ? ($config['native']['signingKey'] ?? null) : null;

        return is_string($key) && preg_match('/^[0-9a-f]{64}$/D', $key) === 1 ? $key : null;
    }

    /**
     * The headers that prove the visitor IP to the collector (contract `collector/internal/proxyauth`):
     * `X-Fsc-Sig = hex(HMAC-SHA256(signingKey, ts + "\n" + ip + "\n" + hex(sha256(body))))`.
     *
     * @return array{X-Fsc-Client-Ip: string, X-Fsc-Ts: string, X-Fsc-Sig: string}
     */
    public static function signHeaders(string $signingKey, string $ip, string $body, ?int $timestamp = null): array
    {
        $ts = (string) ($timestamp ?? time());

        return [
            'X-Fsc-Client-Ip' => $ip,
            'X-Fsc-Ts' => $ts,
            'X-Fsc-Sig' => hash_hmac('sha256', $ts . "\n" . $ip . "\n" . hash('sha256', $body), $signingKey),
        ];
    }

    /** @param array<string, mixed> $config */
    private static function umamiReady(array $config): bool
    {
        $websiteId = $config['websiteId'] ?? null;

        return ($config['ready'] ?? false) === true && is_string($websiteId) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $websiteId) === 1;
    }

    /**
     * Content-Security-Policy sources per directive for analytics. First-party: everything is 'self' (plus the recorder's worker, which
     * starts from a blob: or data: URL). Direct: the analytics host for scripts and collect calls, plus 'self' when the native tracker
     * runs (`$native`; it is always first-party).
     *
     * @return array<string, list<string>>
     */
    public static function csp(string $mode = self::MODE_FIRST_PARTY, string $host = self::DEFAULT_HOST, bool $native = false): array
    {
        $origin = self::origin($host) ?? self::DEFAULT_HOST;

        if ($mode === self::MODE_DIRECT) {
            $self = $native ? ["'self'"] : [];

            return ['script-src' => [...$self, $origin], 'connect-src' => [...$self, $origin], 'worker-src' => ['blob:', 'data:']];
        }

        return ['script-src' => ["'self'"], 'connect-src' => ["'self'"], 'worker-src' => ["'self'", 'blob:', 'data:']];
    }

    /**
     * Merge the analytics sources into an existing policy string (`script-src 'self'; img-src *` …). Directives that are missing are added.
     */
    public static function mergeCsp(string $policy, string $mode = self::MODE_FIRST_PARTY, string $host = self::DEFAULT_HOST, bool $native = false): string
    {
        $directives = [];

        foreach (array_filter(array_map('trim', explode(';', $policy))) as $part) {
            $tokens = preg_split('/\s+/', $part) ?: [];
            $name = strtolower((string) array_shift($tokens));
            $directives[$name] = $tokens;
        }

        foreach (self::csp($mode, $host, $native) as $name => $sources) {
            $directives[$name] = array_values(array_unique([...($directives[$name] ?? []), ...$sources]));
        }

        $out = [];

        foreach ($directives as $name => $sources) {
            $out[] = trim($name . ' ' . implode(' ', $sources));
        }

        return implode('; ', $out);
    }

    /** @param array<string, mixed> $config @param array<string, mixed> $options */
    private static function host(array $config, array $options): string
    {
        foreach ([$options['host'] ?? null, $config['hostUrl'] ?? null] as $candidate) {
            $url = self::httpsUrl($candidate);

            if ($url !== null) {
                return rtrim($url, '/');
            }
        }

        return self::DEFAULT_HOST;
    }

    private static function httpsUrl(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^https://[a-z0-9.-]+(?::\d{1,5})?(?:/[A-Za-z0-9._~/-]*)?$#iD', $value) === 1 ? $value : null;
    }

    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return null;
        }

        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
