<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\Laravel\Devmode as DevmodeTags;
use FullStackStudio\Content\Laravel\SiteSettings;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Throwable;

/**
 * `<x-content::devmode />`: the dev panel tags, only for a request with the devmode cookie. `<x-content::head />` already includes them, so this is
 * only for a layout without that component. Renders nothing otherwise; written once per request.
 */
final class Devmode extends Component
{
    public function __construct(public readonly ?string $nonce = null)
    {
    }

    public function render(): HtmlString
    {
        try {
            return new HtmlString(DevmodeTags::tags(null, SiteSettings::renderOptions($this->nonce)['nonce']));
        } catch (Throwable) {
            return new HtmlString('');
        }
    }
}
