<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Tests\Support\FakeTransport;

/**
 * A fake Hub for the out of the box site tests: routes read from the public arrays below, everything else is a 404, a path in `$down` is
 * a 500, and every request is recorded in `$http`.
 */
abstract class FakeHubTestCase extends LaravelTestCase
{
    protected FakeTransport $http;

    /** @var array<string, mixed> */
    protected array $project = ['locales' => ['nl', 'en'], 'defaultLocale' => 'nl'];

    /** @var array<string, mixed> */
    protected array $seo = ['articlePathTemplate' => '/blog/{slug}', 'canonicalOrigin' => 'https://klant.test'];

    /** @var array<string, array<string, mixed>> "locale:path" => page */
    protected array $pages = [];

    /** @var list<array<string, mixed>> */
    protected array $articles = [];

    /** @var array<string, array<string, mixed>> slug => event */
    protected array $events = [];

    /** @var array<string, array<string, mixed>> kind => document */
    protected array $legal = [];

    /** @var list<array<string, mixed>> */
    protected array $categories = [];

    /** @var list<string> API paths that answer 500 */
    protected array $down = [];

    /** @var list<string> API paths that answer 404 module_disabled */
    protected array $off = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeTransport();
        $this->http->queue(...array_fill(0, 200, fn (Request $request): Response => $this->answer($request)));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
        $this->app->forgetInstance(\FullStackStudio\Content\Laravel\Site\Urls::class);
    }

    protected function page(string $path, string $locale = 'nl', array $extra = []): void
    {
        $this->pages[$locale . ':' . trim($path, '/')] = ['id' => 'p-' . $locale . trim($path, '/'), 'path' => '/' . trim($path, '/'), 'locale' => $locale, 'status' => 'published', 'title' => 'Page ' . $path, ...$extra];
    }

    protected function article(string $slug, string $locale = 'nl', array $extra = []): void
    {
        $this->articles[] = ['slug' => $slug, 'locale' => $locale, 'status' => 'published', 'title' => 'Article ' . $slug, 'categories' => [], ...$extra];
    }

    /** @return list<string> the API paths asked for, in order */
    protected function hubPaths(): array
    {
        return array_map(static fn (Request $r): string => substr((string) parse_url($r->url, PHP_URL_PATH), strlen('/api/v1')), $this->http->requests);
    }

    /** @return array<string, mixed> query of the last request to an API path */
    protected function queryOf(string $path): array
    {
        foreach (array_reverse($this->http->requests) as $request) {
            if (substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1')) === $path) {
                parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

                return $query;
            }
        }

        self::fail('No request to ' . $path);
    }

    private function answer(Request $request): Response
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));
        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $q);
        $locale = $q['locale'] ?? 'nl';

        if (in_array($path, $this->down, true)) {
            return FakeTransport::json(['error' => ['code' => 'server_error', 'message' => 'x']], 500);
        }

        if (in_array($path, $this->off, true)) {
            return FakeTransport::json(['error' => ['code' => 'module_disabled', 'message' => 'x']], 404);
        }

        $found = match (true) {
            $path === '/project' => $this->project,
            $path === '/seo' => ['seo' => $this->seo],
            $path === '/pages' => isset($this->pages[$locale . ':' . trim($q['path'] ?? '', '/')]) ? ['page' => $this->pages[$locale . ':' . trim($q['path'], '/')]] : null,
            $path === '/categories' => ['categories' => $this->categories],
            $path === '/articles' => $this->articleList($q),
            str_starts_with($path, '/articles/') => $this->articleDetail(substr($path, 10), $locale),
            str_starts_with($path, '/events/') => isset($this->events[substr($path, 8)]) ? ['event' => $this->events[substr($path, 8)]] : null,
            str_starts_with($path, '/legal/') => isset($this->legal[substr($path, 7)]) ? ['document' => $this->legal[substr($path, 7)]] : null,
            default => null,
        };

        return $found === null
            ? FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404)
            : FakeTransport::json($found, 200, $path === '/articles' ? ['X-Total-Count' => (string) $found['total']] : []);
    }

    /** @param array<string, mixed> $q @return array<string, mixed> */
    private function articleList(array $q): array
    {
        $matching = array_values(array_filter($this->articles, static fn (array $a): bool => ($q['status'] ?? $a['status']) === $a['status']
            && ($q['locale'] ?? 'nl') === $a['locale']
            && (! isset($q['category']) || in_array($q['category'], $a['categories'], true))));
        $limit = (int) ($q['limit'] ?? 20);
        $page = (int) ($q['page'] ?? 1);

        return ['articles' => array_slice($matching, ($page - 1) * $limit, $limit), 'page' => $page, 'limit' => $limit, 'total' => count($matching)];
    }

    /** @return array<string, mixed>|null */
    private function articleDetail(string $slug, string $locale): ?array
    {
        foreach ($this->articles as $article) {
            if ($article['slug'] === $slug && $article['locale'] === $locale) {
                return ['article' => $article];
            }
        }

        return null;
    }
}
