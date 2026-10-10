<?php

namespace App\Library\ExternalSite;

/**
 * One page the crawler fetched (or failed to): the final URL, how the fetch went
 * and, for an HTML page, its extracted values. `status` 0 with an `error` means
 * the page could not be fetched at all.
 */
final class CrawledPage
{
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly ?string $error,
        public readonly ?ExtractedPage $facts,
        public readonly bool $xRobotsNoindex = false,
        public readonly int $brokenLinks = 0,
    ) {
    }

    public function isReachable(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 400;
    }

    public function withBrokenLinks(int $count): self
    {
        return new self($this->url, $this->status, $this->error, $this->facts, $this->xRobotsNoindex, $count);
    }
}
