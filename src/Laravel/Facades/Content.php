<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Facades;

use FullStackStudio\Content\Analytics;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\AnalyticsSettings;
use FullStackStudio\Content\Laravel\ContentServiceProvider;
use FullStackStudio\Content\Laravel\HtmlBlock;
use FullStackStudio\Content\Laravel\Site\PageContext;
use FullStackStudio\Content\Laravel\Site\Urls;
use FullStackStudio\Content\Laravel\SiteSettings;
use FullStackStudio\Content\Site;
use Illuminate\Support\Facades\Facade;

/**
 * `Content::getArticle('hello')`, `Content::admin()`... forwards to the {@see ContentClient} in the container.
 *
 * @mixin ContentClient
 */
final class Content extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ContentClient::class;
    }

    /** Registers the form route (`POST content/forms/{key}`) behind `<x-content::form>`. */
    /**
     * True while this request renders a portal preview (see AGENTS.md A17). Wrap third-party scripts written straight into a layout in
     * `@unless (Content::isPreview()) ... @endunless`: an editor's preview is never a visit, and such scripts can read the page source.
     */
    public static function isPreview(): bool
    {
        return \FullStackStudio\Content\Laravel\Preview\Preview::isActive();
    }

    /** The request scoped context of the out of the box site (resolved resource, status, meta overrides). */
    public static function context(): PageContext
    {
        return app(PageContext::class);
    }

    /** Builds every content URL (page, article, event, legal, locale prefix, trailing slash, absolute) in one place. */
    public static function urls(): Urls
    {
        return app(Urls::class);
    }

    /**
     * Overrides site meta (`title`, `description`, `canonical`, `image`, ...) for this request; merges over earlier calls. The head
     * component prints it over the Hub values.
     *
     * @param array<string, mixed> $meta
     */
    public static function seo(array $meta): void
    {
        self::context()->seo($meta);
    }

    public static function formRoutes(): void
    {
        ContentServiceProvider::registerFormRoute();
    }

    /** Registers the newsletter route behind the `newsletterSignup` block (`POST content/newsletter`), for a site with `content.newsletter.enabled` off. */
    public static function newsletterRoutes(): void
    {
        ContentServiceProvider::registerNewsletterRoute();
    }

    /** Registers the webhook route (`POST content/webhook`). Put it in `routes/api.php` or any routes file. */
    public static function routes(): void
    {
        ContentServiceProvider::registerWebhookRoute();
    }

    /**
     * Content-Security-Policy sources for analytics in the configured mode (first-party: 'self' only), per directive. Merge them into your
     * policy, or use `analyticsCspMerge()` on an existing policy string.
     *
     * @return array<string, list<string>>
     */
    public static function analyticsCsp(): array
    {
        $settings = AnalyticsSettings::make();

        return Analytics::csp($settings->mode(), $settings->host(), $settings->nativeSitekey() !== null);
    }

    /** Adds the analytics sources to an existing CSP string (missing directives are added). */
    public static function analyticsCspMerge(string $policy): string
    {
        $settings = AnalyticsSettings::make();

        return Analytics::mergeCsp($policy, $settings->mode(), $settings->host(), $settings->nativeSitekey() !== null);
    }

    /**
     * Content-Security-Policy sources the customer's own code needs, per directive: the snippets of the module `site` (hosts of their
     * `src`/`href` attributes and of URLs inside inline scripts) plus the page builder `html` blocks rendered in this request. Empty when
     * there is neither. The components put the nonce on every script and style they print, so a nonce-based policy needs nothing else for
     * them. Call it after the page rendered (a middleware that sets the header on the response), or the blocks are not counted yet.
     *
     * @return array<string, list<string>>
     */
    public static function siteCsp(): array
    {
        try {
            $site = SiteSettings::make()->current();
            $sources = $site === null ? [] : Site::csp($site);
        } catch (\Throwable) {
            $sources = [];
        }

        foreach (HtmlBlock::current()->sources() as $directive => $origins) {
            $sources[$directive] = array_values(array_unique([...($sources[$directive] ?? []), ...$origins]));
        }

        return $sources;
    }

    /**
     * Adds the site sources to an existing policy: an array (`['script-src' => ["'self'"], ...]`) gives an array back, a CSP string a
     * string. A missing directive starts from `default-src`; without `default-src` it is left out (adding it would forbid the rest).
     *
     * @param string|array<string, string|list<string>> $policy
     * @return ($policy is string ? string : array<string, list<string>>)
     */
    public static function siteCspMerge(string|array $policy): string|array
    {
        return Site::mergeCsp($policy, self::siteCsp());
    }
}
