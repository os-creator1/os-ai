<?php

namespace App\Library\ExternalSite;

/**
 * A URL that passed every check, bound to the ONE public IP address the
 * connection must use. The crawler hands this (and nothing else) to the
 * transport, so a second DNS lookup can never redirect the request elsewhere.
 */
final class ValidatedTarget
{
    public function __construct(
        public readonly string $url,
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
    ) {
    }
}
