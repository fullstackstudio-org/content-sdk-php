<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Facades\Content;
use Throwable;

/**
 * The hreflang alternates of a page or article: self plus every published translation, as absolute URLs built by `Content::urls()`
 * (so locale prefix and trailing slash agree with the sitemap and the canonical), and the `x-default` URL (the default locale version).
 */
final class Alternates
{
    /** @var object|null test seam: replaces `Content::urls()` (an object with `page()`, `article()` and `absolute()`) */
    private static ?object $urls = null;

    public static function useUrls(?object $urls): void
    {
        self::$urls = $urls;
    }

    /**
     * @param 'page'|'article' $type
     * @param array<string, mixed> $resource a page or article with its `translations` (`[{id, locale, slug, status}]`)
     * @return array{alternates: list<array{locale: string, url: string}>, default: ?string}
     */
    public static function for(string $type, array $resource, ?string $locale): array
    {
        $none = ['alternates' => [], 'default' => null];

        try {
            $urls = self::$urls ?? (method_exists(Content::class, 'urls') ? Content::urls() : null);
            $translations = is_array($resource['translations'] ?? null) ? $resource['translations'] : [];

            if ($urls === null || $translations === []) {
                return $none;
            }

            $self = $locale ?? (is_string($resource['locale'] ?? null) ? $resource['locale'] : null);
            $default = self::defaultLocale();
            $entries = [];

            foreach ($translations as $translation) {
                if (is_array($translation) && is_string($translation['locale'] ?? null) && $translation['locale'] !== '' && ($translation['status'] ?? 'published') === 'published') {
                    $entries[$translation['locale']] = $translation;
                }
            }

            if ($self !== null && ! isset($entries[$self])) {
                $entries[$self] = [...$resource, 'locale' => $self];
            }

            $alternates = [];
            $defaultUrl = null;

            foreach ($entries as $code => $entry) {
                $path = $type === 'article' ? $urls->article($entry) : $urls->page($entry);
                $url = $urls->absolute($path);

                if (! is_string($url) || $url === '') {
                    continue;
                }

                $alternates[] = ['locale' => $code, 'url' => $url];

                if ($code === $default) {
                    $defaultUrl = $url;
                }
            }

            return ['alternates' => $alternates, 'default' => $defaultUrl];
        } catch (Throwable) {
            return $none;
        }
    }

    private static function defaultLocale(): ?string
    {
        try {
            $project = app(ContentClient::class)->getProject();
            $default = $project['defaultLocale'] ?? null;

            return is_string($default) && $default !== '' ? $default : (string) config('app.locale');
        } catch (Throwable) {
            return (string) config('app.locale');
        }
    }
}
