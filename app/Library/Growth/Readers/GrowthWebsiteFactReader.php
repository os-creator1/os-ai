<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Library\Website\WebsiteCatalogReferences;
use App\Models\Website;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Website facts from the Website table itself — Growth never crawls or
 * fetches a public URL (Growth Center §18: the Website snapshot is the
 * authority). Two queries.
 *
 * Fact shape (domain `website`):
 *   has_external_site   bool   the Business profile already carries a website_url
 *   exists              bool   a Website row exists
 *   status              string|null  draft | published | archived
 *   published           bool   status = published AND a published revision exists
 *
 * `has_external_site` matters: a Business that runs its marketing from a site
 * it hosts elsewhere must not be told its platform Website is unpublished.
 *
 * Package sync (`package_changed_count`, `package_removed_count`) is the
 * Website module's own verdict, WebsiteCatalogReferences::staleness() — Growth
 * counts what it reports and compares nothing itself.
 *
 * DEFERRED: "high-priority service area page not published" (no canonical seam).
 */
final class GrowthWebsiteFactReader implements GrowthFactReader
{
    public function __construct(private readonly WebsiteCatalogReferences $catalogReferences)
    {
    }

    public function domain(): string
    {
        return 'website';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::WebsiteGeneration;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $website = DB::table('websites')
            ->where('business_id', $business->id)
            ->first(['status', 'published_revision_id']);
        $published = $website !== null && $website->status === 'published' && $website->published_revision_id !== null;
        $staleness = ['changed' => [], 'removed' => []];

        if ($published) {
            $model = Website::query()->where('business_id', $business->id)->first();
            $staleness = $model !== null ? $this->catalogReferences->staleness($model) : $staleness;
        }

        return GrowthFactSet::available($this->domain(), [
            'has_external_site' => trim((string) $business->website_url) !== '',
            'exists' => $website !== null,
            'status' => $website?->status,
            'published' => $published,
            'package_changed_count' => count($staleness['changed']),
            'package_removed_count' => count($staleness['removed']),
        ]);
    }
}
