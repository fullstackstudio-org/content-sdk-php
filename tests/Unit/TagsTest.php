<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use FullStackStudio\Content\Tags;
use PHPUnit\Framework\TestCase;

final class TagsTest extends TestCase
{
    public function testNormalization(): void
    {
        self::assertSame('services/web-design', Tags::normalizePagePath('/Services/Web-Design/'));
        self::assertSame('content:page:services/web-design', Tags::page('/Services/Web-Design/'));
        self::assertSame('content:article:hello', Tags::article('  Hello '));
        self::assertSame('content:form:contact', Tags::form('Contact'));
    }

    public function testArticleEvent(): void
    {
        self::assertSame(
            ['content:articles', 'content:article:new', 'content:article:old', 'content:article:11111111-1111-4111-8111-111111111111', 'content:categories', 'content:sitemap', 'content:project'],
            Tags::forEvent(['type' => 'article.updated', 'data' => ['slug' => 'new', 'previousSlug' => 'old', 'id' => '11111111-1111-4111-8111-111111111111']]),
        );
    }

    public function testOtherEvents(): void
    {
        self::assertSame(['content:categories', 'content:articles'], Tags::forEvent(['type' => 'category.created', 'data' => []]));
        self::assertSame(['content:pages', 'content:page:about', 'content:sitemap'], Tags::forEvent(['type' => 'page.published', 'data' => ['slug' => 'About']]));
        self::assertSame(['content:media', 'content:articles', 'content:pages'], Tags::forEvent(['type' => 'media.deleted', 'data' => []]));
        self::assertSame(['content:product-categories', 'content:products'], Tags::forEvent(['type' => 'product.category.updated', 'data' => []]));
        self::assertSame(['content:products', 'content:product:shoe', 'content:sitemap'], Tags::forEvent(['type' => 'product.stock_changed', 'data' => ['id' => 'shoe']]));
        self::assertSame(['content:products', 'content:product:p1'], Tags::forEvent(['type' => 'review.approved', 'data' => ['productId' => 'p1']]));
        self::assertSame(['content:faq'], Tags::forEvent(['type' => 'faq.item.updated', 'data' => []]));
        self::assertSame(['content:form:contact'], Tags::forEvent(['type' => 'form.updated', 'data' => ['key' => 'contact']]));
        self::assertSame([], Tags::forEvent(['type' => 'form.submission.created', 'data' => ['key' => 'contact']]));
        self::assertSame(['content:redirects'], Tags::forEvent(['type' => 'redirect.deleted', 'data' => []]));
        self::assertSame(['content:seo', 'content:sitemap'], Tags::forEvent(['type' => 'seo.updated', 'data' => []]));
        self::assertSame(['content:legal', 'content:legal:terms'], Tags::forEvent(['type' => 'legal.version.published', 'data' => ['kind' => 'terms']]));
        self::assertSame([], Tags::forEvent(['type' => 'legal.accepted', 'data' => []]));
        self::assertSame([], Tags::forEvent(['type' => 'discount.updated', 'data' => []]));
        self::assertSame([], Tags::forEvent(['type' => 'webhook.test', 'data' => []]));
        self::assertSame([], Tags::forEvent(['type' => 'future.thing', 'data' => []]));
        self::assertSame([], Tags::forEvent([]));
    }
}
