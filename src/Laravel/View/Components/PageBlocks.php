<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Preview\Preview;
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
 * resolved media|null), `$baseUrl`. A type without a view is skipped, or rendered by the view in `views['unknown']` / `content.blocks.unknown`.
 * Type names must match {@see self::TYPE_PATTERN}; anything else counts as a type without a view.
 *
 * `media` is `$page['media']`: with it, a block whose file was deleted is omitted; without it (null) the URL is derived from the id.
 * Only richText and columns print their HTML unescaped (Content sanitizes it on save); everything else is escaped.
 *
 * In a portal preview the root gets `data-fsc-preview-blocks` and every block is wrapped in `<div data-fsc-block="{id or index}"
 * style="display:contents">`, so the preview can select, highlight and swap blocks. Outside a preview the markup is unchanged.
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

    /** @var list<array{key: string, block: array<string, mixed>, index: int, view: string, marker: string}> */
    public array $items = [];

    /**
     * @param list<array<string, mixed>>|null $blocks
     * @param array<string, array<string, mixed>>|null $media
     * @param array<string, string> $views
     */
    public function __construct(public readonly ?array $blocks = null, public readonly ?array $media = null, ?string $baseUrl = null, public readonly array $views = [])
    {
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
            $this->items[] = ['key' => (string) ($block['id'] ?? "{$type}-{$index}"), 'block' => $block, 'index' => $index, 'view' => $view, 'marker' => $marker];
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

    public function shouldRender(): bool
    {
        return $this->items !== [];
    }

    public function render(): View
    {
        return view('content::components.page-blocks');
    }
}
