<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\BlockLook;
use FullStackStudio\Content\Tests\Support\BlockCorpus;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;

/** Layouts and tones in `<x-content::page-blocks>` and the default views: the class on the block root, bleed, site views, unknown values. */
final class BlockLayoutsTest extends LaravelTestCase
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
        View::addNamespace('site', __DIR__ . '/views');
    }

    /** @param array<string, mixed> $extra */
    private function render(string $type, array $extra = [], string $attributes = ''): string
    {
        $block = ['id' => 'b1', ...BlockCorpus::block($type), ...$extra];

        return Blade::render('<x-content::page-blocks :blocks="$b" :media="$m" ' . $attributes . ' />', ['b' => [$block], 'm' => BlockCorpus::media()]);
    }

    /** @return iterable<string, array{string, string}> every non default layout of every type */
    public static function layouts(): iterable
    {
        foreach (BlockLook::LAYOUTS as $type => $keys) {
            foreach (array_slice($keys, 1) as $key) {
                yield "{$type} {$key}" => [$type, $key];
            }
        }
    }

    #[DataProvider('layouts')]
    public function testEveryLayoutPutsItsClassOnTheBlockRoot(string $type, string $layout): void
    {
        $html = $this->render($type, ['layout' => $layout]);

        self::assertMatchesRegularExpression('/<(section|div|figure) class="fsc-block fsc-block--' . $type . '[^"]* fsc-block--layout-' . preg_quote($layout, '/') . '[ "]/', $html, $html);
    }

    #[DataProvider('layouts')]
    public function testAnUnknownLayoutOfTheSameTypeIsTheDefault(string $type, string $layout): void
    {
        $unknown = $this->render($type, ['layout' => 'carousel']);

        self::assertStringNotContainsString('--layout-', $unknown);
        self::assertSame($this->render($type), $unknown);
    }

    /** @return iterable<string, array{string, string}> */
    public static function tones(): iterable
    {
        foreach (BlockLook::TONE_TYPES as $type) {
            foreach (['soft', 'accent'] as $tone) {
                yield "{$type} {$tone}" => [$type, $tone];
            }
        }
    }

    #[DataProvider('tones')]
    public function testEveryToneAddsItsClass(string $type, string $tone): void
    {
        $html = $this->render($type, ['tone' => $tone]);

        self::assertMatchesRegularExpression('/class="fsc-block fsc-block--' . $type . '[^"]* fsc-block--tone-' . $tone . '"/', $html, $html);
        self::assertStringNotContainsString('--layout-', $html, 'a tone alone adds no layout class');
    }

    public function testALayoutAndAToneTogether(): void
    {
        self::assertStringContainsString('class="fsc-block fsc-block--cta fsc-block--layout-split fsc-block--tone-soft"', $this->render('cta', ['layout' => 'split', 'tone' => 'soft']));
    }

    public function testATypeWithoutTonesNeverGetsAToneClass(): void
    {
        foreach (array_diff(BlockCorpus::TYPES, BlockLook::TONE_TYPES) as $type) {
            self::assertStringNotContainsString('--tone-', $this->render($type, ['tone' => 'accent']), $type);
        }

        self::assertStringNotContainsString('--tone-', $this->render('legalDocument', ['tone' => 'accent', 'layout' => 'wide']));
    }

    public function testAnUnknownToneIsTheDefault(): void
    {
        self::assertSame($this->render('cta'), $this->render('cta', ['tone' => 'neon']));
    }

    public function testABleedLayoutIsNotWrappedInTheContentContainer(): void
    {
        $wide = $this->render('image', ['layout' => 'wide'], 'wrap="wrap max-w-5xl"');

        self::assertStringNotContainsString('class="wrap max-w-5xl"', $wide);
        self::assertStringContainsString('<div class="fsc-blocks">' . "\n" . '<figure class="fsc-block fsc-block--image fsc-block--layout-wide">', $wide);
        self::assertStringNotContainsString('class="fsc-wrap"', $this->render('embed', ['layout' => 'wide']));
        self::assertStringContainsString('<div class="fsc-block fsc-block--embed fsc-block--layout-wide">', $this->render('embed', ['layout' => 'wide']));
    }

    public function testOtherLayoutsAndTheDefaultStayWrapped(): void
    {
        self::assertStringContainsString('class="wrap"><figure class="fsc-block fsc-block--image fsc-block--layout-small">', $this->render('image', ['layout' => 'small'], 'wrap="wrap"'));
        self::assertStringContainsString('class="wrap"><figure class="fsc-block fsc-block--image">', $this->render('image', [], 'wrap="wrap"'));
        self::assertStringContainsString('class="wrap"><section class="fsc-block fsc-block--hero', $this->render('hero', ['layout' => 'image-left'], 'wrap="wrap"'));
    }

    public function testASiteViewGetsTheResolvedLookAndOnlyDeclaredLayouts(): void
    {
        config(['content.blocks.cta' => 'site::layout-cta']);
        $block = ['type' => 'cta', 'label' => 'Ga', 'layout' => 'split', 'tone' => 'accent'];
        $render = static fn (): string => Blade::render('<x-content::page-blocks :blocks="$b" />', ['b' => [$block]]);

        // Nothing declared: the layout falls back to the default, the tone does not need a declaration, and the site view is never wrapped.
        $html = $render();
        self::assertStringContainsString('<div class="mine-cta fsc-block--tone-accent" data-layout="standard" data-tone="accent">Ga</div>', $html);
        self::assertStringNotContainsString('fsc-wrap', $html);

        config(['content.block_layouts.cta' => ['split']]);
        self::assertStringContainsString('<div class="mine-cta fsc-block--layout-split fsc-block--tone-accent" data-layout="split" data-tone="accent">', $render());

        config(['content.block_layouts.cta' => ['standard']]);
        self::assertStringContainsString('data-layout="standard"', $render());
    }

    public function testASiteViewOfATypeWithoutLayoutsGetsANullLayout(): void
    {
        config(['content.blocks.legalDocument' => 'site::layout-cta']);
        $html = Blade::render('<x-content::page-blocks :blocks="$b" />', ['b' => [['type' => 'legalDocument', 'label' => 'x', 'layout' => 'wide']]]);

        self::assertStringContainsString('<div class="mine-cta " data-layout="" data-tone="none">', $html);
    }

    public function testTheFaqOpenLayoutPrintsEveryAnswerWithoutDetails(): void
    {
        $open = $this->render('faq', ['layout' => 'open']);

        self::assertStringNotContainsString('<details', $open);
        self::assertStringNotContainsString('<summary', $open);
        self::assertStringContainsString('<dl class="fsc-faq">', $open);
        self::assertSame(3, substr_count($open, '<dt class="fsc-faq__question">'));
        self::assertSame(3, substr_count($open, '<dd class="fsc-faq__answer-wrap">'));
        self::assertStringContainsString('<div class="fsc-article-body fsc-faq__answer"><p>Een eerste gesprek is gratis.', $open);

        $accordion = $this->render('faq');
        self::assertSame(3, substr_count($accordion, '<details class="fsc-faq__item">'));
        self::assertStringNotContainsString('<dl', $accordion);
    }

    public function testTheHeroSplitLayoutsKeepTheImageAndTheContent(): void
    {
        foreach (['image-right', 'image-left'] as $layout) {
            $html = $this->render('hero', ['layout' => $layout]);

            self::assertStringContainsString('fsc-hero--has-media fsc-block--layout-' . $layout, $html);
            self::assertStringContainsString('class="fsc-hero__media"', $html);
            self::assertStringContainsString('<h1 class="fsc-hero__heading">', $html);
        }
    }

    public function testTheLookClassesAreOnlyEverFromTheFixedLists(): void
    {
        $html = $this->render('cta', ['layout' => '"><script>alert(1)</script>', 'tone' => 'soft" onload="x']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('onload', $html);
    }

    public function testTheInlineStyleCarriesTheLayoutAndToneRules(): void
    {
        config(['content.blocks.inline_styles' => true]);
        $html = $this->render('cta', ['layout' => 'split']);

        foreach (['--fsc-tone-soft-bg', '--fsc-tone-pad', 'fsc-block--layout-strip', 'fsc-block--layout-image-side', 'fsc-block--tone-accent', 'fsc-block--layout-narrow'] as $needle) {
            self::assertStringContainsString($needle, $html, $needle);
        }
    }
}
