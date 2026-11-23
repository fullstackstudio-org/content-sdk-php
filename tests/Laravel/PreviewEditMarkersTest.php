<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Tests\Support\BlockCorpus;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;

/**
 * Page builder v2, task 7: what a preview prints so `preview.js` can select and edit (`$edit`, wrapper data, placeholders) and the guarantee that
 * a live page never carries any of it (the byte for byte check of the live markup is `BlockSnapshotTest`).
 */
final class PreviewEditMarkersTest extends LaravelTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('nl');
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => new BlockCorpus()]));
    }

    private function preview(): void
    {
        app(Preview::class)->activate(['blocks' => []], '/x', 'https://portal.test', 1, 'fscpv_' . str_repeat('A', 43));
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, string> $views
     */
    private function render(array $blocks, array $views = []): string
    {
        return Blade::render('<x-content::page-blocks :blocks="$b" :media="$m" :views="$v" />', ['b' => $blocks, 'm' => BlockCorpus::media(), 'v' => $views]);
    }

    private static function strip(string $html): string
    {
        return (string) preg_replace('/(name="_token" value=")[^"]*"/', '$1TOKEN"', $html);
    }

    public function testNoEditAttributeEverReachesALivePage(): void
    {
        $blocks = [];

        foreach (BlockCorpus::TYPES as $type) {
            $blocks[] = ['id' => $type, ...BlockCorpus::block($type)];
        }

        $blocks[] = ['id' => 'ph', 'type' => 'fscPlaceholder', 'label' => 'Kop', 'reason' => 'incomplete'];
        $html = $this->render($blocks);

        self::assertStringNotContainsString('data-fsc-edit', $html);
        self::assertStringNotContainsString('data-fsc-block', $html);
        self::assertStringNotContainsString('fsc-preview-empty', $html);
    }

    public function testEveryDefaultViewPrintsItsEditableFieldsInAPreview(): void
    {
        $this->preview();

        $expected = [
            'hero' => ['heading' => 'plain', 'subheading' => 'plain', 'ctaLabel' => 'plain'],
            'richText' => ['html' => 'rich'],
            'image' => ['caption' => 'plain'],
            'cta' => ['heading' => 'plain', 'text' => 'plain', 'label' => 'plain'],
            'columns' => ['columns.0.html' => 'rich', 'columns.1.html' => 'rich', 'columns.2.html' => 'rich'],
            'eventList' => ['heading' => 'plain'],
            'event' => ['ctaLabel' => 'plain'],
            'appointmentBooking' => ['heading' => 'plain', 'text' => 'plain', 'ctaLabel' => 'plain'],
            'faq' => ['heading' => 'plain'],
            'productList' => ['heading' => 'plain'],
            'articleList' => ['heading' => 'plain'],
            'newsletterSignup' => ['heading' => 'plain', 'text' => 'plain', 'buttonLabel' => 'plain'],
        ];

        foreach ($expected as $type => $fields) {
            $html = $this->render([['id' => $type, ...BlockCorpus::block($type)]]);

            foreach ($fields as $field => $kind) {
                self::assertStringContainsString(' data-fsc-edit="' . $field . '" data-fsc-edit-kind="' . $kind . '"', $html, "{$type}.{$field}");
            }

            self::assertSame(count($fields), substr_count($html, 'data-fsc-edit="'), "{$type} marks exactly its inline fields");
        }
    }

    public function testEmptyOptionalFieldsStillPrintTheirElementInAPreviewOnly(): void
    {
        $blocks = [
            ['id' => 'h', 'type' => 'hero', 'heading' => '', 'ctaHref' => '/c'],
            ['id' => 'c', 'type' => 'cta', 'label' => 'Ga', 'href' => '/c'],
            ['id' => 'i', 'type' => 'image', 'mediaId' => 'm1'],
            ['id' => 'e', 'type' => 'eventList', 'heading' => '', 'emptyText' => ''],
        ];

        $live = $this->render($blocks);
        self::assertStringNotContainsString('fsc-hero__subheading', $live);
        self::assertStringNotContainsString('fsc-cta__heading', $live);
        self::assertStringNotContainsString('<figcaption', $live);

        $this->preview();
        $html = $this->render($blocks);
        self::assertStringContainsString('<p class="fsc-hero__subheading" data-fsc-edit="subheading" data-fsc-edit-kind="plain"></p>', $html);
        self::assertStringContainsString('<a class="fsc-button fsc-button--primary" data-fsc-edit="ctaLabel" data-fsc-edit-kind="plain" href="/c"></a>', $html);
        self::assertStringContainsString('<h2 class="fsc-cta__heading" data-fsc-edit="heading" data-fsc-edit-kind="plain"></h2>', $html);
        self::assertStringContainsString('<figcaption data-fsc-edit="caption" data-fsc-edit-kind="plain"></figcaption>', $html);
    }

    public function testTheHubLabelBecomesTheEditPlaceholderAndIsEscaped(): void
    {
        $this->preview();
        $html = $this->render([['id' => 'h', 'type' => 'hero', 'heading' => 'Kop', 'fscLabels' => ['heading' => 'Kop "van" <de> pagina', 'ctaLabel' => 5]]]);

        self::assertStringContainsString('data-fsc-edit="heading" data-fsc-edit-kind="plain" data-fsc-edit-placeholder="Kop &quot;van&quot; &lt;de&gt; pagina"', $html);
        self::assertStringNotContainsString('data-fsc-edit-placeholder="5"', $html);
        self::assertStringNotContainsString('<de>', $html);
    }

    public function testTheWrapperCarriesTypeDefaultAndLayouts(): void
    {
        $this->preview();
        View::addNamespace('site', __DIR__ . '/views');
        config(['content.block_layouts.cta' => ['split', 'bogus-ignored-by-look'], 'content.blocks.cta' => 'site::cta']);

        $html = $this->render([
            ['id' => 'a', 'type' => 'hero', 'heading' => 'x'],
            ['id' => 'b', 'type' => 'cta', 'heading' => 'y', 'label' => 'z', 'href' => '/z'],
            ['id' => 'c', 'type' => 'legalDocument', 'kind' => 'terms'],
        ]);

        self::assertStringContainsString('data-fsc-block="a" data-fsc-block-index="0" data-fsc-block-type="hero" data-fsc-block-default="1" data-fsc-layouts="overlay,image-right,image-left" style="display:contents"', $html);
        self::assertStringContainsString('data-fsc-block="b" data-fsc-block-index="1" data-fsc-block-type="cta" data-fsc-block-default="0" data-fsc-layouts="split,bogus-ignored-by-look"', $html);
        self::assertStringContainsString('data-fsc-block-type="legalDocument" data-fsc-block-default="1" data-fsc-layouts=""', $html);
    }

    public function testASiteViewWithoutEditRendersAsBefore(): void
    {
        View::addNamespace('site', __DIR__ . '/views');
        config(['content.blocks.cta' => 'site::cta']);
        $block = ['id' => 'b', 'type' => 'cta', 'heading' => 'y', 'label' => 'z', 'href' => '/z'];

        $live = $this->render([$block]);
        $this->preview();
        $preview = $this->render([$block]);

        self::assertStringNotContainsString('data-fsc-edit', $preview);
        self::assertStringContainsString($live === '' ? 'never' : trim(strip_tags($live)), strip_tags($preview));
        self::assertStringNotContainsString('data-fsc-edit', $live);
    }

    public function testAnEmptyDefaultViewIsAPlaceholderInAPreviewAndNothingLive(): void
    {
        $block = ['id' => 'i', 'type' => 'image', 'mediaId' => 'gone'];

        $live = $this->render([$block]);
        self::assertStringNotContainsString('fsc-wrap', $live);
        self::assertStringNotContainsString('fsc-preview-empty', $live);

        $this->preview();
        $html = $this->render([$block]);
        self::assertStringContainsString('<div class="fsc-preview-empty">Afbeelding</div>', $html);
        self::assertStringContainsString('data-fsc-block="i"', $html);
        self::assertStringContainsString('<div class="fsc-wrap"><div class="fsc-preview-empty">', $html);

        $html = $this->render([['id' => 'x', 'type' => 'image', 'mediaId' => 'gone', 'fscLabel' => 'Een <b>label</b>']]);
        self::assertStringContainsString('<div class="fsc-preview-empty">Een &lt;b&gt;label&lt;/b&gt;</div>', $html);
    }

    public function testAPlaceholderBlockRendersItsLabelEscapedInAPreviewAndNothingLive(): void
    {
        $block = ['id' => 'p1', 'type' => 'fscPlaceholder', 'label' => 'Kop <script>x</script>', 'reason' => 'incomplete'];

        self::assertStringNotContainsString('fsc-preview-empty', $this->render([$block]));
        self::assertStringNotContainsString('data-fsc-block', $this->render([$block]));

        $this->preview();
        $html = $this->render([$block]);
        self::assertStringContainsString('<div class="fsc-preview-empty">Kop &lt;script&gt;x&lt;/script&gt;</div>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('data-fsc-block="p1" data-fsc-block-index="0" data-fsc-block-type="fscPlaceholder" data-fsc-block-default="1"', $html);
    }

    public function testPreviewMarkupOfEveryDefaultViewMatchesTheFixture(): void
    {
        $this->preview();
        $out = [];

        foreach (BlockCorpus::TYPES as $type) {
            $out[] = '<!-- ' . $type . " -->\n" . self::strip($this->render([['id' => $type, ...BlockCorpus::block($type)]]));
        }

        $actual = implode("\n", $out) . "\n";
        $path = __DIR__ . '/../Fixtures/preview/builder-markup.html';

        if (getenv('FSC_WRITE_SNAPSHOT') === '1') {
            file_put_contents($path, $actual);
            self::markTestSkipped('Snapshot written.');
        }

        self::assertSame((string) file_get_contents($path), $actual);
    }
}
