<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Webhooks;

/** The outcome of {@see WebhookVerifier::verify()}. On a failure respond 401 and process nothing. */
final class VerifyResult
{
    public const MISSING_SIGNATURE = 'missing_signature';
    public const MALFORMED = 'malformed';
    public const EXPIRED = 'expired';
    public const MISMATCH = 'mismatch';
    public const INVALID_PAYLOAD = 'invalid_payload';
    public const MISSING_SECRET = 'missing_secret';

    /**
     * @param array{id: string, type: string, createdAt: string, projectId: string, data: array<string, mixed>}|null $event
     * @param string|null $reason one of the constants above when not ok
     */
    private function __construct(public readonly bool $ok, public readonly ?array $event, public readonly ?string $reason)
    {
    }

    /** @param array{id: string, type: string, createdAt: string, projectId: string, data: array<string, mixed>} $event */
    public static function success(array $event): self
    {
        return new self(true, $event, null);
    }

    public static function failure(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
