<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\Site;
use Throwable;

/**
 * The inline `<style>` for the default page-block views (`resources/assets/blocks.css`, generated from `content.css`), returned once per request
 * by `<x-content::page-blocks>` and only when at least one block renders with a default SDK view. Every selector sits in `:where()`, so the
 * site's own CSS always wins, and every visual value goes through a `--fsc-*` variable the site can set (see AGENTS.md, "Site design").
 * Same mechanism as {@see RichStyle}: one flag per request, the nonce defaults to Laravel's Vite CSP nonce.
 *
 * `content.blocks.inline_styles` (env `FSC_BLOCKS_INLINE_STYLES`, default true) switches it off for a site that loads the published `content.css`.
 */
final class BlockStyle
{
    private const FLAG = 'fsc.block-style';

    public static function for(bool $usesDefaultView, ?string $nonce = null): string
    {
        if (!$usesDefaultView || config('content.blocks.inline_styles', true) === false) {
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

        return '<style' . ($nonce !== null ? ' nonce="' . e($nonce) . '"' : '') . '>' . Site::asset('blocks.css') . '</style>';
    }
}
