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
    public function test_available_features_are_exactly_crm_conversations_automations_website_generation_prospect_outreach(): void
    {
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::Crm->value));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::Conversations->value));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::Automations->value));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::WebsiteGeneration->value));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::ProspectOutreach->value));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::Calendar->value));
        // Unified Business Home & COO contract §17, slice AI-3.
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::AiCooBasic->value));
    }

    public function test_every_other_feature_is_planned_not_available(): void
    {
        $planned = [
            PlatformFeature::Forms,
            PlatformFeature::SeoBasicVisibility,
            PlatformFeature::AdsBasicVisibility,
            PlatformFeature::SeoModule,
            PlatformFeature::GoogleAdsModule,
            PlatformFeature::MetaAdsModule,
            PlatformFeature::WhiteLabel,
            PlatformFeature::AgencyPackageCapabilities,
            // (PackagesProducts left this list at Contract 16 Sub-slice E's final
            // flip; test_packages_products_is_available_after_sub_slice_es_final_flip
            // asserts it is Available.)
            // Implementation Contract 17, Sub-slice A — schema and inert
            // entitlement identity only; stays Planned until Sub-slice G's
            // final flip, after A-F are merged and verified end to end.
            PlatformFeature::PaymentsContracts,
        ];

        $this->assertCount(9, $planned);

        foreach ($planned as $feature) {
            $this->assertFalse(
                PlatformFeatureRegistry::isAvailable($feature->value),
                "Expected [{$feature->value}] to be Planned, not Available."
            );
        }
    }

    public function test_prospect_outreach_availability_reflects_the_agency_ai_prospecting_foundation_pass(): void
    {
        // Agency AI Prospecting foundation pass: a real, executable,
        // Workspace-scoped controller/routes/persistence now exists
        // (App\Http\Controllers\Customer\Workspace\AgencyProspectingController),
        // matching the same evidentiary bar Crm/Conversations/Automations
        // were already held to — this assertion reflects that direct
        // repository evidence, not an inherited product-intent assumption.
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::ProspectOutreach->value));
    }

    public function test_packages_products_is_available_after_sub_slice_es_final_flip(): void
    {
        // Implementation Contract 16: Planned through Sub-slices A-D (schema,
        // inert entitlement identity and domain services with no customer HTTP
        // surface), flipped to Available only by Sub-slice E once the full
        // authorization chain over a real customer surface was proven. It is
        // Business-scoped, like every catalog surface.
        $this->assertSame('packages_products', PlatformFeature::PackagesProducts->value);
        $this->assertTrue(PlatformFeatureRegistry::isKnown(PlatformFeature::PackagesProducts->value));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::PackagesProducts->value));
        $this->assertTrue(PlatformFeatureRegistry::isBusinessScoped(PlatformFeature::PackagesProducts->value));
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
