<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Preview;

use Illuminate\Http\Response;

/** `GET {content.preview.path}/preview.js`: the static preview helper (no visitor data, no secrets; the tag carries `?v=<sdk version>`). */
final class PreviewAssetController
{
    public function __invoke(): Response
    {
        return new Response((string) file_get_contents(__DIR__ . '/../../../resources/assets/preview.js'), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
