<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\Site;
use Throwable;

/**
 * The inline `<style>` for the Hub rich-editor blocks (`for()`), returned once per request and only for HTML that holds an `fsc-rt-` block, a
 * native table or an `hr`; any other HTML gets exactly the markup it had before. The rules sit in the cascade layer `fsc-rich`.
 *
 * Layer order is fixed by the first mention of a layer in the document. The stylesheet is printed in the body, long after the site's own CSS,
 * so without help the layer would rank above Tailwind v4's `theme, base, components, utilities`. {@see layerDeclaration()} (printed by
 * `<x-content::head>`) names `fsc-rich` first, which ranks it below every layer a stylesheet declares later. Unlayered site CSS beats a layer
 * either way. The nonce defaults to Laravel's Vite CSP nonce, like `<x-content::head>`.
 */
final class RichStyle
{
    private const FLAG = 'fsc.rich-style';

    /** `<style>@layer fsc-rich;</style>`: declares the layer early so it ranks below the site's own layers. Place `<x-content::head>` before the site's CSS. */
    public static function layerDeclaration(?string $nonce = null): string
    {
        $nonce = SiteSettings::renderOptions($nonce)['nonce'];

        return '<style' . ($nonce !== null ? ' nonce="' . e($nonce) . '"' : '') . '>@layer fsc-rich;</style>';
    }

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
