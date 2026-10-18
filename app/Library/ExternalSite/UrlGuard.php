<?php

namespace App\Library\ExternalSite;

/**
 * External Website Audit Mode V1 — the SSRF gate. Every URL the crawler would
 * ever fetch (the start page, every redirect target, every discovered link,
 * robots.txt, every sitemap) passes through validate() FIRST, from scratch.
 *
 * It refuses, with a stable reason code:
 *   - anything that is not http or https                       scheme_not_allowed
 *   - credentials in the URL                                    credentials_in_url
 *   - an IP literal in ANY spelling (127.0.0.1, 2130706433,
 *     0x7f.1, [::1]) — a customer site is reached by name       ip_literal_not_allowed
 *   - localhost and internal/private naming (.local, .internal,
 *     .lan, single-label hosts ...)                             host_not_allowed
 *   - a port other than the allowed ones (80, 443)              port_not_allowed
 *   - a name that does not resolve                              dns_failed
 *   - a name with ANY resolved address that is not public
 *     (private, loopback, link-local incl. the metadata
 *     endpoint, ULA, multicast, reserved, mapped/NAT64 forms)   private_destination
 *
 * and returns a ValidatedTarget pinned to one validated public address. The
 * validated address — not the hostname — is what the transport connects to
 * (curl is told to resolve the name to it), so a DNS answer that changes
 * between this check and the connection cannot redirect the request: a
 * pre-request lookup alone would NOT be enough, which is why the pin exists.
 */
final class UrlGuard
{
    private const FORBIDDEN_SUFFIXES = ['localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp', 'private', 'invalid'];

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly ExternalSiteConfig $config,
    ) {
    }

    /**
     * @throws ExternalSiteException
     */
    public function validate(string $url): ValidatedTarget
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new ExternalSiteException('url_malformed');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'])) {
            throw new ExternalSiteException('url_malformed');
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new ExternalSiteException('scheme_not_allowed');
        }

        if (! isset($parts['host'])) {
            throw new ExternalSiteException('url_malformed');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ExternalSiteException('credentials_in_url');
        }

        $host = $this->normalizeHost((string) $parts['host']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($port, $this->config->allowedPorts(), true)) {
            throw new ExternalSiteException('port_not_allowed');
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw new ExternalSiteException('dns_failed');
        }

        foreach ($ips as $ip) {
            if (! IpClassifier::isPublic($ip)) {
                throw new ExternalSiteException('private_destination');
            }
        }

        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $normalized = $scheme . '://' . $host . ($isDefaultPort ? '' : ':' . $port) . $path . $query;

        return new ValidatedTarget($normalized, $scheme, $host, $port, $ips[0]);
    }

    /**
     * The syntactic half, with no DNS: used by the crawler to decide whether a
     * discovered link is even worth considering. Throws like validate().
     *
     * @throws ExternalSiteException
     */
    public function normalizeHost(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));

        if ($host === '') {
            throw new ExternalSiteException('url_malformed');
        }

        // Any IP literal, however spelled: bracketed IPv6, dotted quad, a bare
        // number (decimal/hex/octal IPv4 forms) — none is a customer website.
        if (str_contains($host, ':') || str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$/i', $host) === 1) {
            throw new ExternalSiteException('ip_literal_not_allowed');
        }

        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;

            if (! is_string($ascii) || $ascii === '') {
                throw new ExternalSiteException('host_not_allowed');
            }

            $host = strtolower($ascii);
        }

        if (strlen($host) > 253 || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1) {
            throw new ExternalSiteException('host_not_allowed');
        }

        $labels = explode('.', $host);

        if (in_array(end($labels), self::FORBIDDEN_SUFFIXES, true) || $host === 'metadata.google.internal') {
            throw new ExternalSiteException('host_not_allowed');
        }

        return $host;
    }
}
