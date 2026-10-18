<?php

namespace App\Library\Seo\Rank;

use App\Models\Business;
use App\Models\Website;

/**
 * The facts we can match a provider result against, resolved from existing
 * canonical authorities only:
 *
 *  - domain: the Business Website's ACTIVE PRIMARY custom domain
 *    (Website::activePrimaryDomain(), the same canonical-URL source the public
 *    renderer uses), normalized to a bare host. NOT businesses.website_url,
 *    which is free text.
 *  - phone: the canonical NAP phone, businesses.phone, normalized.
 *  - cid: reserved. No connected seam supplies a Google CID today; the local
 *    matcher already prefers it when present, so adding one later changes no
 *    schema and no observation shape.
 *
 * The Business NAME is intentionally absent: a name alone is never an identity.
 */
final class SeoRankIdentity
{
    public function __construct(
        public readonly ?string $domain,
        public readonly ?string $phone,
        public readonly ?string $cid = null,
    ) {
    }

    public static function forBusiness(Business $business): self
    {
        $website = Website::query()->where('business_id', $business->id)->first();
        $domain = $website?->activePrimaryDomain()?->domain;

        return new self(
            self::normalizeHost(is_string($domain) ? $domain : null),
            self::normalizePhone($business->phone),
            null,
        );
    }

    public function canMatchOrganic(): bool
    {
        return $this->domain !== null;
    }

    public function canMatchLocal(): bool
    {
        return $this->cid !== null || $this->domain !== null || $this->phone !== null;
    }

    /** Lowercased host with scheme, credentials, port, path and leading www. removed. */
    public static function normalizeHost(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(mb_strtolower($value));

        if ($value === '') {
            return null;
        }

        if (! str_contains($value, '://')) {
            $value = 'http://' . $value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = rtrim($host, '.');

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return str_contains($host, '.') ? $host : null;
    }

    /**
     * Digits only; a leading US country code on an 11-digit number is dropped so
     * "+1 (312) 555-0100" equals "312-555-0100". Anything under 10 digits is not
     * an identity.
     */
    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if (! is_string($digits)) {
            return null;
        }

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return strlen($digits) >= 10 ? $digits : null;
    }
}
