<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Visitor;

/**
 * Sends one forwarded visitor request to the Hub. Must not follow redirects, must stop reading at `$request->maxBytes` and must throw a
 * {@see VisitorProxyException} (never return) when no complete response arrived. Bound in the container, so tests use a fake upstream.
 */
interface Upstream
{
    public function send(UpstreamRequest $request): UpstreamResponse;
}
