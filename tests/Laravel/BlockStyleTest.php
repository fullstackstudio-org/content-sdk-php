<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;

/** The default page-block views are styled out of the box: inline zero-specificity CSS once per page, a theme layer of variables, a content width. */
final class BlockStyleTest extends LaravelTestCase
{
    /** Every variable of the theme layer, declared once in the `:root` rule with a neutral default. */
    private const THEME = [
        '--fsc-font', '--fsc-heading-font', '--fsc-text', '--fsc-muted', '--fsc-accent', '--fsc-accent-contrast', '--fsc-surface', '--fsc-border',
        '--fsc-radius', '--fsc-gap', '--fsc-content-width', '--fsc-content-pad', '--fsc-button-bg', '--fsc-button-text', '--fsc-button-radius',
        '--fsc-embed-ratio',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('content.blocks.inline_styles', true);
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => new FakeTransport()]));
        View::addNamespace('site', __DIR__ . '/views');
    }

    private function render(string $template, array $data = []): string
    {
        return Blade::render($template, $data);
    }

    /** @return list<array<string, mixed>> */
    private function blocks(): array
    {
        return [
            ['id' => 'a', 'type' => 'richText', 'html' => '<p>Tekst</p>'],
            ['id' => 'b', 'type' => 'columns', 'columns' => [['html' => '<p>A</p>'], ['html' => '<p>B</p>']]],
            ['id' => 'c', 'type' => 'gallery', 'columns' => 3, 'items' => [['mediaId' => 'm1', 'alt' => 'Een', 'caption' => 'Onderschrift']]],
            ['id' => 'd', 'type' => 'embed', 'url' => 'https://youtu.be/x', 'title' => 'Video', 'provider' => 'youtube', 'embedUrl' => 'https://www.youtube-nocookie.com/embed/x'],
        ];
    }

    private function media(): array
    {
        return ['m1' => ['id' => 'm1', 'url' => '/api/media/m1', 'contentType' => 'image/png', 'alt' => '', 'width' => 800, 'height' => 600]];
    }

    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../resources/assets/blocks.css');
    }

    // --- printing -----------------------------------------------------------------------------------------------------------

    public function testTheStyleIsPrintedOncePerPageWithTheNonceWhenADefaultViewIsUsed(): void
    {
        Vite::useCspNonce('n0nce');
        $first = $this->render('<x-content::page-blocks :blocks="$b" :media="$m" />', ['b' => $this->blocks(), 'm' => $this->media()]);
        $second = $this->render('<x-content::page-blocks :blocks="$b" :media="$m" />', ['b' => $this->blocks(), 'm' => $this->media()]);

        self::assertSame(1, substr_count($first, '<style'));
        self::assertStringContainsString('<style nonce="n0nce">:where(:root){', $first);
        self::assertStringContainsString(':where(.fsc-block--gallery)', $first);
        self::assertStringNotContainsString('<style', $second, 'one style per request, however many page-blocks components');
    }

    public function testTheStyleCarriesNoNonceWithoutOneAndEscapesAGivenNonce(): void
    {
        $plain = $this->render('<x-content::page-blocks :blocks="$b" />', ['b' => $this->blocks()]);
        self::assertStringContainsString('<style>:where(:root){', $plain);

        $this->app['request']->attributes->remove('fsc.block-style');
        $evil = $this->render('<x-content::page-blocks :blocks="$b" :nonce="$n" />', ['b' => $this->blocks(), 'n' => '"><script>x</script>']);
        self::assertStringNotContainsString('<script>', $evil);
        self::assertStringContainsString('<style nonce="&quot;&gt;&lt;script&gt;x&lt;/script&gt;">', $evil);
    }

    public function testNothingIsPrintedWhenEveryBlockHasASiteView(): void
    {
        $views = ['richText' => 'site::custom', 'columns' => 'site::custom'];
        $html = $this->render('<x-content::page-blocks :blocks="$b" :views="$v" />', ['b' => array_slice($this->blocks(), 0, 2), 'v' => $views]);

        self::assertStringNotContainsString('<style', $html);
        self::assertStringContainsString('site-custom:richText', $html);
    }

    public function testNothingIsPrintedWhenTheOnlyDefaultBlockRendersNothing(): void
    {
        $html = $this->render('<x-content::page-blocks :blocks="$b" />', ['b' => [['type' => 'legalDocument', 'kind' => 'privacy_statement']]]);

        self::assertStringNotContainsString('<style', $html);
        self::assertStringNotContainsString('fsc-wrap', $html, 'an empty default block adds no wrapper');
    }

    public function testTheSwitchTurnsTheInlineStyleOff(): void
    {
        $this->app['config']->set('content.blocks.inline_styles', false);

        self::assertStringNotContainsString('<style', $this->render('<x-content::page-blocks :blocks="$b" />', ['b' => $this->blocks()]));
    }

    // --- wrap ---------------------------------------------------------------------------------------------------------------

    public function testWrapWrapsOnlyTheBlocksOfADefaultView(): void
    {
        $blocks = [['id' => 'a', 'type' => 'richText', 'html' => '<p>Site</p>'], ['id' => 'b', 'type' => 'columns', 'columns' => [['html' => '<p>A</p>']]]];
        $html = $this->render('<x-content::page-blocks :blocks="$b" :views="$v" wrap="wrap max-w-5xl" />', ['b' => $blocks, 'v' => ['richText' => 'site::custom']]);

        self::assertStringContainsString('<div class="wrap max-w-5xl"><div class="fsc-block fsc-block--columns"', $html);
        self::assertSame(1, substr_count($html, 'class="wrap max-w-5xl"'), 'the site view of richText is not wrapped');
        self::assertStringContainsString("site-custom:richText\n", $html);
        self::assertStringNotContainsString('<div class="fsc-wrap">', $html, 'wrap replaces the fallback container');
    }

    public function testTheWrapClassIsEscaped(): void
    {
        $html = $this->render('<x-content::page-blocks :blocks="$b" wrap=\'a" onclick="x\' />', ['b' => $this->blocks()]);

        self::assertStringNotContainsString('onclick="x', $html);
        self::assertStringContainsString('class="a&quot; onclick=&quot;x"', $html);
    }

    public function testWithoutWrapDefaultBlocksSitInTheFallbackContainer(): void
    {
        $html = $this->render('<x-content::page-blocks :blocks="$b" :media="$m" />', ['b' => $this->blocks(), 'm' => $this->media()]);

        self::assertSame(4, substr_count($html, '<div class="fsc-wrap">'));
        self::assertMatchesRegularExpression('/:where\(\.fsc-wrap\)\{box-sizing: border-box; width: 100%; min-width: 0; max-width: var\(--fsc-content-width\); margin-inline: auto; padding-inline: var\(--fsc-content-pad\);\}/', $html);
    }

    // --- the CSS ------------------------------------------------------------------------------------------------------------

    public function testGalleryColumnsEmbedAndImagesAreStyledOutOfTheBox(): void
    {
        $css = $this->css();

        self::assertStringContainsString(':where(.fsc-block--gallery),:where(.fsc-block--columns){display: grid; gap: var(--fsc-gap); grid-template-columns: repeat(var(--fsc-columns), minmax(0, 1fr));}', $css);
        self::assertStringContainsString('@media (max-width: 40rem){:where(.fsc-block--gallery),:where(.fsc-block--columns){grid-template-columns: 1fr;}}', $css, 'one column on a phone');
        self::assertStringContainsString(':where(.fsc-gallery__item img){display: block; width: 100%; height: auto; aspect-ratio: 3 / 2; object-fit: cover;', $css);
        self::assertStringContainsString(':where(.fsc-block figcaption)', $css);
        self::assertStringContainsString(':where(.fsc-block--embed){position: relative; aspect-ratio: 16 / 9;}', $css);
        self::assertStringContainsString(':where(.fsc-block--embed){width: 100%; aspect-ratio: var(--fsc-embed-ratio);}', $css);
        self::assertStringContainsString(':where(.fsc-embed__frame){position: absolute; inset: 0; width: 100%; height: 100%; border: 0;', $css);
    }

    public function testEveryRuleHasZeroSpecificity(): void
    {
        foreach (preg_split('/\R/', trim($this->css())) as $line) {
            self::assertMatchesRegularExpression('/^(?:@media[^{]+\{)?:where\(/', $line, $line);

            // Every selector of the rule is a :where() group, also the second one of a list.
            $prelude = preg_replace('/^@media[^{]+\{/', '', $line);
            $prelude = substr($prelude, 0, (int) strpos($prelude, '{'));
            foreach (explode(',:where(', substr($prelude, 6)) as $selector) {
                self::assertSame(')', substr($selector, -1), $line);
            }
        }

        self::assertStringNotContainsString('.fsc-consent', $this->css(), 'the cookie banner is not part of the block styles');
    }

    public function testEveryThemeVariableIsDeclaredExactlyOnceWithADefault(): void
    {
        $css = $this->css();
        self::assertSame(1, preg_match_all('/:where\(:root\)\{/', $css), 'one :root rule');

        preg_match('/:where\(:root\)\{([^}]*)\}/', $css, $root);

        foreach (self::THEME as $name) {
            self::assertSame(1, preg_match_all('/(?<![\w-])' . preg_quote($name, '/') . ':/', $css), "{$name} is declared exactly once");
            self::assertMatchesRegularExpression('/(?<![\w-])' . preg_quote($name, '/') . ': [^;]+;/', $root[1], "{$name} has a default in :root");
        }

        self::assertSame(0, preg_match_all('/--fsc-columns:/', preg_replace('/:where\(:root\)\{[^}]*\}/', '', $css)), 'no declaration outside :root');
    }

    public function testNoColourIsHardCodedOutsideTheDefaults(): void
    {
        $rest = preg_replace('/:where\(:root\)\{[^}]*\}/', '', $this->css());

        self::assertSame(0, preg_match('/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(|\bhwb\(|\blab\(|\boklch\(/', $rest), 'colours come from the variables');
        self::assertDoesNotMatchRegularExpression('/(?<![-\w])(?:white|black|red|blue|green|gray|grey)(?![-\w])/i', $rest);
    }

    public function testTheThemeVariablesReachTheBlocks(): void
    {
        $css = $this->css();

        self::assertStringContainsString(':where(.fsc-blocks){font-family: var(--fsc-font); color: var(--fsc-text);}', $css);
        self::assertStringContainsString('font-family: var(--fsc-heading-font)', $css);
        self::assertStringContainsString('color: var(--fsc-button-text); background: var(--fsc-button-bg)', $css);
        self::assertStringContainsString('border-radius: var(--fsc-button-radius)', $css);
        self::assertStringContainsString('background: var(--fsc-surface)', $css);
        self::assertStringContainsString('color: var(--fsc-muted)', $css);
    }

    public function testCommasInsideFunctionalPseudoClassesDoNotSplitTheSelector(): void
    {
        require_once __DIR__ . '/../../scripts/build-block-css.php';

        $css = \build_block_css(".fsc-a :is(h2, h3, h4):not(:first-child), .fsc-b { margin-top: 1em; }");

        self::assertStringContainsString(':where(.fsc-a :is(h2, h3, h4):not(:first-child)),:where(.fsc-b){margin-top: 1em;}', $css);
    }

    public function testTheCommittedFileMatchesTheGenerator(): void
    {
        require_once __DIR__ . '/../../scripts/build-block-css.php';

        self::assertSame(
            \build_block_css((string) file_get_contents(__DIR__ . '/../../resources/assets/content.css')),
            $this->css(),
            'run `php scripts/build-block-css.php` after changing resources/assets/content.css',
        );
    }
}
