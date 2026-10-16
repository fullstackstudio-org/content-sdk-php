<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\ModuleContext;

/**
 * The public newsletter routes (`newsletter:subscribe`): work with a pub token and a server token. Lists, subscribers and campaigns
 * (`$newsletter->lists`, `->subscribers`, `->campaigns`) are the admin side and need a server token.
 */
final class Newsletter
{
    public readonly NewsletterLists $lists;

    public readonly NewsletterSubscribers $subscribers;

    public readonly NewsletterCampaigns $campaigns;

    public function __construct(private readonly ModuleContext $ctx)
    {
        $this->lists = new NewsletterLists($ctx);
        $this->subscribers = new NewsletterSubscribers($ctx);
        $this->campaigns = new NewsletterCampaigns($ctx);
    }

    /**
     * Subscribes an address. Resolves with `['accepted' => true]` for every well-formed request, also when the address is already
     * subscribed, unsubscribed or bounced (no enumeration). A new address gets a confirmation mail (double opt-in). Throws a
     * ContentException (400) for a malformed request and 429 when the visitor tries too often.
     *
     * @param array<string, mixed> $input `email` (required), plus any of the optional fields of the API
     * @param array{clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function subscribe(array $input, array $options = []): array
    {
        $body = array_filter($input, static fn (mixed $value): bool => $value !== null);
        $ip = $this->ctx->visitorIp($options['clientIp'] ?? null);

        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/newsletter/subscribe',
            'body' => $body,
            'headers' => $ip !== null ? ['X-FSC-Client-Ip' => $ip] : null,
            'options' => $options,
        ])->data;
    }

    /**
     * For your own confirmation page (`confirmUrl` in the module settings): confirms with the token from the mail. A POST, never a GET.
     *
     * @param array<string, mixed> $options
     * @return array{ok: true, status: string}
     */
    public function confirm(string $token, array $options = []): array
    {
        return $this->ctx->write('POST', '/newsletter/confirm', ['token' => $token], $options);
    }

    /**
     * For your own unsubscribe page (`unsubscribeUrl`): unsubscribes with the token from the mail. Idempotent.
     *
     * @param array<string, mixed> $options
     * @return array{ok: true, status: string}
     */
    public function unsubscribe(string $token, array $options = []): array
    {
        return $this->ctx->write('POST', '/newsletter/unsubscribe', ['token' => $token], $options);
    }
}
