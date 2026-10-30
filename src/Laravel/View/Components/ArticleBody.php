<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\Laravel\RichStyle;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-content::article-body :html="$article['bodyHtml']" />`: the formatted text of an article.
 *
 * The HTML is printed unescaped on purpose: Content sanitizes `bodyHtml` on save with an allowlist of tags and attributes (no scripts,
 * no event handlers, no `javascript:` links). Never pass it HTML that does not come from Content.
 *
 * When the HTML holds a block of the Hub rich editor (`fsc-rt-*`), one small inline `<style>` with default styles for those blocks is printed
 * before the body, once per request (zero-specificity selectors, so the site's own CSS wins). `nonce` defaults to Laravel's Vite CSP nonce.
 */
final class ArticleBody extends Component
{
    public string $tag;

    public function __construct(public readonly ?string $html = null, string $tag = 'div', public readonly ?string $nonce = null)
    {
        $this->tag = preg_match('/^[a-z][a-z0-9-]*$/', $tag) === 1 ? $tag : 'div';
    }

    public function render(): View
    {
        return view('content::components.article-body', ['richStyle' => RichStyle::for($this->html, $this->nonce)]);
    }
}
