<?php

namespace App\Library\ExternalSite;

/**
 * Real DNS: every A and AAAA record of the host. The caller validates ALL of
 * them (one private record among public ones is still a refusal) and then PINS
 * the connection to a validated address, so the HTTP client never resolves the
 * name a second time.
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = [];

        foreach ([[DNS_A, 'ip'], [DNS_AAAA, 'ipv6']] as [$type, $field]) {
            $records = @dns_get_record($host, $type);

            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record[$field]) && is_string($record[$field])) {
                    $ips[] = $record[$field];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
