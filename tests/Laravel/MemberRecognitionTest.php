<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Laravel\MemberRecognition;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

final class MemberRecognitionTest extends LaravelTestCase
{
    private const PID = 'abcdefabcdefabcdef12';
    private const VALUE = 'v2.A1.pseudonym_abc-123.1790000000.sig_abc-123';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'https://site.test');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->post('/login', function (Request $request) {
            MemberRecognition::queue(['member' => ['id' => 'm1'], 'recognition' => ['cookie' => 'fsi_rec', 'value' => self::VALUE, 'maxAge' => 15552000]], $request);

            return 'ok';
        });
        $router->middleware('web')->post('/logout', function (Request $request) {
            MemberRecognition::queue(['loggedOut' => true, 'recognition' => null], $request);

            return 'ok';
        });
        $router->middleware('web')->post('/plain', function (Request $request) {
            MemberRecognition::queue(['member' => ['id' => 'm1']], $request);

            return 'ok';
        });
    }

    /** @param array<string, string> $headers */
    private function request(array $headers = [], string $url = 'https://site.test/x'): Request
    {
        $request = Request::create($url, 'GET');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function answer(array $recognition = []): array
    {
        return ['recognition' => ['cookie' => 'fsi_rec', 'value' => self::VALUE, 'maxAge' => 15552000, ...$recognition]];
    }

    public function testDefaultCookieNameIsFsiRec(): void
    {
        self::assertSame('fsi_rec', config('content.intelligence.recognition_cookie'));
        self::assertSame('fsi_rec', MemberRecognition::cookieName());
    }

    public function testCookieIsBuiltFromTheAnswer(): void
    {
        $before = time();
        $cookie = MemberRecognition::cookie($this->answer(), $this->request(['User-Agent' => 'Mozilla/5.0']));

        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame('fsi_rec', $cookie->getName());
        self::assertSame(self::VALUE, $cookie->getValue());
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure(), 'app.url is https');
        self::assertSame('lax', $cookie->getSameSite());
        self::assertEqualsWithDelta($before + 15552000, $cookie->getExpiresTime(), 5);
    }

    public function testSecureFollowsTheRequestOrAppUrl(): void
    {
        config()->set('app.url', 'http://site.test');
        self::assertFalse(MemberRecognition::cookie($this->answer(), $this->request(['User-Agent' => 'Mozilla/5.0'], 'http://site.test/x'))->isSecure());
        self::assertTrue(MemberRecognition::cookie($this->answer(), $this->request(['User-Agent' => 'Mozilla/5.0'], 'https://site.test/x'))->isSecure());
    }

    public function testCookieDomainAndNameComeFromConfig(): void
    {
        config()->set('content.intelligence.recognition_cookie_domain', '.site.test');
        config()->set('content.intelligence.recognition_cookie', 'my_rec');
        $cookie = MemberRecognition::cookie($this->answer());

        self::assertSame('my_rec', $cookie->getName());
        self::assertSame('.site.test', $cookie->getDomain());
        self::assertSame('my_rec', MemberRecognition::forget()->getName());
        self::assertSame('.site.test', MemberRecognition::forget()->getDomain());
    }

    public function testNoCookieWhenRecognitionIsAbsentNullOrMalformed(): void
    {
        self::assertNull(MemberRecognition::cookie([]));
        self::assertNull(MemberRecognition::cookie(['recognition' => null]));
        self::assertNull(MemberRecognition::cookie($this->answer(['value' => ''])));
        self::assertNull(MemberRecognition::cookie($this->answer(['value' => 'a b;c'])));
        self::assertNull(MemberRecognition::cookie($this->answer(['value' => str_repeat('a', 201)])));
        self::assertNull(MemberRecognition::cookie($this->answer(['maxAge' => 0])));
        self::assertNull(MemberRecognition::cookie($this->answer(['maxAge' => '100'])));
        self::assertNull(MemberRecognition::cookie(['recognition' => 'x']));
    }

    public function testLifetimeIsCapped(): void
    {
        $cookie = MemberRecognition::cookie($this->answer(['maxAge' => 999_999_999]));

        self::assertLessThanOrEqual(time() + 34_560_000 + 5, $cookie->getExpiresTime());
    }

    public function testCrawlerNeverGetsACookie(): void
    {
        $crawler = $this->request(['User-Agent' => 'Googlebot/2.1']);

        self::assertNull(MemberRecognition::cookie($this->answer(), $crawler));
        self::assertNull(MemberRecognition::fromAnswer($this->answer(), $crawler));
        self::assertNull(MemberRecognition::fromAnswer(['recognition' => null], $crawler));
    }

    public function testFromAnswerSetsForgetsOrDoesNothing(): void
    {
        $browser = $this->request(['User-Agent' => 'Mozilla/5.0']);

        self::assertSame(self::VALUE, MemberRecognition::fromAnswer($this->answer(), $browser)->getValue());

        $gone = MemberRecognition::fromAnswer(['recognition' => null], $browser);
        self::assertSame('fsi_rec', $gone->getName());
        self::assertSame('', $gone->getValue());
        self::assertSame(1, $gone->getExpiresTime());
        self::assertTrue($gone->isCleared());

        self::assertNull(MemberRecognition::fromAnswer(['member' => ['id' => 'm1']], $browser), 'no recognition key = module or opt-in off');
    }

    public function testEmptyCookieNameSwitchesTheFeatureOff(): void
    {
        config()->set('content.intelligence.recognition_cookie', '');

        self::assertSame('', MemberRecognition::cookieName());
        self::assertNull(MemberRecognition::cookie($this->answer()));
        self::assertNull(MemberRecognition::fromAnswer(['recognition' => null]));
    }

    public function testPidPickup(): void
    {
        self::assertSame(self::PID, MemberRecognition::pid($this->request(['Cookie' => 'x=1; fsa_pid=' . self::PID, 'User-Agent' => 'Mozilla/5.0'])));
        self::assertNull(MemberRecognition::pid($this->request(['User-Agent' => 'Mozilla/5.0'])), 'no cookie');
        self::assertNull(MemberRecognition::pid($this->request(['Cookie' => 'fsa_pid=' . self::PID, 'Sec-GPC' => '1', 'User-Agent' => 'Mozilla/5.0'])), 'GPC');
        self::assertNull(MemberRecognition::pid($this->request(['Cookie' => 'fsa_pid=short', 'User-Agent' => 'Mozilla/5.0'])), 'too short');
        self::assertNull(MemberRecognition::pid($this->request(['Cookie' => 'fsa_pid=' . str_repeat('a', 65), 'User-Agent' => 'Mozilla/5.0'])), 'too long');
        self::assertNull(MemberRecognition::pid($this->request(['Cookie' => 'fsa_pid=abcdefabcd%20efabcdef12', 'User-Agent' => 'Mozilla/5.0'])), 'bad characters');
        self::assertNull(MemberRecognition::pid($this->request(['Cookie' => 'fsa_pid=' . self::PID, 'User-Agent' => 'Googlebot/2.1'])), 'crawler');
        $noAgent = $this->request(['Cookie' => 'fsa_pid=' . self::PID]);
        $noAgent->headers->remove('User-Agent');
        self::assertNull(MemberRecognition::pid($noAgent), 'no user agent at all counts as a bot');
    }

    public function testQueuedCookieReachesTheResponseAsPlainText(): void
    {
        $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0'])->post('/login');
        $cookie = collect($response->baseResponse->headers->getCookies())->first(fn (Cookie $c): bool => $c->getName() === 'fsi_rec');

        self::assertNotNull($cookie);
        self::assertSame(self::VALUE, $cookie->getValue(), 'EncryptCookies must skip the recognition cookie');
        self::assertTrue($cookie->isHttpOnly());
    }

    public function testQueuedLogoutClearsTheCookie(): void
    {
        $gone = $this->withHeaders(['User-Agent' => 'Mozilla/5.0'])->post('/logout');
        $cookie = collect($gone->baseResponse->headers->getCookies())->first(fn (Cookie $c): bool => $c->getName() === 'fsi_rec');
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isCleared());
    }

    public function testAnswerWithoutRecognitionQueuesNothing(): void
    {
        $plain = $this->withHeaders(['User-Agent' => 'Mozilla/5.0'])->post('/plain');
        $names = array_map(static fn (Cookie $c): string => $c->getName(), $plain->baseResponse->headers->getCookies());
        self::assertNotContains('fsi_rec', $names);
    }

    public function testCrawlerRequestQueuesNothing(): void
    {
        $response = $this->withHeaders(['User-Agent' => 'Googlebot/2.1'])->post('/login');
        $names = array_map(static fn (Cookie $c): string => $c->getName(), $response->baseResponse->headers->getCookies());

        self::assertNotContains('fsi_rec', $names);
    }
}
