<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;

final class BladeComponentsTest extends LaravelTestCase
{
    private FakeTransport $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeTransport();
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
    }

    private function render(string $template, array $data = []): string
    {
        return preg_replace('/ {2,}/', ' ', Blade::render($template, $data));
    }

    // --- article-body -------------------------------------------------------------------------------------------------------

    public function testArticleBodyPrintsTheSanitizedHtmlUnescaped(): void
    {
        $html = $this->render('<x-content::article-body :html="$html" class="prose" />', ['html' => '<p>Hello <strong>you</strong></p>']);

        self::assertStringContainsString('<div class="fsc-article-body prose"><p>Hello <strong>you</strong></p></div>', $html);
    }

    public function testArticleBodyHandlesNullAndRestrictsTheTag(): void
    {
        self::assertStringContainsString('<article class="fsc-article-body"></article>', $this->render('<x-content::article-body :html="null" tag="article" />'));
        self::assertStringContainsString('<div class="fsc-article-body">', $this->render('<x-content::article-body html="x" tag="div onload=alert(1)" />'));
    }

    // --- image --------------------------------------------------------------------------------------------------------------

    public function testImageRendersSrcsetForAVariantType(): void
    {
        $media = ['url' => '/api/media/abc', 'contentType' => 'image/jpeg', 'alt' => 'A cat', 'width' => 1000, 'height' => 500];
        $html = $this->render('<x-content::image :media="$media" sizes="50vw" class="cover" />', ['media' => $media]);

        self::assertStringContainsString('src="https://content.test/api/media/abc"', $html);
        self::assertStringContainsString('srcset="https://content.test/api/media/abc?w=320&amp;format=webp 320w, https://content.test/api/media/abc?w=640&amp;format=webp 640w, https://content.test/api/media/abc?w=960&amp;format=webp 960w, https://content.test/api/media/abc?w=1280&amp;format=webp 1000w"', $html);
        self::assertStringContainsString('sizes="50vw"', $html);
        self::assertStringContainsString('alt="A cat" width="1000" height="500" loading="lazy" decoding="async"', $html);
        self::assertStringContainsString('class="cover"', $html);
        self::assertStringNotContainsString('<picture', $html);
    }

    public function testImageRendersAPictureForTwoFormatsAndNoSrcsetForAGif(): void
    {
        $media = ['url' => '/api/media/abc', 'contentType' => 'image/png', 'alt' => '', 'width' => 400, 'height' => 300];
        $html = $this->render('<x-content::image :media="$media" formats="avif,webp" alt="Decor" :eager="true" />', ['media' => $media]);

        self::assertStringContainsString('<picture>', $html);
        self::assertStringContainsString('type="image/avif"', $html);
        self::assertStringContainsString('format=avif 320w, https://content.test/api/media/abc?w=640&amp;format=avif 400w', $html);
        self::assertStringContainsString('loading="eager"', $html);

        $gif = $this->render('<x-content::image :media="$media" />', ['media' => ['url' => '/api/media/g', 'contentType' => 'image/gif', 'alt' => 'g', 'width' => 10, 'height' => 10]]);
        self::assertStringNotContainsString('srcset', $gif);
    }

    public function testImageEscapesAltAndKeepsAnEmptyAltForDecorativeImages(): void
    {
        $media = ['url' => '/api/media/abc', 'contentType' => 'image/gif', 'alt' => '"><script>alert(1)</script>', 'width' => null, 'height' => null];
        $html = $this->render('<x-content::image :media="$media" />', ['media' => $media]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('alt="&quot;&gt;&lt;script&gt;', $html);
        self::assertStringContainsString('alt=""', $this->render('<x-content::image :media="$media" alt="" />', ['media' => $media]));
        self::assertSame('', trim($this->render('<x-content::image :media="null" />')));
    }

    // --- page-blocks --------------------------------------------------------------------------------------------------------

    private function media(): array
    {
        return ['m1' => ['id' => 'm1', 'url' => '/api/media/m1', 'contentType' => 'image/png', 'alt' => 'Media alt', 'width' => 800, 'height' => 600]];
    }

    private function blocks(): array
    {
        return [
            ['id' => 'b1', 'type' => 'hero', 'heading' => 'Welcome', 'subheading' => 'Sub', 'mediaId' => 'm1', 'ctaLabel' => 'Go', 'ctaHref' => '/start'],
            ['id' => 'b2', 'type' => 'richText', 'html' => '<p>Rich <em>text</em></p>'],
            ['id' => 'b3', 'type' => 'image', 'mediaId' => 'm1', 'alt' => '', 'caption' => 'Cap'],
            ['id' => 'b4', 'type' => 'gallery', 'columns' => 2, 'items' => [['mediaId' => 'm1', 'alt' => 'One', 'caption' => 'First'], ['mediaId' => 'gone', 'alt' => '', 'caption' => '']]],
            ['id' => 'b5', 'type' => 'cta', 'heading' => 'Act', 'text' => 'Now', 'label' => 'Click', 'href' => '/x', 'variant' => 'secondary'],
            ['id' => 'b6', 'type' => 'columns', 'columns' => [['html' => '<p>A</p>'], ['html' => '<p>B</p>']]],
            ['id' => 'b7', 'type' => 'articleList', 'heading' => 'News', 'category' => 'press', 'locale' => 'nl', 'limit' => 3],
            ['id' => 'b8', 'type' => 'embed', 'url' => 'https://youtu.be/x', 'title' => 'Intro', 'provider' => 'youtube', 'embedUrl' => 'https://www.youtube.com/embed/x'],
        ];
    }

    public function testEveryBlockTypeRenders(): void
    {
        $this->http->always(FakeTransport::json(['form' => ['key' => 'contact', 'name' => 'Contact', 'fields' => [], 'honeypotField' => 'website', 'successMessage' => 'ok', 'confirmEnabled' => false, 'turnstileSiteKey' => null]]));
        $blocks = [...$this->blocks(), ['id' => 'b9', 'type' => 'form', 'formKey' => 'contact']];
        $html = $this->render('<x-content::page-blocks :blocks="$b" :media="$m" />', ['b' => $blocks, 'm' => $this->media()]);

        self::assertStringContainsString('<div class="fsc-blocks">', $html);
        self::assertStringContainsString('fsc-block fsc-block--hero fsc-hero--has-media', $html);
        self::assertStringContainsString('<h1 class="fsc-hero__heading">Welcome</h1>', $html);
        self::assertStringContainsString('class="fsc-hero__media"', $html);
        self::assertStringContainsString('loading="eager"', $html, 'the first block (hero) image is eager');
        self::assertStringContainsString('<div class="fsc-block fsc-block--richText"><p>Rich <em>text</em></p></div>', $html);
        self::assertStringContainsString('<figcaption>Cap</figcaption>', $html);
        self::assertStringContainsString('alt="Media alt"', $html, 'an empty block alt falls back to the media alt');
        self::assertStringContainsString('--fsc-columns: 2', $html);
        self::assertStringContainsString('alt="One"', $html);
        self::assertSame(2, substr_count($html, 'class="fsc-gallery__item"') + 1, 'the item with a deleted file is omitted');
        self::assertStringContainsString('fsc-button fsc-button--secondary" href="/x">Click</a>', $html);
        self::assertStringContainsString('<div class="fsc-column"><p>A</p></div>', $html);
        self::assertStringContainsString('data-category="press" data-locale="nl" data-limit="3"', $html);
        self::assertStringContainsString('<h2 class="fsc-articlelist__heading">News</h2>', $html);
        self::assertStringContainsString('src="https://www.youtube.com/embed/x" title="Intro"', $html);
        self::assertStringContainsString('fsc-block fsc-block--form', $html);
    }

    public function testBlocksWithADeletedFileAreOmittedAndWithoutAMediaMapTheUrlIsDerived(): void
    {
        $blocks = [['type' => 'image', 'mediaId' => 'gone', 'alt' => 'x', 'caption' => '']];

        self::assertStringNotContainsString('<figure', $this->render('<x-content::page-blocks :blocks="$b" :media="[]" />', ['b' => $blocks]));
        self::assertStringContainsString('src="https://content.test/api/media/gone"', $this->render('<x-content::page-blocks :blocks="$b" />', ['b' => $blocks]));
    }

    public function testEmptyBlocksRenderNothing(): void
    {
        self::assertSame('', trim($this->render('<x-content::page-blocks :blocks="[]" />')));
        self::assertSame('', trim($this->render('<x-content::page-blocks :blocks="null" />')));
    }

    public function testUserContentIsEscapedExceptRichTextAndColumns(): void
    {
        $xss = '<script>alert(1)</script>';
        $blocks = [
            ['type' => 'hero', 'heading' => $xss, 'subheading' => $xss, 'mediaId' => null, 'ctaLabel' => $xss, 'ctaHref' => 'javascript:alert(1)'],
            ['type' => 'cta', 'heading' => $xss, 'text' => $xss, 'label' => $xss, 'href' => ' JavaScript:alert(1)', 'variant' => 'primary" onclick="x'],
            ['type' => 'image', 'mediaId' => 'm1', 'alt' => $xss, 'caption' => $xss],
            ['type' => 'articleList', 'heading' => $xss, 'category' => '"><b>', 'locale' => null, 'limit' => 1],
            ['type' => 'embed', 'embedUrl' => 'javascript:alert(1)', 'title' => $xss, 'provider' => 'youtube'],
        ];
        $html = $this->render('<x-content::page-blocks :blocks="$b" :media="$m" />', ['b' => $blocks, 'm' => $this->media()]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('javascript:', strtolower($html));
        self::assertStringNotContainsString('onclick=', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('href="#"', $html);
    }

    public function testViewOverridePerBlockTypeViaPropAndConfig(): void
    {
        View::addNamespace('site', __DIR__ . '/views');
        $blocks = [['type' => 'articleList', 'heading' => 'News', 'category' => null, 'locale' => null, 'limit' => 5], ['type' => 'cta', 'heading' => 'H', 'text' => '', 'label' => 'L', 'href' => '/', 'variant' => 'primary']];

        $viaProp = $this->render('<x-content::page-blocks :blocks="$b" :views="[\'articleList\' => \'site::article-list\']" />', ['b' => $blocks]);
        self::assertStringContainsString('<ul class="mine" data-i="0" data-limit="5" data-base="https://content.test">News</ul>', $viaProp);
        self::assertStringContainsString('fsc-block--cta', $viaProp, 'other types keep their default');

        config(['content.blocks.cta' => 'site::cta']);
        $viaConfig = $this->render('<x-content::page-blocks :blocks="$b" />', ['b' => $blocks]);
        self::assertStringContainsString('<a class="mine-cta">L</a>', $viaConfig);
        self::assertStringNotContainsString('fsc-block--cta', $viaConfig);
    }

    public function testUnknownBlockTypesAreSkippedUnlessAViewIsGiven(): void
    {
        $blocks = [['type' => 'hologram', 'x' => 1], ['type' => 'richText', 'html' => '<p>ok</p>']];

        self::assertSame(1, substr_count($this->render('<x-content::page-blocks :blocks="$b" />', ['b' => $blocks]), 'fsc-block--'));

        View::addNamespace('site', __DIR__ . '/views');
        self::assertStringContainsString('unknown:hologram', $this->render('<x-content::page-blocks :blocks="$b" :views="[\'unknown\' => \'site::unknown\']" />', ['b' => $blocks]));
    }

    public function testStylesheetAssetIsPublishableAndMatchesTheJsSdk(): void
    {
        $asset = __DIR__ . '/../../resources/assets/content.css';
        self::assertFileExists($asset);

        $js = __DIR__ . '/../../../content-sdk/src/styles.css';

        if (is_file($js)) {
            self::assertSame(file_get_contents($js), file_get_contents($asset), 'keep resources/assets/content.css identical to packages/content-sdk/src/styles.css');
        }

        $paths = \Illuminate\Support\ServiceProvider::pathsToPublish(\FullStackStudio\Content\Laravel\ContentServiceProvider::class, 'content-assets');
        self::assertContains(public_path('vendor/content/content.css'), array_values($paths));
    }
}
