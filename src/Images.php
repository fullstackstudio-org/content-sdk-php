<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

/**
 * `srcset` logic for Content's on-demand image variants (`/api/media/{id}?w=...&format=...`), a port of `buildImageSrcSet` and `<Image>`.
 * Variants exist for png, jpeg and still webp only; for anything else the original is used without a `srcset`.
 * Content never upscales: the largest entry is the original size.
 */
final class Images
{
    /** The widths Content renders (anything else is a 400). */
    public const WIDTHS = [320, 640, 960, 1280, 1920];

    private const VARIANT_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /** @param array{format?: string, sourceWidth?: int|null} $options */
    public static function srcSet(string $url, array $options = []): string
    {
        $format = $options['format'] ?? 'webp';
        $sourceWidth = $options['sourceWidth'] ?? null;
        $entry = static fn (int $width, int $descriptor): string => "{$url}?w={$width}&format={$format} {$descriptor}w";

        if (!$sourceWidth) {
            return implode(', ', array_map(static fn (int $w): string => $entry($w, $w), self::WIDTHS));
        }

        $entries = [];

        foreach (self::WIDTHS as $width) {
            if ($width < $sourceWidth) {
                $entries[] = $entry($width, $width);
            }
        }

        foreach (self::WIDTHS as $width) {
            if ($width >= $sourceWidth) {
                $entries[] = $entry($width, $sourceWidth);
                break;
            }
        }

        return implode(', ', $entries);
    }

    /**
     * Everything the view needs for one image.
     *
     * @param array<string, mixed> $media {url, contentType, alt, width, height}
     * @param list<string> $formats best first; one format gives a plain `<img srcset>`, two or more a `<picture>`
     * @return array{src: string, srcset: ?string, sizes: ?string, sources: list<array{type: string, srcset: string}>, width: ?int, height: ?int, alt: string}
     */
    public static function describe(array $media, string $baseUrl, string $sizes = '100vw', array $formats = ['webp'], ?string $alt = null): array
    {
        $url = Html::absolute($baseUrl, (string) ($media['url'] ?? ''));
        $width = isset($media['width']) && (int) $media['width'] > 0 ? (int) $media['width'] : null;
        $height = isset($media['height']) && (int) $media['height'] > 0 ? (int) $media['height'] : null;
        $canVary = in_array(strtolower((string) ($media['contentType'] ?? '')), self::VARIANT_TYPES, true) && $width !== null;
        $formats = array_values(array_filter($formats, static fn (string $f): bool => in_array($f, ['webp', 'avif'], true))) ?: ['webp'];
        $sources = [];

        if ($canVary && count($formats) >= 2) {
            foreach ($formats as $format) {
                $sources[] = ['type' => $format === 'avif' ? 'image/avif' : 'image/webp', 'srcset' => self::srcSet($url, ['format' => $format, 'sourceWidth' => $width])];
            }
        }

        return [
            'src' => $url,
            'srcset' => $canVary && count($formats) === 1 ? self::srcSet($url, ['format' => $formats[0], 'sourceWidth' => $width]) : null,
            'sizes' => $canVary ? $sizes : null,
            'sources' => $sources,
            'width' => $width,
            'height' => $height,
            'alt' => $alt ?? (string) ($media['alt'] ?? ''),
        ];
    }
}
