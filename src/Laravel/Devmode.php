<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use Illuminate\Http\Request;

/**
 * The dev panel (`resources/assets/fsc-devmode.js`) is OFF for everybody: the server only writes its script tags when the request carries the cookie
 * `content.devmode.cookie` (default `fsc_devmode`) with the value 1, set by visiting any page with `?fsc_devmode=1` ({@see DevmodeSwitch}).
 * Without the cookie, and for crawlers, the HTML contains no devmode bit at all, so cached pages stay identical for everyone. A response that did
 * get the tags is sent `Cache-Control: private, no-cache` (the same mechanism as a server-decided slot); the CDN should also skip its cache for
 * requests with `fsc_devmode=1` in the cookie header.
 */
final class Devmode
{
    public const DEFAULT_COOKIE = 'fsc_devmode';

    public const PARAM = 'fsc_devmode';

    public const RENDERED = 'fsc.devmode';

    public static function enabled(): bool
    {
        return (bool) config('content.devmode.enabled', true);
    }

    public static function cookie(): string
    {
        $name = (string) config('content.devmode.cookie', self::DEFAULT_COOKIE);

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $name) === 1 ? $name : self::DEFAULT_COOKIE;
    }

    /**
     * The path prefix of the panel assets as a URL path (default `/_fsc/d`).
     */
    public static function base(): string
    {
        return '/' . (trim((string) config('content.devmode.path', '_fsc/d'), '/') ?: '_fsc/d');
    }

    /**
     * Whether the request carries the cookie. Read from the raw header: the cookie is set by the SDK itself, without Laravel's cookie encryption,
     * so the `web` group's EncryptCookies would drop it from `$request->cookie()`.
     */
    public static function requested(Request $request): bool
    {
        $header = (string) $request->headers->get('Cookie', '');

        return preg_match('/(?:^|;\s*)' . preg_quote(self::cookie(), '/') . '=1(?:;|$)/', $header) === 1;
    }

    /** Whether this request gets the panel: devmode on, the cookie is there and the visitor is not a crawler or prefetch. */
    public static function active(Request $request): bool
    {
        return self::enabled() && self::requested($request) && !Crawler::is($request);
    }

    /**
     * The tags for the `<head>`: the display flags (a blocking script, so it runs before the page's own scripts) and the panel (deferred). Empty
     * without the cookie. Written once per request (head and `<x-content::devmode />` can both ask).
     */
    public static function tags(?Request $request = null, ?string $nonce = null): string
    {
        $request ??= request();

        if (!self::active($request) || $request->attributes->get(self::RENDERED) === true) {
            return '';
        }

        $request->attributes->set(self::RENDERED, true);
        $request->attributes->set(SlotDecisions::PRIVATE_ATTRIBUTE, true);

        $version = rawurlencode(ContentClient::VERSION);
        $base = self::base();
        $nonceAttr = $nonce !== null && $nonce !== '' ? ' nonce="' . self::e($nonce) . '"' : '';
        $data = [
            'v' => ContentClient::VERSION,
            'cookie' => self::cookie(),
            'me' => IntelligenceProxy::enabled() ? '/' . (trim((string) config('content.intelligence.path', '_fsc/i'), '/') ?: '_fsc/i') . '/me' : '',
            'portal' => self::portal(),
            'scripts' => self::scripts(),
            'aliases' => self::aliases(),
        ];
        $attrs = '';

        foreach ($data as $name => $value) {
            if ($value === '' || $value === []) {
                continue;
            }

            $attrs .= ' data-' . $name . '="' . self::e(is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_SLASHES) : (string) $value) . '"';
        }

        return '<script src="' . $base . '/flags.js?v=' . $version . '"' . $nonceAttr . '></script>' . "\n"
            . '<script src="' . $base . '/devmode.js?v=' . $version . '" defer' . $attrs . $nonceAttr . '></script>';
    }

    /** The portal link template with `{ref}`, or '' when none is configured (the link is then not shown). */
    private static function portal(): string
    {
        $url = (string) config('content.devmode.portal_url', '');

        return preg_match('~^https://[^\s"<>]+\{ref\}[^\s"<>]*$~', $url) === 1 ? $url : '';
    }

    /** @return list<string> same-origin script paths of the site's own panel extensions */
    private static function scripts(): array
    {
        $list = config('content.devmode.scripts', []);

        return is_array($list)
            ? array_values(array_filter($list, static fn ($src): bool => is_string($src) && preg_match('~^/(?!/)[^\s"<>]*$~', $src) === 1))
            : [];
    }

    /** @return list<string> extra console names for `devmode()` (e.g. an old name) */
    private static function aliases(): array
    {
        $list = config('content.devmode.aliases', []);

        return is_array($list)
            ? array_values(array_filter($list, static fn ($name): bool => is_string($name) && preg_match('/^[A-Za-z_$][A-Za-z0-9_$]{0,40}$/D', $name) === 1))
            : [];
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
