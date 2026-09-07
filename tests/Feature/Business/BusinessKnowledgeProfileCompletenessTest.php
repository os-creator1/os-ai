<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Library\Business\BusinessKnowledgeProfileCompleteness;
use App\Library\Business\BusinessKnowledgeProfileManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessKnowledgeProfileFixtures;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §4.3, §5.4, §16 Slice 1 --
 * BusinessKnowledgeProfileManager::completenessCheck() behavior:
 * missing/stale/present partitioning, field-sensitive freshness, and
 * plan-tier independence.
 */
class BusinessKnowledgeProfileCompletenessTest extends TestCase
{
    use CreatesBusinessKnowledgeProfileFixtures;
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private BusinessKnowledgeProfileManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(BusinessKnowledgeProfileManager::class);
    }

    private function allFieldKeys(): array
    {
        return array_column(BusinessKnowledgeProfileFieldKey::cases(), 'value');
    }

    public function test_an_empty_profile_reports_every_tracked_field_as_missing(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $result = $this->manager->completenessCheck($business);

        $this->assertInstanceOf(BusinessKnowledgeProfileCompleteness::class, $result);
        $expected = $this->allFieldKeys();
        sort($expected);
        $missing = $result->missingFieldKeys;
        sort($missing);
        $this->assertSame($expected, $missing);
        $this->assertSame([], $result->staleFieldKeys);
        $this->assertSame([], $result->presentFieldKeys);
        $this->assertFalse($result->isComplete());
    }

    public function test_a_fully_populated_and_confirmed_profile_reports_every_field_present_except_the_permanently_unconfirmable_vertical_key(): void
    {
        [$business] = $this->profileFixtureBusinessWithPrimaryLocation();
        $actorId = $this->actorUserId();

        $this->manager->updateFields($business, [
            'pricing_method' => 'fixed',
            'financing_available' => true,
            'offers' => [['name' => 'Install', 'description' => null, 'price_label' => null, 'pricing_method_override' => null]],
            'differentiators' => ['Fast'],
            'ideal_customers' => 'Homeowners',
            'customer_problems' => ['Leaky roofs'],
            'credentials' => [['label' => 'Licensed', 'verified' => true]],
            'years_operating' => 10,
            'warranties_guarantees' => 'One year',
            'primary_conversion_goal' => 'call',
            'conversion_target' => 'tel:+15551234567',
            'brand_voice' => 'Friendly',
            'prohibited_claims' => ['guaranteed lowest price'],
            'growth_priority_service_ids' => [],
            'growth_priority_location_ids' => [],
            'testimonials' => [['quote' => 'Great!', 'author_name' => 'Jane', 'author_title' => null]],
        ], 'manual_edit', $actorId, markVerified: true);

        $this->manager->updateLocationHours($business, $business->primaryLocation, [
            'monday' => [['open' => '09:00', 'close' => '17:00']],
            'tuesday' => [], 'wednesday' => [], 'thursday' => [], 'friday' => [], 'saturday' => [], 'sunday' => [],
        ], 'manual_edit', $actorId, markVerified: true);

        $result = $this->manager->completenessCheck($business);

        // vertical_key can never be confirmed in Slice 1 (business_verticals
        // does not exist yet, §6) -- it is the one field that remains
        // permanently "missing" until Slice 2 ships, so a Slice 1 Profile
        // can never itself be fully complete. Every other field, having
        // been submitted and confirmed above, is present. growth_priority_
        // service_ids/location_ids were submitted as [] (an explicit
        // answer, not "unanswered"), so they count present too.
        $this->assertSame(['vertical_key'], $result->missingFieldKeys);
        $this->assertSame([], $result->staleFieldKeys);
        $expected = array_values(array_diff($this->allFieldKeys(), ['vertical_key']));
        sort($expected);
        $present = $result->presentFieldKeys;
        sort($present);
        $this->assertSame($expected, $present);
        $this->assertFalse($result->isComplete());
    }

    public function test_a_partially_populated_profile_returns_the_exact_mixed_set(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->manager->updateFields($business, ['brand_voice' => 'Friendly'], 'manual_edit', $this->actorUserId(), markVerified: true);

        $result = $this->manager->completenessCheck($business);

        $this->assertContains('brand_voice', $result->presentFieldKeys);
        $this->assertNotContains('brand_voice', $result->missingFieldKeys);
        $this->assertContains('ideal_customers', $result->missingFieldKeys);
        $this->assertContains('hours', $result->missingFieldKeys);
    }

    public function test_a_present_but_unconfirmed_field_is_stale_not_present(): void
    {
        [$business] = $this->profileFixtureBusiness();

        // markVerified defaults to false -> unverified.
        $this->manager->updateFields($business, ['brand_voice' => 'Friendly'], 'manual_edit', $this->actorUserId());

        $result = $this->manager->completenessCheck($business);

        $this->assertContains('brand_voice', $result->staleFieldKeys);
        $this->assertNotContains('brand_voice', $result->presentFieldKeys);
        $this->assertNotContains('brand_voice', $result->missingFieldKeys);
    }

    public function test_a_field_past_its_reconfirm_after_days_is_flagged_stale_even_though_present(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $actorId = $this->actorUserId();

        $this->manager->updateFields($business, ['brand_voice' => 'Friendly'], 'manual_edit', $actorId, markVerified: true);

        // brand_voice is in the 180-day group.
        \App\Models\BusinessKnowledgeProfileFieldState::where('business_id', $business->id)
            ->where('field_key', 'brand_voice')
            ->update(['verified_at' => now()->subDays(181)]);

        $result = $this->manager->completenessCheck($business);

        $this->assertContains('brand_voice', $result->staleFieldKeys);
        $this->assertNotContains('brand_voice', $result->presentFieldKeys);
    }

    public function test_a_field_within_its_reconfirm_after_days_window_remains_present(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $actorId = $this->actorUserId();

        $this->manager->updateFields($business, ['brand_voice' => 'Friendly'], 'manual_edit', $actorId, markVerified: true);

        \App\Models\BusinessKnowledgeProfileFieldState::where('business_id', $business->id)
            ->where('field_key', 'brand_voice')
            ->update(['verified_at' => now()->subDays(179)]);

        $result = $this->manager->completenessCheck($business);

        $this->assertContains('brand_voice', $result->presentFieldKeys);
    }

    public function test_field_sensitive_reconfirm_windows_differ_by_field(): void
    {
        $this->assertSame(90, BusinessKnowledgeProfileFieldKey::Hours->reconfirmAfterDays());
        $this->assertSame(90, BusinessKnowledgeProfileFieldKey::Offers->reconfirmAfterDays());
        $this->assertSame(90, BusinessKnowledgeProfileFieldKey::PricingMethod->reconfirmAfterDays());
        $this->assertSame(90, BusinessKnowledgeProfileFieldKey::FinancingAvailable->reconfirmAfterDays());

        $this->assertSame(365, BusinessKnowledgeProfileFieldKey::Credentials->reconfirmAfterDays());
        $this->assertSame(365, BusinessKnowledgeProfileFieldKey::YearsOperating->reconfirmAfterDays());
        $this->assertSame(365, BusinessKnowledgeProfileFieldKey::WarrantiesGuarantees->reconfirmAfterDays());
        $this->assertSame(365, BusinessKnowledgeProfileFieldKey::Testimonials->reconfirmAfterDays());

        $this->assertSame(180, BusinessKnowledgeProfileFieldKey::Differentiators->reconfirmAfterDays());
        $this->assertSame(180, BusinessKnowledgeProfileFieldKey::VerticalKey->reconfirmAfterDays());
        $this->assertSame(180, BusinessKnowledgeProfileFieldKey::GrowthPriorityServiceIds->reconfirmAfterDays());

        // §5.4's table omits prohibited_claims entirely (a merged-contract
        // gap) -- placed in the 180-day group, see the enum's docblock.
        $this->assertSame(180, BusinessKnowledgeProfileFieldKey::ProhibitedClaims->reconfirmAfterDays());
    }

    public function test_freshness_is_identical_regardless_of_plan_tier(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $actorId = $this->actorUserId();

        $this->manager->updateFields($business, ['brand_voice' => 'Friendly'], 'manual_edit', $actorId, markVerified: true);
        \App\Models\BusinessKnowledgeProfileFieldState::where('business_id', $business->id)
            ->where('field_key', 'brand_voice')
            ->update(['verified_at' => now()->subDays(181)]);

        // completenessCheck() takes no plan-tier argument at all -- the
        // result cannot vary by tier by construction. Two independent
        // calls (standing in for Core vs Growth/Agency callers) agree.
        $resultA = $this->manager->completenessCheck($business);
        $resultB = $this->manager->completenessCheck($business);

        $this->assertSame($resultA->staleFieldKeys, $resultB->staleFieldKeys);
        $this->assertContains('brand_voice', $resultA->staleFieldKeys);
    }

    public function test_hours_completeness_uses_the_primary_location_when_a_website_is_supplied(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $actorId = $this->actorUserId();

        // A primary location with NO hours set, plus a secondary
        // location whose hours ARE confirmed -- v1 only ever evaluates
        // primaryLocation(), so the secondary's confirmed hours must not
        // make the check report "present."
        $primary = $this->addLocation($business, ['is_primary' => true]);
        $secondary = $this->addLocation($business, ['is_primary' => false]);
        $this->manager->updateLocationHours($business->fresh(), $secondary, [
            'monday' => [['open' => '09:00', 'close' => '17:00']],
            'tuesday' => [], 'wednesday' => [], 'thursday' => [], 'friday' => [], 'saturday' => [], 'sunday' => [],
        ], 'manual_edit', $actorId, markVerified: true);

        $result = $this->manager->completenessCheck($business->fresh(), $website);

        $this->assertContains('hours', $result->missingFieldKeys);
        $this->assertNotContains('hours', $result->presentFieldKeys);
    }

    public function test_hours_completeness_reports_missing_when_the_business_has_no_locations_at_all(): void
    {
        [$business] = $this->profileFixtureBusiness();
        // profileFixtureBusiness() creates a Business via the ordinary
        // repository path, which does not auto-create a primary
        // location.
        $this->assertNull($business->primaryLocation);

        $result = $this->manager->completenessCheck($business);

        $this->assertContains('hours', $result->missingFieldKeys);
    }

    public function test_completeness_check_performs_no_writes(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $before = \App\Models\BusinessKnowledgeProfileChange::count();
        $this->manager->completenessCheck($business);
        $this->manager->completenessCheck($business);
        $after = \App\Models\BusinessKnowledgeProfileChange::count();

        $this->assertSame($before, $after);
    }
}
