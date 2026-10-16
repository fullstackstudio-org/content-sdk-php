<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Http;

use RuntimeException;
use Throwable;

/** No response arrived (connection refused, DNS, TLS, timeout). The message must not contain the request headers. */
final class TransportException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $timedOut = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
