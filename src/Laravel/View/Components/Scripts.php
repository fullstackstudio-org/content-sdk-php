<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\Laravel\Auto\Auto;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Laravel\Site\PageContext;
use FullStackStudio\Content\Laravel\Site\Parts;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Throwable;

/**
 * `<x-content::scripts />` right before `</body>`. Prints, each part once per request: the cookie banner (when `consent` is on, never in a
 * portal preview), the owner's body code and click events, and the analytics trackers with the HTTP status of the request from
 * {@see PageContext} (so a 404 is counted without a `status` prop). Never throws. `nonce` defaults to Laravel's Vite CSP nonce.
 */
final class Scripts extends Component
{
    public function __construct(public readonly ?string $nonce = null, public readonly ?bool $consent = null)
    {
    }

    public function render(): HtmlString
    {
        try {
            return new HtmlString($this->html());
        } catch (Throwable) {
            return new HtmlString('');
        }
    }

    private function html(): string
    {
        $nonce = Parts::nonce($this->nonce);
        $html = '';

        if (($this->consent ?? Auto::on('consent')) && ! Preview::isActive() && ! Parts::seen('banner')) {
            $html .= trim(view('content::partials.banner', ['nonce' => $nonce])->render());
        }

        if (! Parts::seen('body-end')) {
            $html .= BodyEnd::markup($nonce);
        }

        if (! Parts::seen('analytics')) {
            $status = null;

            try {
                $status = app(PageContext::class)->status();
            } catch (Throwable) {
                // No context: the tracker prints without a status.
            }

            $html .= Analytics::markup($nonce, $status);
        }

        return $html;
    }
}
