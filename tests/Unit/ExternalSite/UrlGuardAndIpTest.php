<?php

namespace Tests\Unit\ExternalSite;

use App\Library\ExternalSite\ExternalSiteConfig;
use App\Library\ExternalSite\ExternalSiteException;
use App\Library\ExternalSite\Fixtures\FixtureHostResolver;
use App\Library\ExternalSite\IpClassifier;
use App\Library\ExternalSite\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The SSRF gate. Explicit refusals for every destination class the crawler must
 * never contact: localhost, loopback, private ranges, link-local (incl. the cloud
 * metadata endpoint), IPv6 equivalents, obfuscated IP spellings, non-http
 * schemes, credentials, odd ports, and names whose DNS answer is (even partly)
 * private.
 */
class UrlGuardAndIpTest extends TestCase
{
    private function guard(?FixtureHostResolver $resolver = null): UrlGuard
    {
        return new UrlGuard($resolver ?? new FixtureHostResolver(['public-site.example' => ['93.184.216.34']]), new ExternalSiteConfig(['allowed_ports' => [80, 443]]));
    }

    private function reason(string $url, ?FixtureHostResolver $resolver = null): string
    {
        try {
            $this->guard($resolver)->validate($url);
        } catch (ExternalSiteException $e) {
            return $e->reason;
        }

        return 'ALLOWED';
    }

    /** @return array<string, array{0: string}> */
    public static function privateOrReservedAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'], 'loopback high' => ['127.255.255.254'], 'this network' => ['0.0.0.0'],
            '10/8' => ['10.1.2.3'], '172.16/12' => ['172.16.5.5'], '172.31' => ['172.31.255.255'], '192.168/16' => ['192.168.1.10'],
            'link-local' => ['169.254.1.1'], 'cloud metadata' => ['169.254.169.254'], 'carrier-grade NAT' => ['100.64.0.1'],
            'multicast' => ['224.0.0.1'], 'reserved' => ['240.0.0.1'], 'broadcast' => ['255.255.255.255'], 'benchmark' => ['198.18.0.1'],
            'doc net 1' => ['192.0.2.1'], 'doc net 2' => ['198.51.100.1'], 'doc net 3' => ['203.0.113.1'],
            'ipv6 loopback' => ['::1'], 'ipv6 unspecified' => ['::'], 'ipv6 unique-local' => ['fd12:3456::1'], 'ipv6 fc' => ['fc00::1'],
            'ipv6 link-local' => ['fe80::1'], 'ipv6 site-local' => ['fec0::1'], 'ipv6 multicast' => ['ff02::1'], 'ipv6 doc' => ['2001:db8::1'],
            'mapped loopback' => ['::ffff:127.0.0.1'], 'mapped private' => ['::ffff:10.0.0.1'], 'mapped metadata' => ['::ffff:169.254.169.254'],
            'nat64 private' => ['64:ff9b::a00:1'], '6to4' => ['2002:c0a8:0101::1'], 'teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
            'not an ip' => ['not-an-ip'], 'empty' => [''],
        ];
    }

    #[DataProvider('privateOrReservedAddresses')]
    public function test_non_public_addresses_are_refused_by_the_classifier(string $ip): void
    {
        $this->assertFalse(IpClassifier::isPublic($ip), $ip);
    }

    /** @return array<string, array{0: string}> */
    public static function publicAddresses(): array
    {
        return ['example' => ['93.184.216.34'], 'google dns' => ['8.8.8.8'], 'cloudflare' => ['1.1.1.1'], 'just outside 172.16/12' => ['172.32.0.1'], 'just outside 100.64/10' => ['100.128.0.1'],
            'public ipv6' => ['2606:4700:4700::1111'], 'mapped public' => ['::ffff:8.8.8.8'], 'just outside 169.254' => ['169.255.0.1']];
    }

    #[DataProvider('publicAddresses')]
    public function test_public_addresses_are_allowed_by_the_classifier(string $ip): void
    {
        $this->assertTrue(IpClassifier::isPublic($ip), $ip);
    }

    public function test_a_normal_public_site_is_allowed_and_pinned_to_its_validated_address(): void
    {
        $target = $this->guard()->validate('https://Public-Site.example/Path?q=1#frag');

        $this->assertSame('https://public-site.example/Path?q=1', $target->url);
        $this->assertSame('93.184.216.34', $target->ip);
        $this->assertSame(443, $target->port);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedUrls(): array
    {
        return [
            'localhost' => ['http://localhost/', 'host_not_allowed'],
            'LOCALHOST uppercase' => ['http://LOCALHOST/admin', 'host_not_allowed'],
            'localhost trailing dot' => ['http://localhost./', 'host_not_allowed'],
            'subdomain of localhost' => ['http://app.localhost/', 'host_not_allowed'],
            'loopback ip' => ['http://127.0.0.1/', 'ip_literal_not_allowed'],
            'loopback with port' => ['http://127.0.0.1:80/', 'ip_literal_not_allowed'],
            'loopback ipv6' => ['http://[::1]/', 'ip_literal_not_allowed'],
            'ipv6 with port' => ['http://[::1]:80/', 'ip_literal_not_allowed'],
            'private 10' => ['http://10.0.0.5/', 'ip_literal_not_allowed'],
            'private 192.168' => ['https://192.168.0.1/router', 'ip_literal_not_allowed'],
            'private 172.16' => ['http://172.16.0.1/', 'ip_literal_not_allowed'],
            'private ipv6' => ['http://[fd00::1]/', 'ip_literal_not_allowed'],
            'link-local' => ['http://169.254.10.10/', 'ip_literal_not_allowed'],
            'metadata endpoint' => ['http://169.254.169.254/latest/meta-data/', 'ip_literal_not_allowed'],
            'metadata hostname' => ['http://metadata.google.internal/computeMetadata/v1/', 'host_not_allowed'],
            'decimal ip' => ['http://2130706433/', 'ip_literal_not_allowed'],
            'hex ip' => ['http://0x7f000001/', 'ip_literal_not_allowed'],
            'dotted hex ip' => ['http://0x7f.0.0.1/', 'ip_literal_not_allowed'],
            'octal ip' => ['http://0177.0.0.1/', 'ip_literal_not_allowed'],
            'short ip' => ['http://127.1/', 'ip_literal_not_allowed'],
            'public ip literal' => ['http://93.184.216.34/', 'ip_literal_not_allowed'],
            '.internal' => ['http://db.internal/', 'host_not_allowed'],
            '.local' => ['http://printer.local/', 'host_not_allowed'],
            '.lan' => ['http://nas.lan/', 'host_not_allowed'],
            'single label' => ['http://intranet/', 'host_not_allowed'],
            'file scheme' => ['file:///etc/passwd', 'scheme_not_allowed'],
            'gopher scheme' => ['gopher://public-site.example/', 'scheme_not_allowed'],
            'ftp scheme' => ['ftp://public-site.example/file', 'scheme_not_allowed'],
            'javascript scheme' => ['javascript:alert(1)', 'scheme_not_allowed'],
            'data scheme' => ['data:text/html,<script>alert(1)</script>', 'scheme_not_allowed'],
            'credentials' => ['http://user:pass@public-site.example/', 'credentials_in_url'],
            'credentials trick' => ['http://public-site.example@127.0.0.1/', 'credentials_in_url'],
            'unusual port' => ['http://public-site.example:8080/', 'port_not_allowed'],
            'ssh port' => ['http://public-site.example:22/', 'port_not_allowed'],
            'redis port' => ['http://public-site.example:6379/', 'port_not_allowed'],
            'whitespace' => ["http://public-site.example/a b", 'url_malformed'],
            'backslash' => ['http://public-site.example\\@127.0.0.1/', 'url_malformed'],
            'no host' => ['http:///path', 'url_malformed'],
            'empty' => ['', 'url_malformed'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_every_dangerous_url_is_refused_with_a_stable_reason(string $url, string $reason): void
    {
        $this->assertSame($reason, $this->reason($url), $url);
    }

    public function test_a_public_looking_name_that_resolves_to_a_private_address_is_refused(): void
    {
        foreach (['10.0.0.8', '127.0.0.1', '169.254.169.254', '192.168.1.1', '::1', 'fd00::1', '::ffff:10.0.0.1'] as $ip) {
            $resolver = new FixtureHostResolver(['looks-public.example' => [$ip]]);

            $this->assertSame('private_destination', $this->reason('https://looks-public.example/', $resolver), $ip);
        }
    }

    public function test_one_private_record_among_public_ones_is_still_a_refusal(): void
    {
        $resolver = new FixtureHostResolver(['mixed.example' => ['93.184.216.34', '10.0.0.9']]);

        $this->assertSame('private_destination', $this->reason('https://mixed.example/', $resolver));
    }

    public function test_a_name_that_does_not_resolve_is_refused(): void
    {
        $this->assertSame('dns_failed', $this->reason('https://nowhere.example/', new FixtureHostResolver()));
    }
}
