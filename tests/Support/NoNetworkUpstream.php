<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Support;

use FullStackStudio\Content\Laravel\Visitor\Upstream;
use FullStackStudio\Content\Laravel\Visitor\UpstreamRequest;
use FullStackStudio\Content\Laravel\Visitor\UpstreamResponse;
use LogicException;

/** The default upstream of every Laravel test: a visitor page request that reaches it is a test bug, never a network call. */
final class NoNetworkUpstream implements Upstream
{
    public function send(UpstreamRequest $request): UpstreamResponse
    {
        throw new LogicException('A test sent a visitor page request without a fake upstream.');
    }
}
