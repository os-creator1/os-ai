<?php

namespace Tests\Unit\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\PlatformFeatureAvailability;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use Tests\TestCase;

/**
 * RFC-004 Milestone 1 Contract §13 — every case of all six Entitlement
 * enums round-trips its string value; WorkspaceEntitlementTransitionType
 * has exactly nine cases; PlatformFeature has exactly fifteen cases
 * matching RFC-004 §11's exact key list.
 */
class EntitlementEnumsTest extends TestCase
{
    public function test_workspace_plan_tier_exact_values(): void
    {
        $this->assertSame('core', WorkspacePlanTier::Core->value);
        $this->assertSame('growth', WorkspacePlanTier::Growth->value);
        $this->assertSame('agency', WorkspacePlanTier::Agency->value);
        $this->assertCount(3, WorkspacePlanTier::cases());
    }

    public function test_workspace_plan_assignment_status_exact_values(): void
    {
        $this->assertSame('active', WorkspacePlanAssignmentStatus::Active->value);
        $this->assertSame('inactive', WorkspacePlanAssignmentStatus::Inactive->value);
        $this->assertSame('suspended', WorkspacePlanAssignmentStatus::Suspended->value);
        $this->assertCount(3, WorkspacePlanAssignmentStatus::cases());
    }

    public function test_workspace_entitlement_override_state_exact_values(): void
    {
        $this->assertSame('allow', WorkspaceEntitlementOverrideState::Allow->value);
        $this->assertSame('deny', WorkspaceEntitlementOverrideState::Deny->value);
        $this->assertCount(2, WorkspaceEntitlementOverrideState::cases());
    }

    public function test_workspace_entitlement_transition_type_has_exactly_nine_cases(): void
    {
        $expected = [
            'plan_assigned',
            'plan_changed',
            'plan_status_changed',
            'complimentary_granted',
            'complimentary_revoked',
            'additional_business_slots_changed',
            'entitlement_override_allowed',
            'entitlement_override_denied',
            'entitlement_override_reverted',
        ];

        $actual = array_map(fn ($case) => $case->value, WorkspaceEntitlementTransitionType::cases());

        $this->assertCount(9, WorkspaceEntitlementTransitionType::cases());
        $this->assertSame($expected, $actual);
    }

    public function test_platform_feature_has_exactly_fifteen_cases_matching_rfc_004(): void
    {
        $expected = [
            'crm',
            'conversations',
            'calendar',
            'forms',
            'automations',
            'website_generation',
            'ai_coo_basic',
            'seo_basic_visibility',
            'ads_basic_visibility',
            'seo_module',
            'google_ads_module',
            'meta_ads_module',
            'white_label',
            'agency_package_capabilities',
            'prospect_outreach',
        ];

        $actual = array_map(fn ($case) => $case->value, PlatformFeature::cases());

        $this->assertCount(15, PlatformFeature::cases());
        $this->assertSame($expected, $actual);
    }

    public function test_platform_feature_availability_exact_values(): void
    {
        $this->assertSame('available', PlatformFeatureAvailability::Available->value);
        $this->assertSame('planned', PlatformFeatureAvailability::Planned->value);
        $this->assertCount(2, PlatformFeatureAvailability::cases());
    }
}
