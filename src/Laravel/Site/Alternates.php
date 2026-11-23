<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\Laravel\Facades\Content;
use Throwable;

/**
 * The hreflang alternates of a page or article: self plus every published translation in an enabled project language, as absolute URLs
 * built by `Content::urls()` (so locale prefix and trailing slash agree with the sitemap and the canonical), and the `x-default` URL (the
 * default locale version). A single-language project, or a resource with no other enabled version, has none.
 */
final class Alternates
{
    /**
     * @param 'page'|'article' $type
     * @param array<string, mixed> $resource a page or article with its `translations` (`[{id, locale, slug, status}]`)
     * @return array{alternates: list<array{locale: string, url: string}>, default: ?string}
     */
    public static function for(string $type, array $resource, ?string $locale): array
    {
        $none = ['alternates' => [], 'default' => null];

        try {
            $urls = Content::urls();
            $translations = is_array($resource['translations'] ?? null) ? $resource['translations'] : [];

            if ($translations === [] || ! $urls->multilingual()) {
                return $none;
            }

            $enabled = $urls->locales();

            $self = $locale ?? (is_string($resource['locale'] ?? null) ? $resource['locale'] : null);
            $default = $urls->defaultLocale();
            $entries = [];

            foreach ($translations as $translation) {
                if (is_array($translation) && is_string($translation['locale'] ?? null) && in_array($translation['locale'], $enabled, true) && ($translation['status'] ?? 'published') === 'published') {
                    $entries[$translation['locale']] = $translation;
                }
            }

            if ($self !== null && ! isset($entries[$self]) && in_array($self, $enabled, true)) {
                $entries[$self] = [...$resource, 'locale' => $self];
            }

            // One version is no alternate.
            if (count($entries) < 2) {
                return $none;
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
}
