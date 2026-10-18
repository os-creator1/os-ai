<?php

namespace App\Library\MetaAds\Attribution;

use App\Library\GoogleAds\Attribution\LeadAttributionReader;
use App\Models\Business;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Meta Ads Module V1 contract 24 §10 — the read-only "Campaign tags only" view
 * of leads on the Meta Leads page.
 *
 * It reads EXISTING lead_attribution_touches only (nothing is captured, no
 * schema is added, no Meta Pixel / Conversions API / click id exists in V1):
 * a lead appears when its FIRST touch carries a Meta source tag in utm_source
 * (facebook, fb, meta, instagram, ig; case-insensitive exact match). Such a
 * tag is NOT proof of a paid click and never names a Meta campaign, ad set or
 * ad. Everything else about a lead (contact link, CRM stage, booked, CRM
 * opportunity value in the BUSINESS currency) is read by composition from the
 * Google LeadAttributionReader's own joins, so the two pages cannot drift.
 *
 * Every query is scoped to the Business and bounded by the page size.
 */
final class MetaAdsLeadAttributionReader
{
    /** The utm_source values that mean "tagged as Meta" (matched ignoring case, exactly). */
    public const SOURCE_TAGS = ['facebook', 'fb', 'meta', 'instagram', 'ig'];

    public const PER_PAGE = LeadAttributionReader::PER_PAGE;

    public function __construct(private readonly LeadAttributionReader $leads)
    {
    }

    /**
     * One page of Meta-tagged leads, newest conversion first. Row shape is the
     * Google reader's, with `level` unused (always "campaign tags only") and
     * `first_touch['utm_source']` the tag text.
     */
    public function page(Business $business, CarbonInterface $from, CarbonInterface $to, int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->leads->page($business, $from, $to, $page, $perPage, self::SOURCE_TAGS);
    }

    /**
     * @return array{leads: int, meta_tagged: int, other: int} distinct leads in the period and how many carry a Meta tag on their first touch
     */
    public function summary(Business $business, CarbonInterface $from, CarbonInterface $to): array
    {
        $all = $this->leads->leadCount($business, $from, $to);
        $tagged = $this->leads->leadCount($business, $from, $to, self::SOURCE_TAGS);

        return ['leads' => $all, 'meta_tagged' => $tagged, 'other' => max(0, $all - $tagged)];
    }
}
