<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Seo\SeoCitationStatus;
use App\Enums\Seo\SeoDirectoryImportance;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Library\Seo\SeoCitationApplicability;
use App\Library\Seo\SeoCitationRow;
use App\Library\Seo\SeoNapComparator;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoCitationDirectory;
use App\Models\SeoNicheCitationRecommendation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Citation facts (domain `citations`), judged by the CANONICAL NAP
 * comparator and the SAME row rules the Citations page shows — Growth contains
 * no name/phone/address matching and no status logic of its own.
 *
 * Constant queries (locations, directories, recommendations, citations),
 * however many of each exist. The comparator is pure, so comparing is not a
 * query.
 *
 * "Priority directory" = one of the first `citation_priority_directories`
 * Essential or Recommended directories the Citations page itself OFFERS at that
 * Location, in the page's own order (SeoCitationApplicability::orderKey — the
 * niche's importance and order, not the platform's raw sort order). What is
 * offered (active, core or niche-recommended, and applicable to the Location's
 * country) is decided by SeoCitationApplicability — the same rule the page
 * uses — so a US-only directory is never expected of a Location in another
 * country, and a directory the Business's niche calls Optional is never a
 * priority. Growth invents no importance flag and no country logic of its own.
 *
 * Three states are kept apart on purpose (Growth Center §23), each taken from
 * SeoCitationRow so the page and Growth agree:
 *   not checked   no row, or a row still being set up (not started / in
 *                 progress) — NEVER a mismatch
 *   needs attention  status needs_correction, or the NAP comparator reports a
 *                 Mismatch (name, phone, address or website) on a field the
 *                 owner actually recorded
 *   fine          everything else
 * A field the owner never filled in is "unchecked", and one the comparator
 * could not verify either way is "not comparable" — neither is ever counted as
 * a mismatch here. (The page's "review due" reminder is a staleness nudge, not
 * a difference, so it is not a Growth finding.)
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
        // Every Location (the phone rule needs the Business's full count); only the
        // active ones are judged.
        $allLocations = BusinessLocation::query()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->get();
        $locations = $allLocations->filter(fn (BusinessLocation $location) => $location->isActive());

        // Platform directories only (a Business's own custom directories are the owner's, not a
        // platform priority).
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
            ->get(['business_location_id', 'seo_citation_directory_id', 'status', 'listed_name', 'listed_phone', 'listed_address', 'listed_website'])
            ->groupBy('business_location_id');

        $limit = $thresholds->get('citation_priority_directories');
        $result = [];

        foreach ($locations as $location) {
            $canonical = $this->nap->canonicalFor($business, $location);
            $phoneApplies = SeoNapComparator::phoneAppliesAt($location, $allLocations->count());

            $priority = $directories
                ->filter(fn (SeoCitationDirectory $d) => SeoCitationApplicability::isOffered($d, $recommendations->get($d->id), $location)
                    && SeoCitationApplicability::importanceFor($d, $recommendations->get($d->id)) !== SeoDirectoryImportance::Optional)
                ->sort(fn (SeoCitationDirectory $a, SeoCitationDirectory $b) => SeoCitationApplicability::orderKey($a, $recommendations->get($a->id))
                    <=> SeoCitationApplicability::orderKey($b, $recommendations->get($b->id)))
                ->take($limit)
                ->values();
            $byDirectory = ($rows->get($location->id) ?? collect())->keyBy('seo_citation_directory_id');

            $notChecked = 0;
            $needsAttention = 0;

            foreach ($priority as $directory) {
                $stored = $byDirectory->get($directory->id);

                if ($stored === null) {
                    $notChecked++;

                    continue;
                }

                $row = $this->row($business, $location, $directory, $recommendations->get($directory->id), $stored, $canonical, $phoneApplies);

                if ($row->isNotApplicable()) {
                    continue;
                }

                if ($row->needsSetup()) {
                    $notChecked++;
                } elseif ($row->displayState()->isProblem()) {
                    $needsAttention++;
                }
            }

            $result[(int) $location->id] = [
                'not_checked' => $notChecked,
                'needs_attention' => $needsAttention,
                'directories' => $priority->count(),
            ];
        }

        return GrowthFactSet::available($this->domain(), ['locations' => $result]);
    }

    /**
     * The page's own row for one stored citation, so "needs setup" and "needs
     * attention" mean exactly what they mean on the Citations page.
     *
     * @param  array{name: ?string, phone: ?string, address: ?string}  $canonical
     */
    private function row(Business $business, BusinessLocation $location, SeoCitationDirectory $directory, ?SeoNicheCitationRecommendation $recommendation, object $stored, array $canonical, bool $phoneApplies): SeoCitationRow
    {
        // The street address is only ever compared where the Location may expose one.
        $listedAddress = $canonical['address'] === null ? null : $stored->listed_address;

        return new SeoCitationRow(
            directory: $directory,
            citation: null,
            status: SeoCitationStatus::tryFrom((string) $stored->status) ?? SeoCitationStatus::NotStarted,
            safeListingUrl: null,
            listedName: $stored->listed_name,
            listedPhone: $stored->listed_phone,
            listedAddress: $listedAddress,
            nap: $this->nap->compare($canonical, [
                'name' => $stored->listed_name,
                'phone' => $stored->listed_phone,
                'address' => $listedAddress,
            ], $business->country_code, $location->country_code, $phoneApplies),
            writable: false,
            importance: $recommendation?->importance,
            listedWebsite: $stored->listed_website,
            websiteResult: $this->nap->compareWebsite($business->website_url, $stored->listed_website),
            phoneCompared: $phoneApplies,
        );
    }
}
