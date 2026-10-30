<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\Site;
use Throwable;

/**
 * The inline `<style>` for the Hub rich-editor blocks. Returned once per request, and only for HTML that holds an `fsc-rt-` block, so
 * a site without such content gets exactly the markup it had before. The nonce defaults to Laravel's Vite CSP nonce, like `<x-content::head>`.
 */
final class RichStyle
{
    private const FLAG = 'fsc.rich-style';

    public static function for(?string $html, ?string $nonce = null): string
    {
        if (!Site::hasRichBlocks($html)) {
            return '';
        }

        try {
            $attributes = request()->attributes;

            if ($attributes->has(self::FLAG)) {
                return '';
            }

            $attributes->set(self::FLAG, true);
        } catch (Throwable) {
            // No request (console, queue): print it every time rather than not at all.
        }

        $nonce = SiteSettings::renderOptions($nonce)['nonce'];

        return '<style' . ($nonce !== null ? ' nonce="' . e($nonce) . '"' : '') . '>' . Site::richCss() . '</style>';
    }
}
