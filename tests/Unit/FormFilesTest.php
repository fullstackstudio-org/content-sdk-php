<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Forms;
use FullStackStudio\Content\Http\CurlTransport;
use FullStackStudio\Content\Http\Psr18Transport;
use FullStackStudio\Content\Http\UploadFile;
use FullStackStudio\Content\Tests\Support\ModuleTestCase;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SplFileInfo;
use SplFileObject;

final class FormFilesTest extends ModuleTestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @unlink($path);
        }
    }

    private function tmpFile(string $contents, string $suffix = '.pdf'): SplFileInfo
    {
        $path = tempnam(sys_get_temp_dir(), 'fsc') . $suffix;
        file_put_contents($path, $contents);
        $this->tmp[] = $path;

        return new SplFileInfo($path);
    }

    public function testWithoutFilesItIsTheUnchangedJsonRequest(): void
    {
        $this->http->always(\FullStackStudio\Content\Tests\Support\FakeTransport::json(['id' => 's1'], 201));
        $this->client()->submitForm('contact', ['name' => 'Jan', 'agree' => true, 'cv' => null], ['meta' => ['utm_source' => 'x'], 'turnstileToken' => 'tok']);

        $request = $this->http->last();
        self::assertNull($request->multipart);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame(['name' => 'Jan', 'agree' => true, '_meta' => ['utm_source' => 'x'], '_turnstile' => 'tok'], $this->http->lastBody());
    }

    public function testAnEmptyListIsLeftOutLikeTheJsSdk(): void
    {
        $this->http->always(\FullStackStudio\Content\Tests\Support\FakeTransport::json(['id' => 's1'], 201));

        // Multipart: an optional file field without files next to one with a file.
        $this->client()->submitForm('job', ['cv' => new UploadFile('%PDF-1.4', 'cv.pdf', 'application/pdf'), 'photos' => []]);
        $request = $this->http->last();
        self::assertNotNull($request->multipart);
        self::assertArrayHasKey('cv', $request->multipart);
        self::assertArrayNotHasKey('photos', $request->multipart);

        // JSON: an empty list is not sent either.
        $this->client()->submitForm('job', ['name' => 'Jan', 'photos' => []]);
        self::assertSame(['name' => 'Jan'], $this->http->lastBody());
    }

    public function testMultipartNamesAndFileNamesCannotBreakOutOfTheirHeader(): void
    {
        $request = new \FullStackStudio\Content\Http\Request('POST', 'https://content.test/x', [], null, [
            "bad\"\\\r\nX-Evil: 1\x00name" => new UploadFile('abc', "f\"\\\r\nX-Evil: 1.pdf", 'application/pdf'),
            "text\r\nX-Evil: 2" => 'value',
        ]);

        [, $body] = $request->multipartBody();

        self::assertStringNotContainsString("\r\nX-Evil", $body);
        self::assertStringContainsString('name="badX-Evil: 1name"; filename="fX-Evil: 1.pdf"', $body);
        self::assertStringContainsString('name="textX-Evil: 2"', $body);
    }

    public function testFilesGoAsMultipartWithTextPartsAndNoJsonContentType(): void
    {
        $this->http->always(\FullStackStudio\Content\Tests\Support\FakeTransport::json(['id' => 's1'], 201));
        $cv = $this->tmpFile('%PDF-1.4 cv');
        $this->client()->submitForm('job', ['name' => 'Jan', 'agree' => true, 'news' => false, 'age' => 31, 'skip' => null, 'cv' => $cv], [
            'meta' => ['page_url' => 'https://x.test/a?b=1'], 'turnstileToken' => 'tok', 'clientIp' => '203.0.113.5',
        ]);

        $request = $this->http->last();
        self::assertNull($request->body);
        self::assertArrayNotHasKey('Content-Type', $request->headers);
        self::assertSame('203.0.113.5', $request->headers['X-FSC-Client-Ip']);
        self::assertSame('/forms/job/submissions', $this->http->lastPath());
        self::assertSame('Jan', $request->multipart['name']);
        self::assertSame('true', $request->multipart['agree']);
        self::assertSame('false', $request->multipart['news']);
        self::assertSame('31', $request->multipart['age']);
        self::assertSame('tok', $request->multipart['_turnstile']);
        self::assertSame(['page_url' => 'https://x.test/a?b=1'], json_decode($request->multipart['_meta'], true));
        self::assertArrayNotHasKey('skip', $request->multipart);

        $upload = $request->multipart['cv'];
        self::assertInstanceOf(UploadFile::class, $upload);
        self::assertSame('%PDF-1.4 cv', $upload->contents);
        self::assertSame($cv->getBasename(), $upload->filename);
        self::assertNotSame('', $upload->contentType);
    }

    public function testSeveralFilesRepeatThePartAndUploadedFileNamesWin(): void
    {
        $this->http->always(\FullStackStudio\Content\Tests\Support\FakeTransport::json(['id' => 's1'], 201));
        $named = new class (sys_get_temp_dir()) extends SplFileObject {
            public function __construct(string $dir)
            {
                $path = tempnam($dir, 'fsc');
                file_put_contents($path, 'AAA');
                parent::__construct($path);
            }

            public function getClientOriginalName(): string
            {
                return 'cv jan.pdf';
            }

            public function getClientMimeType(): string
            {
                return 'application/pdf';
            }
        };
        $this->tmp[] = $named->getPathname();
        $other = $this->tmpFile('BBB', '.png');

        $this->client()->submitForm('job', ['cv' => [$named, new UploadFile('CCC', 'c.txt', 'text/plain')], 'photo' => $other]);

        $form = $this->http->last()->multipart;
        self::assertCount(2, $form['cv']);
        self::assertSame('cv jan.pdf', $form['cv'][0]->filename);
        self::assertSame('application/pdf', $form['cv'][0]->contentType);
        self::assertSame('c.txt', $form['cv'][1]->filename);

        [$type, $body] = $this->http->last()->multipartBody();
        self::assertSame(2, substr_count($body, 'name="cv"; filename='));
        self::assertStringContainsString('name="photo"; filename="' . $other->getBasename() . '"', $body);
        self::assertStringStartsWith('multipart/form-data; boundary=', $type);
    }

    public function testAnUnreadableFileOrMoreThan50MbIsRefusedBeforeAnyRequest(): void
    {
        try {
            $this->client()->submitForm('job', ['cv' => new SplFileInfo('/nonexistent/file.pdf')]);
            self::fail('expected exception');
        } catch (ContentException $e) {
            self::assertSame('invalid_input', $e->errorCode());
        }

        $this->expectException(ContentException::class);

        try {
            $this->client()->submitForm('job', ['a' => new UploadFile(str_repeat('x', 30 * 1024 * 1024), 'a.zip'), 'b' => new UploadFile(str_repeat('x', 30 * 1024 * 1024), 'b.zip')]);
        } finally {
            self::assertSame([], $this->http->requests);
        }
    }

    public function testDownloadSubmissionFileReturnsTheBytes(): void
    {
        $this->http->always(new \FullStackStudio\Content\Http\Response(200, ['content-type' => 'application/pdf', 'content-disposition' => 'attachment; filename="cv-jan.pdf"'], '%PDF-bytes'));

        $file = $this->client()->downloadSubmissionFile('job form', 's 1', 'f1');

        self::assertSame(['bytes' => '%PDF-bytes', 'contentType' => 'application/pdf', 'fileName' => 'cv-jan.pdf'], $file);
        self::assertSame('GET', $this->http->last()->method);
        self::assertSame('/forms/job%20form/submissions/s%201/files/f1', $this->http->lastPath());
    }

    public function testDownloadOfAMissingFileIsNotFound(): void
    {
        $this->http->always(new \FullStackStudio\Content\Http\Response(404, ['content-type' => 'text/plain'], 'Not found'));

        try {
            $this->client()->downloadSubmissionFile('job', 's1', 'nope');
            self::fail('expected exception');
        } catch (ContentException $e) {
            self::assertTrue($e->isNotFound());
        }
    }

    public function testPsr18TransportSendsTheMultipartBodyWithBoundaryHeader(): void
    {
        $psr = new class implements ClientInterface {
            public ?RequestInterface $seen = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->seen = $request;

                return new PsrResponse(201, ['Content-Type' => 'application/json'], '{"id":"s1"}');
            }
        };
        $factory = new HttpFactory();
        $client = new ContentClient(['token' => self::SERVER, 'transport' => new Psr18Transport($psr, $factory, $factory)]);

        $result = $client->submitForm('job', ['name' => 'Jan', 'cv' => new UploadFile('PDFDATA', 'cv.pdf', 'application/pdf')], ['meta' => ['a' => 'b']]);

        self::assertSame(['id' => 's1'], $result);
        $contentType = $psr->seen->getHeaderLine('Content-Type');
        self::assertMatchesRegularExpression('/^multipart\/form-data; boundary=(\S+)$/', $contentType);
        $body = (string) $psr->seen->getBody();
        self::assertStringContainsString('name="name"' . "\r\n\r\nJan\r\n", $body);
        self::assertStringContainsString('name="cv"; filename="cv.pdf"' . "\r\nContent-Type: application/pdf\r\n\r\nPDFDATA\r\n", $body);
        self::assertStringContainsString('name="_meta"' . "\r\n\r\n{\"a\":\"b\"}\r\n", $body);
    }

    public function testCurlTransportSendsTheSameBodyOverTheWire(): void
    {
        if (!function_exists('proc_open') || !extension_loaded('curl')) {
            self::markTestSkipped('needs proc_open and ext-curl');
        }

        $port = random_int(20000, 40000);
        $process = proc_open([PHP_BINARY, '-d', 'enable_post_data_reading=0', '-S', "127.0.0.1:{$port}", __DIR__ . '/../Support/echo-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            self::markTestSkipped('could not start the PHP built-in server');
        }

        try {
            $socket = false;

            for ($i = 0; $i < 50 && $socket === false; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
                usleep(100_000);
            }

            if ($socket === false) {
                self::markTestSkipped('the PHP built-in server did not start');
            }

            fclose($socket);

            $url = "http://127.0.0.1:{$port}";
            // The echo server answers JSON with 200; submitForm just returns it.
            $client = new ContentClient(['token' => self::SERVER, 'baseUrl' => $url, 'transport' => new CurlTransport()]);
            $echo = $client->submitForm('job', ['name' => 'Jan'], ['meta' => ['a' => 'b']]);
            self::assertSame('application/json', $echo['contentType']);

            $echo = $client->submitForm('job', ['name' => 'Jan', 'cv' => [new UploadFile('ONE', 'a.pdf', 'application/pdf'), new UploadFile('TWO', 'b.pdf', 'application/pdf')]], ['meta' => ['a' => 'b']]);
            self::assertMatchesRegularExpression('/^multipart\/form-data; boundary=\S+$/', $echo['contentType']);
            $body = base64_decode($echo['body']);
            self::assertSame(2, substr_count($body, 'name="cv"; filename='));
            self::assertStringContainsString("\r\n\r\nONE\r\n", $body);
            self::assertStringContainsString("\r\n\r\nTWO\r\n", $body);
            self::assertStringContainsString("name=\"_meta\"\r\n\r\n{\"a\":\"b\"}", $body);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testFormsHelpersForFileFields(): void
    {
        $field = ['name' => 'cv', 'type' => 'file', 'required' => true, 'file' => ['maxFiles' => 2, 'maxSizeMb' => 10, 'accept' => ['pdf', 'jpg', 'heic']]];
        self::assertSame('.pdf,.jpg,.jpeg,.heic,.heif', Forms::acceptAttribute($field));
        self::assertSame('', Forms::acceptAttribute(['name' => 'x', 'type' => 'text']));

        $definition = ['fields' => [['name' => 'name', 'type' => 'text'], $field]];
        self::assertTrue(Forms::hasFileField($definition));
        self::assertFalse(Forms::hasFileField(['fields' => [['name' => 'name', 'type' => 'text']]]));

        $file = $this->tmpFile('x');
        $empty = $this->tmpFile('');
        self::assertSame(['name' => '', 'cv' => [$file]], Forms::values($definition, ['cv' => 'ignored'], ['cv' => [$file, $empty], 'other' => $file]));
        self::assertSame(['name' => ''], Forms::values($definition, [], ['cv' => $empty]));

        $labels = Forms::labels('en');
        self::assertSame(['cv' => 'This field is required.'], Forms::validate($definition, ['name' => 'Jan'], $labels));
        self::assertSame([], Forms::validate($definition, ['name' => 'Jan', 'cv' => [$file]], $labels));
    }

    public function testMultipartPartHeadersAreSanitised(): void
    {
        $request = new \FullStackStudio\Content\Http\Request('POST', 'http://x.test', [], null, [
            'file' => new UploadFile('DATA', "ev\\il\"\r\nX: y.pdf", "text/html\r\nX-Evil: 1"),
            'ok' => new UploadFile('DATA', 'a.pdf', 'application/vnd.ms-excel; charset=x'),
            'fine' => new UploadFile('DATA', 'b.pdf', 'image/svg+xml'),
        ]);
        [, $body] = $request->multipartBody();

        self::assertStringContainsString("name=\"file\"; filename=\"evilX: y.pdf\"\r\nContent-Type: application/octet-stream\r\n\r\nDATA", $body);
        self::assertStringNotContainsString('X-Evil', $body);
        self::assertStringContainsString("filename=\"a.pdf\"\r\nContent-Type: application/octet-stream\r\n", $body);
        self::assertStringContainsString("filename=\"b.pdf\"\r\nContent-Type: image/svg+xml\r\n", $body);
    }

    public function testCurlSingleFileMediaUploadKeepsItsShape(): void
    {
        if (!function_exists('proc_open') || !extension_loaded('curl')) {
            self::markTestSkipped('needs proc_open and ext-curl');
        }

        $port = random_int(20000, 40000);
        $process = proc_open([PHP_BINARY, '-d', 'enable_post_data_reading=0', '-S', "127.0.0.1:{$port}", __DIR__ . '/../Support/echo-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            self::markTestSkipped('could not start the PHP built-in server');
        }

        try {
            $socket = false;

            for ($i = 0; $i < 50 && $socket === false; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
                usleep(100_000);
            }

            if ($socket === false) {
                self::markTestSkipped('the PHP built-in server did not start');
            }

            fclose($socket);

            $client = new ContentClient(['token' => self::SERVER, 'baseUrl' => "http://127.0.0.1:{$port}", 'transport' => new CurlTransport()]);
            // The echo server answers with its own JSON; uploadMedia only reads `media` out of it, the raw request is checked through a second call below.
            $request = new \FullStackStudio\Content\Http\Request('POST', "http://127.0.0.1:{$port}/media", ['Authorization' => 'Bearer x'], null, [
                'file' => new UploadFile('PNGDATA', 'a.png', 'image/png'),
                'alt' => 'Logo',
            ]);
            $response = (new CurlTransport())->send($request);
            $echo = json_decode($response->body, true);

            self::assertMatchesRegularExpression('/^multipart\/form-data; boundary=\S+$/', $echo['contentType']);
            $body = base64_decode($echo['body']);
            self::assertSame(1, substr_count($body, 'name="file"; filename="a.png"'));
            self::assertStringContainsString("name=\"file\"; filename=\"a.png\"\r\nContent-Type: image/png\r\n\r\nPNGDATA\r\n", $body);
            self::assertStringContainsString("name=\"alt\"\r\n\r\nLogo\r\n", $body);
            self::assertStringEndsWith("--\r\n", $body);
            self::assertSame(200, $response->status);
            unset($client);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
