<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Site;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The page builder's `html` block (SDK 0.28, plan page-builder section D): code from the Hub that is printed as it is, never sanitized or
 * rewritten, but isolated and consent gated.
 *
 * - `isolate` anything but `false` (the default): an `<iframe sandbox="allow-scripts allow-popups allow-forms allow-popups-to-escape-sandbox">`
 *   WITHOUT `allow-same-origin`, so the code runs in an opaque origin that cannot read the site's cookies, storage or DOM. On a live page the
 *   frame loads the block as its own document from the site route `GET /_fsc/h/{pageId}/{key}` ({@see HtmlBlockController}), which carries
 *   its own CSP (with the same `sandbox`); the page's policy only needs `frame-src 'self'` and never the hosts of the customer's code. In a
 *   portal preview (unsaved blocks, nothing to serve) the frame is a `srcdoc` document instead, which inherits the preview response's policy.
 *   A reporter inside the frame posts its height; the parent script (`html-block.js`, printed once per page with the nonce) sizes only the
 *   iframe whose `contentWindow` sent the message, only from the opaque origin `null`, with damping against a feedback loop.
 * - `isolate: false` (staff only, enforced by the Hub): the code is printed in place, like a `site` snippet, with the page nonce on its
 *   `<script>` and `<style>` tags. Only these blocks add hosts to the page's CSP ({@see sources()}).
 * - `consent` other than `necessary`: the block (the iframe, or the inline code) sits in an inert `<template data-fsc-code data-consent>` with a
 *   neutral placeholder before it; the idempotent consent loader of the site snippets ({@see Site::consentLoader()}) is printed once per
 *   page and activates it when the visitor accepts that category (and removes the placeholder).
 *
 * One instance per request (bound `scoped`, Octane safe).
 */
final class HtmlBlock
{
    public const CONSENTS = ['necessary', 'analytics', 'marketing', 'preferences'];

    /** The Hub's limit (20,000 characters); anything longer is not printed. */
    public const MAX_LENGTH = 20000;

    /** The frame's sandbox. Never `allow-same-origin`: with it the code could reach the site's cookies and DOM. */
    public const SANDBOX = 'allow-scripts allow-popups allow-forms allow-popups-to-escape-sandbox';

    /** A block key in the frame route: the block id (the Hub's pattern) or `~<index>` for a block without one. */
    public const KEY_PATTERN = '/^(?:[A-Za-z0-9_-]{1,40}|~\d{1,3})$/D';

    private const CONSENT_LABELS = ['analytics' => 'statistieken', 'marketing' => 'marketing', 'preferences' => 'voorkeuren'];

    /** @var list<string> the code of every inline (`isolate: false`) block rendered in this request */
    private array $inline = [];

    /** An isolated block was served through the frame route in this request (the page needs `frame-src 'self'`). */
    private bool $framed = false;

    private bool $sizerPrinted = false;

    private bool $loaderPrinted = false;

    /** @var array<string, true> */
    private static array $logged = [];

    /** The current request's instance (a fresh one outside a Laravel app). */
    public static function current(): self
    {
        try {
            return app(self::class);
        } catch (Throwable) {
            return new self();
        }
    }

    /** Is this code printable: a non-empty UTF-8 string within the Hub's limit? */
    public static function validCode(mixed $code): bool
    {
        return is_string($code) && trim($code) !== '' && mb_check_encoding($code, 'UTF-8') && mb_strlen($code) <= self::MAX_LENGTH;
    }

    /**
     * The markup of one `html` block, '' when there is nothing to print: empty or oversized code, inline code that would end its inert
     * wrapper early, or an isolated block on a live page whose page this request did not fetch with `getPage()` (logged once).
     *
     * @param array<string, mixed> $block `{id, name, html, consent, isolate}`
     * @param array{nonce?: string|null, consentKey?: string, consentEvent?: string}|null $options default: {@see SiteSettings::renderOptions()}
     */
    public function render(array $block, ?array $options = null): string
    {
        $code = $block['html'] ?? null;

        if (!self::validCode($code)) {
            return '';
        }

        $options ??= SiteSettings::renderOptions();
        $nonce = $options['nonce'] ?? null;
        $consent = in_array($block['consent'] ?? null, self::CONSENTS, true) ? $block['consent'] : 'marketing';
        $preview = Preview::isActive();
        // Only a real `false` prints inline, and never in a portal preview (inline code would run in the editor's frame with the site's origin).
        $isolate = ($block['isolate'] ?? true) !== false || $preview;
        $name = is_string($block['name'] ?? null) ? trim($block['name']) : '';
        $gated = $consent !== 'necessary';

        if ($gated && !$isolate && stripos($code, '</template') !== false) {
            return '';
        }

        if ($isolate) {
            $content = $preview ? $this->srcdocFrame($code, $name, $nonce) : $this->routeFrame($block, $name);

            if ($content === null) {
                return '';
            }
        } else {
            $this->inline[] = $code;
            $content = Site::withNonce($code, $nonce);
        }

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
     * What the rendered blocks need in the PAGE's CSP: the hosts of inline (`isolate: false`) code (same extraction as the site snippets),
     * plus `frame-src 'self'` when an isolated block was framed through the site route. Never the hosts of isolated code: that runs under
     * its own policy. Empty when no block was rendered in this request.
     *
     * @return array<string, list<string>>
     */
    public function sources(): array
    {
        $sources = $this->inline === [] ? [] : Site::cspForHtml($this->inline);

        if ($this->framed) {
            $sources['frame-src'] = array_values(array_unique(["'self'", ...($sources['frame-src'] ?? [])]));
        }

        return $sources;
    }

    /**
     * The document of an isolated block: the code (unchanged, plus the nonce when one is given, which only the preview's srcdoc needs) and the
     * height reporter.
     */
    public static function document(string $code, ?string $nonce = null): string
    {
        $attr = self::nonceAttr($nonce);

        return '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><style' . $attr . '>html,body{margin:0;padding:0;background:transparent}</style></head><body>'
            . ($nonce !== null && $nonce !== '' ? Site::withNonce($code, $nonce) : $code)
            . '<script' . $attr . '>' . Site::asset('html-block-frame.js') . '</script></body></html>';
    }

    /**
     * The policy of the frame document served by the route: permissive for the code of that one block (it runs sandboxed in an opaque origin),
     * the same `sandbox` as the iframe attribute (so the document stays sandboxed when opened directly), and framed only by the site itself.
     */
    public static function frameCsp(): string
    {
        return "default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval' https: blob:; style-src 'unsafe-inline' https:; "
            . "img-src https: data: blob:; font-src https: data:; media-src https: blob:; connect-src https: wss:; frame-src https:; "
            . "form-action https:; base-uri 'none'; frame-ancestors 'self'; sandbox " . self::SANDBOX;
    }

    /** The live frame: `src` = the site route of this block, or null when its page is unknown in this process. */
    private function routeFrame(array $block, string $name): ?string
    {
        try {
            $location = app(ContentClient::class)->htmlBlockLocation($block);
        } catch (Throwable) {
            $location = null;
        }

        if ($location === null || preg_match(self::KEY_PATTERN, $location['key']) !== 1) {
            self::logOnce('unknown_page', 'An isolated html block renders nothing: its page was not fetched with getPage() in this request.');

            return null;
        }

        $this->framed = true;
        $src = '/' . trim(HtmlBlockController::base(), '/') . '/' . rawurlencode($location['pageId']) . '/' . rawurlencode($location['key']);

        return $this->iframe('src="' . self::e($src) . '"', $name);
    }

    private function srcdocFrame(string $code, string $name, ?string $nonce): string
    {
        return $this->iframe('srcdoc="' . self::e(self::document($code, $nonce)) . '"', $name);
    }

    private function iframe(string $source, string $name): string
    {
        return '<iframe class="fsc-html__frame" data-fsc-html-frame title="' . self::e($name !== '' ? $name : 'Ingesloten inhoud') . '"'
            . ' width="100%" sandbox="' . self::SANDBOX . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" ' . $source . '></iframe>';
    }

    private static function logOnce(string $reason, string $message): void
    {
        if (isset(self::$logged[$reason])) {
            return;
        }

        self::$logged[$reason] = true;

        try {
            Log::warning('[fullstackstudio/content] ' . $message);
        } catch (Throwable) {
            // Logging must never break a page.
        }
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
