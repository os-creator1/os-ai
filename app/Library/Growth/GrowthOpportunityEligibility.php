<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Library\Ads\AdsFeatureAccess;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use Throwable;

/**
 * Which stored Growth rule types a Business's plan still entitles it to see
 * and act on. Applies, at READ time, the same domain => feature requirement
 * GrowthFactSnapshotBuilder uses at generation time (each reader's feature()),
 * decided live by EntitlementManager — so an SEO, Ads, ranking or reviews
 * Opportunity generated on Growth stops being actionable the moment the
 * workspace drops to Core, and returns on upgrade. Nothing is deleted.
 *
 * Fails closed: without a workspace or a decision, every entitlement-gated
 * domain is ineligible. Holds no state, so a plan change is seen immediately.
 */
class GrowthOpportunityEligibility
{
    public function __construct(
        private readonly GrowthFactSnapshotBuilder $snapshots,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /**
     * @return array<int, string> rule type keys to exclude from every read
     */
    public function ineligibleTypes(Business $business): array
    {
        $requirements = $this->snapshots->domainRequirements();

        $featureKeys = collect($requirements)
            ->map(fn (array $r) => $r['feature']?->value)
            ->filter()
            ->unique()
            ->values()
            ->merge(AdsFeatureAccess::fullModuleKeys())
            ->unique()
            ->values()
            ->all();

        $decisions = [];

        try {
            $workspace = $business->workspace;

            if ($workspace !== null && $featureKeys !== []) {
                $decisions = $this->entitlements->snapshotBusinessFeatureDecisions($workspace, $business, $featureKeys, 0);
            }
        } catch (Throwable) {
            $decisions = [];
        }

        $blockedDomains = [];

        foreach ($requirements as $domain => $requirement) {
            $feature = $requirement['feature'];

            // Ads declares no single feature(): GrowthAdsFactReader gates on ANY of the Ads
            // module's full-module keys, so the stored rows are gated by the same rule.
            if ($domain === 'ads') {
                $allowed = collect(AdsFeatureAccess::fullModuleKeys())->contains(fn (string $key) => $decisions[$key]->allowed ?? false);

                if (! $allowed) {
                    $blockedDomains[$domain] = true;
                }

                continue;
            }

            if ($requirement['unavailable'] || ($feature !== null && ! ($decisions[$feature->value]->allowed ?? false))) {
                $blockedDomains[$domain] = true;
            }
        }

        $types = [];

        foreach (GrowthRuleRegistry::all() as $key => $rule) {
            if (isset($blockedDomains[$rule->definition()->domain])) {
                $types[] = $key;
            }
        }

        return $types;
    }
}
