<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Errors;

use FullStackStudio\Content\ContentClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /_fsc/e/reporter.js`: the browser reporter, served from the site's own domain (a `script-src 'self'` CSP covers it, nothing to
 * publish). With `?v=<sdk version>` it is cached for a year (the component always adds the current version), without for five minutes.
 */
final class ReporterScriptController
{
    public static function path(): string
    {
        return dirname(__DIR__, 3) . '/resources/js/fsc-errors.js';
    }

    public function __invoke(Request $request): Response
    {
        $body = (string) file_get_contents(self::path());
        $versioned = $request->query('v') === ContentClient::VERSION;

        return new Response($body, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
