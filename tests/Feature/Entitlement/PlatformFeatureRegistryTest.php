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
            PlatformFeature::AdsBasicVisibility,
            PlatformFeature::GoogleAdsModule,
            PlatformFeature::MetaAdsModule,
            PlatformFeature::WhiteLabel,
            PlatformFeature::AgencyPackageCapabilities,
            // (PackagesProducts left this list at Contract 16 Sub-slice E's final
            // flip; test_packages_products_is_available_after_sub_slice_es_final_flip
            // asserts it is Available.)
            // (PaymentsContracts left this list at Contract 17 Sub-slice G's final
            // flip; test_payments_contracts_is_available_after_sub_slice_gs_final_flip
            // asserts it is Available — merged in from origin/main, which
            // independently fixed the same stale assertion this branch had
            // found and documented as a pre-existing, out-of-scope defect.)
        ];

        $this->assertCount(6, $planned);

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

    /**
     * Implementation Contract 18, Sub-slice H: both SEO keys are Planned
     * through Sub-slices A/D/E/F/G (schema, readers and full customer HTTP
     * surfaces with no entitlement query ever answering yes), flipped to
     * Available only once every built sub-slice's own focused tests and the
     * cross-cutting entitlement/navigation/View-as matrix passed. Search
     * Console (Sub-slices B/C) is not built, so this flip exposes nothing
     * beyond what already exists: Overview, Keywords (Core+), and the
     * Google Business Profile section (existing pages), Citations, Reviews,
     * and the Website SEO audit (Growth+).
     */
    public function test_seo_features_are_available_after_sub_slice_hs_flip(): void
    {
        $this->assertSame('seo_basic_visibility', PlatformFeature::SeoBasicVisibility->value);
        $this->assertSame('seo_module', PlatformFeature::SeoModule->value);
        $this->assertTrue(PlatformFeatureRegistry::isKnown(PlatformFeature::SeoBasicVisibility->value));
        $this->assertTrue(PlatformFeatureRegistry::isKnown(PlatformFeature::SeoModule->value));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::SeoBasicVisibility->value));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::SeoModule->value));
        $this->assertTrue(PlatformFeatureRegistry::isBusinessScoped(PlatformFeature::SeoBasicVisibility->value));
        $this->assertTrue(PlatformFeatureRegistry::isBusinessScoped(PlatformFeature::SeoModule->value));
    }

    public function test_payments_contracts_is_available_after_sub_slice_gs_final_flip(): void
    {
        // Implementation Contract 17: Planned through Sub-slices A-F (schema,
        // inert entitlement identity, authoring, secure send/sign and Stripe
        // Connect payments with no availability flip), flipped to Available
        // only by Sub-slice G once the full flow was proven end to end
        // (PaymentsContractsAcceptanceTest). Packaged into all three plan
        // tiers — no tier is excluded
        // (`2026_09_25_100012_seed_payments_contracts_plan_packaging.php`).
        $this->assertSame('payments_contracts', PlatformFeature::PaymentsContracts->value);
        $this->assertTrue(PlatformFeatureRegistry::isKnown(PlatformFeature::PaymentsContracts->value));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::PaymentsContracts->value));
        $this->assertTrue(PlatformFeatureRegistry::isBusinessScoped(PlatformFeature::PaymentsContracts->value));
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
