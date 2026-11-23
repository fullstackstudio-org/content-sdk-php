<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Laravel\BlockLook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `BlockLook`: which layout and tone a block gets, never an unknown one, and the class string for the block root. */
final class BlockLookTest extends TestCase
{
    public function testAnAbsentLayoutAndToneAreTheDefaultAndAddNoClass(): void
    {
        self::assertSame(['layout' => 'overlay', 'tone' => 'none', 'classes' => '', 'bleed' => false], BlockLook::for('hero', []));
        self::assertSame(['layout' => 'grid', 'tone' => 'none', 'classes' => '', 'bleed' => false], BlockLook::for('gallery', ['layout' => 'grid']));
    }

    public function testAKnownLayoutAndToneBecomeClassesLayoutFirst(): void
    {
        $look = BlockLook::for('cta', ['layout' => 'split', 'tone' => 'accent']);

        self::assertSame('split', $look['layout']);
        self::assertSame('accent', $look['tone']);
        self::assertSame('fsc-block--layout-split fsc-block--tone-accent', $look['classes']);
    }

    #[DataProvider('unknownValues')]
    public function testAnUnknownOrMistypedValueIsTheDefault(mixed $layout, mixed $tone): void
    {
        $look = BlockLook::for('cta', ['layout' => $layout, 'tone' => $tone]);

        self::assertSame(['layout' => 'standard', 'tone' => 'none', 'classes' => '', 'bleed' => false], $look);
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function unknownValues(): iterable
    {
        yield 'unknown keys' => ['carousel', 'neon'];
        yield 'a layout of another type' => ['strip', 'dark'];
        yield 'wrong types' => [['split'], 7];
        yield 'case matters' => ['SPLIT', 'Soft'];
        yield 'markup' => ['"><script>', 'soft"'];
    }

    public function testATypeWithoutLayoutsOrTonesIgnoresBothKeys(): void
    {
        foreach (['legalDocument', 'html', 'someFutureType'] as $type) {
            self::assertSame(['layout' => null, 'tone' => 'none', 'classes' => '', 'bleed' => false], BlockLook::for($type, ['layout' => 'wide', 'tone' => 'accent']));
        }
    }

    public function testATypeWithoutTonesIgnoresTheTone(): void
    {
        $look = BlockLook::for('image', ['layout' => 'wide', 'tone' => 'accent']);

        self::assertSame('none', $look['tone']);
        self::assertSame('fsc-block--layout-wide', $look['classes']);
    }

    public function testOnlyTheWideImageAndTheWideEmbedBleed(): void
    {
        self::assertTrue(BlockLook::for('image', ['layout' => 'wide'])['bleed']);
        self::assertTrue(BlockLook::for('embed', ['layout' => 'wide'])['bleed']);
        self::assertFalse(BlockLook::for('image', ['layout' => 'small'])['bleed']);
        self::assertFalse(BlockLook::for('image', [])['bleed']);
        self::assertFalse(BlockLook::for('hero', ['layout' => 'image-left'])['bleed']);
    }

    public function testASiteViewOnlyGetsTheLayoutsItDeclares(): void
    {
        self::assertSame('standard', BlockLook::for('cta', ['layout' => 'split'], [])['layout'], 'declared nothing');
        self::assertSame('standard', BlockLook::for('cta', ['layout' => 'split'], ['standard'])['layout']);
        self::assertSame('split', BlockLook::for('cta', ['layout' => 'split'], ['split'])['layout']);
        self::assertSame('standard', BlockLook::for('cta', ['layout' => 'split'], ['carousel'])['layout'], 'a declared key the SDK does not know is no layout');
        self::assertSame('accent', BlockLook::for('cta', ['tone' => 'accent'], [])['tone'], 'tones do not need a declaration');
    }

    public function testEveryDefaultIsTheFirstKeyAndTheMapIsComplete(): void
    {
        foreach (BlockLook::LAYOUTS as $type => $keys) {
            self::assertGreaterThanOrEqual(2, count($keys), $type);
            self::assertSame($keys[0], BlockLook::for($type, [])['layout']);

            foreach ($keys as $key) {
                self::assertSame($key, BlockLook::for($type, ['layout' => $key])['layout'], "{$type}.{$key}");
            }
        }

        foreach (BlockLook::TONE_TYPES as $type) {
            self::assertArrayHasKey($type, BlockLook::LAYOUTS, 'every toned type here also has layouts');
        }
    }

    /** The AD4 table of the plan: the SDK map and the Hub registry must stay equal (the Hub copy is `BlockRegistry::LAYOUTS`). */
    public function testTheMapMatchesTheAd4Table(): void
    {
        self::assertSame([
            'hero' => ['overlay', 'image-right', 'image-left'],
            'richText' => ['standard', 'narrow'],
            'image' => ['standard', 'wide', 'small'],
            'gallery' => ['grid', 'strip'],
            'cta' => ['standard', 'split'],
            'columns' => ['plain', 'cards'],
            'articleList' => ['grid', 'list'],
            'form' => ['standard', 'card'],
            'embed' => ['standard', 'wide'],
            'eventList' => ['cards', 'list'],
            'event' => ['standard', 'image-side'],
            'appointmentBooking' => ['standard', 'split'],
            'faq' => ['accordion', 'open'],
            'productList' => ['grid', 'list'],
            'newsletterSignup' => ['standard', 'inline'],
        ], BlockLook::LAYOUTS);
        self::assertSame(['hero', 'richText', 'cta', 'columns', 'form', 'appointmentBooking', 'faq', 'newsletterSignup'], BlockLook::TONE_TYPES);
        self::assertSame(['none', 'soft', 'accent'], BlockLook::TONES);
    }
}
