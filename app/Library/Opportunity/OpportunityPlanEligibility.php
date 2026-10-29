<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use Throwable;

/**
 * Which business_advisor recommendation types a Business's plan actually
 * entitles it to act on. The single read/generation-time gate: a type whose
 * action belongs to a gated platform feature (e.g. Google Business Profile
 * management) is ineligible unless EntitlementManager — the canonical
 * entitlement authority, never a plan name or price — allows that feature.
 *
 * Eligibility is evaluated live, never persisted, so a downgrade hides
 * previously generated rows without deleting them and an upgrade restores
 * them (EntitlementManager caches its own reads per request, so this holds
 * no state that could outlive a plan change in a long-lived worker). Fails closed: if the decision cannot be made, gated types are
 * treated as ineligible.
 */
class OpportunityPlanEligibility
{
    /**
     * Gated recommendation type => the PlatformFeature its action needs.
     * Types absent here (profile basics, CRM, services, website, optional
     * social links) are included on every plan.
     *
     * @var array<string, PlatformFeature>
     */
    private const REQUIRED_FEATURE = [
        'missing_gbp_url' => PlatformFeature::GoogleBusinessProfileModule,
    ];

    public function __construct(private readonly EntitlementManager $entitlements)
    {
    }

    /**
     * @return array<int, string>
     */
    public function ineligibleTypes(Business $business): array
    {
        return $this->resolve($business);
    }

    public function isEligible(Business $business, string $type): bool
    {
        return ! in_array($type, $this->ineligibleTypes($business), true);
    }

    /**
     * @return array<int, string>
     */
    private function resolve(Business $business): array
    {
        $ineligible = [];

        foreach (self::REQUIRED_FEATURE as $type => $feature) {
            if (! $this->allows($business, $feature)) {
                $ineligible[] = $type;
            }
        }

        return $ineligible;
    }

    private function allows(Business $business, PlatformFeature $feature): bool
    {
        try {
            $workspace = $business->workspace;

            if ($workspace === null) {
                return false;
            }

            return $this->entitlements->decide($workspace, $business, $feature->value, 0)->allowed;
        } catch (Throwable) {
            return false;
        }
    }
}
