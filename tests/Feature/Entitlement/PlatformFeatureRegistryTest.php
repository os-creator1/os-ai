<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\PlatformFeatureRegistry;
use Tests\TestCase;

/**
 * RFC-004 Milestone 1 Contract §6/§13 — availability is locked to direct
 * repository evidence gathered at contract-drafting time, never inferred
 * from product intent.
 */
class PlatformFeatureRegistryTest extends TestCase
{
    public function test_available_features_are_exactly_crm_conversations_automations(): void
    {
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::Crm->value));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::Conversations->value));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::Automations->value));
    }

    public function test_every_other_feature_is_planned_not_available(): void
    {
        $planned = [
            PlatformFeature::Calendar,
            PlatformFeature::Forms,
            PlatformFeature::WebsiteGeneration,
            PlatformFeature::AiCooBasic,
            PlatformFeature::SeoBasicVisibility,
            PlatformFeature::AdsBasicVisibility,
            PlatformFeature::SeoModule,
            PlatformFeature::GoogleAdsModule,
            PlatformFeature::MetaAdsModule,
            PlatformFeature::WhiteLabel,
            PlatformFeature::AgencyPackageCapabilities,
            PlatformFeature::ProspectOutreach,
        ];

        $this->assertCount(12, $planned);

        foreach ($planned as $feature) {
            $this->assertFalse(
                PlatformFeatureRegistry::isAvailable($feature->value),
                "Expected [{$feature->value}] to be Planned, not Available."
            );
        }
    }

    public function test_prospect_outreach_availability_reflects_direct_repository_evidence_not_assumed(): void
    {
        // RFC-004 M1 Contract §6: direct repository inspection found no
        // executable Prospect Outreach implementation anywhere in this
        // repository — this assertion reflects that finding, not an
        // inherited product-intent assumption.
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::ProspectOutreach->value));
    }

    public function test_is_known_true_for_every_platform_feature_case(): void
    {
        foreach (PlatformFeature::cases() as $feature) {
            $this->assertTrue(PlatformFeatureRegistry::isKnown($feature->value));
        }
    }

    public function test_is_known_and_is_available_both_false_for_an_unknown_key(): void
    {
        $this->assertFalse(PlatformFeatureRegistry::isKnown('not_a_real_feature'));
        $this->assertFalse(PlatformFeatureRegistry::isAvailable('not_a_real_feature'));
    }
}
