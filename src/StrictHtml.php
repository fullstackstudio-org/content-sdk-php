<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * A strict, DOM based allowlist for short rich text from the Hub that a block prints (the event description): defence in depth on top of
 * the Hub's own sanitizer, so a row the Hub ever served raw can never run in a visitor's browser.
 *
 * The HTML is parsed with `DOMDocument` and rebuilt from scratch: only `p, br, strong, em, u, s, a, ul, ol, li, h2, h3, h4, blockquote`
 * survive, with no attributes except `href` on `a` (http, https or mailto only; it always gets `rel="noopener nofollow"`). `b` and `i` become
 * `strong` and `em`. Elements whose content is code or embedded media (`script`, `style`, `iframe`, `svg`, `template`, ...) are dropped with
 * their content; any other element is unwrapped (its text stays). Text is escaped. Nothing is matched with a regular expression.
 */
final class StrictHtml
{
    private const ALLOWED = ['p', 'br', 'strong', 'em', 'u', 's', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote'];

    private const RENAMED = ['b' => 'strong', 'i' => 'em'];

    private const DROPPED = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'noembed', 'noframes', 'template', 'svg',
        'math', 'head', 'title', 'meta', 'link', 'base', 'textarea', 'select', 'option', 'button', 'input', 'form', 'video', 'audio', 'canvas',
        'xmp', 'plaintext', 'img', 'picture', 'source', 'track', 'portal',
    ];

    /** The maximum depth that is rebuilt; deeper content is dropped (a DOM bomb never reaches the page). */
    private const MAX_DEPTH = 64;

    public static function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '' || !mb_check_encoding($html, 'UTF-8')) {
            return '';
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            // The meta charset makes libxml read UTF-8; the body is what we rebuild.
            $loaded = $document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;

        return $body === null ? '' : trim(self::children($body, 0));
    }

    private static function children(DOMNode $node, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '';
        }

        $out = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= self::e($child->data);
            } elseif ($child instanceof DOMElement) {
                $out .= self::element($child, $depth + 1);
            }
            // Comments, processing instructions and CDATA are dropped.
        }

        return $out;
    }

    private static function element(DOMElement $element, int $depth): string
    {
        $name = strtolower($element->localName ?? $element->nodeName);
        $name = self::RENAMED[$name] ?? $name;

        if (in_array($name, self::DROPPED, true)) {
            return '';
        }

        if (!in_array($name, self::ALLOWED, true)) {
            return self::children($element, $depth);
        }

        if ($name === 'br') {
            return '<br>';
        }

        $open = '<' . $name;

        if ($name === 'a') {
            $href = self::href($element->getAttribute('href'));

            if ($href === null) {
                // A link without a safe target keeps its text only.
                return self::children($element, $depth);
            }

            $open .= ' href="' . self::e($href) . '" rel="noopener nofollow"';
        }

        return $open . '>' . self::children($element, $depth) . '</' . $name . '>';
    }

    /** The href when its scheme is http, https or mailto (after the parser decoded entities), else null. */
    private static function href(string $value): ?string
    {
        // Browsers ignore whitespace and control characters in a scheme ("java\tscript:"): strip them before looking at it.
        $clean = preg_replace('/[\x00-\x20\x7F]+/u', '', $value) ?? '';

        if ($clean === '' || strlen($clean) > 2000) {
            return null;
        }

        $colon = strpos($clean, ':');
        $scheme = $colon === false ? '' : strtolower(substr($clean, 0, $colon));

        if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return null;
        }

        if ($scheme !== 'mailto' && !str_starts_with(strtolower($clean), $scheme . '://')) {
            return null;
        }

        return $clean;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
