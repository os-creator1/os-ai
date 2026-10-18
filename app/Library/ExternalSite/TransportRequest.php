<?php

namespace App\Library\ExternalSite;

/**
 * One GET the transport is allowed to make: the validated target (already bound
 * to a public IP) plus the hard bounds. The transport enforces `maxBytes` and
 * the content-type allow-list WHILE reading, so an oversized or wrong-typed
 * response is never buffered.
 */
final class TransportRequest
{
    /** @param list<string> $allowedContentTypes lowercase media-type prefixes, e.g. ['text/html', 'application/xhtml+xml'] */
    public function __construct(
        public readonly ValidatedTarget $target,
        public readonly int $maxBytes,
        public readonly int $connectTimeout,
        public readonly int $timeout,
        public readonly string $userAgent,
        public readonly string $accept,
        public readonly array $allowedContentTypes,
    ) {
    }
}
