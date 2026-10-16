<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-content::article-body :html="$article['bodyHtml']" />`: the formatted text of an article.
 *
 * The HTML is printed unescaped on purpose: Content sanitizes `bodyHtml` on save with an allowlist of tags and attributes (no scripts,
 * no event handlers, no `javascript:` links). Never pass it HTML that does not come from Content.
 */
final class ArticleBody extends Component
{
    public string $tag;

    public function __construct(public readonly ?string $html = null, string $tag = 'div')
    {
        $this->tag = preg_match('/^[a-z][a-z0-9-]*$/', $tag) === 1 ? $tag : 'div';
    }

    public function render(): View
    {
        return view('content::components.article-body');
    }
}
