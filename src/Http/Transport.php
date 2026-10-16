<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Http;

/**
 * Sends one HTTP request. Implementations must not follow redirects and must throw a {@see TransportException}
 * (never return) when no response arrived.
 */
interface Transport
{
    public function send(Request $request): Response;
}
