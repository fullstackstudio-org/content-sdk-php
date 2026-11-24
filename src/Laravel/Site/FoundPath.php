<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use RuntimeException;

/** @internal Carries a resolved path out of the negative cache of {@see SiteController} (only misses are stored there). */
final class FoundPath extends RuntimeException
{
    public function __construct(public readonly Resolved $resolved)
    {
        parent::__construct('found');
    }
}
