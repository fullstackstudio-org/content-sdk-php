<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Laravel\Site\PageContext;
use FullStackStudio\Content\Laravel\Site\Parts;

/** The print-once state of {@see PageContext} must not leak from one request into the next one of the same application (Laravel HTTP tests). */
final class PageContextPerRequestTest extends LaravelTestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/once-a', static fn () => (Parts::seen('head') ? 'seen' : 'first') . '|' . (Parts::seen('head') ? 'seen' : 'first'));
        $router->get('/once-b', static fn () => Parts::seen('head') ? 'seen' : 'first');
    }

    public function testEachRequestStartsWithNothingPrinted(): void
    {
        $this->get('/once-a')->assertOk()->assertSee('first|seen', false);
        $this->get('/once-b')->assertOk()->assertSee('first', false)->assertDontSee('seen', false);
        $this->get('/once-a')->assertOk()->assertSee('first|seen', false);
    }

    public function testThePageContextIsAFreshInstanceAfterARequest(): void
    {
        $this->get('/once-b')->assertOk();
        $after = $this->app->make(PageContext::class);

        self::assertFalse($after->printed('head'));
    }
}
