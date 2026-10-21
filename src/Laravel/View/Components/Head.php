<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\Laravel\SiteSettings;
use FullStackStudio\Content\Site;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Throwable;

/**
 * `<x-content::head />` in the `<head>` of every layout, after `<x-content::meta>`: search-engine verification metas, favicon,
 * apple-touch-icon, theme-color, the LocalBusiness JSON-LD and the customer's own head code from the Content module `site` (code that
 * needs consent stays inert until the visitor accepts that category). Renders nothing when the module is off or Content is
 * unreachable; never throws. `nonce` defaults to Laravel's Vite CSP nonce when one is set.
 */
final class Head extends Component
{
    public function __construct(public readonly ?string $nonce = null)
    {
    }

    public function render(): HtmlString
    {
        try {
            $site = SiteSettings::make()->current();

            if ($site === null) {
                return new HtmlString('');
            }

            return new HtmlString(Site::head($site, SiteSettings::renderOptions($this->nonce)));
        } catch (Throwable) {
            return new HtmlString('');
        }
    }
}
