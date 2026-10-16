<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

/** Small output-safety helpers shared by the Blade components. */
final class Html
{
    /** Rejects `javascript:`, `data:` and `vbscript:` links (Content already does that on save; this is the second layer). */
    public static function href(?string $href): string
    {
        $href ??= '';

        return preg_match('/^\s*(javascript|data|vbscript):/i', $href) === 1 ? '#' : $href;
    }

    /** `https://x.test` + `/api/media/1` => absolute; an absolute url stays as it is. */
    public static function absolute(string $baseUrl, string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return rtrim($baseUrl, '/') . (str_starts_with($path, '/') ? '' : '/') . $path;
    }
}
