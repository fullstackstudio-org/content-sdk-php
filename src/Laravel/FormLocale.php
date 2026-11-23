<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\Laravel\Site\PageContext;
use Throwable;

/**
 * The language of the form texts when nothing was passed explicitly. Order: `content.forms.locale` (env `FSC_FORMS_LOCALE`), the locale of the
 * Hub page being rendered, the Laravel app locale, and finally `nl`. Laravel's own default `en` counts as "never set" (a fresh skeleton has it
 * and Dutch sites forget to change it), so an English site sets `FSC_FORMS_LOCALE=en` or passes `locale="en"` to the component.
 */
final class FormLocale
{
    public static function resolve(): string
    {
        $configured = config('content.forms.locale');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        foreach ([self::pageLocale(), self::appLocale()] as $candidate) {
            $short = strtolower(substr((string) $candidate, 0, 2));

            if ($short !== '' && $short !== 'en') {
                return $short;
            }
        }

        return 'nl';
    }

    private static function pageLocale(): ?string
    {
        try {
            return app(PageContext::class)->resolved()?->locale;
        } catch (Throwable) {
            return null;
        }
    }

    private static function appLocale(): ?string
    {
        try {
            return app()->getLocale();
        } catch (Throwable) {
            return null;
        }
    }
}
