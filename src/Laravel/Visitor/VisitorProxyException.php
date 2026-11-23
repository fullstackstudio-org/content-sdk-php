<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Visitor;

use RuntimeException;

/**
 * A visitor request that could not be answered with the Hub's page. The message holds only the reason and the scrubbed path
 * ({@see VisitorRoutes::scrub()}): never a token, the server token, a cURL error text (it can echo the URL) or the Hub's body.
 */
final class VisitorProxyException extends RuntimeException
{
    /** The Hub could not be reached (DNS, connection, TLS). */
    public const UNREACHABLE = 'unreachable';

    /** The Hub did not answer within the timeout. */
    public const TIMEOUT = 'timeout';

    /** The Hub's answer was larger than the 2 MB the proxy buffers. */
    public const RESPONSE_TOO_LARGE = 'response_too_large';

    /** The visitor's body was larger than the proxy forwards (url-encoded bodies, 1 MB). */
    public const REQUEST_TOO_LARGE = 'request_too_large';

    /** The Hub refused the site's server token (401). */
    public const REFUSED = 'refused';

    /** The Hub does not (or no longer) trust this site's origin for visitor pages (409 `site_not_verified`): a neutral 404, not reported. */
    public const NOT_VERIFIED = 'not_verified';

    /** The visitor's body cannot be forwarded as it is (a multipart field name the Hub would read differently): 400, not reported. */
    public const BAD_REQUEST = 'bad_request';

    /** No server token or no usable `content.base_url`. */
    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'Visitor page proxy: ' . $reason);
    }

    /** The same reason with the request it belongs to, for a report. */
    public function forRequest(string $method, string $path): self
    {
        return new self($this->reason, 'Visitor page proxy: ' . $this->reason . ' on ' . strtoupper($method) . ' ' . VisitorRoutes::scrub($path));
    }
}
