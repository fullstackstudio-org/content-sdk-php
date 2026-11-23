<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\Laravel\BlockStyle;
use FullStackStudio\Content\Laravel\SiteSettings;
use FullStackStudio\Content\Site;
use Throwable;

/**
 * The inline `<style>` of the default site views (`resources/assets/site.css`, generated from `site-source.css`), printed once per request by
 * the `content::site.*` views. Same mechanism as {@see BlockStyle}: zero specificity (`:where()`), values through the `--fsc-*` variables,
 * one flag per request, the nonce defaults to Laravel's Vite CSP nonce. The base block CSS is printed in front of it (it holds the theme
 * variables, the container and the article cards), also once per request.
 *
 * `content.blocks.inline_styles` (env `FSC_BLOCKS_INLINE_STYLES`, default true) switches both off for a site that loads its own styles.
 */
final class SiteStyle
{
    private const FLAG = 'fsc.site-style';

    public static function for(?string $nonce = null): string
    {
        if (config('content.blocks.inline_styles', true) === false) {
            return '';
        }

        $base = BlockStyle::for(true, $nonce);

        try {
            $attributes = request()->attributes;

            if ($attributes->has(self::FLAG)) {
                return $base;
            }

            $attributes->set(self::FLAG, true);
        } catch (Throwable) {
            // No request (console, queue): print it every time rather than not at all.
        }

        $nonce = SiteSettings::renderOptions($nonce)['nonce'];

        return $base . '<style' . ($nonce !== null ? ' nonce="' . e($nonce) . '"' : '') . '>' . Site::asset('site.css') . '</style>';
    }
}
