<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Events;

/** Dispatched after a verified webhook has invalidated its cache tags. Listen to it to clear your own caches or rebuild things. */
final class ContentWebhookReceived
{
    /**
     * @param array{id: string, type: string, createdAt: string, projectId: string, data: array<string, mixed>} $event
     * @param list<string> $tags the cache tags that were invalidated
     */
    public function __construct(public readonly array $event, public readonly array $tags)
    {
    }
}
