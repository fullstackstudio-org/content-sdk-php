<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\Cache\ContentCache;
use FullStackStudio\Content\Laravel\Events\ContentWebhookReceived;
use FullStackStudio\Content\Tags;
use FullStackStudio\Content\Webhooks\VerifyResult;
use FullStackStudio\Content\Webhooks\WebhookVerifier;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives webhooks from Content (mirror of `createContentWebhookHandler`): verifies the signature against the raw body,
 * invalidates the cache tags of the event, dispatches {@see ContentWebhookReceived} and answers 200. Only an invalid signature is
 * a 401; events that invalidate nothing (and unknown types) are a 200 too, because a non-2xx counts as failed and is retried.
 */
final class WebhookController
{
    public function __invoke(Request $request, Repository $config, ContentCache $cache, Dispatcher $events): JsonResponse
    {
        $secret = (string) $config->get('content.webhook.secret');

        if ($secret === '') {
            Log::error('[content-webhook] secret is missing (FSC_WEBHOOK_SECRET)');

            return new JsonResponse(['error' => 'Webhook is not configured.'], 500);
        }

        $result = WebhookVerifier::verify(
            $request->getContent(),
            $request->header('X-FSC-Signature'),
            $secret,
            (int) $config->get('content.webhook.tolerance', WebhookVerifier::DEFAULT_TOLERANCE_SECONDS),
        );

        if (!$result->ok && $result->reason !== VerifyResult::INVALID_PAYLOAD) {
            // Log only the reason, never the secret, the signature or the body.
            Log::warning('[content-webhook] signature rejected: ' . $result->reason);

            return new JsonResponse(['error' => 'Invalid signature.'], 401);
        }

        if (!$result->ok) {
            // Correctly signed but not an event: retrying does not help.
            return new JsonResponse(['received' => true, 'revalidated' => []]);
        }

        $event = $result->event;
        $tags = Tags::forEvent($event);

        try {
            if ($tags !== []) {
                $cache->invalidateTags($tags);
            }

            if (in_array(Tags::SITE, $tags, true)) {
                // A paused lookup (module was off, or an outage) must not hide the new site standards.
                SiteSettings::forgetPause();
            }

            $events->dispatch(new ContentWebhookReceived($event, $tags));
        } catch (Throwable $error) {
            Log::error('[content-webhook] processing failed: ' . $error->getMessage());

            return new JsonResponse(['error' => 'Processing failed.'], 500);
        }

        return new JsonResponse(['received' => true, 'event' => $event['type'], 'revalidated' => $tags]);
    }
}
