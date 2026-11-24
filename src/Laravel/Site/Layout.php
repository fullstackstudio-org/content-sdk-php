<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\Laravel\Auto\Auto;
use Throwable;

/**
 * Which layout the default views extend: `content.auto.layout`; else the site's own `layouts.app` when it exists (the convention of the
 * example site); else the small package layout `content::layouts.site` (head, content section, scripts), so a fresh app renders.
 *
 * Both layout styles work. A classic layout prints the content with `@yield('<section>')`. A component layout (`{{ $slot }}`, as Breeze
 * and the starter kits write `layouts/app.blade.php`) cannot be extended: for it {@see self::name()} returns the small bridge view
 * {@see self::BRIDGE}, which renders the section and passes it to the layout as `$slot`.
 */
final class Layout
{
    public const PACKAGE = 'content::layouts.site';

    /** Renders a component layout ({@see self::target()}) with the content section as `$slot`. */
    public const BRIDGE = 'content::layouts.slot';

    /** @var array<string, bool> layout file => component layout (per process; the file does not change in a running app) */
    private static array $components = [];

    /** The view the default views `@extends`: the layout itself, or {@see self::BRIDGE} for a component layout. */
    public static function name(): string
    {
        $target = self::target();

        return $target !== self::PACKAGE && self::isComponent($target) ? self::BRIDGE : $target;
    }

    /** The site's layout (or the package layout), whatever its style. */
    public static function target(): string
    {
        $configured = Auto::layout();

        if ($configured !== null) {
            return $configured;
        }

        return view()->exists('layouts.app') ? 'layouts.app' : self::PACKAGE;
    }

    /** Whether a layout view prints `$slot` and no `@yield`: a component layout. False when the view cannot be found or read. */
    public static function isComponent(string $view): bool
    {
        try {
            $file = view()->getFinder()->find($view);
        } catch (Throwable) {
            return false;
        }

        if (! is_string($file) || ! is_file($file)) {
            return false;
        }

        return self::$components[$file] ??= self::componentSource((string) file_get_contents($file));
    }

    /** Whether Blade source is a component layout: it prints `$slot` and has no `@yield`. */
    public static function componentSource(string $contents): bool
    {
        return preg_match('/\$slot\b/', $contents) === 1 && ! str_contains($contents, '@yield(');
    }
}
