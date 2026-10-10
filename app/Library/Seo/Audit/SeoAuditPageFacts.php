<?php

namespace App\Library\Seo\Audit;

/**
 * External Website Audit Mode V1 — one page, as the audit rules see it, with no
 * trace of WHERE the page came from.
 *
 * This is the SOURCE-NEUTRAL evaluator input. A hosted Website revision
 * snapshot (HostedRevisionAuditSource) and a crawled external site
 * (ExternalSiteAuditSource) both produce exactly this object, and the single
 * SeoAuditEvaluator consumes it. It carries only values the rules need — never
 * a DOM, a response body, headers or a snapshot section — so remote content
 * cannot travel deep into the engine.
 *
 * A fact is `null` when the SOURCE does not supply it, and a rule that needs a
 * fact does not fire without it. That is how one rule set serves two kinds of
 * site honestly: a hosted site's canonical tag, Open Graph tags and structured
 * data are the PLATFORM'S job (Contract 18 §8.7, G-2/G-3), so the hosted source
 * never supplies them and they can never become findings against a hosted
 * customer, while an external site's owner controls them, so the crawler does.
 */
final class SeoAuditPageFacts
{
    public function __construct(
        /** The key a finding stores as `page_uid`: a snapshot page uid, or a crawled page's id. */
        public readonly string $key,
        /** Owner-facing page name. */
        public readonly string $name,
        /** The title the OWNER set (hosted: the SEO title; external: the <title> text). Blank = none. */
        public readonly ?string $explicitTitle,
        /** The title searchers actually get (hosted: page + business name composed as the public page does). */
        public readonly string $effectiveTitle,
        public readonly ?string $metaDescription,
        public readonly bool $noindex,
        /** External only: the final HTTP status; 0 = the page could not be fetched at all. */
        public readonly ?int $httpStatus = null,
        public readonly ?int $h1Count = null,
        public readonly ?bool $hasCanonical = null,
        public readonly ?bool $hasOpenGraph = null,
        public readonly ?bool $hasStructuredData = null,
        public readonly ?int $brokenInternalLinks = null,
    ) {
    }

    public function hasTitle(): bool
    {
        return $this->explicitTitle !== null && trim($this->explicitTitle) !== '';
    }

    public function hasMetaDescription(): bool
    {
        return $this->metaDescription !== null && trim($this->metaDescription) !== '';
    }

    /** False only when the source says the page did not load. */
    public function isReachable(): bool
    {
        return $this->httpStatus === null || ($this->httpStatus >= 200 && $this->httpStatus < 400);
    }
}
