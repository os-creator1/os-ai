<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Review WORKFLOW facts (domain `reviews`). Current Reviews is workflow-only:
 * a manual review link per Location and a ledger of review requests. There is
 * no Google review ingestion, so there is no rating, no review count and no
 * sentiment here — and none is ever invented (Growth Center §22).
 *
 * Two queries over the active Locations.
 *
 * Fact shape:
 *   locations  array<int, array{has_link: bool, requests_in_window: int}>
 *              keyed by active Location id
 *
 * A "request" is any row in the request ledger regardless of its later
 * outcome (requested / reviewed / declined) — the rule asks "has this Location
 * asked anyone recently", not "did anyone say yes".
 */
final class GrowthReputationFactReader implements GrowthFactReader
{
    public function domain(): string
    {
        return 'reviews';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::SeoModule;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $locationIds = DB::table('business_locations')
            ->where('business_id', $business->id)
            ->where('lifecycle_state', 'active')
            ->pluck('id');

        if ($locationIds->isEmpty()) {
            return GrowthFactSet::available($this->domain(), ['locations' => []]);
        }

        $links = DB::table('seo_location_review_links')
            ->where('business_id', $business->id)
            ->whereIn('business_location_id', $locationIds)
            ->pluck('business_location_id')
            ->flip();

        $requests = DB::table('seo_review_requests')
            ->where('business_id', $business->id)
            ->where('requested_at', '>=', $now->subDays($thresholds->get('review_request_lookback_days')))
            ->whereIn('business_location_id', $locationIds)
            ->selectRaw('business_location_id, COUNT(*) AS n')
            ->groupBy('business_location_id')
            ->pluck('n', 'business_location_id');

        $locations = [];

        foreach ($locationIds as $id) {
            $locations[(int) $id] = [
                'has_link' => $links->has($id),
                'requests_in_window' => (int) ($requests[$id] ?? 0),
            ];
        }

        return GrowthFactSet::available($this->domain(), ['locations' => $locations]);
    }
}
