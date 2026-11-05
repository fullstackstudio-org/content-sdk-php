<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\PersonalizationController;
use FullStackStudio\Content\Laravel\SlotDecisions;
use FullStackStudio\Content\Laravel\SlotMarkup;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Component;
use Throwable;

/**
 * `<x-content::slot name="home-hero" class="…">default content<x-content::variant key="voip">…</x-content::variant></x-content::slot>`:
 * a personalisation slot of FullStack Intelligence (phase 7).
 *
 * The HTML always holds the DEFAULT content (search engines, the edge cache and visitors without JavaScript see it; nothing waits). With
 * personalisation off (the default) the slot is exactly its element with the default content: no attributes, no templates, no script.
 * With it on, the variants become inert `<template>`s and `slots.js` (loaded once per page) may swap in the variant the Hub chose for this
 * visitor, only when the swap cannot move the page (no layout shift) and only in time; otherwise the default stays.
 *
 * - `name`: the slot key in the Hub (`a-z`, `0-9`, `-`).
 * - `as`: the element (default `div`); other attributes go on it, so the slot can BE the existing element (no extra wrapper in the layout).
 * - `data`: a slot without visible content (for example the opening line of the chat): renders an empty `<template>` while on, nothing
 *   while off; read it with `window.fsiSlots.get(name)` and report the view with `fsiSlots.view(name)`.
 *
 * Variants: a `<x-content::variant key="…">` holds the markup for that variant; without one the default content is reused. In either,
 * elements with `data-fsi-field="title"` get the plain-text field `title` of the variant and `data-fsi-href="cta_url"` gets the link of the
 * field `cta_url`.
 *
 * Server-side decision (default, {@see SlotDecisions}): when the request carries a signal (same-site Referer on another path, recognition
 * cookie, or the consented `fsa_pid` cookie) the variant is rendered straight into the HTML (one Hub call per request for all slots of
 * the page, `ssr_timeout_ms`), marked with `data-fsi-variant` / `data-fsi-group` so slots.js neither asks nor swaps for it and only
 * measures. Without a signal the default renders and nothing is asked. The response is then `private, no-store`.
 *
 * Field A variant with `hide` hides the slot (functional use for logged-in customers: no "word klant" call to action).
 */
final class Slot extends Component
{
    private const ELEMENTS = ['div', 'section', 'aside', 'span', 'p', 'header', 'footer', 'nav', 'li', 'ul', 'article'];

    public readonly bool $enabled;

    public readonly string $tag;

    public readonly ?string $scriptTag;

    public readonly bool $dataOnly;

    /** @var array<string, mixed>|null the decision rendered on the server for this visitor, null = the HTML holds the default */
    public readonly ?array $decision;

    /** The attributes that mark a server-decided slot (already escaped, with a leading space), empty otherwise. */
    public readonly string $marks;

    public function __construct(public readonly string $name, string $as = 'div', bool $data = false, ?string $nonce = null)
    {
        $this->dataOnly = $data;
        $this->enabled = PersonalizationController::enabled() && preg_match(PersonalizationController::KEY, $name) === 1;
        $this->tag = in_array(strtolower($as), self::ELEMENTS, true) ? strtolower($as) : 'div';
        $this->scriptTag = $this->enabled ? self::scriptOnce($nonce) : null;
        $this->decision = $this->enabled && SlotDecisions::enabled() ? SlotDecisions::make()->decisionFor($name) : null;
        $this->marks = $this->decision !== null ? self::marksFor($this->decision) : '';
    }

    public function render(): View
    {
        return view('content::components.slot');
    }

    /** The inner HTML of the slot: the default as written, or the server-decided variant. */
    public function content(string $html): string
    {
        return $this->decision !== null ? SlotMarkup::render($html, $this->decision) : $html;
    }

    /** @param array<string, mixed> $decision */
    private static function marksFor(array $decision): string
    {
        $group = (string) ($decision['g'] ?? 'none');
        $variant = $group === 'none' ? 'default' : (string) ($decision['v'] ?? 'default');
        $out = ' data-fsi-variant="' . e($variant) . '" data-fsi-group="' . e($group) . '"';

        // As in the browser path: only an applied variant hands its fields to the page (data slots read them with fsiSlots.get).
        if (($group === 'personal' || $group === 'customer') && is_array($decision['f'] ?? null) && $decision['f'] !== []) {
            $out .= ' data-fsi-fields="' . e((string) json_encode($decision['f'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
        }

        if (($group === 'personal' || $group === 'customer') && ($decision['h'] ?? 0) === 1) {
            $out .= ' hidden';
        }

        return $out;
    }

    /** The `<script>` of slots.js, only for the first slot of the request. */
    private static function scriptOnce(?string $nonce): ?string
    {
        try {
            $request = app('request');

            if ($request->attributes->get('fsi.slots-script') === true) {
                return null;
            }

            $request->attributes->set('fsi.slots-script', true);
            $base = '/' . (trim((string) config('content.intelligence.path', '_fsc/i'), '/') ?: '_fsc/i');
            $nonce ??= self::viteNonce();

            return '<script defer src="' . e($base . '/slots.js?v=' . ContentClient::VERSION) . '"'
                . ' data-endpoint="' . e($base . '/decide') . '"'
                . ' data-client="' . (config('content.intelligence.client_decide', false) || !config('content.intelligence.ssr', true) ? '1' : '0') . '"'
                . ' data-deadline="' . max(200, min(5000, (int) config('content.intelligence.swap_deadline_ms', 1500))) . '"'
                . ($nonce !== null ? ' nonce="' . e($nonce) . '"' : '') . '></script>';
        } catch (Throwable) {
            return null;
        }
    }

    private static function viteNonce(): ?string
    {
        try {
            $nonce = class_exists(Vite::class) ? Vite::cspNonce() : null;
        } catch (Throwable) {
            $nonce = null;
        }

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }
}
