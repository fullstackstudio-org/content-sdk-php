<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

/**
 * Applies a slot decision to the rendered HTML of a slot on the server: the same rules as slots.js, without a DOM. Only the tags that carry
 * `data-fsi-field` / `data-fsi-href` are touched, everything else stays byte for byte. Text goes in escaped, links only when safe.
 */
final class SlotMarkup
{
    private const OPEN_TAG = '#<([a-zA-Z][a-zA-Z0-9]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#';

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /**
     * The inner HTML of the slot element for a decision: the variant's own markup when it has a `<template>`, else the default content, with
     * the fields applied; the templates are always dropped (nothing is swapped in the browser). A group other than personal/customer keeps
     * the default untouched.
     *
     * @param array<string, mixed> $decision {v, g, f?, h?}
     */
    public static function render(string $inner, array $decision): string
    {
        [$default, $templates] = self::extractTemplates($inner);

        $group = $decision['g'] ?? 'none';

        if ($group !== 'personal' && $group !== 'customer') {
            return $default;
        }

        if (($decision['h'] ?? 0) === 1) {
            return '';
        }

        $variant = (string) ($decision['v'] ?? '');
        $fields = is_array($decision['f'] ?? null) ? $decision['f'] : [];

        return self::applyFields(array_key_exists($variant, $templates) ? $templates[$variant] : $default, $fields);
    }

    /**
     * Splits the variant `<template data-fsi-variant>` blocks off the default content. The end of a block is found by counting depth, so a
     * nested `<template x-if>` / `<template x-for>` (Alpine) inside a variant stays intact.
     *
     * @return array{0: string, 1: array<string, string>} [default HTML, variant => inner HTML]
     */
    public static function extractTemplates(string $inner): array
    {
        $templates = [];
        $default = '';
        $offset = 0;

        while (preg_match('#<template\s+data-fsi-variant="([a-z0-9-]+)">#', $inner, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1];
            $bodyStart = $start + strlen($m[0][0]);
            $close = self::closeOffset($inner, 'template', $bodyStart);

            if ($close === null) {
                break; // unbalanced: leave the rest as it is
            }

            $default .= substr($inner, $offset, $start - $offset);
            $templates[$m[1][0]] = substr($inner, $bodyStart, $close - $bodyStart);
            $end = strpos($inner, '>', $close);
            $offset = $end === false ? strlen($inner) : $end + 1;
        }

        return [$default . substr($inner, $offset), $templates];
    }

    /** @param array<array-key, mixed> $fields */
    public static function applyFields(string $html, array $fields): string
    {
        if ($fields === [] || !str_contains($html, 'data-fsi-')) {
            return $html;
        }

        $out = '';
        $offset = 0;
        $length = strlen($html);

        while ($offset < $length && preg_match(self::OPEN_TAG, $html, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $tag = $m[0][0];
            $start = $m[0][1];
            $end = $start + strlen($tag);
            $name = strtolower($m[1][0]);
            $attrs = $m[2][0];
            $out .= substr($html, $offset, $start - $offset);
            $offset = $end;

            $field = self::attribute($attrs, 'data-fsi-field');
            $href = self::attribute($attrs, 'data-fsi-href');
            $hrefValue = $href !== null && is_string($fields[$href] ?? null) && self::safeUrl($fields[$href]) ? $fields[$href] : null;

            if ($hrefValue !== null) {
                $tag = self::withHref($tag, $attrs, $hrefValue);
            }

            $text = $field !== null && is_string($fields[$field] ?? null) && $fields[$field] !== '' ? $fields[$field] : null;
            $close = $text !== null && !in_array($name, self::VOID, true) && !str_ends_with(rtrim($attrs), '/') ? self::closeOffset($html, $name, $end) : null;

            if ($close !== null) {
                $out .= $tag . e($text);
                $offset = $close;
            } else {
                $out .= $tag;
            }
        }

        return $out . substr($html, $offset);
    }

    private static function attribute(string $attrs, string $name): ?string
    {
        if (preg_match('#(?<![\w-])' . preg_quote($name, '#') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $attrs, $m) !== 1) {
            return null;
        }

        return $m[2] ?? $m[1];
    }

    private static function withHref(string $tag, string $attrs, string $href): string
    {
        $new = 'href="' . e($href) . '"';
        $pattern = '#(?<![\w-])href\s*=\s*(?:"[^"]*"|\'[^\']*\')#i';

        if (preg_match($pattern, $attrs) === 1) {
            return (string) preg_replace_callback($pattern, fn (): string => $new, $tag, 1);
        }

        return (string) preg_replace('#\s*/?>$#', ' ' . $new . '$0', $tag, 1);
    }

    /** The offset of the matching `</name>` (the start of the closing tag), or null when the markup is unbalanced. */
    private static function closeOffset(string $html, string $name, int $from): ?int
    {
        $depth = 1;
        $pattern = '#<(/?)' . preg_quote($name, '#') . '(?![\w-])((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#i';
        $position = $from;

        while (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $position) === 1) {
            $position = $m[0][1] + strlen($m[0][0]);

            if ($m[1][0] === '/') {
                if (--$depth === 0) {
                    return $m[0][1];
                }
            } elseif (!str_ends_with(rtrim($m[2][0]), '/')) {
                $depth++;
            }
        }

        return null;
    }

    private static function safeUrl(string $value): bool
    {
        return preg_match('#^/(?![/\\\\])\S*$#D', $value) === 1 || preg_match('#^https://[^\s/]+(/\S*)?$#D', $value) === 1;
    }
}
