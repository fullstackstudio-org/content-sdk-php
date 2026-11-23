<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Site\PageContext;
use Throwable;

/**
 * The language of the form texts when nothing was passed explicitly. Order: `content.forms.locale` (env `FSC_FORMS_LOCALE`), the content
 * locale of the client (`Content::setLocale()`, set by the path resolver), the locale of the Hub page being rendered, the Laravel app locale,
 * and finally `nl`. The page locale comes from the Hub (an enabled project language), so it
 * counts as is, `en` included: the English version of a multilingual site gets English form texts. Laravel's own default app locale `en`
 * counts as "never set" (a fresh skeleton has it and Dutch sites forget to change it), so an English site without Hub pages sets
 * `FSC_FORMS_LOCALE=en` or passes `locale="en"` to the component.
 */
final class FormLocale
{
    public static function resolve(): string
    {
        $configured = config('content.forms.locale');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $content = strtolower(substr((string) self::contentLocale(), 0, 2));

        if ($content !== '') {
            return $content;
        }

        $page = strtolower(substr((string) self::pageLocale(), 0, 2));

        if ($page !== '') {
            return $page;
        }

        $app = strtolower(substr((string) self::appLocale(), 0, 2));

        return $app !== '' && $app !== 'en' ? $app : 'nl';
    }

    private static function contentLocale(): ?string
    {
        try {
            return app(ContentClient::class)->locale();
        } catch (Throwable) {
            return null;
        }
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
