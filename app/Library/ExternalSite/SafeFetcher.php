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
        $chain = [];

        while (true) {
            try {
                $target = $this->guard->validate($current);
            } catch (ExternalSiteException $e) {
                return new FetchResult($url, $current, 0, '', '', $e->reason, $redirects, null, $chain);
            }

            if (! in_array($target->host, $allowedHosts, true)) {
                return new FetchResult($url, $current, 0, '', '', 'foreign_domain', $redirects, null, $chain);
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
                // The server answered but its body was refused (too large / wrong type): keep the real status and headers.
                $answered = in_array($response->error, ['too_large', 'content_type_not_allowed'], true) && $response->status > 0;

                return new FetchResult($url, $target->url, $answered ? $response->status : 0, $answered ? $response->contentType() : '', '', $response->error, $redirects, null, $chain, $answered ? $this->keepHeaders($response) : []);
            }

            if ($response->status >= 300 && $response->status < 400 && $response->header('location') !== null) {
                $chain[] = ['url' => $target->url, 'status' => $response->status];

                if ($redirects >= $this->config->maxRedirects()) {
                    return new FetchResult($url, $target->url, $response->status, '', '', 'too_many_redirects', $redirects, null, $chain);
                }

                $next = UrlResolver::resolve($target->url, (string) $response->header('location'));

                if ($next === null) {
                    return new FetchResult($url, $target->url, $response->status, '', '', 'url_malformed', $redirects, null, $chain);
                }

                if (in_array($next, array_column($chain, 'url'), true) || $next === $target->url) {
                    return new FetchResult($url, $next, $response->status, '', '', 'redirect_loop', $redirects, null, $chain);
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
                $chain,
                $this->keepHeaders($response),
            );
        }
    }

    /** @return array<string, string> the response headers the audit reads */
    private function keepHeaders(TransportResponse $response): array
    {
        $kept = [];

        foreach (['content-length', 'server', 'retry-after', 'cf-mitigated', 'cf-ray', 'x-sucuri-id', 'x-iinfo', 'x-cdn', 'location', 'link', 'last-modified', 'content-encoding'] as $name) {
            $value = $response->header($name);

            if ($value !== null) {
                $kept[$name] = mb_substr($value, 0, 300);
            }
        }

        return $kept;
    }
}
