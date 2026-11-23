<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\BlockLook;
use FullStackStudio\Content\Laravel\BlockStyle;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Laravel\SiteSettings;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-content::page-blocks :blocks="$page['blocks']" :media="$page['media']" />`: renders the blocks of a page with default markup per block
 * type (`fsc-*` classes, see the publishable `content.css`).
 *
 * A block renders when a view resolves for its type (first hit wins): the `views` prop (`:views="['articleList' => 'blocks.articles']"`), the
 * config `content.blocks.<type>`, and finally the package view `content::blocks.<type>` if it exists. There is no type list: a new block type
 * from the Hub renders as soon as the site or a newer SDK has a view for it. Because the package views are namespaced, you can also replace
 * one by creating `resources/views/vendor/content/blocks/<type>.blade.php`. A view gets: `$block`, `$index`, `$resolve` (a closure id =>
 * resolved media|null), `$baseUrl`, and the resolved look ({@see BlockLook}): `$layout` (the layout key, never unknown, null for a type without layouts),
 * `$tone` (`none`, `soft` or `accent`) and `$look` (the class string for the block root, empty for the default layout and tone). A site view
 * only gets a layout that `content.block_layouts.<type>` lists; otherwise `$layout` is the default. A type without a view is skipped, or rendered by the view in `views['unknown']` / `content.blocks.unknown`.
 * Type names must match {@see self::TYPE_PATTERN}; anything else counts as a type without a view.
 *
 * `media` is `$page['media']`: with it, a block whose file was deleted is omitted; without it (null) the URL is derived from the id.
 * Only richText and columns print their HTML unescaped (Content sanitizes it on save); everything else is escaped.
 *
 * Default views are styled out of the box: the first render of a page prints the inline base CSS ({@see BlockStyle}, zero specificity, theme
 * variables `--fsc-*`). `wrap="wrap max-w-5xl"` puts every block rendered by a default view in `<div class="wrap max-w-5xl">` (the site's own
 * content container); without it they sit in `<div class="fsc-wrap">` (`--fsc-content-width`, `--fsc-content-pad`). Blocks rendered by a site
 * view (`views` prop, `content.blocks.<type>`, `views['unknown']`) are never wrapped: the site view handles its own width. A published override
 * in `resources/views/vendor/content/blocks` counts as a default view. All default blocks are wrapped, the hero fallback included.
 *
 * In a portal preview the root gets `data-fsc-preview-blocks` and every block is wrapped in `<div data-fsc-block="{id or index}"
 * data-fsc-block-type="{type}" data-fsc-block-default="1|0" data-fsc-layouts="{layouts}" style="display:contents">`, so the preview can select,
 * highlight and swap blocks and knows which layouts the view behind it supports (a default view: the SDK map of {@see BlockLook}; a site view:
 * `content.block_layouts.<type>`). Views also get `$preview` (bool) and `$edit`, a closure that returns the inline edit attributes of a field
 * (` data-fsc-edit="heading" data-fsc-edit-kind="plain"`, kind `rich` as second argument, plus `data-fsc-edit-placeholder` when the Hub payload
 * carries a label for the field in `$block['fscLabels'][<field path>]`) in a preview and '' outside one. A default view that prints nothing, and every
 * `fscPlaceholder` block (an incomplete block the Hub keeps in a builder preview), render `<div class="fsc-preview-empty">{label}</div>` in a
 * preview. Outside a preview the markup is unchanged.
 */
final class PageBlocks extends Component
{
    /**
     * The block types of 0.26. Not used for rendering any more (a block renders when a view resolves, see the class docs); kept for sites that read it.
     *
     * @deprecated since the Hub block registry (page-builder); the Hub is the source of truth for block types.
     */
    public const TYPES = ['richText', 'image', 'gallery', 'cta', 'hero', 'columns', 'articleList', 'form', 'embed', 'legalDocument'];

    /** A block type name as the Hub allows it; checked before it is used in a config key or a view name. */
    public const TYPE_PATTERN = '/^[A-Za-z][A-Za-z0-9]{0,39}$/D';

    public string $baseUrl;

    public bool $preview = false;

    /** Class of the wrapper of a default-rendered block: the site's `wrap`, else the fallback `fsc-wrap`. */
    public string $wrapClass = 'fsc-wrap';

    /** Fallback labels of the empty-block placeholder when the Hub payload carries none (`$block['fscLabel']`), per block type. */
    private const EMPTY_LABELS = [
        'articleList' => 'Artikelen',
        'eventList' => 'Evenementen',
        'event' => 'Evenement',
        'appointmentBooking' => 'Afspraak maken',
        'faq' => 'Veelgestelde vragen',
        'productList' => 'Producten',
        'newsletterSignup' => 'Nieuwsbriefaanmelding',
        'legalDocument' => 'Juridisch document',
        'image' => 'Afbeelding',
        'html' => 'HTML-code',
    ];

    /** @var list<array{key: string, block: array<string, mixed>, index: int, view: string, marker: string, default: bool}> */
    public array $items = [];

    /**
     * @param list<array<string, mixed>>|null $blocks
     * @param array<string, array<string, mixed>>|null $media
     * @param array<string, string> $views
     * @param string|null $wrap class list of the site's content container, e.g. `wrap` or `wrap max-w-5xl`
     */
    public function __construct(public readonly ?array $blocks = null, public readonly ?array $media = null, ?string $baseUrl = null, public readonly array $views = [], ?string $wrap = null, public readonly ?string $nonce = null)
    {
        $this->wrapClass = $wrap !== null && trim($wrap) !== '' ? trim($wrap) : 'fsc-wrap';
        $this->baseUrl = $baseUrl ?? app(ContentClient::class)->baseUrl();
        $this->preview = Preview::isActive();

        foreach (array_values($blocks ?? []) as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = is_string($block['type'] ?? null) ? $block['type'] : '';
            $view = $this->viewFor($type) ?? $this->views['unknown'] ?? config('content.blocks.unknown');

            if ($view === null) {
                continue;
            }

            $marker = is_string($block['id'] ?? null) || is_int($block['id'] ?? null) ? (string) $block['id'] : (string) $index;
            $this->items[] = ['key' => (string) ($block['id'] ?? "{$type}-{$index}"), 'block' => $block, 'index' => $index, 'view' => $view, 'marker' => $marker, 'default' => str_starts_with((string) $view, 'content::blocks.')];
        }
    }

    /**
     * The view of a block type: the `views` prop, the config `content.blocks.<type>`, or the package view `content::blocks.<type>` when it
     * exists; null when none resolves. The type name is checked before any lookup (it ends up in a config key and a view name).
     */
    private function viewFor(string $type): ?string
    {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1 || $type === 'unknown') {
            return null;
        }

        $view = $this->views[$type] ?? config('content.blocks.' . $type);

        if (is_string($view) && $view !== '') {
            return $view;
        }

        return view()->exists("content::blocks.{$type}") ? "content::blocks.{$type}" : null;
    }

    /** Media of an id as `{id, url, contentType, alt, width, height}`; null if the file is gone (only known when the `media` map is passed). */
    public function mediaFor(string $id): ?array
    {
        if ($this->media !== null) {
            return $this->media[$id] ?? null;
        }

        return ['id' => $id, 'url' => '/api/media/' . rawurlencode($id), 'contentType' => '', 'alt' => '', 'width' => null, 'height' => null];
    }

    /**
     * The blocks as markup, in order. A block rendered by a default view that prints nothing (a module block without data) is left out, so it
     * adds no empty wrapper and no grid gap. `styles` is the base CSS of the default views (empty when none rendered, or already printed).
     *
     * @return array{styles: string, blocks: list<array{marker: string, index: int, html: string, default: bool, type: string, layouts: string}>}
     */
    public function rendered(): array
    {
        $blocks = [];
        $usesDefault = false;

        foreach ($this->items as $item) {
            $type = is_string($item['block']['type'] ?? null) ? $item['block']['type'] : '';
            $declared = $item['default'] ? null : array_values(array_filter((array) config('content.block_layouts.' . $type, []), is_string(...)));
            $look = BlockLook::for($type, $item['block'], $declared);
            $html = trim(view($item['view'], ['block' => $item['block'], 'index' => $item['index'], 'baseUrl' => $this->baseUrl, 'resolve' => $this->mediaFor(...), 'layout' => $look['layout'], 'tone' => $look['tone'], 'look' => $look['classes'], 'preview' => $this->preview, 'edit' => $this->editFor($item['block'])])->render());

            if ($item['default']) {
                if ($html === '') {
                    if (!$this->preview) {
                        continue;
                    }

                    // A preview keeps an empty block selectable: the editor needs something to click.
                    $html = $this->emptyMarkup($item['block'], $type);
                    $look['bleed'] = false;
                }

                $usesDefault = true;

                // A bleed layout (`image` wide, `embed` wide) runs edge to edge: no content container around it.
                if (!$look['bleed']) {
                    $html = '<div class="' . e($this->wrapClass) . '">' . $html . '</div>';
                }
            }

            $layouts = $item['default'] ? (BlockLook::LAYOUTS[$type] ?? []) : $declared;
            $blocks[] = ['marker' => $item['marker'], 'index' => $item['index'], 'html' => $html, 'default' => $item['default'], 'type' => $type, 'layouts' => implode(',', $layouts)];
        }

        return ['styles' => BlockStyle::for($usesDefault, $this->nonce) . $this->previewStyle(), 'blocks' => $blocks];
    }

    /**
     * The closure a view calls as `$edit('heading')` or `$edit('html', 'rich')`: the attributes that make the element of a field inline editable
     * in a preview; '' outside one. Field and label are escaped; the kind is `plain` unless `rich` is asked for.
     *
     * @param array<string, mixed> $block
     */
    private function editFor(array $block): \Closure
    {
        if (!$this->preview) {
            return static fn (string $field = '', string $kind = 'plain'): string => '';
        }

        $labels = is_array($block['fscLabels'] ?? null) ? $block['fscLabels'] : [];

        return static function (string $field, string $kind = 'plain') use ($labels): string {
            $label = $labels[$field] ?? null;

            return ' data-fsc-edit="' . e($field) . '" data-fsc-edit-kind="' . ($kind === 'rich' ? 'rich' : 'plain') . '"'
                . (is_string($label) && $label !== '' ? ' data-fsc-edit-placeholder="' . e($label) . '"' : '');
        };
    }

    /** @param array<string, mixed> $block */
    private function emptyMarkup(array $block, string $type): string
    {
        $label = $block['fscLabel'] ?? self::EMPTY_LABELS[$type] ?? $type;

        return '<div class="fsc-preview-empty">' . e(is_string($label) ? $label : $type) . '</div>';
    }

    /** Styles of the empty-block placeholder; only in a preview (the live stylesheet stays untouched). */
    private function previewStyle(): string
    {
        if (!$this->preview) {
            return '';
        }

        $nonce = SiteSettings::renderOptions($this->nonce)['nonce'];

        return '<style' . ($nonce !== null ? ' nonce="' . e($nonce) . '"' : '') . '>:where(.fsc-preview-empty){padding:2rem 1rem;border:1px dashed currentColor;border-radius:.5rem;text-align:center;opacity:.55;font:inherit}</style>';
    }

    public function shouldRender(): bool
    {
        return $this->items !== [];
    }

    public function render(): View
    {
        return view('content::components.page-blocks');
    }
}
