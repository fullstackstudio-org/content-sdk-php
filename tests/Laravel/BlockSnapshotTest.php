<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\BlockLook;
use FullStackStudio\Content\Tests\Support\BlockCorpus;
use Illuminate\Support\Facades\Blade;

/**
 * Guards the rule of page builder v2: a block without `layout` and `tone` renders byte for byte as it did before layouts existed. The fixture was
 * generated from the views of the SDK before `BlockLook`; regenerate it only when a default view changes on purpose
 * (`FSC_WRITE_SNAPSHOT=1 vendor/bin/phpunit --filter BlockSnapshotTest`).
 */
final class BlockSnapshotTest extends LaravelTestCase
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

    /**
     * One block per type, each rendered on its own, with the random CSRF token removed.
     *
     * @param array<string, mixed> $extra keys added to every block
     */
    private function markup(array $extra = []): string
    {
        $out = [];

        foreach (BlockCorpus::TYPES as $type) {
            $block = ['id' => $type, ...BlockCorpus::block($type), ...($extra[$type] ?? [])];
            $html = Blade::render('<x-content::page-blocks :blocks="$b" :media="$m" />', ['b' => [$block], 'm' => BlockCorpus::media()]);
            $out[] = '<!-- ' . $type . " -->\n" . preg_replace('/(name="_token" value=")[^"]*"/', '$1TOKEN"', $html);
        }

        return implode("\n", $out) . "\n";
    }

    public function testBlocksWithoutLayoutOrToneRenderExactlyAsBefore(): void
    {
        $path = __DIR__ . '/../Fixtures/blocks/default-markup.html';

        if (getenv('FSC_WRITE_SNAPSHOT') === '1') {
            file_put_contents($path, $this->markup());
            self::markTestSkipped('Snapshot written.');
        }

        self::assertSame((string) file_get_contents($path), $this->markup());
    }

    public function testTheDefaultKeysRenderTheSameAsAbsentKeys(): void
    {
        // `layout: <default>` and `tone: none` are never stored (AD2), but a hand edited page can carry them: they must add nothing.
        $defaults = [];

        foreach (BlockCorpus::TYPES as $type) {
            $defaults[$type] = ['layout' => BlockLook::LAYOUTS[$type][0], 'tone' => 'none'];
        }

        self::assertSame($this->markup(), $this->markup($defaults));
    }
}
