<?php

namespace App\Library\ExternalSite;

/**
 * The outcome of one SafeFetcher::fetch(): the FINAL url after validated
 * redirects, its status, content type and (bounded) body, or an `error` reason.
 * `status` is 0 when nothing was fetched.
 */
final class FetchResult
{
    public function __construct(
        public readonly string $requestedUrl,
        public readonly string $finalUrl,
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $body,
        public readonly ?string $error,
        public readonly int $redirects = 0,
        public readonly ?string $robotsTag = null,
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
}
