<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Testing\TestResponse;

/**
 * The portal preview through the automatic fallback route with the real {@see \FullStackStudio\Content\Laravel\Site\SiteController} and
 * {@see \FullStackStudio\Content\Laravel\Site\PathResolver} (no probe): the previewed page renders in the package layout, private and
 * without cookies, also when it is a draft, in another language or on a legal path.
 */
final class AutoPreviewTest extends FakeHubTestCase
{
    private const TOKEN = 'fscpv_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const PORTAL = 'https://portal.fullstackstudio.nl';

    /** @var array<string, mixed>|null what the fake Hub answers on `pages/_preview` */
    private ?array $preview = null;

    protected function answer(Request $request): Response
    {
        if (parse_url($request->url, PHP_URL_PATH) === '/api/v1/pages/_preview') {
            return $this->preview !== null && ($request->headers['X-FSC-Preview'] ?? null) === self::TOKEN
                ? FakeTransport::json(['preview' => $this->preview], 200, ['cache-control' => 'no-store'])
                : FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404);
        }

        return parent::answer($request);
    }

    /** @param array<string, mixed> $page */
    private function previewOf(string $path, array $page): TestResponse
    {
        $this->preview = [
            'version' => 3,
            'path' => $path,
            'embedOrigin' => self::PORTAL,
            'page' => ['id' => '11111111-2222-3333-4444-555555555555', 'preview' => true, 'media' => [], ...$page],
        ];

        return $this->call('POST', '/_fsc/p', ['token' => self::TOKEN], ['laravel_session' => 'stolen'], [], ['REMOTE_ADDR' => '203.0.113.7']);
    }

    private function assertPrivatePreview(TestResponse $response): void
    {
        $response->assertOk();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies(), 'no Set-Cookie');
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertStringContainsString('data-fsc-preview', (string) $response->getContent());
    }

    public function testADutchPageRendersThroughTheFallback(): void
    {
        $this->page('over-ons', 'nl', ['title' => 'Live over ons']);

        $response = $this->previewOf('/over-ons', ['title' => 'Concept over ons', 'slug' => 'over-ons', 'path' => '/over-ons', 'locale' => 'nl', 'status' => 'published',
            'blocks' => [['id' => 'b1', 'type' => 'richText', 'html' => '<p>Nog niet gepubliceerd</p>']]]);

        $this->assertPrivatePreview($response);
        $html = (string) $response->getContent();
        self::assertStringContainsString('<p>Nog niet gepubliceerd</p>', $html);
        self::assertStringContainsString('Concept over ons', $html);
        self::assertStringNotContainsString('Live over ons', $html);
        self::assertStringContainsString('<main class="fsc-site">', $html, 'the package layout and the default page view');
    }

    public function testAnEnglishPageRendersInItsOwnLanguage(): void
    {
        $this->page('about', 'en', ['title' => 'Live about']);

        $response = $this->previewOf('/about', ['title' => 'Draft about', 'slug' => 'about', 'path' => '/about', 'locale' => 'en', 'status' => 'published',
            'blocks' => [['id' => 'b1', 'type' => 'richText', 'html' => '<p>English draft</p>']]]);

        $this->assertPrivatePreview($response);
        $html = (string) $response->getContent();
        self::assertStringContainsString('<p>English draft</p>', $html);
        self::assertStringContainsString('lang="en"', $html, 'the page is rendered in its own language');
    }

    public function testAnEnglishPageOnItsPrefixedPath(): void
    {
        $response = $this->previewOf('/en/about', ['title' => 'Draft about', 'slug' => 'about', 'path' => '/about', 'locale' => 'en', 'status' => 'published',
            'blocks' => [['id' => 'b1', 'type' => 'richText', 'html' => '<p>English draft</p>']]]);

        $this->assertPrivatePreview($response);
        self::assertStringContainsString('<p>English draft</p>', (string) $response->getContent());
        self::assertStringContainsString('lang="en"', (string) $response->getContent());
    }

    public function testADraftPageIsShownInThePreview(): void
    {
        $response = $this->previewOf('/nieuw', ['title' => 'Nieuwe pagina', 'slug' => 'nieuw', 'path' => '/nieuw', 'locale' => 'nl', 'status' => 'draft',
            'blocks' => [['id' => 'b1', 'type' => 'richText', 'html' => '<p>Nog een concept</p>']]]);

        $this->assertPrivatePreview($response);
        self::assertStringContainsString('<p>Nog een concept</p>', (string) $response->getContent());
    }

    public function testADraftPageIsStillA404OutsideThePreview(): void
    {
        $this->page('nieuw', 'nl', ['status' => 'draft']);

        $this->get('/nieuw')->assertNotFound();
    }

    public function testAPreviewedPageOnALegalPathShowsThePage(): void
    {
        $this->legal['privacy_statement'] = ['title' => 'Privacyverklaring in de Hub', 'bodyHtml' => '<p>Juridisch</p>'];

        $response = $this->previewOf('/privacyverklaring', ['title' => 'Eigen privacypagina', 'slug' => 'privacyverklaring', 'path' => '/privacyverklaring',
            'locale' => 'nl', 'status' => 'draft', 'blocks' => [['id' => 'b1', 'type' => 'richText', 'html' => '<p>Eigen tekst</p>']]]);

        $this->assertPrivatePreview($response);
        $html = (string) $response->getContent();
        self::assertStringContainsString('<p>Eigen tekst</p>', $html);
        self::assertStringNotContainsString('Juridisch', $html);
        self::assertNotContains('/legal/privacy_statement', $this->hubPaths(), 'the legal step is skipped in a preview');
    }

    public function testOutsideThePreviewTheLegalDocumentStillWins(): void
    {
        $this->legal['privacy_statement'] = ['title' => 'Privacyverklaring in de Hub', 'bodyHtml' => '<p>Juridisch</p>'];
        $this->page('privacyverklaring', 'nl', ['title' => 'Eigen privacypagina']);

        $this->get('/privacyverklaring')->assertOk()->assertSee('Juridisch', false);
    }
}
