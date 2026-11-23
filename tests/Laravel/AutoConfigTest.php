<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Laravel\Auto\Auto;
use FullStackStudio\Content\Laravel\ContentServiceProvider;
use FullStackStudio\Content\Laravel\Facades\Content;
use FullStackStudio\Content\Laravel\Site\PageContext;
use FullStackStudio\Content\Laravel\Site\Resolved;

final class AutoConfigTest extends LaravelTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // The base test case switches the Hub redirect lookup off; the defaults are what this test checks.
        $app['config']->set('content.auto.redirects', true);
    }

    public function testDefaults(): void
    {
        foreach (['pages', 'articles', 'legal', 'sitemap', 'robots', 'health', 'redirects', 'errors', 'platform', 'consent'] as $feature) {
            self::assertTrue(Auto::on($feature), $feature);
        }

        self::assertFalse(config('content.auto.trailing_slash'));
        self::assertFalse(Auto::on('unknown'));
        self::assertNull(Auto::layout());
        self::assertSame('content', Auto::section());
        self::assertNull(Auto::wrap());
        self::assertSame('content::site.article', Auto::view('article'));
        self::assertNull(Auto::handler('article'));
        self::assertSame('categorie', Auto::option('articles', 'category_segment'));
        self::assertSame(12, Auto::option('articles', 'per_page'));
        self::assertSame([], Auto::option('sitemap', 'exclude'));
        self::assertSame('terms', Auto::legalPaths()['algemene-voorwaarden']);
    }

    /** The package config is a plain PHP file: evaluate it under a given environment to see what a site gets. */
    private function webhookEnabledWith(?string $enabled, ?string $secret): bool
    {
        $repository = \Illuminate\Support\Env::getRepository();

        foreach (['FSC_WEBHOOK_ENABLED' => $enabled, 'FSC_WEBHOOK_SECRET' => $secret] as $name => $value) {
            $repository->clear($name);
            if ($value !== null) {
                $repository->set($name, $value);
            }
        }

        try {
            return (require __DIR__ . '/../../config/content.php')['webhook']['enabled'];
        } finally {
            $repository->clear('FSC_WEBHOOK_ENABLED');
            $repository->clear('FSC_WEBHOOK_SECRET');
        }
    }

    public function testTheWebhookRouteFollowsTheSecretUnlessSwitchedOffExplicitly(): void
    {
        self::assertFalse($this->webhookEnabledWith(null, null), 'no secret, no route');
        self::assertFalse($this->webhookEnabledWith('', ''), 'empty values count as unset');
        self::assertTrue($this->webhookEnabledWith(null, 'fsc_whsec_abc'), 'a secret turns it on by itself');
        self::assertFalse($this->webhookEnabledWith('false', 'fsc_whsec_abc'), 'FSC_WEBHOOK_ENABLED=false still wins');
        self::assertTrue($this->webhookEnabledWith('true', 'fsc_whsec_abc'));
        self::assertTrue($this->webhookEnabledWith('true', null), 'an explicit true registers the route without a secret');
    }

    public function testMasterSwitchAndFeatureSwitch(): void
    {
        config(['content.auto.pages' => false]);
        self::assertFalse(Auto::on('pages'));
        self::assertTrue(Auto::on('robots'));

        config(['content.auto.enabled' => false]);
        self::assertFalse(Auto::on('robots'));
        self::assertFalse(Auto::on('enabled'));
    }

    public function testArrayFeatureCanBeSwitchedOff(): void
    {
        config(['content.auto.articles.enabled' => false]);
        self::assertFalse(Auto::on('articles'));

        config(['content.auto.sitemap' => false]);
        self::assertFalse(Auto::on('sitemap'));
        self::assertSame('x', Auto::option('sitemap', 'exclude', 'x'));
    }

    public function testViewHandlerLayoutAndWrap(): void
    {
        config([
            'content.auto.views.article' => 'site.my-article',
            'content.auto.handlers.event' => Resolved::class,
            'content.auto.handlers.page' => 'App\\Missing',
            'content.auto.layout' => 'layouts.app',
            'content.auto.section' => 'main',
            'content.auto.wrap' => 'max-w-5xl',
        ]);

        self::assertSame('site.my-article', Auto::view('article'));
        self::assertSame('content::site.page', Auto::view('page'));
        self::assertSame(Resolved::class, Auto::handler('event'));
        self::assertNull(Auto::handler('page'));
        self::assertSame('layouts.app', Auto::layout());
        self::assertSame('main', Auto::section());
        self::assertSame('max-w-5xl', Auto::wrap());
    }

    public function testEnvOverrides(): void
    {
        putenv('FSC_AUTO_PAGES=false');
        putenv('FSC_AUTO_TRAILING_SLASH=true');
        putenv('FSC_AUTO_LAYOUT=layouts.env');
        $_ENV['FSC_AUTO_PAGES'] = 'false';
        $_ENV['FSC_AUTO_TRAILING_SLASH'] = 'true';
        $_ENV['FSC_AUTO_LAYOUT'] = 'layouts.env';

        try {
            $config = require __DIR__ . '/../../config/content.php';

            self::assertFalse($config['auto']['pages']);
            self::assertTrue($config['auto']['trailing_slash']);
            self::assertSame('layouts.env', $config['auto']['layout']);
        } finally {
            putenv('FSC_AUTO_PAGES');
            putenv('FSC_AUTO_TRAILING_SLASH');
            putenv('FSC_AUTO_LAYOUT');
            unset($_ENV['FSC_AUTO_PAGES'], $_ENV['FSC_AUTO_TRAILING_SLASH'], $_ENV['FSC_AUTO_LAYOUT']);
        }
    }

    public function testSparseAppConfigKeepsNewDefaults(): void
    {
        config(['content.auto' => ['pages' => false, 'articles' => ['per_page' => 5], 'legal_paths' => ['privacy' => 'privacy_statement']]]);
        (new ContentServiceProvider($this->app))->register();

        self::assertFalse(Auto::on('pages'));
        self::assertTrue(Auto::on('robots'));
        self::assertTrue(Auto::on('articles'));
        self::assertSame(5, Auto::option('articles', 'per_page'));
        self::assertSame('categorie', Auto::option('articles', 'category_segment'));
        self::assertSame('content', Auto::section());
        self::assertSame(['privacy' => 'privacy_statement'], Auto::legalPaths());
    }

    public function testBoolOverArrayDefaultSwitchesFeatureOff(): void
    {
        config(['content.auto' => ['articles' => false]]);
        (new ContentServiceProvider($this->app))->register();

        self::assertFalse(Auto::on('articles'));
        self::assertTrue(Auto::on('legal'));
    }

    public function testContextIsScopedAndNeverThrows(): void
    {
        $context = Content::context();
        self::assertSame($context, app(PageContext::class));
        self::assertNull($context->resolved());
        self::assertNull($context->status());
        self::assertSame([], $context->seo());

        $resolved = new Resolved('page', ['id' => 'p1'], '/over-ons', 'nl');
        $context->resolve($resolved);
        $context->setStatus(404);
        Content::seo(['title' => 'A', 'description' => 'B']);
        Content::seo(['title' => 'C']);

        self::assertSame($resolved, $context->resolved());
        self::assertSame(404, $context->status());
        self::assertSame(['title' => 'C', 'description' => 'B'], $context->seo());
        self::assertSame([], $resolved->extra);

        self::assertFalse($context->printed('head'));
        self::assertTrue($context->printed('head'));
        self::assertFalse($context->printed('scripts'));

        // A new request (scoped instances are flushed) starts clean.
        $this->app->forgetScopedInstances();
        $fresh = Content::context();
        self::assertNotSame($context, $fresh);
        self::assertNull($fresh->resolved());
        self::assertSame([], $fresh->seo());
        self::assertFalse($fresh->printed('head'));
    }
}
