<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\BlockLook;
use FullStackStudio\Content\Tests\Support\BlockCorpus;
use Illuminate\Support\Facades\Blade;

/**
 * A test page with every block type in every layout and tone, the way a site's test page shows them (AGENTS.md A18). The page is a standalone
 * HTML file per type with the inline block CSS; open it at 390 and 1280 px. Written only on request:
 *
 *   FSC_WRITE_LAYOUT_PAGE=/some/dir vendor/bin/phpunit --filter BlockLayoutPageTest
 *
 * Without the variable the test only checks that every combination renders.
 */
final class BlockLayoutPageTest extends LaravelTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('content.blocks.inline_styles', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('nl');
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => new BlockCorpus()]));
    }

    /** A gradient with a few shapes: enough structure to judge crops, rounding and alignment. */
    private static function picture(int $n): string
    {
        $palettes = [1 => ['#0f6b5c', '#e9c46a'], 2 => ['#264653', '#f4a261'], 3 => ['#6d597a', '#b56576'], 4 => ['#355070', '#eaac8b']];
        [$a, $b] = $palettes[$n] ?? $palettes[1];
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $a . '"/><stop offset="1" stop-color="' . $b . '"/></linearGradient></defs>'
            . '<rect width="1200" height="800" fill="url(#g)"/><circle cx="' . (300 + $n * 150) . '" cy="300" r="170" fill="#fff" fill-opacity=".18"/><rect x="620" y="420" width="420" height="260" rx="24" fill="#000" fill-opacity=".16"/>'
            . '<text x="60" y="740" font-family="sans-serif" font-size="56" fill="#fff" fill-opacity=".85">' . $n . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** @return list<array{label: string, block: array<string, mixed>}> */
    private function variants(string $type): array
    {
        $out = [];
        $tones = in_array($type, BlockLook::TONE_TYPES, true) ? BlockLook::TONES : ['none'];

        foreach (BlockLook::LAYOUTS[$type] as $layout) {
            foreach ($tones as $tone) {
                $block = ['id' => "{$type}-{$layout}-{$tone}", ...BlockCorpus::block($type)];

                if ($layout !== BlockLook::LAYOUTS[$type][0]) {
                    $block['layout'] = $layout;
                }

                if ($tone !== 'none') {
                    $block['tone'] = $tone;
                }

                $out[] = ['label' => "{$type} / layout {$layout} / tone {$tone}", 'block' => $block];
            }
        }

        return $out;
    }

    private function page(string $type): string
    {
        $media = BlockCorpus::media();

        foreach ($media as $id => $item) {
            $media[$id]['url'] = self::picture((int) substr($id, 1));
        }

        // The inline style is printed once per request: each page file needs its own copy.
        request()->attributes->remove('fsc.block-style');
        $sections = '';

        foreach ($this->variants($type) as $variant) {
            // The image component puts the base URL in front of a path; the pictures here are data URIs.
            $html = str_replace(['https://content.test/data:', ...array_map(static fn (string $id): string => "https://content.test/api/media/{$id}", array_keys($media))], ['data:', ...array_column($media, 'url')], Blade::render('<x-content::page-blocks :blocks="$b" :media="$m" wrap="wrap" />', ['b' => [$variant['block']], 'm' => $media]));
            $sections .= '<section class="case"><p class="case__label">' . e($variant['label']) . '</p>' . $html . "</section>\n";
        }

        return '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($type) . '</title><style>'
            . ':root{--fsc-accent:#0f6b5c;--fsc-radius:.75rem;--fsc-heading-font:Georgia,serif;--fsc-gap:1.25rem;--fsc-surface:#fff;--fsc-border:#d9d4c8}'
            . 'body{margin:0;background:#f6f4ee;color:#1f2a27;font:16px/1.5 system-ui,sans-serif}.wrap{max-width:64rem;margin-inline:auto;padding-inline:1rem;box-sizing:border-box}'
            . '.case{padding:1.5rem 0;border-bottom:1px dashed #b9b2a3}.case__label{margin:0 auto 1rem;max-width:64rem;padding-inline:1rem;font:600 .8rem/1.2 ui-monospace,monospace;color:#6b6659}'
            . '</style></head><body>' . $sections . '</body></html>';
    }

    public function testEveryTypeInEveryLayoutAndToneRenders(): void
    {
        $dir = getenv('FSC_WRITE_LAYOUT_PAGE');
        $count = 0;

        foreach (BlockCorpus::TYPES as $type) {
            $html = $this->page($type);

            foreach ($this->variants($type) as $variant) {
                $count++;
                $block = $variant['block'];
                $expected = ($block['layout'] ?? null) !== null ? 'fsc-block--layout-' . $block['layout'] : null;

                if ($expected !== null) {
                    self::assertStringContainsString($expected, $html, $variant['label']);
                }

                if (isset($block['tone'])) {
                    self::assertStringContainsString('fsc-block--tone-' . $block['tone'], $html, $variant['label']);
                }
            }

            if (is_string($dir) && $dir !== '') {
                is_dir($dir) || mkdir($dir, 0777, true);
                file_put_contents($dir . '/' . $type . '.html', $html);
            }
        }

        // 15 types: the layouts of the table times the tones of the types that have them.
        self::assertSame(array_sum(array_map(static fn (string $t): int => count(BlockLook::LAYOUTS[$t]) * (in_array($t, BlockLook::TONE_TYPES, true) ? 3 : 1), BlockCorpus::TYPES)), $count);
    }
}
