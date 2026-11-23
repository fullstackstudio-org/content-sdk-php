<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use CURLFile;
use FullStackStudio\Content\Laravel\Visitor\CurlUpstream;
use FullStackStudio\Content\Laravel\Visitor\UpstreamRequest;
use FullStackStudio\Content\Laravel\Visitor\VisitorProxyException;
use PHPUnit\Framework\TestCase;

/**
 * The real cURL upstream of the visitor page proxy against a local `php -S` stand-in (never a real Hub): the 2 MB cap with and without
 * `Content-Length`, the timeout, no redirect following, repeated headers, and a multipart body with a file streamed from disk.
 */
final class CurlUpstreamTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;

    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open') || !extension_loaded('curl')) {
            return;
        }

        $port = random_int(40001, 60000);
        $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/../Support/visitor-upstream-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            return;
        }

        $socket = false;

        for ($i = 0; $i < 50 && $socket === false; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            usleep(100_000);
        }

        if ($socket === false) {
            proc_terminate($process);

            return;
        }

        fclose($socket);
        self::$process = $process;
        self::$base = "http://127.0.0.1:{$port}";
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }

        self::$process = null;
    }

    protected function setUp(): void
    {
        if (self::$process === null) {
            self::markTestSkipped('needs proc_open, ext-curl and the PHP built-in server');
        }
    }

    private function send(string $method, string $path, array $headers = [], ?string $body = null, ?array $multipart = null, int $timeoutMs = 5000): \FullStackStudio\Content\Laravel\Visitor\UpstreamResponse
    {
        return (new CurlUpstream())->send(new UpstreamRequest($method, self::$base . $path, $headers, $body, $multipart, 2000, $timeoutMs));
    }

    public function testAnAnswerOverTheCapIsAbortedWithAndWithoutContentLength(): void
    {
        foreach (['/big', '/big-declared'] as $path) {
            try {
                $this->send('GET', $path);
                self::fail('no exception for ' . $path);
            } catch (VisitorProxyException $error) {
                self::assertSame(VisitorProxyException::RESPONSE_TOO_LARGE, $error->reason);
            }
        }
    }

    public function testATimeoutIsReportedAsTimeout(): void
    {
        try {
            $this->send('GET', '/slow', timeoutMs: 1000);
            self::fail('no exception');
        } catch (VisitorProxyException $error) {
            self::assertSame(VisitorProxyException::TIMEOUT, $error->reason);
            self::assertSame('Visitor page proxy: timeout', $error->getMessage());
        }
    }

    public function testRedirectsAreNotFollowedAndRepeatedHeadersAreKept(): void
    {
        $response = $this->send('POST', '/redirect', [], 'a=1');

        self::assertSame(302, $response->status);
        self::assertSame('/elsewhere', $response->header('location'));
        self::assertSame(['a=1', 'b=2'], $response->headers['set-cookie']);
    }

    public function testRawBodyQueryAndHeadersArrive(): void
    {
        $response = $this->send('POST', '/a/l/tok?b=2&a=1', ['Content-Type' => 'application/x-www-form-urlencoded', 'X-FSC-Proxy' => '1'], 'naam=Jan&x=%20');
        $echo = json_decode($response->body, true);

        self::assertSame('POST', $echo['method']);
        self::assertSame('/a/l/tok?b=2&a=1', $echo['uri']);
        self::assertSame('1', $echo['proxy']);
        self::assertSame('naam=Jan&x=%20', $echo['raw']);
    }

    public function testMultipartStreamsTheFileFromDisk(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fsc');
        file_put_contents($file, 'JPEGDATA');

        try {
            $response = $this->send('POST', '/t/tok/reply', [], null, [
                'message' => 'Hallo',
                'meta[order]' => '12',
                'attachments[0]' => new CURLFile($file, 'image/jpeg', 'foto.jpg'),
            ]);
        } finally {
            @unlink($file);
        }

        $echo = json_decode($response->body, true);
        self::assertStringStartsWith('multipart/form-data; boundary=', $echo['contentType']);
        self::assertSame(['message' => 'Hallo', 'meta' => ['order' => '12']], $echo['post']);
        self::assertSame('foto.jpg', $echo['files']['attachments']['name'][0]);
        self::assertSame('image/jpeg', $echo['files']['attachments']['type'][0]);
    }

    public function testAnUnreachableHubIsUnreachable(): void
    {
        try {
            (new CurlUpstream())->send(new UpstreamRequest('GET', 'http://127.0.0.1:1/a/ontvangen', [], connectTimeoutMs: 500, timeoutMs: 1000));
            self::fail('no exception');
        } catch (VisitorProxyException $error) {
            self::assertSame(VisitorProxyException::UNREACHABLE, $error->reason);
        }
    }
}
