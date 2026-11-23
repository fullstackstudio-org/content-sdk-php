<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use Illuminate\Http\JsonResponse;

/**
 * `GET /api/health` (route `content.auto.health`): the liveness answer the platform's HEALTHCHECK expects. No Hub call, no session, never
 * cached: a Hub outage must not mark the pod unhealthy (pages degrade to the last good answer or a 503 on their own).
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['ok' => true], 200, ['Cache-Control' => 'no-store']);
    }
}
