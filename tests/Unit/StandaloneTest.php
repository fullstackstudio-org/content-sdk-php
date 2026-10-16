<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The core must work without Laravel (and without Composer's vendor/autoload): run it in a bare PHP process. */
final class StandaloneTest extends TestCase
{
    public function testCoreRunsInABarePhpProcessWithoutLaravel(): void
    {
        $script = dirname(__DIR__) . '/Support/standalone.php';
        $process = proc_open([PHP_BINARY, '-n', '-d', 'extension=curl', '-d', 'extension=mbstring', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($process);

        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('OK', $out);
    }
}
