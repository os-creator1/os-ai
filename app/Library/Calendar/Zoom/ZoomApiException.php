<?php

namespace App\Library\Calendar\Zoom;

use RuntimeException;

/**
 * A classified Zoom failure. The message never carries a token, a response body or a meeting URL: only a stable code.
 *
 *   auth          the access/refresh token is no longer valid (revoked, expired, wrong app)  -> reconnect needed
 *   rate_limited  429                                                                         -> retry after $retryAfter
 *   transient     network error, timeout, 5xx                                                -> retry with backoff
 *   not_found     the meeting no longer exists                                               -> success for a delete
 *   permanent     any other 4xx (bad request, missing scope, host not licensed, ...)        -> do not retry
 */
class ZoomApiException extends RuntimeException
{
    public function __construct(
        public readonly string $kind,
        public readonly string $errorCode,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct('zoom:'.$kind.':'.$errorCode);
    }

    public function retryable(): bool
    {
        return in_array($this->kind, ['rate_limited', 'transient'], true);
    }
}
