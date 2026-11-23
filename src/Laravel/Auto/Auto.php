<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Auto;

use FullStackStudio\Content\Laravel\Site\PageContext;
use Illuminate\Contracts\Foundation\Application;

/**
 * The one place that answers "is out of the box feature X on" and reads the `content.auto` config. Reads config on every call (cheap, and
 * correct when a test or an Octane worker changes it); never calls the Hub.
 */
final class Auto
{
    private const PACKAGE_CONFIG = __DIR__ . '/../../../config/content.php';

    /**
     * Called by the service provider after `mergeConfigFrom`. `mergeConfigFrom` is shallow, so an app `auto` array would replace the package
     * block: deep-merge it so a sparse app config keeps every new default. `legal_paths` is a whole list: the app's replaces ours.
     * Also binds the request scoped {@see PageContext} and `Urls`.
     */
    public static function register(Application $app): void
    {
        $app->scoped(PageContext::class, static fn (): PageContext => new PageContext());
        // One per request: the project and SEO settings Urls reads are remembered, so a page builds many links with one lookup (Octane safe).
        $app->scoped(\FullStackStudio\Content\Laravel\Site\Urls::class);

        $config = $app['config'];
        $package = (require self::PACKAGE_CONFIG)['auto'];
        $site = $config->get('content.auto');

        if (! is_array($site)) {
            $config->set('content.auto', $package);

            return;
        }

        $merged = array_replace_recursive($package, $site);

        if (isset($site['legal_paths']) && is_array($site['legal_paths'])) {
            $merged['legal_paths'] = $site['legal_paths'];
        }

        $config->set('content.auto', $merged);
    }

    /** Master switch and feature switch. A feature that is not in the config is off. */
    public static function on(string $feature): bool
    {
        if (! self::flag(self::get('enabled'))) {
            return false;
        }

        return $feature === 'enabled' || self::flag(self::get($feature));
    }

    /** Layout view name the default views extend, or null for the package layout. */
    public static function layout(): ?string
    {
        return self::string('layout');
    }

    /** Name of the content section of that layout (default `content`). */
    public static function section(): string
    {
        return self::string('section') ?? 'content';
    }

    /** Classes of the content container, or null. */
    public static function wrap(): ?string
    {
        return self::string('wrap');
    }

    /** View for a page type: `content.auto.views.<type>`, else `content::site.<type>`. */
    public static function view(string $type): string
    {
        return self::string('views.' . $type) ?? 'content::site.' . $type;
    }

    /** Own handler class for a page type, or null. */
    public static function handler(string $type): ?string
    {
        $class = self::string('handlers.' . $type);

        return $class !== null && class_exists($class) ? $class : null;
    }

    /** Option of a feature that has options (`articles`, `sitemap`): `Auto::option('articles', 'per_page', 12)`. */
    public static function option(string $feature, string $key, mixed $default = null): mixed
    {
        $value = self::get($feature);

        return is_array($value) && array_key_exists($key, $value) ? $value[$key] : $default;
    }

    /** @return array<string, string> path => legal kind */
    public static function legalPaths(): array
    {
        $paths = self::get('legal_paths');

        return is_array($paths) ? array_filter($paths, 'is_string') : [];
    }

    private static function get(string $key): mixed
    {
        return config('content.auto.' . $key);
    }

    private static function string(string $key): ?string
    {
        $value = self::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A feature value is a bool, or an array whose `enabled` (default true) decides. */
    private static function flag(mixed $value): bool
    {
        if (is_array($value)) {
            return (bool) ($value['enabled'] ?? true);
        }

        return (bool) $value;
    }
}
