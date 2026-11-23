<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Auto\Auto;
use FullStackStudio\Content\Laravel\Preview\Preview;

/**
 * Decides at request time which Hub resource a path is, in the order of the resolver table of the out of the box site plan:
 *
 *  0. reject unsafe paths without a Hub call; 1. strip a non-default project locale; 2. legal document; 3. event (own template);
 *  4. article / category list / article list (article template); 5. page by path; 6. root level article, then event, after a page 404.
 *
 * A root level template is `/{slug}` or `/{id}`, also `/{locale}/{slug}`; templates come normalised (no trailing slash) from {@see Urls}.
 *
 * A template match is decisive: `/blog/x` asks for the article only, never for a page. Only published resources are served. Null is a 404.
 *
 * In a portal preview ({@see Preview}) the previewed page is what the editor wants to see: step 2 is skipped (a page on a legal path shows),
 * the previewed page is served whatever its status, and it is rendered in its own language when that is a project locale.
 * Throws nothing but a {@see \FullStackStudio\Content\ContentException} (a Hub failure other than 404). `Resolved::$path` is the path without
 * locale prefix (for a `{locale}` article template: as matched); `Resolved::$locale` is always the language the content was read in.
 */
final class PathResolver
{
    private const MAX_LENGTH = 200;

    private const SAFE = '/^[A-Za-z0-9][A-Za-z0-9\/_-]*$/D';

    private const LOCALE = '[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*';

    /** The public event fields, as the Hub's `EventRules::toPublicEventView()` plus `registrationUrl` (what a pub token gets). */
    private const EVENT_FIELDS = ['id', 'slug', 'title', 'summary', 'descriptionHtml', 'location', 'startsAt', 'endsAt', 'available', 'salesStartAt',
        'salesEndAt', 'maxPerRegistration', 'currency', 'salesState', 'ticketTypes', 'coverMediaId', 'cover', 'registrationUrl'];

    /** The public fields of an active ticket type. */
    private const TICKET_FIELDS = ['id', 'name', 'description', 'priceCents', 'available'];

    public function __construct(private readonly ContentClient $client, private readonly Urls $urls)
    {
    }

    public function resolve(string $path): ?Resolved
    {
        // Step 0
        $path = self::normalize($path);

        if ($path === null) {
            return null;
        }

        // Step 1
        $default = $this->urls->defaultLocale();
        $locale = null;
        $rest = $path;
        $segments = $path === '/' ? [] : explode('/', ltrim($path, '/'));

        if ($segments !== [] && $segments[0] !== $default && in_array($segments[0], $this->urls->locales(), true)) {
            $locale = $segments[0];
            $rest = count($segments) > 1 ? '/' . implode('/', array_slice($segments, 1)) : '/';
        }

        $locale ??= $default;
        app()->setLocale($locale);
        $this->client->setLocale($locale);

        $preview = Preview::isActive() ? app(Preview::class) : null;

        // Step 2 (not in a preview: the previewed page is what the editor wants to see)
        if ($preview === null && Auto::on('legal') && ($kind = $this->legalKind($rest)) !== null) {
            $document = Hub::call(fn (): array => $this->client->legal->get($kind, ['locale' => $locale]));

            if (is_array($document) && $document !== []) {
                return new Resolved('legal', $document, $rest, $locale, ['kind' => $kind]);
            }
        }

        // Step 3 (a root level event template waits for step 6)
        $eventTemplate = $this->urls->eventTemplate();
        $eventRoot = $eventTemplate !== null && self::rootLevel($eventTemplate);

        if ($eventTemplate !== null && ! $eventRoot && ($match = self::match($eventTemplate, $rest, ['slug' => Urls::SEGMENT, 'id' => Urls::SEGMENT])) !== null) {
            return $this->event($match['slug'] ?? $match['id'], $rest, $locale);
        }

        // Step 4
        $articles = Auto::on('articles');
        $articleTemplate = $articles ? $this->urls->articleTemplate() : null;
        $articleRoot = $articleTemplate !== null && self::rootLevel($articleTemplate);
        $withLocale = $articleTemplate !== null && str_contains($articleTemplate, '{locale}');
        $haystack = $withLocale ? $path : $rest;
        $listLocale = null;

        if ($articleTemplate !== null && ! $articleRoot) {
            $match = $this->templateMatch($articleTemplate, $haystack, ['slug' => Urls::SEGMENT]);

            if ($match !== null) {
                return $this->article($match['slug'], $haystack, $this->templateLocale($match, $locale));
            }

            $parent = preg_match('#^(/.+)/\{slug\}$#D', $articleTemplate, $m) === 1 ? $m[1] : null;

            if ($parent !== null) {
                $segment = (string) Auto::option('articles', 'category_segment', 'categorie');

                if (($match = $this->templateMatch($parent, $haystack, [])) !== null) {
                    $listLocale = $this->templateLocale($match, $locale);
                } elseif ($segment !== '' && ($match = $this->templateMatch($parent . '/' . $segment . '/{category}', $haystack, ['category' => Urls::SEGMENT])) !== null) {
                    return $this->category($match['category'], $haystack, $this->templateLocale($match, $locale));
                }
            }
        }

        // Step 5
        if (Auto::on('pages')) {
            $page = Hub::call(fn (): array => $this->client->getPage($rest, ['locale' => $locale]));

            if (is_array($page) && $page !== [] && $preview !== null && self::isPreviewPage($page, $preview)) {
                $own = $page['locale'] ?? null;
                $locale = is_string($own) && in_array($own, $this->urls->locales(), true) ? $own : $locale;
                app()->setLocale($locale);
                $this->client->setLocale($locale);

                return new Resolved('page', $page, $rest, $locale);
            }

            if (is_array($page) && $page !== [] && ($page['status'] ?? 'published') === 'published') {
                return new Resolved('page', $page, $rest, $locale);
            }
        }

        if ($listLocale !== null) {
            return $this->articleList($haystack, $listLocale, null);
        }

        // Step 6
        if ($articleRoot && ($match = $this->templateMatch($articleTemplate, $haystack, ['slug' => Urls::SEGMENT])) !== null) {
            $resolved = $this->article($match['slug'], $haystack, $this->templateLocale($match, $locale));

            if ($resolved !== null) {
                return $resolved;
            }
        }

        if ($eventRoot && ($match = self::match($eventTemplate, $rest, ['slug' => Urls::SEGMENT, 'id' => Urls::SEGMENT])) !== null) {
            return $this->event($match['slug'] ?? $match['id'], $rest, $locale);
        }

        return null;
    }

    private function event(string $ref, string $path, string $locale): ?Resolved
    {
        $event = Hub::call(fn (): array => $this->client->events->get($ref, ['cache' => true]));

        if (! is_array($event) || $event === [] || ($event['status'] ?? 'published') === 'draft') {
            return null;
        }

        return new Resolved('event', self::publicEvent($event), $path, $locale);
    }

    /**
     * The server token reads the internal event (capacity, inactive ticket types, staff fields): only the public fields reach views,
     * handlers and the head, plus `status` (a cancelled event shows its state). Fields the Hub did not send stay absent.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private static function publicEvent(array $event): array
    {
        $public = array_intersect_key($event, array_flip(self::EVENT_FIELDS));

        if (array_key_exists('ticketTypes', $public)) {
            $types = [];

            foreach (is_array($public['ticketTypes']) ? $public['ticketTypes'] : [] as $type) {
                if (is_array($type) && ($type['active'] ?? true) !== false) {
                    $types[] = array_intersect_key($type, array_flip(self::TICKET_FIELDS));
                }
            }

            $public['ticketTypes'] = $types;
        }

        // In the order of the public view, then the status.
        $ordered = array_replace(array_intersect_key(array_flip(self::EVENT_FIELDS), $public), $public);

        return $ordered + (array_key_exists('status', $event) ? ['status' => $event['status']] : []);
    }

    private function article(string $slug, string $path, string $locale): ?Resolved
    {
        $article = Hub::call(fn (): array => $this->client->getArticle($slug, ['locale' => $locale]));

        if (! is_array($article) || $article === [] || ($article['status'] ?? 'published') !== 'published') {
            return null;
        }

        return new Resolved('article', $article, $path, $locale);
    }

    private function category(string $slug, string $path, string $locale): ?Resolved
    {
        $tree = Hub::call(fn (): array => $this->client->listCategories(['locale' => $locale]));
        $category = is_array($tree) ? self::findCategory($tree, $slug) : null;

        return $category === null ? null : $this->articleList($path, $locale, $category);
    }

    /** @param array<string, mixed>|null $category */
    private function articleList(string $path, string $locale, ?array $category): ?Resolved
    {
        $page = self::requestedPage();

        if ($page === null) {
            return null;
        }

        $perPage = max(1, (int) Auto::option('articles', 'per_page', 12));
        $list = Hub::call(fn (): array => $this->client->listArticles([
            'status' => 'published',
            'category' => $category['slug'] ?? null,
            'locale' => $locale,
            'page' => $page,
            'limit' => $perPage,
        ]));

        if (! is_array($list)) {
            return null;
        }

        $items = array_values(array_filter((array) ($list['articles'] ?? []), static fn (mixed $a): bool => is_array($a) && ($a['status'] ?? 'published') === 'published'));

        // A page beyond the last one is a 404; the first page may be empty (the view shows an empty state).
        if ($page > 1 && $items === []) {
            return null;
        }

        $total = is_int($list['total'] ?? null) ? $list['total'] : count($items);
        $list['articles'] = $items;

        return new Resolved($category === null ? 'articles' : 'category', $list, $path, $locale, [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'category' => $category,
        ]);
    }

    /**
     * Whether `getPage()` answered with the previewed page (its override), not a page of the Hub.
     *
     * @param array<string, mixed> $page
     */
    private static function isPreviewPage(array $page, Preview $preview): bool
    {
        $previewed = $preview->page();

        if ($previewed === null) {
            return false;
        }

        $id = $previewed['id'] ?? null;

        return is_string($id) && $id !== '' ? ($page['id'] ?? null) === $id : $page === $previewed;
    }

    /** @return non-empty-string|null null when the path is not safe to look up (step 0) */
    private static function normalize(string $path): ?string
    {
        if (strlen($path) > self::MAX_LENGTH || str_contains($path, '//')) {
            return null;
        }

        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return '/';
        }

        $last = substr($trimmed, (int) strrpos('/' . $trimmed, '/'));

        if (str_contains($last, '.') || preg_match(self::SAFE, $trimmed) !== 1) {
            return null;
        }

        return '/' . $trimmed;
    }

    private function legalKind(string $path): ?string
    {
        $path = trim($path, '/');

        foreach (Auto::legalPaths() as $legalPath => $kind) {
            if (trim($legalPath, '/') === $path && $path !== '') {
                return $kind;
            }
        }

        return null;
    }

    /**
     * A template that is just `/{slug}` (or `/{id}`), also behind a leading `/{locale}`: it competes with pages, so it is tried after the
     * page lookup. Expects a template normalised by {@see Urls::template()}.
     */
    private static function rootLevel(string $template): bool
    {
        if (str_starts_with($template, '/{locale}/')) {
            $template = substr($template, strlen('/{locale}'));
        }

        return in_array($template, ['/{slug}', '/{id}'], true);
    }

    /**
     * @param array<string, string> $groups
     * @return array<string, string>|null the named groups of a match
     */
    private static function match(string $template, string $path, array $groups): ?array
    {
        if (preg_match(Urls::pattern($template, $groups), $path, $m) !== 1) {
            return null;
        }

        return array_filter($m, static fn (mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Matches a template that may hold `{locale}`; the captured locale has to be a project locale.
     *
     * @param array<string, string> $groups
     * @return array<string, string>|null
     */
    private function templateMatch(string $template, string $path, array $groups): ?array
    {
        $match = self::match($template, $path, $groups + ['locale' => self::LOCALE]);

        return $match !== null && (! isset($match['locale']) || in_array($match['locale'], $this->urls->locales(), true)) ? $match : null;
    }

    /**
     * The locale a `{locale}` template captured, when it is a project locale; otherwise the request locale.
     *
     * @param array<string, string> $match
     */
    private function templateLocale(array $match, string $locale): string
    {
        $captured = $match['locale'] ?? null;

        return is_string($captured) && in_array($captured, $this->urls->locales(), true) ? $captured : $locale;
    }

    /**
     * @param list<array<string, mixed>> $tree
     * @return array<string, mixed>|null
     */
    private static function findCategory(array $tree, string $slug): ?array
    {
        foreach ($tree as $category) {
            if (! is_array($category)) {
                continue;
            }

            if (($category['slug'] ?? null) === $slug) {
                return $category;
            }

            if (is_array($category['children'] ?? null) && ($found = self::findCategory($category['children'], $slug)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** `?page=`: 1 when absent; an integer >= 1; anything else null (404). */
    private static function requestedPage(): ?int
    {
        $page = request()->query('page');

        if ($page === null) {
            return 1;
        }

        return is_string($page) && preg_match('/^[1-9][0-9]{0,5}$/D', $page) === 1 ? (int) $page : null;
    }
}
