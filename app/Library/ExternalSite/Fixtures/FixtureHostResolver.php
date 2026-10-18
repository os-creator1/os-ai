<?php

namespace App\Library\ExternalSite\Fixtures;

use App\Library\ExternalSite\HostResolver;

/**
 * A resolver that answers from a map instead of DNS, so the guard's DNS checks
 * (a private answer, a mixed answer, no answer) can be exercised without a
 * network and the fixture site can resolve to a public address.
 */
final class FixtureHostResolver implements HostResolver
{
    /** @param array<string, list<string>> $hosts host => addresses */
    public function __construct(private array $hosts = [])
    {
        $this->hosts += FixtureSite::hosts();
    }

    public function set(string $host, array $ips): self
    {
        $this->hosts[strtolower($host)] = $ips;

        return $this;
    }

    public function resolve(string $host): array
    {
        return $this->hosts[strtolower($host)] ?? [];
    }
}
