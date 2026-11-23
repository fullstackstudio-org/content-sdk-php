<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

/**
 * What the path resolver decided a request is: one Hub resource of one type. Immutable; later tasks build it and the handlers and default
 * views read it.
 */
final readonly class Resolved
{
    public const TYPES = ['page', 'article', 'articles', 'category', 'event', 'legal'];

    /**
     * @param 'page'|'article'|'articles'|'category'|'event'|'legal' $type
     * @param array<string, mixed> $resource The Hub resource as returned by the API (for lists: the list payload).
     * @param string $path Canonical path with a leading slash.
     * @param array<string, mixed> $extra Type specific extras (pagination, category, ...).
     */
    public function __construct(
        public string $type,
        public array $resource,
        public string $path,
        public ?string $locale = null,
        public array $extra = [],
    ) {
    }
}
