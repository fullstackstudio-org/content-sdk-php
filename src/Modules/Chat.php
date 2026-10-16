<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/**
 * The chatbot (`$client->chat`; module `chatbot`).
 *
 * A conversation is opened with `chat:converse` (a pub token works). The conversation token that `open` returns is the only key to that
 * conversation: pass it to every next call (sent as `X-FSC-Conversation`, never in the URL). With a server token pass the visitor's IP as
 * `clientIp` so the per-visitor limits work. `memberSession` links the conversation to a logged-in member; a conversation that belongs to a member
 * needs that member's session on EVERY call (get, send, feedback, close): without it 404 `conversation_invalid`, with another member's
 * session 409 `conversation_closed`.
 */
final class Chat
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array<string, mixed> $options @return array<string, string>|null */
    private function headers(string $token, array $options): ?array
    {
        return $this->ctx->visitorHeaders($options['memberSession'] ?? null, $options['clientIp'] ?? null, ['X-FSC-Conversation' => $token]);
    }

    /**
     * What a widget may know before a conversation exists: name, greeting, retention, Turnstile site key, link policy.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function config(array $options = []): array
    {
        return $this->ctx->execute(['path' => '/chat/config', 'options' => $options])->data['config'] ?? [];
    }

    /**
     * Opens a conversation (201). 400 `turnstile_required` when the bot asks for a Turnstile token; 429 `rate_limited` (5 per hour per visitor IP).
     *
     * @param array{locale?: string, turnstileToken?: string} $input
     * @param array{memberSession?: string, clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed> includes the `conversationToken` (shown once)
     */
    public function open(array $input = [], array $options = []): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/chat/conversations',
            'body' => Arr::defined(['locale' => $input['locale'] ?? null, '_turnstile' => $input['turnstileToken'] ?? null]),
            'headers' => $this->ctx->visitorHeaders($options['memberSession'] ?? null, $options['clientIp'] ?? null),
            'options' => $options,
        ])->data;
    }

    /**
     * Your own conversation with its messages (a reload shows it again). 404 `conversation_invalid` for a wrong, missing or expired (24 h) token.
     *
     * @param array{memberSession?: string, clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function get(string $conversationId, string $conversationToken, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/chat/conversations/' . ModuleContext::seg($conversationId), 'headers' => $this->headers($conversationToken, $options), 'options' => $options])->data;
    }

    /**
     * Sends a message (max 2000 characters) and returns the answer with its sources. 409 `conversation_closed`, 422 `limit_reached` (40 messages), 429 `rate_limited`.
     *
     * @param array{memberSession?: string, clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function send(string $conversationId, string $conversationToken, string $text, array $options = []): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/chat/conversations/' . ModuleContext::seg($conversationId) . '/messages',
            'body' => ['text' => $text],
            'headers' => $this->headers($conversationToken, $options),
            'options' => $options,
        ])->data;
    }

    /**
     * Thumbs up (1), down (-1) or removed (null) on an assistant message.
     *
     * @param 1|-1|null $value
     * @param array{memberSession?: string, clientIp?: string, timeoutMs?: int} $options
     * @return array{messageId: string, feedback: int|null}
     */
    public function feedback(string $conversationId, string $conversationToken, string $messageId, ?int $value, array $options = []): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/chat/conversations/' . ModuleContext::seg($conversationId) . '/feedback',
            'body' => ['messageId' => $messageId, 'value' => $value],
            'headers' => $this->headers($conversationToken, $options),
            'options' => $options,
        ])->data;
    }

    /**
     * Sends a 6-digit code to an email address (202, always: also for an unknown address, no oracle). Valid 10 minutes; a new code replaces the previous one.
     * 3 codes per hour per address, 429 `rate_limited` after 10 requests per hour per visitor IP; 409 `conversation_closed` when the conversation
     * belongs to a member or to another confirmed address.
     *
     * @param array{memberSession?: string, clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function verifyRequest(string $conversationId, string $conversationToken, string $email, array $options = []): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/chat/conversations/' . ModuleContext::seg($conversationId) . '/verify/request',
            'body' => ['email' => $email],
            'headers' => $this->headers($conversationToken, $options),
            'options' => $options,
        ])->data;
    }

    /**
     * Confirms the code: the address becomes the identity of the conversation for 60 minutes. 400 `verification_failed` (wrong, expired or 5 wrong attempts).
     *
     * @param array{memberSession?: string, clientIp?: string, timeoutMs?: int} $options
     * @return array{conversation: array<string, mixed>}
     */
    public function verifyConfirm(string $conversationId, string $conversationToken, string $code, array $options = []): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/chat/conversations/' . ModuleContext::seg($conversationId) . '/verify/confirm',
            'body' => ['code' => $code],
            'headers' => $this->headers($conversationToken, $options),
            'options' => $options,
        ])->data;
    }

    /**
     * Closes the conversation (idempotent).
     *
     * @param array{memberSession?: string, clientIp?: string, timeoutMs?: int} $options
     * @return array{conversation: array<string, mixed>}
     */
    public function close(string $conversationId, string $conversationToken, array $options = []): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/chat/conversations/' . ModuleContext::seg($conversationId) . '/close',
            'headers' => $this->headers($conversationToken, $options),
            'options' => $options,
        ])->data;
    }

    /**
     * Server token with `chat:read`: the conversations of the project (no messages).
     *
     * @param array{from?: string, to?: string, status?: string, page?: int, limit?: int} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->execute(['path' => '/chat/conversations', 'query' => Arr::pick($params, ['from', 'to', 'status', 'page', 'limit']), 'options' => $options])->data;
    }
}
