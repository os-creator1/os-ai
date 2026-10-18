<?php

namespace App\Library\ExternalSite;

/**
 * The outcome of one SafeFetcher::fetch(): the FINAL url after validated
 * redirects, its status, content type and (bounded) body, or an `error` reason.
 * `status` is 0 when nothing was fetched.
 *
 * `chain` lists the redirect hops that were followed ([url, status] for each hop
 * that answered 3xx), so the audit can show WHERE a redirect went. `headers` holds
 * the few response headers the audit reads (lowercase). When the transport reached
 * the server but refused the body (a response too large, or of a type we did not
 * ask for), `status` still carries the real HTTP status and `error` says why the
 * body was not read — which is what lets a size or content-type probe work
 * without downloading anything.
 */
final class FetchResult
{
    /**
     * @param  list<array{url: string, status: int}>  $chain
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $requestedUrl,
        public readonly string $finalUrl,
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $body,
        public readonly ?string $error,
        public readonly int $redirects = 0,
        public readonly ?string $robotsTag = null,
        public readonly array $chain = [],
        public readonly array $headers = [],
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    public function xRobotsNoindex(): bool
    {
        return $this->robotsTag !== null && stripos($this->robotsTag, 'noindex') !== false;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
