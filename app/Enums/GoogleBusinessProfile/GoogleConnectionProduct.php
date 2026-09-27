<?php

namespace App\Enums\GoogleBusinessProfile;

/**
 * SEO Contract 18 §7.2 — the ONE Google-connection authority's product
 * discriminator. `business_google_connections` now holds at most one row
 * per (business_id, product) pair instead of one row per Business, so a
 * customer may hold an independent connection — independent Google
 * account, independent lifecycle, independent revocation — for each
 * product it lists (§7.2's "one authority, product-discriminated
 * connection rows" decision).
 *
 * `BusinessProfile` is written as the default for every row that existed
 * before this discriminator (the migration backfills it), so every
 * existing Google Business Profile connection keeps its identity and
 * behavior unchanged (§7.3, the hard behavior-preservation gate).
 *
 * `SearchConsole` is defined here, alongside its scope, because §7.2's
 * table defines the discriminator and each product's scope together — but
 * nothing in this sub-slice wires it to an OAuth flow, a controller
 * action, or any connection row: that is Sub-slice C, explicitly deferred
 * (§7.4, §21.B "Do NOT add Search Console code").
 */
enum GoogleConnectionProduct: string
{
    case BusinessProfile = 'business_profile';
    case SearchConsole = 'search_console';

    /**
     * §7.2's scope column. Business Profile's is the existing, unchanged
     * HttpGoogleBusinessProfileReadClient::SCOPE constant, duplicated here
     * only as documentation — that constant, not this method, is what the
     * live GBP OAuth flow actually requests.
     */
    public function scope(): string
    {
        return match ($this) {
            self::BusinessProfile => 'https://www.googleapis.com/auth/business.manage',
            self::SearchConsole => 'https://www.googleapis.com/auth/webmasters.readonly',
        };
    }
}
