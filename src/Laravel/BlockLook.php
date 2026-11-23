<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

/**
 * Resolves the `layout` and `tone` of a page block (page builder v2, AD2 to AD5) into what a view needs: the two resolved keys, the class string
 * for the block root and whether the block bleeds out of the content container.
 *
 * The SDK keeps its own copy of the Hub registry map ({@see self::LAYOUTS}, first key = default = the markup of before layouts existed). An absent,
 * unknown or unsupported value is the default, so a newer Hub never breaks an older site. The default layout and the default tone add no class,
 * so a block without `layout`/`tone` renders byte for byte as before.
 *
 * A default view supports every layout in the map. A site view supports only what the site declares in `content.block_layouts.<type>` (default
 * none), so a custom view never gets a layout it does not implement.
 */
final class BlockLook
{
    /** Layout keys per block type, default first. A type that is absent has no layouts (`legalDocument`, `html`). */
    public const LAYOUTS = [
        'hero' => ['overlay', 'image-right', 'image-left'],
        'richText' => ['standard', 'narrow'],
        'image' => ['standard', 'wide', 'small'],
        'gallery' => ['grid', 'strip'],
        'cta' => ['standard', 'split'],
        'columns' => ['plain', 'cards'],
        'articleList' => ['grid', 'list'],
        'form' => ['standard', 'card'],
        'embed' => ['standard', 'wide'],
        'eventList' => ['cards', 'list'],
        'event' => ['standard', 'image-side'],
        'appointmentBooking' => ['standard', 'split'],
        'faq' => ['accordion', 'open'],
        'productList' => ['grid', 'list'],
        'newsletterSignup' => ['standard', 'inline'],
    ];

    /** The tones, default first. */
    public const TONES = ['none', 'soft', 'accent'];

    /** The block types that accept a tone. */
    public const TONE_TYPES = ['hero', 'richText', 'cta', 'columns', 'form', 'appointmentBooking', 'faq', 'newsletterSignup'];

    /** Layouts that run edge to edge: a default view renders them outside the `wrap` container. `type => layouts`. */
    public const BLEED = [
        'image' => ['wide'],
        'embed' => ['wide'],
    ];

    /**
     * @param array<string, mixed> $block
     * @param list<string>|null $siteDeclared layouts the view supports; null for a default view (every layout of the SDK map)
     *
     * @return array{layout: string|null, tone: string, classes: string, bleed: bool}
     */
    public static function for(string $type, array $block, ?array $siteDeclared = null): array
    {
        $known = self::LAYOUTS[$type] ?? [];
        $default = $known[0] ?? null;
        $layout = $default;

        if ($default !== null && is_string($block['layout'] ?? null) && in_array($block['layout'], $known, true)
            && ($siteDeclared === null || in_array($block['layout'], $siteDeclared, true))) {
            $layout = $block['layout'];
        }

        $tone = self::TONES[0];

        if (in_array($type, self::TONE_TYPES, true) && is_string($block['tone'] ?? null) && in_array($block['tone'], self::TONES, true)) {
            $tone = $block['tone'];
        }

        $classes = [];

        if ($layout !== null && $layout !== $default) {
            $classes[] = 'fsc-block--layout-' . $layout;
        }

        if ($tone !== self::TONES[0]) {
            $classes[] = 'fsc-block--tone-' . $tone;
        }

        return [
            'layout' => $layout,
            'tone' => $tone,
            'classes' => implode(' ', $classes),
            'bleed' => $layout !== null && in_array($layout, self::BLEED[$type] ?? [], true),
        ];
    }
}
