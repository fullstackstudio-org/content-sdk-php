<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Images;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-content::image :media="$article['cover']" sizes="(min-width: 768px) 50vw, 100vw" />`: an `<img>` (or `<picture>`) with a `srcset` of Content's
 * on-demand variants. Variants exist for png, jpeg and still webp only; anything else renders the original without `srcset`.
 *
 * `alt` null = the alt text of the media record; `alt=""` marks a decorative image. `formats="avif,webp"` renders a `<picture>`.
 */
final class Image extends Component
{
    /** @var array{src: string, srcset: ?string, sizes: ?string, sources: list<array{type: string, srcset: string}>, width: ?int, height: ?int, alt: string}|null */
    public ?array $image = null;

    /**
     * @param array<string, mixed>|null $media {url, contentType, alt, width, height}
     * @param array<int, string>|string $formats
     */
    public function __construct(
        ?array $media = null,
        ?string $baseUrl = null,
        string $sizes = '100vw',
        array|string $formats = 'webp',
        ?string $alt = null,
        public readonly bool $eager = false,
    ) {
        if (is_array($media) && !empty($media['url'])) {
            $list = is_string($formats) ? array_map('trim', explode(',', $formats)) : array_values($formats);
            $this->image = Images::describe($media, $baseUrl ?? app(ContentClient::class)->baseUrl(), $sizes, $list, $alt);
        }
    }

    public function shouldRender(): bool
    {
        return $this->image !== null;
    }

    public function render(): View
    {
        return view('content::components.image');
    }
}
