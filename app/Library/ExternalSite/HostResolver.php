<?php

namespace App\Library\ExternalSite;

/**
 * Resolves a hostname to the IP addresses it currently points at. A seam so the
 * guard's DNS checks can be tested without a network, and so the crawler can be
 * pointed at a fixture site in tests and browser acceptance. The real
 * implementation is DnsHostResolver.
 */
interface HostResolver
{
    /** @return list<string> every A and AAAA address; empty when the name does not resolve */
    public function resolve(string $host): array;
}
