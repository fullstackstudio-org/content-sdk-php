<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drift guard between the JavaScript SDK (`packages/content-sdk`) and this one: every API path that a JS client sends is also in the PHP
 * sources, and the other way round. Same idea as `utils/sdk-coverage.test.ts` in the main repo: paths are read from the source text and
 * normalised (`${seg(id)}` and `seg($id)` both become `*`, the query string is dropped).
 *
 * Skipped when the JS package is not next to this one (a standalone checkout of the PHP package).
 */
final class SdkCoverageTest extends TestCase
{
    /** JS paths that have no PHP method on purpose, with the reason. */
    private const JS_ONLY = [
        '/api/v1' => 'The base path: the PHP core adds it in ModuleContext, it is not a route.',
        '/services/web-design' => 'An example path in a JS doc comment (`getPage`), not a route.',
        '/api/media/{id}' => 'A JS doc comment about `mediaUrl()`; the PHP `mediaUrl()` builds the same URL (see PHP_ONLY `/api/media/*`).',
        '/articles/*/translations' => 'PHP builds these four with one `match` in `Admin::createTranslation` (`/{kind}/*/translations`).',
        '/pages/*/translations' => 'See `/articles/*/translations`.',
        '/products/*/translations' => 'See `/articles/*/translations`.',
        '/categories/*/translations' => 'See `/articles/*/translations`.',
    ];

    /** PHP paths that the JS SDK does not have, with the reason. */
    private const PHP_ONLY = [
        '/translations' => 'The tail of the dynamic `/{kind}/*/translations` in `Admin::createTranslation` (the four JS paths are in JS_ONLY).',
        '/analytics' => '`getAnalyticsConfig()`: the analytics module is Laravel/PHP only; the JS SDK is frozen and gets no new routes.',
        '/address/suggest' => '`suggestAddresses()`: Dutch address lookup is Laravel/PHP only; the JS SDK is frozen and gets no new routes.',
        '/address/lookup' => '`lookupAddress()`: see `/address/suggest`.',
        '/api/media/*' => '`mediaUrl()`: the JS version builds it from a template string that does not start with a slash, so the scan does not see it.',
    ];

    private function jsDir(): string
    {
        return dirname(__DIR__, 3) . '/content-sdk/src';
    }

    /** @return list<string> */
    private function jsPaths(): array
    {
        $paths = [];

        foreach (glob($this->jsDir() . '/*client.ts') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match_all('/[`"\'](\/[a-z][^`"\'\s]*)[`"\']/', $source, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $path) {
                $path = (string) preg_replace('/\$\{[^}]*\}/', '*', $path);
                $paths[] = (string) preg_replace('/\?.*$/', '', $path);
            }
        }

        return array_values(array_unique($paths));
    }

    /** @return list<string> */
    private function phpPaths(): array
    {
        $paths = [];
        $files = array_merge(glob(dirname(__DIR__, 2) . '/src/Modules/*.php') ?: [], [dirname(__DIR__, 2) . '/src/ContentClient.php']);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            // 'a/' . ModuleContext::seg($x) . '/b'  ->  'a/*/b'   and   'a/' . ModuleContext::seg($x)  ->  'a/*'
            $seg = '(?:ModuleContext|self)::seg\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)';
            $source = (string) preg_replace("/'\\s*\\.\\s*{$seg}\\s*\\.\\s*'/", '*', $source);
            $source = (string) preg_replace("/'\\s*\\.\\s*{$seg}/", "*'", $source);

            if (preg_match_all('/\'(\/[a-z][^\'\s]*)\'/', $source, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $path) {
                $paths[] = (string) preg_replace('/\?.*$/', '', $path);
            }
        }

        return array_values(array_unique($paths));
    }

    public function testEveryJsPathHasAPhpCounterpart(): void
    {
        if (!is_dir($this->jsDir())) {
            self::markTestSkipped('The JS SDK is not next to the PHP package.');
        }

        $js = $this->jsPaths();
        self::assertGreaterThan(100, count($js), 'the JS path scan found suspiciously few paths: the pattern is broken');

        $missing = array_values(array_filter($js, fn (string $path): bool => !in_array($path, $this->phpPaths(), true) && !isset(self::JS_ONLY[$path])));

        self::assertSame([], $missing, 'JS SDK paths without a PHP counterpart (add the method, or list the path in JS_ONLY with a reason)');
    }

    public function testEveryPhpPathExistsInTheJsSdk(): void
    {
        if (!is_dir($this->jsDir())) {
            self::markTestSkipped('The JS SDK is not next to the PHP package.');
        }

        $js = $this->jsPaths();
        $extra = array_values(array_filter($this->phpPaths(), fn (string $path): bool => !in_array($path, $js, true) && !isset(self::PHP_ONLY[$path])));

        self::assertSame([], $extra, 'PHP paths the JS SDK does not have (a typo, or list the path in PHP_ONLY with a reason)');
    }

    public function testTheExceptionListsHoldNoStaleEntries(): void
    {
        if (!is_dir($this->jsDir())) {
            self::markTestSkipped('The JS SDK is not next to the PHP package.');
        }

        $js = $this->jsPaths();
        $php = $this->phpPaths();

        self::assertSame([], array_values(array_filter(array_keys(self::JS_ONLY), fn (string $path): bool => !in_array($path, $js, true) || in_array($path, $php, true))));
        self::assertSame([], array_values(array_filter(array_keys(self::PHP_ONLY), fn (string $path): bool => in_array($path, $js, true) || !in_array($path, $php, true))));
    }
}
