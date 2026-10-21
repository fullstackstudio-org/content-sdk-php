<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

use RuntimeException;
use Throwable;

/**
 * A single error shape for everything that goes wrong. The API always responds `{ "error": { "code", "message" } }`;
 * branch on `errorCode()` (or `status()`), never on the message.
 *
 * Besides the codes the API returns (docs/api-v1.md: `invalid_token`, `not_found`, `rate_limited`, ...) the SDK adds, with
 * status 0 (there is no response): `network_error`, `timeout`, `invalid_response` and `invalid_config`.
 *
 * The message never contains the token or the body of a response that was not valid JSON.
 */
class ContentException extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly ?string $requestId = null,
        private readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** HTTP status, or 0 if no response arrived. */
    public function status(): int
    {
        return $this->status;
    }

    /** Stable code (see above). */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** `X-FSC-Request-Id`: quote it in a support request. */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /** Seconds from `Retry-After` (on a 429), otherwise null. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /** The project has the module behind this route switched off (404 `module_disabled`). */
    public function isModuleDisabled(): bool
    {
        return $this->status === 404 && $this->errorCode === 'module_disabled';
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429 || $this->errorCode === 'rate_limited';
    }
}
