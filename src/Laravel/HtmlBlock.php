<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Site;
use Throwable;

/**
 * The page builder's `html` block (SDK 0.28, plan page-builder section D): code from the Hub that is printed as it is, never sanitized or
 * rewritten (only the page nonce is put on its `<script>` and `<style>` tags), but isolated and consent gated.
 *
 * - `isolate: true` (the default, and always in a portal preview): the code runs in `<iframe sandbox="allow-scripts allow-popups allow-forms"
 *   srcdoc>` WITHOUT `allow-same-origin`: an opaque origin that cannot read the site's cookies, storage or DOM. A small reporter inside the
 *   frame posts its height; the parent script (`html-block.js`, printed once per page with the nonce) sizes only the iframe whose
 *   `contentWindow` sent the message, and only from the opaque origin `null`. The frame is `loading="lazy"`.
 * - `isolate: false` (staff only, enforced by the Hub): the code is printed in place, like a `site` snippet, with full access to the page.
 * - `consent` other than `necessary`: the block (the iframe, or the inline code) sits in an inert `<template data-fsc-code data-consent>` with a
 *   neutral placeholder before it; the idempotent consent loader of the site snippets ({@see Site::consentLoader()}) is printed once per
 *   page and activates it when the visitor accepts that category (and removes the placeholder).
 *
 * One instance per request (bound `scoped`): it collects the code of every rendered block, so {@see sources()} (merged into
 * `Content::siteCsp()` / `siteCspMerge()`) adds the external origins the code needs to the page's CSP. A srcdoc frame inherits the page's
 * policy, so the same sources and the nonce apply inside the frame.
 */
final class HtmlBlock
{
    public const CONSENTS = ['necessary', 'analytics', 'marketing', 'preferences'];

    /** The Hub's limit (20,000 characters); anything longer is not printed. */
    public const MAX_LENGTH = 20000;

    /** The frame's sandbox. Never `allow-same-origin`: with it the code could reach the site's cookies and DOM. */
    public const SANDBOX = 'allow-scripts allow-popups allow-forms';

    private const CONSENT_LABELS = ['analytics' => 'statistieken', 'marketing' => 'marketing', 'preferences' => 'voorkeuren'];

    /** @var list<string> the code of every block rendered in this request */
    private array $fragments = [];

    private bool $sizerPrinted = false;

    private bool $loaderPrinted = false;

    /** The current request's instance (a fresh one outside a Laravel app). */
    public static function current(): self
    {
        try {
            return app(self::class);
        } catch (Throwable) {
            return new self();
        }
    }

    /**
     * The markup of one `html` block, '' when there is nothing to print (empty or oversized code, or inline code that would end its inert
     * wrapper early).
     *
     * @param array<string, mixed> $block `{name, html, consent, isolate}`
     * @param array{nonce?: string|null, consentKey?: string, consentEvent?: string}|null $options default: {@see SiteSettings::renderOptions()}
     */
    public function render(array $block, ?array $options = null): string
    {
        $code = $block['html'] ?? null;

        if (!is_string($code) || trim($code) === '' || mb_strlen($code) > self::MAX_LENGTH || !mb_check_encoding($code, 'UTF-8')) {
            return '';
        }

        $options ??= SiteSettings::renderOptions();
        $nonce = $options['nonce'] ?? null;
        $consent = in_array($block['consent'] ?? null, self::CONSENTS, true) ? $block['consent'] : 'marketing';
        // In a portal preview the block is always isolated: inline code would run inside the editor's frame with the site's origin.
        $isolate = ($block['isolate'] ?? true) !== false || Preview::isActive();
        $name = is_string($block['name'] ?? null) ? trim($block['name']) : '';
        $gated = $consent !== 'necessary';

        if ($gated && !$isolate && stripos($code, '</template') !== false) {
            return '';
        }

        $this->fragments[] = $code;
        $content = $isolate ? $this->frame($code, $name, $nonce) : Site::withNonce($code, $nonce);
        $html = '<div class="fsc-block fsc-block--html' . ($isolate ? ' fsc-html--isolated' : '') . '"' . ($name !== '' ? ' data-name="' . self::e($name) . '"' : '') . '>';

        if ($gated) {
            $html .= '<div class="fsc-html__placeholder" data-fsc-code-placeholder><p>Deze inhoud verschijnt zodra je cookies voor ' . self::CONSENT_LABELS[$consent] . ' accepteert.</p></div>';
            $html .= '<template data-fsc-code data-consent="' . $consent . '"' . ($name !== '' ? ' data-name="' . self::e($name) . '"' : '') . '>' . $content . '</template>';
        } else {
            $html .= $content;
        }

        if ($isolate && !$this->sizerPrinted) {
            $this->sizerPrinted = true;
            $html .= '<script' . self::nonceAttr($nonce) . '>' . Site::asset('html-block.js') . '</script>';
        }

        if ($gated && !$this->loaderPrinted) {
            $this->loaderPrinted = true;
            $html .= Site::consentLoader($options);
        }

        return $html . '</div>';
    }

    /**
     * The CSP sources the rendered blocks need, per directive (the same extraction as the site snippets: `script-src`, `connect-src`,
     * `img-src`, `frame-src`, `style-src`). Empty when no block was rendered in this request.
     *
     * @return array<string, list<string>>
     */
    public function sources(): array
    {
        return $this->fragments === [] ? [] : Site::cspForHtml($this->fragments);
    }

    /** The sandboxed frame: a minimal document with the code (nonce applied) and the height reporter, in `srcdoc` (attribute escaped). */
    private function frame(string $code, string $name, ?string $nonce): string
    {
        $attr = self::nonceAttr($nonce);
        $document = '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style' . $attr . '>html,body{margin:0;padding:0;background:transparent}</style></head><body>'
            . Site::withNonce($code, $nonce)
            . '<script' . $attr . '>' . Site::asset('html-block-frame.js') . '</script></body></html>';

        return '<iframe class="fsc-html__frame" data-fsc-html-frame title="' . self::e($name !== '' ? $name : 'Ingesloten inhoud') . '"'
            . ' width="100%" sandbox="' . self::SANDBOX . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"'
            . ' srcdoc="' . self::e($document) . '"></iframe>';
    }

    private static function nonceAttr(?string $nonce): string
    {
        return $nonce !== null && $nonce !== '' ? ' nonce="' . self::e($nonce) . '"' : '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
