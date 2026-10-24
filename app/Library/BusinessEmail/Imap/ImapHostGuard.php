<?php

namespace App\Library\BusinessEmail\Imap;

use App\Exceptions\BusinessEmail\BusinessEmailImapException as Imap;
use Closure;

/**
 * SSRF / unsafe-host protection for customer-supplied mail servers.
 *
 * The host must be a public DNS NAME (never an IP literal, `localhost`, a
 * single label or an internal suffix) and EVERY address it resolves to must be
 * public. The connection is then made to the RESOLVED address (the caller
 * verifies the certificate against the name), so a DNS answer that changes
 * between the check and the connect cannot redirect traffic inward.
 */
final class ImapHostGuard
{
    private const BLOCKED_SUFFIXES = ['.local', '.localhost', '.internal', '.lan', '.home', '.corp', '.intranet', '.private', '.test', '.invalid', '.example', '.onion'];

    /** @param Closure(string): list<string>|null $resolver host => ip list; tests inject one */
    public function __construct(private readonly ?Closure $resolver = null)
    {
    }

    /**
     * @return string the address to connect to
     * @throws Imap
     */
    public function resolveSafe(string $host): string
    {
        $host = strtolower($host);

        if (ImapSettings::host($host) === null || $host === 'localhost') {
            throw new Imap(Imap::UNSAFE_HOST);
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new Imap(Imap::UNSAFE_HOST);
            }
        }

        $addresses = $this->resolver !== null ? ($this->resolver)($host) : $this->dnsLookup($host);

        if ($addresses === []) {
            throw new Imap(Imap::UNRESOLVABLE);
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw new Imap(Imap::UNSAFE_HOST);
            }
        }

        return $addresses[0];
    }

    public function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Carrier-grade NAT (100.64.0.0/10) and IPv4-mapped IPv6 are not covered by the flags.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            return ! ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
        }

        return ! str_starts_with(strtolower($ip), '::ffff:');
    }

    /** @return list<string> */
    private function dnsLookup(string $host): array
    {
        $ips = [];

        foreach ((array) @dns_get_record($host, DNS_A) as $record) {
            if (isset($record['ip'])) {
                $ips[] = (string) $record['ip'];
            }
        }

        foreach ((array) @dns_get_record($host, DNS_AAAA) as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
