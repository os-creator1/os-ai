<?php

namespace Tests\Support\WebsiteAcceptance;

/**
 * Website V1 full-site acceptance — what the audit needs to know about the
 * site it is judging (the intended truth, taken from the application's own
 * models — never from the HTML being judged).
 */
final class AuditContext
{
    /**
     * @param  'preview'|'platform'|'custom_domain'  $surface
     * @param  string  $origin  scheme://host of the pages being audited
     * @param  ?string  $canonicalOrigin  the origin every canonical must use (null: no canonical expected)
     * @param  bool  $indexingAllowed  true when the surface may carry `index` (custom domain only)
     * @param  string  $publicRoot  filesystem root the page's /images URLs resolve against
     * @param  array<int, array{uid: string, slug: ?string, title: string, type: string, url: string, noindex: bool, service: ?string, area: ?string}>  $manifest
     * @param  array<int, string>  $allowedImageDirs  directories (relative to the public root) an image may come from
     * @param  array<string, string>  $packages  package name => the canonical price label (e.g. "USD 699.00")
     */
    public function __construct(
        public readonly string $surface,
        public readonly string $businessName,
        public readonly string $origin,
        public readonly ?string $canonicalOrigin,
        public readonly bool $indexingAllowed,
        public readonly string $publicRoot,
        public readonly array $manifest,
        public readonly array $allowedImageDirs,
        public readonly array $packages,
    ) {}

    /** @return ?array<string, mixed> */
    public function entryForUrl(string $url): ?array
    {
        foreach ($this->manifest as $entry) {
            if ($entry['url'] === $url) {
                return $entry;
            }
        }

        return null;
    }
}
