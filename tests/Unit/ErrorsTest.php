<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use FullStackStudio\Content\Cache\ArrayStore;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Errors\BrowserEvent;
use FullStackStudio\Content\Errors\Delivery;
use FullStackStudio\Content\Errors\EnvelopeSender;
use FullStackStudio\Content\Errors\ErrorsConfig;
use FullStackStudio\Content\Errors\ErrorsConfigResolver;
use FullStackStudio\Content\Errors\EventBuilder;
use FullStackStudio\Content\Errors\Scrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorsTest extends TestCase
{
    private const BACKEND = 'https://0123456789abcdef0123456789abcdef@errors.int0.net/12';

    private const BROWSER = 'https://0123456789abcdef0123456789abcdef@errors.fullstackstudio.nl/12';

    /** @return iterable<string, array{array{in: string, contains: list<string>, absent: list<string>}}> */
    public static function vectors(): iterable
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/Fixtures/telemetry/scrub-vectors.json'), true);

        foreach ($data['vectors'] as $vector) {
            yield $vector['in'] => [$vector];
        }
    }

    /** @param array{in: string, contains: list<string>, absent: list<string>} $vector */
    #[DataProvider('vectors')]
    public function testScrubberSharedVectors(array $vector): void
    {
        $out = Scrubber::text($vector['in']);

        foreach ($vector['contains'] as $part) {
            self::assertStringContainsString($part, $out);
        }

        foreach ($vector['absent'] as $part) {
            self::assertStringNotContainsString($part, $out);
        }
    }

    public function testEventIsBuiltFromAnAllowlistOnly(): void
    {
        $base = dirname(__DIR__, 2);
        $inner = new RuntimeException('Customer jan@example.nl has IBAN NL60REVO1319691978');
        $error = new \LogicException('Order 12345678 failed', 0, $inner);
        $config = new ErrorsConfig(self::BACKEND, self::BROWSER, 'staging', 'abc123');

        $event = EventBuilder::fromThrowable($error, $config, $base, '/orders/{order}', str_repeat('b', 32), 1700000000.123);

        self::assertSame(['event_id', 'timestamp', 'platform', 'level', 'environment', 'sdk', 'exception', 'release', 'transaction'], array_keys($event));
        self::assertSame('staging', $event['environment']);
        self::assertSame('abc123', $event['release']);
        self::assertSame('/orders/{order}', $event['transaction']);
        self::assertSame([RuntimeException::class, \LogicException::class], array_column($event['exception']['values'], 'type'));
        self::assertSame('Order [number] failed', $event['exception']['values'][1]['value']);

        $json = json_encode($event);
        self::assertStringNotContainsString('jan@example.nl', (string) $json);
        self::assertStringNotContainsString('NL60REVO', (string) $json);
        self::assertStringNotContainsString($base, (string) $json);

        $frames = $event['exception']['values'][1]['stacktrace']['frames'];
        self::assertSame('tests/Unit/ErrorsTest.php', end($frames)['filename']);
        self::assertTrue(end($frames)['in_app']);
        foreach ($frames as $frame) {
            self::assertSame([], array_diff(array_keys($frame), ['filename', 'in_app', 'function', 'lineno']));
        }
    }

    public function testEmptyMessageGetsAPlaceholderAndPathsBecomeTemplatesOnly(): void
    {
        $config = new ErrorsConfig(self::BACKEND, null);
        $event = EventBuilder::fromThrowable(new RuntimeException(''), $config, '/nope', '/klanten/123?x=1');

        self::assertSame('[message removed]', $event['exception']['values'][0]['value']);
        self::assertArrayNotHasKey('transaction', $event);
        self::assertArrayNotHasKey('release', $event);
        self::assertSame('[external]', end($event['exception']['values'][0]['stacktrace']['frames'])['filename']);
    }

    public function testBrowserEventIsRebuiltAndForeignDsnsAreRefused(): void
    {
        $config = new ErrorsConfig(self::BACKEND, self::BROWSER, 'production', 'rel1');
        $event = [
            'event_id' => str_repeat('c', 32),
            'environment' => 'evil',
            'release' => 'evil',
            'user' => ['email' => 'jan@example.nl'],
            'request' => ['url' => 'https://www.example.nl/x?token=1'],
            'transaction' => 'GET /contact',
            'exception' => ['values' => [[
                'type' => 'CustomError',
                'value' => 'Call 06-12345678 now',
                'mechanism' => ['type' => 'onerror', 'handled' => false],
                'stacktrace' => ['frames' => [
                    ['filename' => 'https://cdn.other.com/x.js', 'lineno' => 1],
                    ['filename' => 'https://www.example.nl/build/app.js?v=1', 'function' => 'send', 'lineno' => 4, 'colno' => 2, 'vars' => ['a' => 'b']],
                ]],
            ]]],
        ];
        $body = json_encode(['dsn' => self::BROWSER]) . "\n" . json_encode(['type' => 'event']) . "\n" . json_encode($event) . "\n";

        $events = BrowserEvent::eventsFromEnvelope($body, self::BROWSER);
        self::assertCount(1, $events);
        $clean = BrowserEvent::prepare($events[0], $config, 'https://www.example.nl');

        self::assertSame('production', $clean['environment']);
        self::assertSame('rel1', $clean['release']);
        self::assertSame('Error', $clean['exception']['values'][0]['type']);
        self::assertSame('Call [phone] now', $clean['exception']['values'][0]['value']);
        self::assertSame([['filename' => 'https://www.example.nl/build/app.js', 'in_app' => true, 'function' => 'send', 'lineno' => 4, 'colno' => 2]], $clean['exception']['values'][0]['stacktrace']['frames']);
        self::assertArrayNotHasKey('user', $clean);
        self::assertArrayNotHasKey('request', $clean);

        // Another project's DSN (open relay) and no own frame (third-party script) are refused.
        $foreign = str_replace('/12', '/13', $body);
        self::assertNull(BrowserEvent::eventsFromEnvelope($foreign, self::BROWSER));
        $event['exception']['values'][0]['stacktrace']['frames'] = [['filename' => 'https://cdn.other.com/x.js']];
        self::assertNull(BrowserEvent::prepare($event, $config, 'https://www.example.nl'));
    }

    public function testEnvelopeSenderUsesTheDsnKeyAndHost(): void
    {
        $sent = [];
        $sender = new EnvelopeSender(function (string $url, array $headers, string $body) use (&$sent): int {
            $sent[] = compact('url', 'headers', 'body');

            return 200;
        });

        self::assertSame(200, $sender->send(self::BACKEND, ['event_id' => str_repeat('d', 32)], 'https://www.example.nl'));
        self::assertSame('https://errors.int0.net/api/12/envelope/', $sent[0]['url']);
        self::assertStringContainsString('sentry_key=0123456789abcdef0123456789abcdef', $sent[0]['headers']['X-Sentry-Auth']);
        self::assertSame('https://www.example.nl', $sent[0]['headers']['Origin']);
        self::assertSame('event', json_decode(explode("\n", $sent[0]['body'])[1], true)['type']);
        self::assertSame(0, $sender->send('https://evil.example/1', []));
    }

    public function testDeliveryFallsBackToThePublicIngestAndRemembersIt(): void
    {
        $urls = [];
        $sender = new EnvelopeSender(function (string $url) use (&$urls): int {
            $urls[] = $url;

            return str_contains($url, 'int0') ? 0 : 200;
        });
        $delivery = new Delivery($sender, new ArrayStore());
        $config = new ErrorsConfig(self::BACKEND, self::BROWSER);

        self::assertSame(200, $delivery->deliver(['event_id' => 'x'], $config, null));
        self::assertSame(200, $delivery->deliver(['event_id' => 'y'], $config, null));
        self::assertSame(['https://errors.int0.net/api/12/envelope/', 'https://errors.fullstackstudio.nl/api/12/envelope/', 'https://errors.fullstackstudio.nl/api/12/envelope/'], $urls);
    }

    public function testResolverCachesTreatsAModuleOffAsOffAndSurvivesAnOutage(): void
    {
        $now = 1000;
        $calls = 0;
        $answer = ['backend' => ['dsn' => self::BACKEND], 'browser' => ['dsn' => self::BROWSER], 'environment' => 'production'];
        $fail = null;
        $store = new ArrayStore();
        $make = function () use (&$calls, &$answer, &$fail, &$now, &$store): ErrorsConfigResolver {
            return new ErrorsConfigResolver(function () use (&$calls, &$answer, &$fail): array {
            $calls++;

            if ($fail !== null) {
                throw $fail;
            }

            return $answer;
            }, $store, null, 'rel-new', 600, 86400, 60, function () use (&$now): int {
                return $now;
            });
        };

        self::assertSame(self::BACKEND, $make()->resolve()?->backendDsn);
        self::assertSame('rel-new', $make()->resolve()?->release);
        self::assertSame(1, $calls);

        // Expired + Content down: the last good config stays, and the next attempt waits for the cooldown.
        $now += 700;
        $fail = new ContentException(0, 'timeout', 'timeout');
        self::assertSame(self::BACKEND, $make()->resolve()?->backendDsn);
        self::assertSame(self::BACKEND, $make()->resolve()?->backendDsn);
        self::assertSame(2, $calls);

        // Module switched off (a fresh store: the cooldown of the outage above uses the real clock).
        $store = new ArrayStore();
        $fail = new ContentException(404, 'module_disabled', 'off');
        self::assertNull($make()->resolve());
        self::assertSame(3, $calls);
    }

    public function testInvalidDsnsAreOff(): void
    {
        self::assertNull(ErrorsConfig::dsn('http://0123456789abcdef0123456789abcdef@errors.int0.net/12'));
        self::assertNull(ErrorsConfig::dsn('https://0123456789abcdef0123456789abcdef@errors.int0.net/12?x'));
        self::assertFalse(ErrorsConfig::fromApi(['backend' => ['dsn' => 'nope']], null)->isActive());
    }
}
