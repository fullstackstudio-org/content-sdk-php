<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

/**
 * Site standards (Content module `site`) without a framework: the HTML for the end of `<head>` and the end of `<body>`, and the
 * Content-Security-Policy sources the customer's own code needs. The Laravel components `<x-content::head />` and
 * `<x-content::body-end />` build on this.
 *
 * Rules (from the config Content returns, see `ContentClient::getSite()`):
 * - Every value from Content is escaped; JSON-LD is encoded with all HTML-significant characters as `\u` escapes, so a business name
 *   can never end the `<script>` block.
 * - Code snippets with consent `necessary` are printed as they are, with the page nonce on every `<script>` and `<style>`.
 * - Every other snippet is an inert `<template data-fsc-code data-consent="…">`. A small inline loader (with the nonce) turns it into
 *   live elements once the visitor accepted that category: stored in `localStorage` (default key `fsc-consent`) or announced with the
 *   `window` event `fsc:consent`. Scripts are re-created so the browser runs them, with the attributes copied and the nonce added.
 * - Every `<script>` and `<style>` this class emits carries the nonce when one is given.
 */
final class Site
{
    public const CONSENT_CATEGORIES = ['analytics', 'marketing', 'preferences'];

    public const BUSINESS_TYPES = [
        'LocalBusiness', 'Plumber', 'Electrician', 'HVACBusiness', 'RoofingContractor', 'HomeAndConstructionBusiness', 'GeneralContractor',
        'ProfessionalService', 'Store', 'Restaurant',
    ];

    /** The verification keys of the contract and the meta tag name each search engine/platform reads. */
    public const VERIFICATION_META = [
        'google' => 'google-site-verification',
        'bing' => 'msvalidate.01',
        'facebook' => 'facebook-domain-verification',
        'pinterest' => 'p:domain_verify',
    ];

    public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private const DAYS = ['Mo' => 'Monday', 'Tu' => 'Tuesday', 'We' => 'Wednesday', 'Th' => 'Thursday', 'Fr' => 'Friday', 'Sa' => 'Saturday', 'Su' => 'Sunday'];

    /** Has the config anything to render? False for the empty shape (module off) and for a config that is not an array. */
    public static function isEmpty(mixed $site): bool
    {
        if (!is_array($site)) {
            return true;
        }

        return self::head($site) === '' && self::bodyEnd($site, ['clickEvents' => true]) === '';
    }

    /**
     * The HTML for the end of `<head>` (after the page meta): verification metas, favicon, apple-touch-icon, theme-color, the
     * LocalBusiness JSON-LD, the head snippets and (when one of the snippets waits for consent) the consent loader.
     *
     * @param array<string, mixed> $site the result of `getSite()`
     * @param array{nonce?: string|null, consentKey?: string, consentEvent?: string} $options
     */
    public static function head(array $site, array $options = []): string
    {
        $nonce = self::nonce($options['nonce'] ?? null);
        $html = [];
        $verification = is_array($site['verification'] ?? null) ? $site['verification'] : [];

        foreach (self::VERIFICATION_META as $key => $name) {
            $value = $verification[$key] ?? null;

            if (is_string($value) && preg_match('/^[A-Za-z0-9_\-:.=]{1,200}$/D', $value) === 1) {
                $html[] = '<meta name="' . self::e($name) . '" content="' . self::e($value) . '">';
            }
        }

        $social = is_array($site['social'] ?? null) ? $site['social'] : [];
        $favicon = is_array($social['favicon'] ?? null) ? $social['favicon'] : null;
        $faviconUrl = self::url($favicon['url'] ?? null);

        if ($faviconUrl !== null) {
            $type = $favicon['type'] ?? null;
            $typeAttr = is_string($type) && in_array($type, ['image/png', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon'], true) ? ' type="' . self::e($type) . '"' : '';
            $html[] = '<link rel="icon" href="' . self::e($faviconUrl) . '"' . $typeAttr . '>';
        }

        $touch = self::url(is_array($social['appleTouchIcon'] ?? null) ? ($social['appleTouchIcon']['url'] ?? null) : ($social['appleTouchIcon'] ?? null));

        if ($touch !== null) {
            $html[] = '<link rel="apple-touch-icon" href="' . self::e($touch) . '">';
        }

        $themeColor = $social['themeColor'] ?? null;

        if (is_string($themeColor) && preg_match('/^#[0-9a-fA-F]{6}$/D', $themeColor) === 1) {
            $html[] = '<meta name="theme-color" content="' . self::e($themeColor) . '">';
        }

        $jsonLd = self::jsonLd($site['business'] ?? null);

        if ($jsonLd !== null) {
            $html[] = '<script type="application/ld+json"' . $nonce . '>' . $jsonLd . '</script>';
        }

        $snippets = self::snippets($site, 'head');
        array_push($html, ...array_map(static fn (array $snippet): string => self::snippetHtml($snippet, $options['nonce'] ?? null), $snippets));

        if (self::needsLoader($snippets)) {
            $html[] = self::loader($options);
        }

        return implode("\n", $html);
    }

    /**
     * The HTML for right before `</body>`: the body snippets, the consent loader (when needed) and, when `clickEvents` is on in Content
     * and the caller says analytics is active (`clickEvents` option), the delegated click listener for tel:, mailto: and WhatsApp links.
     *
     * @param array<string, mixed> $site the result of `getSite()`
     * @param array{nonce?: string|null, consentKey?: string, consentEvent?: string, clickEvents?: bool} $options `clickEvents` = the
     *        analytics module is active for this site (default false)
     */
    public static function bodyEnd(array $site, array $options = []): string
    {
        $html = [];
        $snippets = self::snippets($site, 'body');
        array_push($html, ...array_map(static fn (array $snippet): string => self::snippetHtml($snippet, $options['nonce'] ?? null), $snippets));

        if (self::needsLoader($snippets)) {
            $html[] = self::loader($options);
        }

        if (($site['clickEvents'] ?? false) === true && ($options['clickEvents'] ?? false) === true) {
            $html[] = '<script' . self::nonce($options['nonce'] ?? null) . '>' . self::asset('fsc-clicks.js') . '</script>';
        }

        return implode("\n", $html);
    }

    /**
     * The LocalBusiness JSON-LD (encoded, ready to put between `<script type="application/ld+json">` tags), or null without business data.
     */
    public static function jsonLd(mixed $business): ?string
    {
        if (!is_array($business)) {
            return null;
        }

        $name = self::text($business['name'] ?? null);

        if ($name === null) {
            return null;
        }

        $type = in_array($business['type'] ?? null, self::BUSINESS_TYPES, true) ? $business['type'] : 'LocalBusiness';
        $data = ['@context' => 'https://schema.org', '@type' => $type, 'name' => $name];
        $data['url'] = self::url($business['url'] ?? null);
        $data['telephone'] = self::text($business['telephone'] ?? null);
        $data['email'] = self::text($business['email'] ?? null);

        $address = is_array($business['address'] ?? null) ? $business['address'] : null;

        if ($address !== null) {
            $postal = array_filter([
                'streetAddress' => self::text($address['streetAddress'] ?? null),
                'postalCode' => self::text($address['postalCode'] ?? null),
                'addressLocality' => self::text($address['addressLocality'] ?? null),
                'addressRegion' => self::text($address['addressRegion'] ?? null),
                'addressCountry' => self::text($address['addressCountry'] ?? null),
            ], static fn (?string $value): bool => $value !== null);
            $data['address'] = $postal !== [] ? ['@type' => 'PostalAddress', ...$postal] : null;
        }

        $geo = is_array($business['geo'] ?? null) ? $business['geo'] : null;

        if ($geo !== null && is_numeric($geo['latitude'] ?? null) && is_numeric($geo['longitude'] ?? null)) {
            $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) $geo['latitude'], 'longitude' => (float) $geo['longitude']];
        }

        $hours = [];

        foreach (is_array($business['openingHours'] ?? null) ? $business['openingHours'] : [] as $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $days = array_values(array_filter(array_map(
                static fn (mixed $day): ?string => is_string($day) ? (self::DAYS[$day] ?? null) : null,
                is_array($slot['days'] ?? null) ? $slot['days'] : [],
            )));
            $opens = $slot['opens'] ?? null;
            $closes = $slot['closes'] ?? null;

            if ($days !== [] && self::time($opens) && self::time($closes)) {
                $hours[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days, 'opens' => $opens, 'closes' => $closes];
            }
        }

        $data['openingHoursSpecification'] = $hours !== [] ? $hours : null;
        $areas = array_values(array_filter(array_map([self::class, 'text'], is_array($business['areaServed'] ?? null) ? $business['areaServed'] : [])));
        $data['areaServed'] = $areas !== [] ? array_map(static fn (string $area): array => ['@type' => 'Place', 'name' => $area], $areas) : null;
        $data['priceRange'] = self::text($business['priceRange'] ?? null);
        $data['logo'] = self::url($business['logo'] ?? null);
        $data['image'] = self::url($business['image'] ?? null);
        $sameAs = array_values(array_filter(array_map([self::class, 'url'], is_array($business['sameAs'] ?? null) ? $business['sameAs'] : [])));
        $data['sameAs'] = $sameAs !== [] ? $sameAs : null;
        $data = array_filter($data, static fn (mixed $value): bool => $value !== null);
        $json = json_encode($data, self::JSON_FLAGS);

        return $json === false ? null : $json;
    }

    /**
     * Content-Security-Policy sources the customer's own code needs, per directive, derived from the `src`/`href` attributes of the
     * snippets (and absolute URLs inside inline scripts): `script-src` (+ `connect-src`, scripts usually report back to their own host),
     * `img-src`, `frame-src`, `style-src`. Directives without a host are left out. The inline loader and the click listener need only the
     * nonce, which the components add.
     *
     * @param array<string, mixed> $site
     * @return array<string, list<string>>
     */
    public static function csp(array $site): array
    {
        $out = ['script-src' => [], 'connect-src' => [], 'img-src' => [], 'frame-src' => [], 'style-src' => []];
        $add = static function (string $directive, ?string $origin) use (&$out): void {
            if ($origin !== null && !in_array($origin, $out[$directive], true)) {
                $out[$directive][] = $origin;
            }
        };

        foreach ([...self::snippets($site, 'head'), ...self::snippets($site, 'body')] as $snippet) {
            $html = $snippet['html'];

            if (preg_match_all('/<(script|img|iframe|link|source)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i', $html, $tags, PREG_SET_ORDER) !== false) {
                foreach ($tags as $tag) {
                    $element = strtolower($tag[1]);
                    $attributes = self::attributes($tag[2]);

                    if ($element === 'script') {
                        $origin = self::origin($attributes['src'] ?? null);
                        $add('script-src', $origin);
                        $add('connect-src', $origin);
                    } elseif ($element === 'img' || $element === 'source') {
                        $add('img-src', self::origin($attributes['src'] ?? null));
                    } elseif ($element === 'iframe') {
                        $add('frame-src', self::origin($attributes['src'] ?? null));
                    } else {
                        $rel = strtolower($attributes['rel'] ?? '');
                        $origin = self::origin($attributes['href'] ?? null);

                        if (str_contains($rel, 'stylesheet') || ($attributes['as'] ?? '') === 'style') {
                            $add('style-src', $origin);
                        } elseif (($attributes['as'] ?? '') === 'script') {
                            $add('script-src', $origin);
                        } elseif (str_contains($rel, 'preconnect') || str_contains($rel, 'dns-prefetch')) {
                            $add('connect-src', $origin);
                        }
                    }
                }
            }

            // Loaders such as Google Tag Manager build their script URL inside an inline script.
            if (preg_match_all('/<script\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>(.*?)<\/script\s*>/is', $html, $scripts) !== false) {
                foreach ($scripts[1] as $body) {
                    if (preg_match_all('#https://[a-z0-9.-]+(?::\d{1,5})?(?=[/\'"`?\s]|$)#i', $body, $urls) !== false) {
                        foreach ($urls[0] as $url) {
                            $origin = self::origin($url);
                            $add('script-src', $origin);
                            $add('connect-src', $origin);
                        }
                    }
                }
            }
        }

        return array_filter($out, static fn (array $sources): bool => $sources !== []);
    }

    /**
     * Merge the site sources into an existing policy: a string (`script-src 'self'; img-src *`) gives a string back, an array
     * (`['script-src' => ["'self'"], ...]`, values may be a list or a space separated string) gives an array back. A directive that is
     * missing starts from `default-src` (what the browser would use); when there is no `default-src` either, the directive is left out,
     * because adding it would forbid everything else. `'none'` is dropped once a source is added.
     *
     * @param string|array<string, string|list<string>> $policy
     * @param array<string, list<string>> $sources e.g. `Site::csp($site)`
     * @return ($policy is string ? string : array<string, list<string>>)
     */
    public static function mergeCsp(string|array $policy, array $sources): string|array
    {
        $directives = [];

        if (is_string($policy)) {
            foreach (array_filter(array_map('trim', explode(';', $policy))) as $part) {
                $tokens = preg_split('/\s+/', $part) ?: [];
                $directives[strtolower((string) array_shift($tokens))] = array_values($tokens);
            }
        } else {
            foreach ($policy as $name => $value) {
                $directives[strtolower((string) $name)] = is_array($value) ? array_values(array_map('strval', $value)) : (preg_split('/\s+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            }
        }

        foreach ($sources as $name => $add) {
            if ($add === []) {
                continue;
            }

            $current = $directives[$name] ?? ($name === 'frame-src' ? ($directives['child-src'] ?? null) : null) ?? $directives['default-src'] ?? null;

            if ($current === null) {
                continue;
            }

            $current = array_values(array_filter($current, static fn (string $token): bool => strtolower($token) !== "'none'"));
            $directives[$name] = array_values(array_unique([...$current, ...$add]));
        }

        if (is_array($policy)) {
            return $directives;
        }

        $out = [];

        foreach ($directives as $name => $tokens) {
            $out[] = trim($name . ' ' . implode(' ', $tokens));
        }

        return implode('; ', $out);
    }

    /**
     * The snippets for one placement that are safe to render: `html` a non-empty string, a known consent category (unknown = treated as
     * the strictest, never as necessary), and no `</template` that could end the inert wrapper early.
     *
     * @param array<string, mixed> $site
     * @return list<array{name: string, consent: string, html: string}>
     */
    public static function snippets(array $site, string $placement): array
    {
        $out = [];

        foreach (is_array($site['code'] ?? null) ? $site['code'] : [] as $snippet) {
            if (!is_array($snippet) || ($snippet['placement'] ?? 'head') !== $placement || ($snippet['enabled'] ?? true) === false) {
                continue;
            }

            $html = $snippet['html'] ?? null;

            if (!is_string($html) || trim($html) === '' || stripos($html, '</template') !== false) {
                continue;
            }

            $consent = $snippet['consent'] ?? null;
            $consent = $consent === 'necessary' || in_array($consent, self::CONSENT_CATEGORIES, true) ? $consent : 'marketing';
            $out[] = ['name' => is_string($snippet['name'] ?? null) ? $snippet['name'] : '', 'consent' => $consent, 'html' => $html];
        }

        return $out;
    }

    /**
     * Adds ` nonce="…"` to every opening `<script>` and `<style>` tag of an HTML fragment (an existing nonce is replaced). The text of a
     * script or style element is copied unchanged, so a `"<script>"` inside JavaScript is never touched.
     */
    public static function withNonce(string $html, ?string $nonce): string
    {
        if ($nonce === null || $nonce === '') {
            return $html;
        }

        $out = '';
        $offset = 0;
        $length = strlen($html);
        $attr = ' nonce="' . self::e($nonce) . '"';

        while ($offset < $length && preg_match('/<(script|style)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i', $html, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$tag, $start] = $match[0];
            $name = $match[1][0];
            $attributes = (string) preg_replace('/\s+nonce\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)|\s+nonce(?=[\s\/]|$)/i', '', $match[2][0]);
            $selfClosing = str_ends_with(rtrim($attributes), '/');
            $attributes = $selfClosing ? rtrim(substr(rtrim($attributes), 0, -1)) : $attributes;
            $out .= substr($html, $offset, $start - $offset) . '<' . $name . $attributes . $attr . ($selfClosing ? ' />' : '>');
            $offset = $start + strlen($tag);

            // Raw text up to the matching end tag stays as it is.
            if (preg_match('/<\/' . $name . '\s*>/i', $html, $end, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $stop = $end[0][1] + strlen($end[0][0]);
                $out .= substr($html, $offset, $stop - $offset);
                $offset = $stop;
            } else {
                $out .= substr($html, $offset);
                $offset = $length;
            }
        }

        return $out . substr($html, $offset);
    }

    /** @param array{name: string, consent: string, html: string} $snippet */
    private static function snippetHtml(array $snippet, ?string $nonce): string
    {
        $html = self::withNonce($snippet['html'], $nonce);

        if ($snippet['consent'] === 'necessary') {
            return $html;
        }

        return '<template data-fsc-code data-consent="' . self::e($snippet['consent']) . '"' . ($snippet['name'] !== '' ? ' data-name="' . self::e($snippet['name']) . '"' : '') . '>'
            . $html . '</template>';
    }

    /** @param list<array{name: string, consent: string, html: string}> $snippets */
    private static function needsLoader(array $snippets): bool
    {
        foreach ($snippets as $snippet) {
            if ($snippet['consent'] !== 'necessary') {
                return true;
            }
        }

        return false;
    }

    /**
     * The inline consent loader. Idempotent: the second copy on a page (head and body-end) only scans for new templates.
     *
     * @param array{nonce?: string|null, consentKey?: string, consentEvent?: string} $options
     */
    private static function loader(array $options): string
    {
        $config = json_encode([
            'key' => (string) ($options['consentKey'] ?? 'fsc-consent'),
            'event' => (string) ($options['consentEvent'] ?? 'fsc:consent'),
            'all' => self::CONSENT_CATEGORIES,
        ], self::JSON_FLAGS);

        return '<script' . self::nonce($options['nonce'] ?? null) . '>' . str_replace('__CONFIG__', (string) $config, self::asset('fsc-code.js')) . '</script>';
    }



    /** @var array<string, string> */
    private static array $assets = [];

    /** An inline script from `resources/assets` (read once per process), without its leading comment block and trailing whitespace. */
    private static function asset(string $file): string
    {
        if (!isset(self::$assets[$file])) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/resources/assets/' . $file);
            self::$assets[$file] = trim((string) preg_replace('#^\s*/\*.*?\*/\s*#s', '', $source));
        }

        return self::$assets[$file];
    }

    /** @return array<string, string> lowercase attribute name => value (entities decoded) */
    private static function attributes(string $source): array
    {
        $out = [];

        if (preg_match_all('/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $source, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $value = ($match[2] ?? '') !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));
                $out[strtolower($match[1])] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $out;
    }

    /** `https://host[:port]` of an absolute http(s) or protocol-relative URL; null for relative URLs (covered by 'self') and anything else. */
    private static function origin(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || !isset($parts['host'])
            || preg_match('/^[a-z0-9.-]+$/i', $parts['host']) !== 1) {
            return null;
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function url(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^https?://[^\s<>"\']+$#iD', $value) === 1 ? $value : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function time(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $value) === 1;
    }

    private static function nonce(?string $nonce): string
    {
        return $nonce !== null && $nonce !== '' ? ' nonce="' . self::e($nonce) . '"' : '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
