<?php

namespace App\Library\ExternalSite;

/**
 * How the configured address actually behaved when the crawler first asked for it.
 * The states are deliberately separate, because they mean different things to the
 * owner and must never be collapsed into "your site is down":
 *
 *   reachable            the address answered with a page
 *   redirects_elsewhere  it redirects to a different website (the destination is shown)
 *   crawler_blocked      a firewall / rate limit turned the crawler away; nothing is known about the site
 *   robots_blocked       the site's robots.txt asks crawlers (us included) to stay out
 *   http_error           the website itself answered with an error status
 *   tls_error            the secure-connection (certificate) handshake failed
 *   network_error        the name did not resolve, or the connection / request timed out
 *   invalid_address      the address was refused before any request (malformed, private, unsupported)
 */
final class ConnectionResult
{
    public const REACHABLE = 'reachable';

    public const REDIRECTS_ELSEWHERE = 'redirects_elsewhere';

    public const CRAWLER_BLOCKED = 'crawler_blocked';

    public const ROBOTS_BLOCKED = 'robots_blocked';

    public const HTTP_ERROR = 'http_error';

    public const TLS_ERROR = 'tls_error';

    public const NETWORK_ERROR = 'network_error';

    public const INVALID_ADDRESS = 'invalid_address';

    /**
     * @param  list<array{url: string, status: int}>  $chain
     */
    public function __construct(
        public readonly string $state,
        public readonly ?string $finalUrl = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $reason = null,
        public readonly array $chain = [],
    ) {
    }

    public function isReachable(): bool
    {
        return $this->state === self::REACHABLE;
    }
}
