<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Auto\Auto;

/**
 * The one place that builds URLs of Hub content: article template, event link, legal path, locale prefix and trailing slash. Sitemap,
 * canonical, hreflang and the default views use it, so they cannot disagree with {@see PathResolver}, which parses the same templates.
 *
 * All methods return a same-site path with a leading slash, except {@see absolute()}. Reads the project and SEO settings through the
 * client (and so through its cache); an instance remembers them for its own lifetime, so keep one while building many links.
 */
final class Urls
{
    public const DEFAULT_ARTICLE_TEMPLATE = '/blog/{slug}';

    public const DEFAULT_LOCALE = 'nl';

    /** Slug or id as it may appear in a content path. */
    public const SEGMENT = '[A-Za-z0-9][A-Za-z0-9_-]*';

    /** @var array<string, mixed>|null */
    private ?array $project = null;

    private ?string $articleTemplate = null;

    private ?string $origin = null;

    public function __construct(private readonly ContentClient $client)
    {
    }

    /** The project's default language: its pages have no locale prefix. */
    public function defaultLocale(): string
    {
        $default = $this->project()['defaultLocale'] ?? null;

        return is_string($default) && $default !== '' ? $default : self::DEFAULT_LOCALE;
    }

    /** @return list<string> all project languages, default first */
    public function locales(): array
    {
        $locales = [$this->defaultLocale()];

        foreach ((array) ($this->project()['locales'] ?? []) as $locale) {
            if (is_string($locale) && $locale !== '' && ! in_array($locale, $locales, true)) {
                $locales[] = $locale;
            }
        }

        return $locales;
    }

    /**
     * The Hub's SEO `articlePathTemplate`: a same-site path with exactly one `{slug}` (and optionally `{locale}`), else `/blog/{slug}`.
     * A Hub failure other than "not found" is thrown.
     */
    public function articleTemplate(): string
    {
        if ($this->articleTemplate !== null) {
            return $this->articleTemplate;
        }

        $template = Hub::call(fn (): array => $this->client->getSeo())['articlePathTemplate'] ?? null;

        return $this->articleTemplate = is_string($template) && str_starts_with($template, '/') && ! str_starts_with($template, '//')
            && substr_count($template, '{slug}') === 1 && substr_count($template, '{locale}') <= 1
            ? $template
            : self::DEFAULT_ARTICLE_TEMPLATE;
    }

    /** `content.links.event` when it is a same-site path with one `{slug}` or `{id}`; null otherwise (events are then not served by the site). */
    public function eventTemplate(): ?string
    {
        $template = config('content.links.event');

        if (! is_string($template) || ! str_starts_with($template, '/') || str_starts_with($template, '//')) {
            return null;
        }

        return substr_count($template, '{slug}') + substr_count($template, '{id}') === 1 ? $template : null;
    }

    /** @param array<string, mixed> $page */
    public function page(array $page, ?string $locale = null): string
    {
        $path = is_string($page['path'] ?? null) ? $page['path'] : (is_string($page['slug'] ?? null) ? $page['slug'] : '/');
        $locale ??= is_string($page['locale'] ?? null) ? $page['locale'] : null;

        return $this->path($path, $locale);
    }

    /**
     * @param array<string, mixed> $article
     * @param string|null $locale Defaults to the article's own. With `{locale}` in the template the locale is part of the template and no prefix is added.
     */
    public function article(array $article, ?string $locale = null): string
    {
        $template = $this->articleTemplate();
        $locale ??= is_string($article['locale'] ?? null) && $article['locale'] !== '' ? $article['locale'] : $this->defaultLocale();
        $path = str_replace(['{slug}', '{locale}'], [rawurlencode((string) ($article['slug'] ?? '')), rawurlencode($locale)], $template);

        return $this->path($path, str_contains($template, '{locale}') ? null : $locale);
    }

    /** @param array<string, mixed> $event Null when `content.links.event` is not set. */
    public function event(array $event): ?string
    {
        $template = $this->eventTemplate();

        if ($template === null) {
            return null;
        }

        return $this->path(str_replace(['{slug}', '{id}'], [rawurlencode((string) ($event['slug'] ?? '')), rawurlencode((string) ($event['id'] ?? ''))], $template));
    }

    /** Path of a legal document kind (`terms`, `privacy_statement`, `cookie_statement`) from `content.auto.legal_paths`, or null. */
    public function legal(string $kind, ?string $locale = null): ?string
    {
        $path = array_search($kind, Auto::legalPaths(), true);

        return is_string($path) ? $this->path($path, $locale) : null;
    }

    /** Applies the locale prefix (not for the default locale or no locale) and the `trailing_slash` setting. `/` stays `/`, a locale root is `/en`. */
    public function path(string $path, ?string $locale = null): string
    {
        $path = trim($path, '/');
        $prefix = $locale !== null && $locale !== '' && $locale !== $this->defaultLocale() ? $locale : '';
        $full = trim($prefix . '/' . $path, '/');

        if ($full === '') {
            return '/';
        }

        return '/' . $full . ((bool) config('content.auto.trailing_slash') ? '/' : '');
    }

    /** Full URL of a same-site path: origin is the SEO `canonicalOrigin`, else the app's own. A URL that is already absolute is returned as is. */
    public function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $this->origin ??= rtrim(is_string($origin = (Hub::call(fn (): array => $this->client->getSeo())['canonicalOrigin'] ?? null)) && $origin !== '' ? $origin : url('/'), '/');

        return $this->origin . '/' . ltrim($path, '/');
    }

    /**
     * Regex for a path template: literal text quoted, each `{name}` of `$groups` a named group. Anchored. Used by {@see PathResolver}.
     *
     * @param array<string, string> $groups name => regex of the group
     */
    public static function pattern(string $template, array $groups): string
    {
        $parts = preg_split('/(\{[a-z]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $regex = '';

        foreach ($parts as $part) {
            $name = trim($part, '{}');
            $regex .= str_starts_with($part, '{') && isset($groups[$name]) ? '(?P<' . $name . '>' . $groups[$name] . ')' : preg_quote($part, '#');
        }

        return '#^' . $regex . '$#D';
    }

    /** @return array<string, mixed> */
    private function project(): array
    {
        return $this->project ??= (array) Hub::call(fn (): array => $this->client->getProject());
    }
}
