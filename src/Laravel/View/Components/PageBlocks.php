<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\ContentClient;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-content::page-blocks :blocks="$page['blocks']" :media="$page['media']" />`: renders the blocks of a page with default markup per block
 * type (`fsc-*` classes, see the publishable `content.css`).
 *
 * Overrides, per block type (first hit wins): the `views` prop (`:views="['articleList' => 'blocks.articles']"`), the config
 * `content.blocks.<type>`, and finally the package view `content::blocks.<type>`. Because the package views are namespaced, you can also replace one by
 * creating `resources/views/vendor/content/blocks/<type>.blade.php`. A view gets: `$block`, `$index`, `$media` (the resolved media of the block, or null),
 * `$resolve` (a closure id => resolved media|null), `$baseUrl`. An unknown type is skipped, or rendered by the view in `views['unknown']` /
 * `content.blocks.unknown`.
 *
 * `media` is `$page['media']`: with it, a block whose file was deleted is omitted; without it (null) the URL is derived from the id.
 * Only richText and columns print their HTML unescaped (Content sanitizes it on save); everything else is escaped.
 */
final class PageBlocks extends Component
{
    public const TYPES = ['richText', 'image', 'gallery', 'cta', 'hero', 'columns', 'articleList', 'form', 'embed', 'legalDocument'];

    public string $baseUrl;

    /** @var list<array{key: string, block: array<string, mixed>, index: int, view: string}> */
    public array $items = [];

    /**
     * @param list<array<string, mixed>>|null $blocks
     * @param array<string, array<string, mixed>>|null $media
     * @param array<string, string> $views
     */
    public function __construct(public readonly ?array $blocks = null, public readonly ?array $media = null, ?string $baseUrl = null, public readonly array $views = [])
    {
        $this->baseUrl = $baseUrl ?? app(ContentClient::class)->baseUrl();

        foreach (array_values($blocks ?? []) as $index => $block) {
            $type = (string) ($block['type'] ?? '');
            $known = in_array($type, self::TYPES, true);
            $view = $this->views[$known ? $type : 'unknown'] ?? config('content.blocks.' . ($known ? $type : 'unknown')) ?? ($known ? "content::blocks.{$type}" : null);

            if ($view === null) {
                continue;
            }

            $this->items[] = ['key' => (string) ($block['id'] ?? "{$type}-{$index}"), 'block' => $block, 'index' => $index, 'view' => $view];
        }
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
