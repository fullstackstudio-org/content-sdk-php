<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

/**
 * Request scoped state of the out of the box site (bound `scoped`, so a fresh one per request, also under Octane). The resolver fills it,
 * the head component and the default views read it. Nothing in here throws: a missing value is null or false.
 */
final class PageContext
{
    private ?Resolved $resolved = null;

    private ?int $status = null;

    /** @var array<string, mixed> */
    private array $seo = [];

    /** @var array<string, true> */
    private array $printed = [];

    public function resolved(): ?Resolved
    {
        return $this->resolved;
    }

    public function resolve(Resolved $resolved): void
    {
        $this->resolved = $resolved;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    public function setStatus(?int $status): void
    {
        $this->status = $status;
    }

    /**
     * Site meta overrides (`title`, `description`, `canonical`, `image`, ...). Merges over earlier calls; the last value of a key wins.
     *
     * @param array<string, mixed> $meta
     */
    public function seo(array $meta = []): array
    {
        if ($meta !== []) {
            $this->seo = array_replace($this->seo, $meta);
        }

        return $this->seo;
    }

    /** Marks a part (`head`, `scripts`, `consent`, ...) as printed and tells whether it already was, so a part is never printed twice. */
    public function printed(string $part): bool
    {
        $was = isset($this->printed[$part]);
        $this->printed[$part] = true;

        return $was;
    }
}
