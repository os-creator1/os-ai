<?php

namespace App\Library\ExternalSite;

/**
 * External Website Audit Mode V1 — fetches ONE resource safely.
 *
 * GET only; every URL (the first and every redirect target) is validated from
 * scratch by the UrlGuard, so a public-looking address that redirects to a
 * private one is refused at the hop, not followed. Redirects are followed by hand,
 * bounded, and may only stay on the SITE being audited: the caller passes the set
 * of hosts that count as "the same site" (the start host and its www / non-www
 * twin), and a redirect anywhere else is refused as a foreign domain.
 *
 * Failures are returned as a short reason code, never as text from the remote
 * side: no response body or header ever reaches a log or an error message.
 */
final class SafeFetcher
{
    public function __construct(
        private readonly UrlGuard $guard,
        private readonly ExternalSiteTransport $transport,
        private readonly ExternalSiteConfig $config,
    ) {
    }

    /**
     * @param  list<string>  $allowedHosts  hosts that count as the audited site (lowercase)
     * @param  list<string>  $contentTypes  accepted response media types (prefix match)
     */
    public function fetch(
        string $url,
        array $allowedHosts,
        string $accept = 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
        array $contentTypes = ['text/html', 'application/xhtml+xml'],
        ?int $maxBytes = null,
    ): FetchResult {
        $current = $url;
        $redirects = 0;

        while (true) {
            try {
                $target = $this->guard->validate($current);
            } catch (ExternalSiteException $e) {
                return new FetchResult($url, $current, 0, '', '', $e->reason, $redirects);
            }

            if (! in_array($target->host, $allowedHosts, true)) {
                return new FetchResult($url, $current, 0, '', '', 'foreign_domain', $redirects);
            }

            $response = $this->transport->get(new TransportRequest(
                $target,
                $maxBytes ?? $this->config->maxResponseBytes(),
                $this->config->connectTimeout(),
                $this->config->requestTimeout(),
                $this->config->userAgent(),
                $accept,
                $contentTypes,
            ));

            if ($response->error !== null) {
                return new FetchResult($url, $target->url, 0, '', '', $response->error, $redirects);
            }

            if ($response->status >= 300 && $response->status < 400 && $response->header('location') !== null) {
                if ($redirects >= $this->config->maxRedirects()) {
                    return new FetchResult($url, $target->url, $response->status, '', '', 'too_many_redirects', $redirects);
                }

                $next = UrlResolver::resolve($target->url, (string) $response->header('location'));

                if ($next === null) {
                    return new FetchResult($url, $target->url, $response->status, '', '', 'url_malformed', $redirects);
                }

                $current = $next;
                $redirects++;

                continue;
            }

            return new FetchResult(
                $url,
                $target->url,
                $response->status,
                $response->contentType(),
                $response->body,
                null,
                $redirects,
                $response->header('x-robots-tag'),
            );
        }
    }
}
