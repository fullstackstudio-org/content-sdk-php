<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\Laravel\Auto\Auto;

/**
 * Which layout the default views extend: `content.auto.layout`; else the site's own `layouts.app` when it exists (the convention of the
 * example site); else the small package layout `content::layouts.site` (head, content section, scripts), so a fresh app renders.
 */
final class Layout
{
    public const PACKAGE = 'content::layouts.site';

    public static function name(): string
    {
        $configured = Auto::layout();

        if ($configured !== null) {
            return $configured;
        }

        return view()->exists('layouts.app') ? 'layouts.app' : self::PACKAGE;
    }
}
