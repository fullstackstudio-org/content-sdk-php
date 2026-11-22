<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Preview;

use FullStackStudio\Content\Tags;
use Throwable;

/**
 * The request scoped preview context (bound `scoped`, so it never outlives one request, also under Octane). {@see PreviewRequest} activates
 * it after the Hub accepted a preview token; from then on {@see \FullStackStudio\Content\ContentClient::getPage()} returns the preview page
 * for its path and the components switch to preview markup (block markers, no analytics, disabled forms).
 *
 * Everything in here was checked by {@see PreviewRequest::parse()}: the path is a plain same-site path, the origin an exact origin.
 *
 * @internal
 */
final class Preview
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    /** @var array<string, mixed>|null */
    private ?array $page = null;

    private string $path = '';

    private string $embedOrigin = '';

    private int $version = 0;

    private string $token = '';

    /** True while the current request renders a preview. Never throws (false outside a Laravel app). */
    public static function isActive(): bool
    {
        try {
            return app(self::class)->active();
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $page */
    public function activate(array $page, string $path, string $embedOrigin, int $version, string $token): void
    {
        $this->page = $page;
        $this->path = $path;
        $this->embedOrigin = $embedOrigin;
        $this->version = $version;
        $this->token = $token;
    }

    public function active(): bool
    {
        return $this->page !== null;
    }

    /** @return array<string, mixed>|null */
    public function page(): ?array
    {
        return $this->page;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function embedOrigin(): string
    {
        return $this->embedOrigin;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function token(): string
    {
        return $this->token;
    }

    /**
     * The preview page when `$ref` (a page id, or a path in any spelling `getPage()` accepts) points at it, else null. A path matches the
     * preview path or the page's own `path`/`slug`, so a home controller that asks for `home` still gets the preview of the home page.
     *
     * @return array<string, mixed>|null
     */
    public function pageFor(string $ref): ?array
    {
        if ($this->page === null) {
            return null;
        }

        if (preg_match(self::UUID, $ref) === 1) {
            $id = $this->page['id'] ?? null;

            return is_string($id) && strcasecmp($id, $ref) === 0 ? $this->page : null;
        }

        $wanted = Tags::normalizePagePath($ref);

        foreach ([$this->path, $this->page['path'] ?? null, $this->page['slug'] ?? null] as $candidate) {
            if (is_string($candidate) && Tags::normalizePagePath($candidate) === $wanted) {
                return $this->page;
            }
        }

        return null;
    }
}
