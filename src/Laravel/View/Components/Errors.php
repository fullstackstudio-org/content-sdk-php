<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Errors\Errors as ErrorMonitoring;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Throwable;

/**
 * `<x-content::errors />` in the `<head>` of your layout: the browser error reporter of the Content module `errors`.
 *
 * Renders a JSON config block (`type="application/json"`, not executable, so no CSP exception) and `<script src="/_fsc/e/reporter.js" defer>`
 * from your own domain. Renders nothing when the module is off, in `local`/`testing`, or without a browser DSN. Optional `nonce`.
 * The reporter only sends uncaught errors and unhandled rejections from your own scripts, scrubbed, through the tunnel route.
 */
final class Errors extends Component
{
    public const ELEMENT_ID = 'fsc-errors';

    public function __construct(public readonly ?string $nonce = null)
    {
    }

    public function render(): HtmlString
    {
        try {
            $app = app();
            $config = $app['config'];
            $monitoring = $app->make(ErrorMonitoring::class);
            $settings = $config->get('content.errors.browser', true) ? $monitoring->config() : null;

            if ($settings === null || $settings->browserDsn === null) {
                return new HtmlString('');
            }

            $base = '/' . trim((string) $config->get('content.errors.tunnel.path', '_fsc/e'), '/');
            $tunnel = (bool) $config->get('content.errors.tunnel.enabled', true);
            $data = [
                'dsn' => $settings->browserDsn,
                'tunnel' => $tunnel ? $base . '/envelope' : null,
                'environment' => $settings->environment,
                'release' => $settings->release,
                'route' => $monitoring->route(true) ?? 'GET /[unmatched]',
            ];
            $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $nonce = $this->nonce !== null && $this->nonce !== '' ? ' nonce="' . e($this->nonce) . '"' : '';
            $src = $base . '/reporter.js?v=' . rawurlencode(ContentClient::VERSION);

            return new HtmlString(
                '<script type="application/json" id="' . self::ELEMENT_ID . '"' . $nonce . '>' . $json . '</script>'
                . '<script src="' . e($src) . '"' . $nonce . ' defer referrerpolicy="no-referrer"></script>'
            );
        } catch (Throwable) {
            return new HtmlString('');
        }
    }
}
