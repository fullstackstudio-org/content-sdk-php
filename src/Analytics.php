<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

/**
 * Analytics (Content module `analytics`, Umami) without a framework: the tags for a page and the Content-Security-Policy sources they
 * need. The Laravel component `<x-content::analytics />` and the first-party proxy routes build on this.
 *
 * Rules (from the config Content returns, see `ContentClient::getAnalyticsConfig()`):
 * - The Umami tracker is cookieless: pageviews and custom events run without consent.
 * - Heatmaps and session replay (recorder.js) are loaded by the helper script only after the visitor accepted cookies.
 * - First-touch attribution (utm_*, ad click ids, landing URL, external referrer) is kept per visit and sent with forms.
 */
final class Analytics
{
    public const MODE_FIRST_PARTY = 'first-party';
    public const MODE_DIRECT = 'direct';

    public const DEFAULT_HOST = 'https://analytics.fullstackstudio.nl';

    public const TRACKER_ID = 'fsc-analytics';

    /** Used when Content does not send a list (older server). */
    public const DEFAULT_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'gbraid', 'wbraid', 'dclid', 'gclsrc', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'twclid', 'rdt_cid', 'epik', 'sccid',
    ];

    /**
     * The HTML for the page: the tracker tag (deferred) and the helper script. Empty when the config is not ready (module off, not
     * provisioned yet, or Content unreachable): a page must never get a broken tag.
     *
     * @param array<string, mixed> $config the result of `getAnalyticsConfig()`
     * @param array{mode?: string, base?: string, host?: string, nonce?: string|null, consentKey?: string, consentEvent?: string, helperSrc?: string} $options
     *        `base` = the first-party path prefix as a URL path (default `/_fsc/a`); `helperSrc` = URL of the helper script (default `{base}/fsc.js`)
     */
    public static function tags(array $config, array $options = []): string
    {
        $websiteId = $config['websiteId'] ?? null;

        if (($config['ready'] ?? false) !== true || !is_string($websiteId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $websiteId) !== 1) {
            return '';
        }

        $mode = ($options['mode'] ?? self::MODE_FIRST_PARTY) === self::MODE_DIRECT ? self::MODE_DIRECT : self::MODE_FIRST_PARTY;
        $base = '/' . trim((string) ($options['base'] ?? '/_fsc/a'), '/');
        $nonce = isset($options['nonce']) && is_string($options['nonce']) && $options['nonce'] !== '' ? ' nonce="' . self::e($options['nonce']) . '"' : '';
        $domains = array_values(array_filter(is_array($config['domains'] ?? null) ? $config['domains'] : [], 'is_string'));
        $params = is_array($config['attribution']['params'] ?? null) ? array_values(array_filter($config['attribution']['params'], 'is_string')) : self::DEFAULT_PARAMS;
        $html = [];

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
        $tracker = '<script id="' . self::TRACKER_ID . '" defer src="' . self::e($script) . '"'
            . ' data-website-id="' . self::e($websiteId) . '"'
            . ' data-host-url="' . self::e($hostUrl) . '"'
            . ' data-do-not-track="true"'
            . ($domains !== [] ? ' data-domains="' . self::e(implode(',', $domains)) . '"' : '')
            . ' data-fsc-recorder-src="' . self::e($recorder) . '"'
            . $nonce . '></script>';
        $html[] = $tracker;

        $helper = (string) ($options['helperSrc'] ?? $base . '/fsc.js');
        $html[] = '<script defer src="' . self::e($helper) . '"'
            . ' data-tracker="' . self::TRACKER_ID . '"'
            . ' data-consent-key="' . self::e((string) ($options['consentKey'] ?? 'fsc-consent')) . '"'
            . ' data-consent-event="' . self::e((string) ($options['consentEvent'] ?? 'fsc:consent')) . '"'
            . ' data-params="' . self::e(implode(',', $params)) . '"'
            . $nonce . '></script>';

        return implode("\n", $html);
    }

    /**
     * Content-Security-Policy sources per directive for analytics. First-party: everything is 'self' (plus the recorder's worker, which
     * starts from a blob: or data: URL). Direct: the analytics host for scripts and collect calls.
     *
     * @return array<string, list<string>>
     */
    public static function csp(string $mode = self::MODE_FIRST_PARTY, string $host = self::DEFAULT_HOST): array
    {
        $origin = self::origin($host) ?? self::DEFAULT_HOST;

        if ($mode === self::MODE_DIRECT) {
            return ['script-src' => [$origin], 'connect-src' => [$origin], 'worker-src' => ['blob:', 'data:']];
        }

        return ['script-src' => ["'self'"], 'connect-src' => ["'self'"], 'worker-src' => ["'self'", 'blob:', 'data:']];
    }

    /**
     * Merge the analytics sources into an existing policy string (`script-src 'self'; img-src *` …). Directives that are missing are added.
     */
    public static function mergeCsp(string $policy, string $mode = self::MODE_FIRST_PARTY, string $host = self::DEFAULT_HOST): string
    {
        $directives = [];

        foreach (array_filter(array_map('trim', explode(';', $policy))) as $part) {
            $tokens = preg_split('/\s+/', $part) ?: [];
            $name = strtolower((string) array_shift($tokens));
            $directives[$name] = $tokens;
        }

        foreach (self::csp($mode, $host) as $name => $sources) {
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
