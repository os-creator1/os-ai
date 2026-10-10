<?php

namespace App\Library\ExternalSite;

/**
 * External Website Audit Mode V1 — "is this a destination the crawler may ever
 * connect to?". A PUBLIC, globally routable unicast address, and nothing else.
 *
 * Refused (IPv4): 0/8 this-network, 10/8, 100.64/10 carrier-grade NAT, 127/8
 * loopback, 169.254/16 link-local (which includes the cloud metadata endpoint
 * 169.254.169.254), 172.16/12, 192.0.0/24, 192.0.2/24, 192.88.99/24, 192.168/16,
 * 198.18/15 benchmarking, 198.51.100/24, 203.0.113/24, 224/4 multicast and
 * 240/4 reserved (which includes the broadcast address).
 *
 * Refused (IPv6): :: and ::1, IPv4-mapped (::ffff:0:0/96) and NAT64
 * (64:ff9b::/96, 64:ff9b:1::/48) addresses are judged by the IPv4 address they
 * embed, 100::/64 discard, 2001::/23 (IETF protocol assignments incl. Teredo),
 * 2001:db8::/32 documentation, 2002::/16 6to4, fc00::/7 unique-local, fe80::/10
 * link-local, fec0::/10 site-local and ff00::/8 multicast.
 *
 * It is a deny list over a malformed-input-safe parse: anything that is not a
 * valid IP literal is "not public". Pure; no DNS, no network.
 */
final class IpClassifier
{
    /** @var list<array{0: string, 1: int}> */
    private const V4_DENY = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
        ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
        ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    /** @var list<array{0: string, 1: int}> */
    private const V6_DENY = [
        ['::', 128], ['::1', 128], ['100::', 64], ['2001::', 23], ['2001:db8::', 32], ['2002::', 16],
        ['fc00::', 7], ['fe80::', 10], ['fec0::', 10], ['ff00::', 8],
    ];

    public static function isPublic(string $ip): bool
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 4) {
            return self::publicV4($binary);
        }

        // IPv4-mapped and NAT64: the embedded IPv4 address decides.
        if (self::inPrefix($binary, (string) inet_pton('::ffff:0:0'), 96) || self::inPrefix($binary, (string) inet_pton('64:ff9b::'), 96)) {
            return self::publicV4(substr($binary, 12, 4));
        }

        if (self::inPrefix($binary, (string) inet_pton('64:ff9b:1::'), 48)) {
            return false;
        }

        foreach (self::V6_DENY as [$network, $bits]) {
            if (self::inPrefix($binary, (string) inet_pton($network), $bits)) {
                return false;
            }
        }

        return true;
    }

    private static function publicV4(string $binary): bool
    {
        foreach (self::V4_DENY as [$network, $bits]) {
            if (self::inPrefix($binary, (string) inet_pton($network), $bits)) {
                return false;
            }
        }

        return true;
    }

    /** True when the first $bits bits of both packed addresses are equal. */
    private static function inPrefix(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);

        if ($bytes > 0 && substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
