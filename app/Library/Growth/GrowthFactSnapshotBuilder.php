<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Growth\GrowthFactStatus;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Growth\Readers\GrowthAutomationFactReader;
use App\Library\Growth\Readers\GrowthBookingFactReader;
use App\Library\Growth\Readers\GrowthCitationFactReader;
use App\Library\Growth\Readers\GrowthConversationFactReader;
use App\Library\Growth\Readers\GrowthCrmFactReader;
use App\Library\Growth\Readers\GrowthDocumentFactReader;
use App\Library\Growth\Readers\GrowthReputationFactReader;
use App\Library\Growth\Readers\GrowthSeoFactReader;
use App\Library\Growth\Readers\GrowthUnavailableFactReader;
use App\Library\Growth\Readers\GrowthWebsiteFactReader;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the one GrowthFactSnapshot an evaluation judges (Growth Center §16).
 *
 *  1. ONE bulk entitlement read decides every domain's feature at once.
 *  2. Each reader that is Available + entitled runs its bounded queries ONCE.
 *  3. A reader that throws is recorded as FAILED (not as zero): the workers
 *     that depend on it fail their run, and everything already stored — and
 *     every other worker — is untouched. One broken integration must not
 *     hide CRM opportunities, and must not make the Opportunities it owns
 *     look resolved.
 *
 * The reader list is closed and explicit — no class discovery.
 */
class GrowthFactSnapshotBuilder
{
    /** @var array<int, GrowthFactReader> */
    private readonly array $readers;

    public function __construct(
        private readonly EntitlementManager $entitlements,
        private readonly GrowthThresholds $thresholds,
        GrowthCrmFactReader $crm,
        GrowthConversationFactReader $conversations,
        GrowthBookingFactReader $booking,
        GrowthWebsiteFactReader $website,
        GrowthSeoFactReader $seo,
        \App\Library\Growth\Readers\GrowthContentFactReader $content,
        GrowthReputationFactReader $reviews,
        GrowthCitationFactReader $citations,
        GrowthDocumentFactReader $documents,
        GrowthAutomationFactReader $automations,
    ) {
        $this->readers = [
            $crm, $conversations, $booking, $website, $seo, $content, $reviews, $citations, $documents, $automations,
            // Modules not on main: reported Unavailable, never zero.
            new GrowthUnavailableFactReader('ads'),
            new GrowthUnavailableFactReader('rank'),
            new GrowthUnavailableFactReader('search_console'),
        ];
    }

    public function build(Business $business, ?CarbonImmutable $now = null): GrowthFactSnapshot
    {
        $now ??= CarbonImmutable::now();
        $workspace = $business->workspace;

        $featureKeys = collect($this->readers)
            ->map(fn (GrowthFactReader $r) => $r->feature()?->value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $decisions = ($workspace !== null && $featureKeys !== [])
            ? $this->entitlements->snapshotBusinessFeatureDecisions($workspace, $business, $featureKeys, 0)
            : [];

        $sets = [];
        $failed = [];

        foreach ($this->readers as $reader) {
            $domain = $reader->domain();

            if ($reader instanceof GrowthUnavailableFactReader) {
                $sets[$domain] = GrowthFactSet::withStatus($domain, GrowthFactStatus::Unavailable);

                continue;
            }

            $feature = $reader->feature();

            if ($feature !== null) {
                $decision = $decisions[$feature->value] ?? null;

                if ($decision === null || ! $decision->allowed) {
                    $sets[$domain] = GrowthFactSet::withStatus(
                        $domain,
                        ($decision?->reason === 'platform_feature_unavailable' || $decision?->reason === 'platform_feature_unknown')
                            ? GrowthFactStatus::Unavailable
                            : GrowthFactStatus::NotEntitled,
                    );

                    continue;
                }
            }

            try {
                $sets[$domain] = $reader->read($business, $now, $this->thresholds);
            } catch (Throwable $e) {
                Log::error('Growth fact reader failed', ['business_id' => $business->id, 'domain' => $domain, 'exception' => $e]);
                $failed[] = $domain;
                $sets[$domain] = GrowthFactSet::withStatus($domain, GrowthFactStatus::Unavailable);
            }
        }

        $locationNames = DB::table('business_locations')
            ->where('business_id', $business->id)
            ->where('lifecycle_state', 'active')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->map(fn ($n) => (string) $n)
            ->all();

        return new GrowthFactSnapshot($business, $now, $this->thresholds, $sets, $locationNames, $failed);
    }
}
