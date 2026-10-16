<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Tags;
use FullStackStudio\Content\Internal\ModuleContext;

/** The FAQ (`$client->faq`; module `faq`). `list` is public; categories and items (`$faq->categories`, `$faq->items`) need a server token. */
final class Faq
{
    public readonly FaqCategories $categories;

    public readonly FaqItems $items;

    public function __construct(private readonly ModuleContext $ctx)
    {
        $this->categories = new FaqCategories($ctx);
        $this->items = new FaqItems($ctx);
    }

    /**
     * The published FAQ, grouped by category (cached under `content:faq`).
     *
     * @param array{category?: string, locale?: string} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->get('/faq', [Tags::FAQ], Arr::pick($params, ['category', 'locale']), $options)->data;
    }
}
