<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Seo\SeoNapFieldResult;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Library\Seo\SeoCitationApplicability;
use App\Library\Seo\SeoNapComparator;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoCitationDirectory;
use App\Models\SeoNicheCitationRecommendation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Citation facts (domain `citations`), judged by the CANONICAL NAP
 * comparator — Growth contains no name/phone/address matching of its own.
 *
 * Constant queries (locations, directories, citations), however many of each
 * exist. The comparator is pure, so comparing is not a query.
 *
 * "Important directory" = one of the first `citation_priority_directories`
 * directories the Citations page itself OFFERS at that Location, in the
 * platform's own sort order. What is offered (active, core or niche-recommended,
 * and applicable to the Location's country) is decided by
 * SeoCitationApplicability — the same rule the Citations page uses — so a US-only
 * directory is never expected of a Location in another country. Growth invents no
 * importance flag and no country logic of its own.
 *
 * Three states are kept apart on purpose (Growth Center §23):
 *   not checked   no row, or status not_started — NEVER a mismatch
 *   needs attention  status needs_correction, or the NAP comparator reports a
 *                 Mismatch on a field the owner actually recorded
 *   fine          everything else
 * A field the owner never filled in is "unchecked" to the comparator and is
 * therefore never counted as a mismatch here.
 *
 * Fact shape:
 *   locations  array<int, array{not_checked: int, needs_attention: int, directories: int}>
 *              keyed by active Location id
 */
final class GrowthCitationFactReader implements GrowthFactReader
{
    public function __construct(private readonly SeoNapComparator $nap)
    {
    }

    public function domain(): string
    {
        return 'citations';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::SeoModule;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $locations = BusinessLocation::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', 'active')
            ->orderBy('id')
            ->get();

        // Platform directories only (a Business's own custom directories are the owner's, not a
        // platform priority), in the platform's order.
        $directories = SeoCitationDirectory::query()
            ->whereNull('business_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $nicheKey = $business->industry?->value;
        $recommendations = $nicheKey === null
            ? collect()
            : SeoNicheCitationRecommendation::query()
                ->where('niche_key', $nicheKey)
                ->where('is_enabled', true)
                ->get()
                ->keyBy('seo_citation_directory_id');

        if ($locations->isEmpty() || $directories->isEmpty()) {
            return GrowthFactSet::available($this->domain(), ['locations' => []]);
        }

        $rows = DB::table('seo_citations')
            ->where('business_id', $business->id)
            ->whereIn('seo_citation_directory_id', $directories->pluck('id'))
            ->get(['business_location_id', 'seo_citation_directory_id', 'status', 'listed_name', 'listed_phone', 'listed_address'])
            ->groupBy('business_location_id');

        $result = [];

        foreach ($locations as $location) {
            $canonical = $this->nap->canonicalFor($business, $location);
            $directoryIds = $directories
                ->filter(fn (SeoCitationDirectory $d) => SeoCitationApplicability::isOffered($d, $recommendations->get($d->id), $location))
                ->take($thresholds->get('citation_priority_directories'))
                ->pluck('id');
            $byDirectory = ($rows->get($location->id) ?? collect())->keyBy('seo_citation_directory_id');

            $notChecked = 0;
            $needsAttention = 0;

            foreach ($directoryIds as $directoryId) {
                $row = $byDirectory->get($directoryId);

                if ($row === null || $row->status === 'not_started') {
                    $notChecked++;

                    continue;
                }

                if ($row->status === 'not_applicable') {
                    continue;
                }

                $comparison = $this->nap->compare($canonical, [
                    'name' => $row->listed_name,
                    'phone' => $row->listed_phone,
                    'address' => $row->listed_address,
                ]);

                $mismatch = collect($comparison)->contains(fn (SeoNapFieldResult $r) => $r === SeoNapFieldResult::Mismatch);

                if ($row->status === 'needs_correction' || $mismatch) {
                    $needsAttention++;
                }
            }

            $result[(int) $location->id] = [
                'not_checked' => $notChecked,
                'needs_attention' => $needsAttention,
                'directories' => $directoryIds->count(),
            ];
        }

        return GrowthFactSet::available($this->domain(), ['locations' => $result]);
    }
}
