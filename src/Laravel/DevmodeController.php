<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Illuminate\Http\Response;

/** `GET {content.devmode.path}/devmode.js` (the panel) and `flags.js` (the display flags). Static files, no visitor data; the tags carry `?v=<sdk version>`. */
final class DevmodeController
{
    public function panel(): Response
    {
        return self::script('fsc-devmode.js');
    }

    public function flags(): Response
    {
        return self::script('fsc-devflags.js');
    }

    private static function script(string $file): Response
    {
        return new Response((string) file_get_contents(__DIR__ . '/../../resources/assets/' . $file), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
