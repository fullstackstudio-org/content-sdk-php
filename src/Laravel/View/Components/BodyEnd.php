<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\Laravel\AnalyticsSettings;
use FullStackStudio\Content\Laravel\SiteSettings;
use FullStackStudio\Content\Site;
use FullStackStudio\Content\Laravel\Site\Parts;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Throwable;

/**
 * `<x-content::body-end />` right before `</body>` of every layout: the customer's own body code from the Content module `site` (gated
 * by consent like the head code) and, when click events are on and the analytics module is active, a delegated listener that sends a
 * `contact-click` event to Umami for tel:, mailto: and WhatsApp links. Renders nothing when the module is off or Content is unreachable;
 * never throws.
 */
final class BodyEnd extends Component
{
    public function __construct(public readonly ?string $nonce = null)
    {
    }

    public function render(): HtmlString
    {
        return new HtmlString(Parts::seen('body-end') ? '' : self::markup($this->nonce));
    }

    /** The body code and click listener, or '' when the module is off. Never throws. */
    public static function markup(?string $nonce): string
    {
        try {
            $site = SiteSettings::make()->current();

            if ($site === null) {
                return '';
            }

            $clickEvents = false;

            if (($site['clickEvents'] ?? false) === true) {
                try {
                    $clickEvents = AnalyticsSettings::make()->current() !== null;
                } catch (Throwable) {
                    $clickEvents = false;
                }
            }

            return Site::bodyEnd($site, [...SiteSettings::renderOptions($nonce), 'clickEvents' => $clickEvents]);
        } catch (Throwable) {
            return '';
        }
    }
}
