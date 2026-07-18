<?php

namespace Tests\Unit\Business;

use App\Enums\Business\BusinessGoal;
use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessServiceStatus;
use App\Library\Business\InitialBusinessSnapshotBuilder;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * All models here are constructed in memory (forceFill + setRelation) rather
 * than persisted — InitialBusinessSnapshotBuilder only ever reads attributes
 * and already-resolved relations, never issues its own queries, so this can
 * stay a true fast unit test with no database.
 */
class InitialBusinessSnapshotBuilderTest extends TestCase
{
    private InitialBusinessSnapshotBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new InitialBusinessSnapshotBuilder();
    }

    public function test_score_is_zero_for_a_fully_blank_business(): void
    {
        $business = $this->makeBusiness();

        $snapshot = $this->builder->build($business);

        $this->assertSame(0, $snapshot['profile_completeness_percent']);
    }

    public function test_score_is_one_hundred_for_a_fully_complete_profile(): void
    {
        $business = $this->completeBusiness();

        $snapshot = $this->builder->build($business);

        $this->assertSame(100, $snapshot['profile_completeness_percent']);
        $this->assertSame(array_fill_keys(array_keys($snapshot['facts']), true), $snapshot['facts']);
    }

    public function test_score_reflects_a_partial_profile(): void
    {
        // Only has_name (10) and has_industry (10) true — nothing else.
        $business = $this->makeBusiness([
            'name' => 'Snap Booth Co',
            'industry' => BusinessIndustry::PhotoBoothService,
        ]);

        $snapshot = $this->builder->build($business);

        $this->assertSame(20, $snapshot['profile_completeness_percent']);
    }

    public function test_description_below_fifty_characters_does_not_count(): void
    {
        $business = $this->completeBusiness(['description' => str_repeat('a', 49)]);

        $snapshot = $this->builder->build($business);

        $this->assertFalse($snapshot['facts']['has_description']);
        $this->assertSame(92, $snapshot['profile_completeness_percent']);
    }

    public function test_description_at_fifty_characters_counts(): void
    {
        $business = $this->completeBusiness(['description' => str_repeat('a', 50)]);

        $snapshot = $this->builder->build($business);

        $this->assertTrue($snapshot['facts']['has_description']);
        $this->assertSame(100, $snapshot['profile_completeness_percent']);
    }

    public function test_storefront_location_requires_address_city_region_and_country(): void
    {
        $complete = $this->makeLocation([
            'service_mode' => BusinessServiceMode::Storefront,
            'address_line_1' => '1 Main St',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
        ]);
        $incomplete = $this->makeLocation([
            'service_mode' => BusinessServiceMode::Storefront,
            'address_line_1' => null,
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
        ]);

        $completeSnapshot = $this->builder->build($this->completeBusiness([], $complete));
        $incompleteSnapshot = $this->builder->build($this->completeBusiness([], $incomplete));

        $this->assertTrue($completeSnapshot['facts']['has_complete_primary_location']);
        $this->assertFalse($incompleteSnapshot['facts']['has_complete_primary_location']);
    }

    public function test_hybrid_location_requires_address_city_region_and_country(): void
    {
        $incomplete = $this->makeLocation([
            'service_mode' => BusinessServiceMode::Hybrid,
            'address_line_1' => null,
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
        ]);

        $snapshot = $this->builder->build($this->completeBusiness([], $incomplete));

        $this->assertFalse($snapshot['facts']['has_complete_primary_location']);
    }

    public function test_service_area_location_does_not_require_an_address_line(): void
    {
        $location = $this->makeLocation([
            'service_mode' => BusinessServiceMode::ServiceArea,
            'address_line_1' => null,
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
        ]);

        $snapshot = $this->builder->build($this->completeBusiness([], $location));

        $this->assertTrue($snapshot['facts']['has_complete_primary_location']);
    }

    public function test_service_area_location_still_requires_city_region_and_country(): void
    {
        $location = $this->makeLocation([
            'service_mode' => BusinessServiceMode::ServiceArea,
            'address_line_1' => null,
            'city' => null,
            'region' => 'TX',
            'country_code' => 'US',
        ]);

        $snapshot = $this->builder->build($this->completeBusiness([], $location));

        $this->assertFalse($snapshot['facts']['has_complete_primary_location']);
    }

    public function test_online_location_only_requires_country_code(): void
    {
        $location = $this->makeLocation([
            'service_mode' => BusinessServiceMode::Online,
            'address_line_1' => null,
            'city' => null,
            'region' => null,
            'country_code' => 'US',
        ]);

        $snapshot = $this->builder->build($this->completeBusiness([], $location));

        $this->assertTrue($snapshot['facts']['has_complete_primary_location']);
    }

    public function test_online_location_without_country_is_incomplete(): void
    {
        $location = $this->makeLocation([
            'service_mode' => BusinessServiceMode::Online,
            'country_code' => null,
        ]);

        $snapshot = $this->builder->build($this->completeBusiness([], $location));

        $this->assertFalse($snapshot['facts']['has_complete_primary_location']);
    }

    public function test_inactive_services_do_not_count_as_active(): void
    {
        $service = $this->makeService(['status' => BusinessServiceStatus::Inactive, 'is_primary' => true]);
        $business = $this->completeBusiness([], null, [$service], null);

        $snapshot = $this->builder->build($business);

        $this->assertFalse($snapshot['facts']['has_active_service']);
        $this->assertFalse($snapshot['facts']['has_active_primary_service']);
    }

    public function test_active_non_primary_service_counts_as_active_but_not_primary(): void
    {
        $service = $this->makeService(['status' => BusinessServiceStatus::Active, 'is_primary' => false]);
        $business = $this->completeBusiness([], null, [$service], null);

        $snapshot = $this->builder->build($business);

        $this->assertTrue($snapshot['facts']['has_active_service']);
        $this->assertFalse($snapshot['facts']['has_active_primary_service']);
    }

    public function test_active_primary_service_counts_for_both_facts(): void
    {
        $service = $this->makeService(['status' => BusinessServiceStatus::Active, 'is_primary' => true]);
        $business = $this->completeBusiness([], null, [$service], $service);

        $snapshot = $this->builder->build($business);

        $this->assertTrue($snapshot['facts']['has_active_service']);
        $this->assertTrue($snapshot['facts']['has_active_primary_service']);
    }

    public function test_missing_phone_finding(): void
    {
        $business = $this->completeBusiness(['phone' => null]);

        $findings = $this->builder->build($business)['findings'];

        $this->assertCount(1, $findings);
        $this->assertSame('business:1:missing_phone', $findings[0]['fingerprint']);
        $this->assertSame('medium', $findings[0]['impact']);
        $this->assertSame('low', $findings[0]['effort']);
        $this->assertSame('add_phone', $findings[0]['action_key']);
        $this->assertSame('business', $findings[0]['action_step']);
        $this->assertSame(1.0, $findings[0]['confidence']);
    }

    public function test_missing_email_finding(): void
    {
        $business = $this->completeBusiness(['email' => null]);

        $findings = $this->builder->build($business)['findings'];

        $this->assertCount(1, $findings);
        $this->assertSame('business:1:missing_email', $findings[0]['fingerprint']);
        $this->assertSame('add_email', $findings[0]['action_key']);
    }

    public function test_missing_website_finding_without_relevant_goals_is_medium_impact(): void
    {
        $business = $this->completeBusiness(['website_url' => null]);

        $findings = $this->builder->build($business, [])['findings'];

        $this->assertCount(1, $findings);
        $this->assertSame('business:1:missing_website', $findings[0]['fingerprint']);
        $this->assertSame('medium', $findings[0]['impact']);
        $this->assertSame('add_website', $findings[0]['action_key']);
    }

    public function test_missing_website_finding_with_relevant_goal_is_high_impact(): void
    {
        $business = $this->completeBusiness(['website_url' => null]);

        $findings = $this->builder->build($business, [BusinessGoal::LocalSeo->value])['findings'];

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]['impact']);
    }

    public function test_missing_description_finding(): void
    {
        $business = $this->completeBusiness(['description' => null]);

        $findings = $this->builder->build($business)['findings'];

        $this->assertCount(1, $findings);
        $this->assertSame('business:1:missing_description', $findings[0]['fingerprint']);
        $this->assertSame('add_description', $findings[0]['action_key']);
    }

    public function test_missing_primary_location_finding(): void
    {
        $business = $this->completeBusiness([], null);

        $findings = $this->builder->build($business)['findings'];

        $this->assertCount(1, $findings);
        $this->assertSame('business:1:missing_primary_location', $findings[0]['fingerprint']);
        $this->assertSame('high', $findings[0]['impact']);
        $this->assertSame('add_location', $findings[0]['action_key']);
        $this->assertSame('location', $findings[0]['action_step']);
    }

    public function test_incomplete_primary_location_finding(): void
    {
        $location = $this->makeLocation([
            'service_mode' => BusinessServiceMode::Storefront,
            'address_line_1' => null,
        ]);
        $business = $this->completeBusiness([], $location);

        $findings = $this->builder->build($business)['findings'];

        $this->assertCount(1, $findings);
        $this->assertSame('business:1:incomplete_primary_location', $findings[0]['fingerprint']);
        $this->assertSame('complete_location', $findings[0]['action_key']);
        $this->assertSame('location', $findings[0]['action_step']);
    }

    public function test_missing_services_finding_when_no_active_services_exist(): void
    {
        $business = $this->completeBusiness([], null, [], null);
        $business->setRelation('primaryLocation', $this->completeLocation());

        $findings = $this->builder->build($business)['findings'];

        $this->assertCount(1, $findings);
        $this->assertSame('business:1:missing_services', $findings[0]['fingerprint']);
        $this->assertSame('high', $findings[0]['impact']);
        $this->assertSame('add_service', $findings[0]['action_key']);
        $this->assertSame('services', $findings[0]['action_step']);
    }

    public function test_missing_primary_service_finding_when_active_services_exist_but_none_is_primary(): void
    {
        $service = $this->makeService(['status' => BusinessServiceStatus::Active, 'is_primary' => false]);
        $business = $this->completeBusiness([], null, [$service], null);
        $business->setRelation('primaryLocation', $this->completeLocation());

        $findings = $this->builder->build($business)['findings'];

        $this->assertCount(1, $findings);
        $this->assertSame('business:1:missing_primary_service', $findings[0]['fingerprint']);
        $this->assertSame('high', $findings[0]['impact']);
        $this->assertSame('confirm_primary_service', $findings[0]['action_key']);
    }

    /**
     * The missing field alone proves this finding (AD-006) — goals may only
     * raise its impact/relevance, never suppress it entirely.
     */
    public function test_missing_gbp_url_finding_exists_even_without_relevant_goals(): void
    {
        $business = $this->completeBusiness(['google_business_profile_url' => null]);

        $findings = $this->builder->build($business, [])['findings'];

        $this->assertCount(1, $findings);
        $this->assertSame('business:1:missing_gbp_url', $findings[0]['fingerprint']);
        $this->assertSame('add_gbp_url', $findings[0]['action_key']);
        $this->assertSame('medium', $findings[0]['impact']);
    }

    public function test_missing_gbp_url_finding_impact_increases_with_a_relevant_goal(): void
    {
        $business = $this->completeBusiness(['google_business_profile_url' => null]);

        $withoutGoal = $this->builder->build($business, [])['findings'];
        $withGoal = $this->builder->build($business, [BusinessGoal::LocalSeo->value])['findings'];
        $withReputationGoal = $this->builder->build($business, [BusinessGoal::Reputation->value])['findings'];

        $this->assertCount(1, $withoutGoal);
        $this->assertSame('medium', $withoutGoal[0]['impact']);

        $this->assertCount(1, $withGoal);
        $this->assertSame('business:1:missing_gbp_url', $withGoal[0]['fingerprint']);
        $this->assertSame('high', $withGoal[0]['impact']);

        $this->assertCount(1, $withReputationGoal);
        $this->assertSame('high', $withReputationGoal[0]['impact']);
    }

    public function test_sort_ranks_use_explicit_impact_and_effort_priority_not_alphabetical_order(): void
    {
        // 'missing_email' (low/low) alphabetically precedes 'missing_website'
        // (medium/medium) — if sorting fell back to string comparison this
        // would order them the wrong way. Both business:1:missing_* fingerprints
        // also alphabetically invert the correct high-impact ordering below.
        $business = $this->completeBusiness([
            'email' => null,
            'website_url' => null,
        ], null);

        $findings = $this->builder->build($business, [])['findings'];

        // missing_primary_location (high) must sort before missing_website
        // (medium) even though 'missing_p...' > 'missing_w...' alphabetically.
        $this->assertSame('business:1:missing_primary_location', $findings[0]['fingerprint']);
        $this->assertSame('high', $findings[0]['impact']);

        $impacts = array_column($findings, 'impact');
        $ranked = array_map(static fn (string $impact) => ['high' => 0, 'medium' => 1, 'low' => 2][$impact], $impacts);
        $sortedRanked = $ranked;
        sort($sortedRanked);
        $this->assertSame($sortedRanked, $ranked, 'Findings must be ordered by impact rank, not alphabetically.');
    }

    public function test_missing_facebook_url_finding(): void
    {
        $business = $this->completeBusiness(['facebook_url' => null]);

        $findings = $this->builder->build($business)['findings'];

        $this->assertCount(1, $findings);
        $this->assertSame('business:1:missing_facebook_url', $findings[0]['fingerprint']);
        $this->assertSame('add_facebook_url', $findings[0]['action_key']);
    }

    public function test_missing_instagram_url_finding_only_appears_for_relevant_industries(): void
    {
        $eventBusiness = $this->completeBusiness([
            'instagram_url' => null,
            'industry' => BusinessIndustry::EventServices,
        ]);
        $otherBusiness = $this->completeBusiness([
            'instagram_url' => null,
            'industry' => BusinessIndustry::HomeServices,
        ]);

        $eventFindings = $this->builder->build($eventBusiness)['findings'];
        $otherFindings = $this->builder->build($otherBusiness)['findings'];

        $this->assertCount(1, $eventFindings);
        $this->assertSame('business:1:missing_instagram_url', $eventFindings[0]['fingerprint']);
        $this->assertCount(0, $otherFindings);
    }

    public function test_photographer_and_wedding_vendor_industries_also_trigger_the_instagram_finding(): void
    {
        foreach ([BusinessIndustry::Photographer, BusinessIndustry::WeddingVendor] as $industry) {
            $business = $this->completeBusiness(['instagram_url' => null, 'industry' => $industry]);

            $findings = $this->builder->build($business)['findings'];

            $this->assertCount(1, $findings);
            $this->assertSame('business:1:missing_instagram_url', $findings[0]['fingerprint']);
        }
    }

    public function test_findings_are_capped_at_five_and_sorted_by_impact_then_goal_relevance_then_effort_then_fingerprint(): void
    {
        $business = $this->makeBusiness([
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

        $findings = $this->builder->build($business, [BusinessGoal::LocalSeo->value])['findings'];

        // Candidates here: missing_website(high,goal), missing_primary_location(high),
        // missing_services(high), missing_gbp_url(high,goal), missing_phone(medium),
        // missing_description(medium), missing_email(low), missing_facebook_url(low),
        // missing_instagram_url(low) — 9 candidates, capped to 5.
        $this->assertCount(5, $findings);

        $impacts = array_column($findings, 'impact');
        $this->assertSame(['high', 'high', 'high', 'high', 'medium'], $impacts);

        $fingerprints = array_column($findings, 'fingerprint');
        $this->assertSame($fingerprints, array_unique($fingerprints));
    }

    public function test_build_is_deterministic_for_the_same_input(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01T00:00:00Z'));

        $business = $this->completeBusiness(['phone' => null]);

        $first = $this->builder->build($business, [BusinessGoal::LocalSeo->value]);
        $second = $this->builder->build($business, [BusinessGoal::LocalSeo->value]);

        $this->assertSame($first, $second);

        Carbon::setTestNow();
    }

    public function test_snapshot_and_findings_contain_only_the_documented_keys(): void
    {
        $business = $this->completeBusiness(['phone' => null, 'email' => null]);

        $snapshot = $this->builder->build($business);

        $this->assertSame(['version', 'generated_at', 'profile_completeness_percent', 'facts', 'findings'], array_keys($snapshot));
        $this->assertSame(1, $snapshot['version']);

        $expectedFactKeys = [
            'has_name', 'has_industry', 'has_email', 'has_phone', 'has_website_url',
            'has_description', 'has_country_and_timezone', 'has_primary_location',
            'has_complete_primary_location', 'has_active_service', 'has_active_primary_service',
        ];
        $this->assertSame($expectedFactKeys, array_keys($snapshot['facts']));

        foreach ($snapshot['findings'] as $finding) {
            $this->assertSame([
                'fingerprint', 'title', 'reason', 'impact', 'effort', 'confidence',
                'worker_key', 'can_ai_prepare', 'action_key', 'action_step',
            ], array_keys($finding));
            $this->assertSame(1.0, $finding['confidence']);
            $this->assertFalse($finding['can_ai_prepare']);
            $this->assertSame('business_advisor', $finding['worker_key']);
        }
    }

    /**
     * A fully complete business (all 11 weighted facts true, no outstanding
     * findings for facebook/gbp/instagram either) that individual test cases
     * punch a single hole in, to isolate exactly one finding/fact at a time.
     */
    private function completeBusiness(
        array $attributeOverrides = [],
        BusinessLocation|false|null $location = false,
        ?array $services = null,
        BusinessService|false|null $primaryService = false
    ): Business {
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
            $location === false ? $this->completeLocation() : $location,
            $services ?? [$service],
            $primaryService === false ? $service : $primaryService
        );
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
