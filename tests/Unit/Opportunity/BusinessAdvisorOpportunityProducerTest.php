<?php

namespace Tests\Unit\Opportunity;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Business\InitialBusinessSnapshotBuilder;
use App\Library\Opportunity\BusinessAdvisorOpportunityProducer;
use App\Library\Opportunity\OpportunityCandidateData;
use App\Library\Opportunity\OpportunityEvidenceFactData;
use App\Library\Opportunity\OpportunityTypeRegistry;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * All models here are constructed in memory (forceFill + setRelation), never
 * persisted — the producer only reads $business/its already-resolved
 * relations via InitialBusinessSnapshotBuilder, never issues its own
 * queries, so this stays a true fast unit test with no database.
 */
class BusinessAdvisorOpportunityProducerTest extends TestCase
{
    public function test_worker_key_returns_business_advisor(): void
    {
        $this->assertSame(OpportunityWorkerKey::BusinessAdvisor, $this->makeProducer()->workerKey());
    }

    #[DataProvider('simpleBlankFieldProvider')]
    public function test_blank_field_condition_emits_its_type(string $attribute, string $expectedType, string $expectedFactKey): void
    {
        $business = $this->completeBusiness([$attribute => null]);

        $candidates = iterator_to_array($this->makeProducer()->produce($business));

        $this->assertCount(1, $candidates);
        $this->assertSame($expectedType, $candidates[0]->type);
        $this->assertSame($expectedFactKey, $candidates[0]->evidence[0]->factKey);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function simpleBlankFieldProvider(): array
    {
        return [
            'phone' => ['phone', 'missing_phone', 'phone_blank'],
            'email' => ['email', 'missing_email', 'email_blank'],
            'website_url' => ['website_url', 'missing_website', 'website_url_blank'],
            'description' => ['description', 'missing_description', 'description_too_short'],
            'google_business_profile_url' => ['google_business_profile_url', 'missing_gbp_url', 'gbp_url_blank'],
            'facebook_url' => ['facebook_url', 'missing_facebook_url', 'facebook_url_blank'],
        ];
    }

    public function test_missing_primary_location_emits_its_type(): void
    {
        $business = $this->completeBusiness();
        $business->setRelation('primaryLocation', null);

        $candidates = iterator_to_array($this->makeProducer()->produce($business));

        $this->assertCount(1, $candidates);
        $this->assertSame('missing_primary_location', $candidates[0]->type);
        $this->assertSame('primary_location_missing', $candidates[0]->evidence[0]->factKey);
    }

    public function test_incomplete_primary_location_emits_its_type(): void
    {
        $business = $this->completeBusiness();
        $business->setRelation('primaryLocation', $this->makeLocation([
            'service_mode' => BusinessServiceMode::Storefront,
            'address_line_1' => null,
        ]));

        $candidates = iterator_to_array($this->makeProducer()->produce($business));

        $this->assertCount(1, $candidates);
        $this->assertSame('incomplete_primary_location', $candidates[0]->type);
        $this->assertSame('primary_location_incomplete', $candidates[0]->evidence[0]->factKey);
    }

    public function test_incomplete_primary_location_is_not_emitted_when_location_is_entirely_missing(): void
    {
        $business = $this->completeBusiness();
        $business->setRelation('primaryLocation', null);

        $types = $this->producedTypes($business);

        $this->assertContains('missing_primary_location', $types);
        $this->assertNotContains('incomplete_primary_location', $types);
    }

    public function test_missing_services_emits_its_type(): void
    {
        $business = $this->completeBusiness();
        $business->setRelation('services', collect());
        $business->setRelation('primaryService', null);

        $candidates = iterator_to_array($this->makeProducer()->produce($business));

        $this->assertCount(1, $candidates);
        $this->assertSame('missing_services', $candidates[0]->type);
        $this->assertSame('active_services_missing', $candidates[0]->evidence[0]->factKey);
    }

    public function test_missing_primary_service_emits_its_type(): void
    {
        $service = $this->makeService(['status' => BusinessServiceStatus::Active, 'is_primary' => false]);
        $business = $this->completeBusiness();
        $business->setRelation('services', collect([$service]));
        $business->setRelation('primaryService', null);

        $candidates = iterator_to_array($this->makeProducer()->produce($business));

        $this->assertCount(1, $candidates);
        $this->assertSame('missing_primary_service', $candidates[0]->type);
        $this->assertSame('primary_service_missing', $candidates[0]->evidence[0]->factKey);
    }

    public function test_missing_primary_service_is_not_emitted_when_no_active_services_exist(): void
    {
        $business = $this->completeBusiness();
        $business->setRelation('services', collect());
        $business->setRelation('primaryService', null);

        $types = $this->producedTypes($business);

        $this->assertContains('missing_services', $types);
        $this->assertNotContains('missing_primary_service', $types);
    }

    #[DataProvider('instagramAllowedIndustryProvider')]
    public function test_instagram_condition_is_emitted_for_each_allowed_industry(BusinessIndustry $industry): void
    {
        $business = $this->completeBusiness(['instagram_url' => null, 'industry' => $industry]);

        $candidates = iterator_to_array($this->makeProducer()->produce($business));

        $this->assertCount(1, $candidates);
        $this->assertSame('missing_instagram_url', $candidates[0]->type);
    }

    /**
     * @return array<string, array{0: BusinessIndustry}>
     */
    public static function instagramAllowedIndustryProvider(): array
    {
        return [
            'event services' => [BusinessIndustry::EventServices],
            'photographer' => [BusinessIndustry::Photographer],
            'wedding vendor' => [BusinessIndustry::WeddingVendor],
        ];
    }

    public function test_instagram_condition_is_not_emitted_for_a_disallowed_industry(): void
    {
        $business = $this->completeBusiness(['instagram_url' => null, 'industry' => BusinessIndustry::HomeServices]);

        $candidates = iterator_to_array($this->makeProducer()->produce($business));

        $this->assertCount(0, $candidates);
    }

    public function test_fully_complete_business_emits_zero_candidates(): void
    {
        $business = $this->completeBusiness();

        $candidates = iterator_to_array($this->makeProducer()->produce($business));

        $this->assertCount(0, $candidates);
    }

    public function test_more_than_five_true_conditions_emit_every_candidate(): void
    {
        $business = $this->incompleteBusiness();

        $candidates = iterator_to_array($this->makeProducer()->produce($business));

        // phone/email/website/description/primary_location_missing/
        // active_services_missing/gbp/facebook/instagram = 9 true conditions,
        // well past the onboarding MAX_FINDINGS=5 cap — every one is present.
        $this->assertCount(9, $candidates);
    }

    public function test_output_order_matches_the_type_registry_declaration_order(): void
    {
        $types = $this->producedTypes($this->incompleteBusiness());

        $this->assertSame([
            'missing_phone',
            'missing_email',
            'missing_website',
            'missing_description',
            'missing_primary_location',
            'missing_services',
            'missing_gbp_url',
            'missing_facebook_url',
            'missing_instagram_url',
        ], $types);
    }

    public function test_every_candidate_uses_fixed_scalar_values(): void
    {
        $business = $this->completeBusiness(['phone' => null, 'email' => null]);

        foreach ($this->makeProducer()->produce($business) as $candidate) {
            $this->assertSame(1.0, $candidate->confidence);
            $this->assertSame(1, $candidate->urgency);
            $this->assertNull($candidate->context);
            $this->assertSame([], $candidate->templateParameters);
            $this->assertSame([], $candidate->actionParameters);
        }
    }

    public function test_relevant_goal_keys_match_the_type_registry_definition(): void
    {
        $business = $this->completeBusiness(['website_url' => null]);

        $candidates = iterator_to_array($this->makeProducer()->produce($business));

        $this->assertCount(1, $candidates);
        $this->assertSame(
            OpportunityTypeRegistry::get('business_advisor', 'missing_website')['allowed_relevant_goal_keys'],
            $candidates[0]->relevantGoalKeys
        );
    }

    public function test_every_candidate_has_exactly_its_registered_required_evidence_fact(): void
    {
        $business = $this->completeBusiness(['phone' => null]);

        $candidates = iterator_to_array($this->makeProducer()->produce($business));

        $this->assertCount(1, $candidates);
        $this->assertCount(1, $candidates[0]->evidence);
        $this->assertInstanceOf(OpportunityEvidenceFactData::class, $candidates[0]->evidence[0]);

        $evidence = $candidates[0]->evidence[0];
        $this->assertSame('business_profile', $evidence->sourceType);
        $this->assertSame('phone_blank', $evidence->factKey);
        $this->assertSame("business:{$business->id}:phone_blank", $evidence->sourceIdentifier);
        $this->assertNull($evidence->observedValue);
        $this->assertNull($evidence->observedAt);
        $this->assertNull($evidence->expiresAt);
        $this->assertNull($evidence->sourceUrl);
        $this->assertNull($evidence->contentHash);
    }

    public function test_all_candidates_in_one_call_share_the_same_retrieved_at(): void
    {
        $business = $this->completeBusiness(['phone' => null, 'email' => null]);

        $candidates = iterator_to_array($this->makeProducer()->produce($business));

        $this->assertCount(2, $candidates);
        $this->assertTrue($candidates[0]->evidence[0]->retrievedAt->equalTo($candidates[1]->evidence[0]->retrievedAt));
    }

    public function test_candidate_and_evidence_dtos_structurally_carry_no_trusted_server_output(): void
    {
        $candidateProperties = array_map(
            fn ($property) => $property->getName(),
            (new ReflectionClass(OpportunityCandidateData::class))->getProperties()
        );
        $evidenceProperties = array_map(
            fn ($property) => $property->getName(),
            (new ReflectionClass(OpportunityEvidenceFactData::class))->getProperties()
        );

        foreach (['title', 'summary', 'fingerprint', 'priorityScore', 'actionKey'] as $forbidden) {
            $this->assertNotContains($forbidden, $candidateProperties, "OpportunityCandidateData must not have a [{$forbidden}] property.");
        }

        $this->assertNotContains('summary', $evidenceProperties, 'OpportunityEvidenceFactData must not have a [summary] property.');
    }

    private function makeProducer(): BusinessAdvisorOpportunityProducer
    {
        return new BusinessAdvisorOpportunityProducer(new InitialBusinessSnapshotBuilder());
    }

    /**
     * @return array<int, string>
     */
    private function producedTypes(Business $business): array
    {
        return array_map(
            fn (OpportunityCandidateData $candidate) => $candidate->type,
            iterator_to_array($this->makeProducer()->produce($business))
        );
    }

    /**
     * A fully complete business (mirrors InitialBusinessSnapshotBuilderTest's
     * fixture) that individual test cases punch a single hole in, to isolate
     * exactly one produced candidate at a time.
     */
    private function completeBusiness(array $attributeOverrides = []): Business
    {
        $service = $this->makeService(['status' => BusinessServiceStatus::Active, 'is_primary' => true]);

        return $this->makeBusiness(
            array_merge([
                'name' => 'Snap Booth Co',
                'industry' => BusinessIndustry::PhotoBoothService,
                'email' => 'hello@example.com',
                'phone' => '+15551234567',
                'website_url' => 'https://example.com',
                'description' => str_repeat('a', 60),
                'country_code' => 'US',
                'timezone' => 'America/New_York',
                'google_business_profile_url' => 'https://business.google.com/example',
                'facebook_url' => 'https://facebook.com/example',
                'instagram_url' => 'https://instagram.com/example',
            ], $attributeOverrides),
            $this->completeLocation(),
            [$service],
            $service
        );
    }

    /**
     * Every simple blank field plus no location and no services — 9 of the
     * 11 conditions true (incomplete_primary_location and
     * missing_primary_service are structurally false alongside their
     * "entirely missing" siblings).
     */
    private function incompleteBusiness(): Business
    {
        return $this->makeBusiness([
            'name' => null,
            'industry' => BusinessIndustry::EventServices,
            'email' => null,
            'phone' => null,
            'website_url' => null,
            'description' => null,
            'country_code' => null,
            'timezone' => null,
            'google_business_profile_url' => null,
            'facebook_url' => null,
            'instagram_url' => null,
        ]);
    }

    private function completeLocation(): BusinessLocation
    {
        return $this->makeLocation([
            'service_mode' => BusinessServiceMode::Storefront,
            'address_line_1' => '1 Main St',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
        ]);
    }

    private function makeBusiness(
        array $attributes = [],
        ?BusinessLocation $location = null,
        array $services = [],
        ?BusinessService $primaryService = null
    ): Business {
        $business = new Business();
        $business->forceFill(array_merge(['id' => 1, 'customer_id' => 10], $attributes));
        $business->setRelation('primaryLocation', $location);
        $business->setRelation('services', collect($services));
        $business->setRelation('primaryService', $primaryService);

        return $business;
    }

    private function makeLocation(array $attributes = []): BusinessLocation
    {
        $location = new BusinessLocation();
        $location->forceFill(array_merge([
            'id' => 1,
            'business_id' => 1,
            'service_mode' => BusinessServiceMode::Storefront,
        ], $attributes));

        return $location;
    }

    private function makeService(array $attributes = []): BusinessService
    {
        $service = new BusinessService();
        $service->forceFill(array_merge([
            'id' => 1,
            'business_id' => 1,
            'name' => 'Digital Photo Booth',
            'status' => BusinessServiceStatus::Active,
            'is_primary' => false,
        ], $attributes));

        return $service;
    }
}
