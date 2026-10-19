<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\Analytics as AnalyticsTags;
use FullStackStudio\Content\Laravel\AnalyticsSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Component;
use Throwable;

/**
 * `<x-content::analytics />` in the layout: the Umami tracker (cookieless, without consent) and the helper script that starts heatmaps and
 * session replay only after cookie consent and adds first-touch attribution to forms. Renders nothing when the module is off, not
 * provisioned or Content is unreachable. `nonce` defaults to Laravel's Vite CSP nonce when one is set.
 */
final class Analytics extends Component
{
    public function __construct(public readonly ?string $nonce = null)
    {
    }

    public function render(): View
    {
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
                    'nonce' => $this->nonce ?? self::viteNonce(),
                    'consentKey' => (string) config('content.analytics.consent_key', 'fsc-consent'),
                    'consentEvent' => (string) config('content.analytics.consent_event', 'fsc:consent'),
                    'helperSrc' => $base . '/fsc.js',
                ]);
            }
        } catch (Throwable) {
            // Analytics never breaks a page.
            $html = '';
        }

        return view('content::components.analytics', ['html' => $html]);
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
