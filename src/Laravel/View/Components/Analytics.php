<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\Analytics as AnalyticsTags;
use FullStackStudio\Content\Laravel\AnalyticsSettings;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Laravel\Site\Parts;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Component;
use Throwable;

/**
 * `<x-content::analytics />` in the layout: the tracker(s) of the Hub's engine (Umami, the native `fsa.js`, or both during the
 * double-measure period; all cookieless without consent) and the helper script that starts heatmaps and session replay only after cookie
 * consent and adds first-touch attribution to forms. Renders nothing when the module is off, not provisioned or Content is unreachable.
 * `nonce` defaults to Laravel's Vite CSP nonce when one is set. `status="404"` on an error page marks it as not found (native tracker).
 * Renders nothing in a portal preview: an editor's preview is never a visit.
 */
final class Analytics extends Component
{
    public function __construct(public readonly ?string $nonce = null, public readonly int|string|null $status = null)
    {
    }

    public function render(): View
    {
        return view('content::components.analytics', ['html' => Parts::seen('analytics') ? '' : self::markup($this->nonce, $this->status)]);
    }

    /** The tracker tags, or '' (module off, preview, outage). Never throws. */
    public static function markup(?string $nonce, int|string|null $status): string
    {
        if (Preview::isActive()) {
            return '';
        }

        $settings = AnalyticsSettings::make();
        $html = '';

        try {
            $config = $settings->current();

            if ($config !== null) {
                $base = '/' . $settings->path();
                $html = AnalyticsTags::tags($config, [
                    'mode' => $settings->mode(),
                    'base' => $base,
                    'host' => $settings->host(),
                    'nonce' => $nonce ?? self::viteNonce(),
                    'consentKey' => (string) config('content.analytics.consent_key', 'fsc-consent'),
                    'consentEvent' => (string) config('content.analytics.consent_event', 'fsc:consent'),
                    'helperSrc' => $base . '/fsc.js',
                    'native' => $settings->nativeSitekey() !== null && $settings->collectorUrl() !== null,
                    'status' => is_numeric($status) ? (int) $status : null,
                    'pidCookieDomain' => config('content.analytics.pid_cookie_domain'),
                ]);
            }
        } catch (Throwable) {
            // Analytics never breaks a page.
            $html = '';
        }

        return $html;
    }

    private static function viteNonce(): ?string
    {
        try {
            $nonce = class_exists(Vite::class) ? Vite::cspNonce() : null;
        } catch (Throwable) {
            $nonce = null;
        }

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }
}
