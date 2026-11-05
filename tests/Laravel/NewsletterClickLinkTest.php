<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Route;

/**
 * Newsletter click links (plan ootb-integrations D9): a page view with `?fscn=<token>` from a CONSENTING browser (the `fsa_pid` cookie) makes
 * exactly one `POST /intelligence/link {pid, newsletterToken}` after the response, per browser and token; nothing without consent, under
 * `Sec-GPC: 1`, for a crawler, a staff browser, a malformed token or with Intelligence off. The page itself is unchanged. Never talks to
 * the Hub (fake transport).
 */
final class NewsletterClickLinkTest extends LaravelTestCase
{
    private const TOKEN = 'AWtra2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2tr';

    private const PID = 'abcdefabcdefabcdef';

    private FakeTransport $content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->content = new FakeTransport();
        $this->content->always(FakeTransport::json(['active' => true, 'linked' => true]));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->content]));
    }

    protected function defineRoutes($router): void
    {
        Route::get('/aanbod', static fn (): string => 'aanbod');
    }

    /** @return list<object> the link calls the site made */
    private function links(): array
    {
        return array_values(array_filter($this->content->requests, fn ($r) => str_contains($r->url, '/api/v1/intelligence/link')));
    }

    private function visit(string $query, array $server = [], ?string $pid = self::PID, array $cookies = []): void
    {
        $server = [...['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Test', 'REMOTE_ADDR' => '203.0.113.9'], ...$server];

        if ($pid !== null) {
            $server['HTTP_COOKIE'] = 'fsa_pid=' . $pid;
        }

        $this->call('GET', '/aanbod' . $query, [], $cookies, [], $server)->assertOk()->assertSee('aanbod');
    }

    public function testAConsentingBrowserIsLinkedOncePerToken(): void
    {
        config(['content.intelligence.enabled' => true]);

        $this->visit('?x=1&fscn=' . self::TOKEN);
        $this->visit('?fscn=' . self::TOKEN);

        self::assertCount(1, $this->links());
        self::assertSame('POST', $this->links()[0]->method);
        self::assertSame(['pid' => self::PID, 'newsletterToken' => self::TOKEN], json_decode((string) $this->links()[0]->body, true));
        self::assertSame('Bearer fsc_srv_laraveltoken', $this->links()[0]->headers['Authorization']);
    }

    public function testNothingWithoutConsentUnderGpcForCrawlersStaffOrABadToken(): void
    {
        config(['content.intelligence.enabled' => true]);

        $this->visit('?fscn=' . self::TOKEN, [], null);
        $this->visit('?fscn=' . self::TOKEN, ['HTTP_SEC_GPC' => '1']);
        $this->visit('?fscn=' . self::TOKEN, ['HTTP_USER_AGENT' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']);
        $this->visit('?fscn=' . self::TOKEN, [], self::PID, ['fsi_staff' => '1']);
        $this->visit('?fscn=kort');
        $this->visit('');

        self::assertSame([], $this->links());
    }

    public function testNothingWhileIntelligenceIsOff(): void
    {
        config(['content.intelligence.enabled' => false]);

        $this->visit('?fscn=' . self::TOKEN);

        self::assertSame([], $this->links());
    }
}
