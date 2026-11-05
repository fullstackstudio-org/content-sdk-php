<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Illuminate\Http\Request;
use Throwable;

/**
 * Who never gets personalised content (SEO: no cloaking; prefetched pages may be reused): crawlers, link-preview and monitoring bots,
 * headless browsers, command-line clients, requests without a User-Agent, and prefetch/prerender requests (`Sec-Purpose` / `Purpose`).
 * Such a request counts as having NO signal: the default HTML, no Hub call, no private header. The list lives here only; a site appends
 * its own alternation with `content.intelligence.crawler_pattern` (env `FSC_INTELLIGENCE_CRAWLER_PATTERN`, a regex fragment such as
 * `acmemonitor|uptime-check`). The browser step has a small copy of the pattern (`resources/assets/fsi-slots.js`).
 */
final class Crawler
{
    public const PATTERN = 'bot|crawl|spider|slurp|googlebot|google-inspectiontool|googleother|storebot-google|adsbot|mediapartners|apis-google|feedfetcher'
        . '|bingbot|bingpreview|duckduckbot|baiduspider|yandex|applebot|petalbot|ahrefs|semrush|mj12bot|dotbot|rogerbot|screaming frog'
        . '|facebookexternalhit|facebot|twitterbot|linkedinbot|slackbot|discordbot|whatsapp|telegrambot|embedly|pinterest'
        . '|headlesschrome|lighthouse|chrome-lighthouse|pagespeed|gptbot|chatgpt-user|oai-searchbot|claudebot|claude-web|anthropic-ai'
        . '|perplexitybot|ccbot|bytespider|amazonbot|python-requests|curl|wget|go-http-client|java/';

    public static function is(Request $request): bool
    {
        $agent = trim((string) $request->headers->get('User-Agent', ''));

        if ($agent === '') {
            return true;
        }

        foreach (['Sec-Purpose', 'Purpose'] as $header) {
            if (preg_match('/prefetch|prerender/i', (string) $request->headers->get($header, '')) === 1) {
                return true;
            }
        }

        return preg_match(self::regex(), $agent) === 1;
    }

    private static function regex(): string
    {
        $pattern = self::PATTERN;
        $extra = trim((string) config('content.intelligence.crawler_pattern', ''));

        // A bad extra fragment must never break a page: it is ignored.
        if ($extra !== '') {
            try {
                if (@preg_match('#' . str_replace('#', '\#', $extra) . '#i', '') !== false) {
                    $pattern .= '|' . str_replace('#', '\#', $extra);
                }
            } catch (Throwable) {
                // ignored
            }
        }

        return '#' . str_replace('#', '\#', $pattern) . '#i';
    }
}
