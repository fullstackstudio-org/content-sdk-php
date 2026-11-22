<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;

/**
 * Outside a preview, `<x-content::page-blocks>` and `<x-content::form>` print exactly what 0.26 printed (fixtures captured from 0.26.0);
 * inside one they add the block markers and the disabled fieldset, nothing else.
 */
final class PreviewMarkupTest extends LaravelTestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/preview/';

    protected function setUp(): void
    {
        parent::setUp();
        $http = new FakeTransport();
        $http->always(FakeTransport::json(['form' => ['key' => 'contact', 'name' => 'Contact', 'honeypotField' => 'website', 'fields' => [
            ['name' => 'name', 'type' => 'text', 'label' => 'Naam', 'required' => true, 'maxLength' => 20],
            ['name' => 'message', 'type' => 'textarea', 'label' => 'Bericht', 'required' => false, 'maxLength' => 500],
        ]]]));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $http]));
    }

    private function blocks(): string
    {
        return Blade::render('<x-content::page-blocks :blocks="$blocks" class="x" />', ['blocks' => require self::FIXTURES . 'blocks.php']);
    }

    public function testLiveMarkupIsByteIdenticalTo026(): void
    {
        self::assertSame(file_get_contents(self::FIXTURES . 'page-blocks.html'), $this->blocks());
        self::assertSame(file_get_contents(self::FIXTURES . 'form.html'), Blade::render('<x-content::form form-key="contact" />'));
    }

    public function testPreviewMarkupOnlyAddsTheMarkersAndTheFieldset(): void
    {
        app(Preview::class)->activate(['blocks' => []], '/x', 'https://portal.test', 1, 'fscpv_' . str_repeat('A', 43));
        $html = $this->blocks();

        self::assertSame(6, substr_count($html, 'style="display:contents"'), 'one wrapper per rendered block (the unknown type is skipped)');
        self::assertStringContainsString('<div data-fsc-block="2" data-fsc-block-index="2" style="display:contents">', $html, 'a block without id is marked by its index');
        self::assertStringContainsString('<fieldset disabled', $html);

        self::assertStringStartsWith('<div class="fsc-blocks x" data-fsc-preview-blocks >', $html);
    }

    public function testABlockIdIsEscapedInTheMarker(): void
    {
        app(Preview::class)->activate(['blocks' => []], '/x', 'https://portal.test', 1, 'fscpv_' . str_repeat('A', 43));
        $html = Blade::render('<x-content::page-blocks :blocks="$blocks" />', ['blocks' => [['id' => '"><script>x</script>', 'type' => 'richText', 'html' => '<p>a</p>']]]);

        self::assertStringNotContainsString('"><script>', $html);
        self::assertStringContainsString('data-fsc-block="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $html);
    }
}
