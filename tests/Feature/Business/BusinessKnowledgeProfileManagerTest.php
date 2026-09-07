<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessPricingMethod;
use App\Enums\Business\BusinessPrimaryConversionGoal;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessKnowledgeProfileChange;
use App\Models\BusinessKnowledgeProfileFieldState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Business\Concerns\CreatesBusinessKnowledgeProfileFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §4.3, §5, §16 Slice 1 --
 * BusinessKnowledgeProfileManager::getOrCreate()/updateFields() behavior.
 */
class BusinessKnowledgeProfileManagerTest extends TestCase
{
    use CreatesBusinessKnowledgeProfileFixtures;
    use RefreshDatabase;

    private BusinessKnowledgeProfileManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(BusinessKnowledgeProfileManager::class);
    }

    // -----------------------------------------------------------------
    // getOrCreate()
    // -----------------------------------------------------------------

    public function test_get_or_create_creates_exactly_one_row_and_is_idempotent(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $first = $this->manager->getOrCreate($business);
        $second = $this->manager->getOrCreate($business);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BusinessKnowledgeProfile::where('business_id', $business->id)->count());
        $this->assertSame('none', $first->reviews_source);
    }

    public function test_get_or_create_generates_a_real_uuid_not_uniqid(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $profile = $this->manager->getOrCreate($business);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $profile->uid,
        );
    }

    public function test_get_or_create_resolves_to_one_profile_when_a_row_already_exists(): void
    {
        [$business] = $this->profileFixtureBusiness();

        // Simulate a concurrent creator that already won and committed.
        BusinessKnowledgeProfile::create(['business_id' => $business->id, 'reviews_source' => 'none']);

        $profile = $this->manager->getOrCreate($business);

        $this->assertSame(1, BusinessKnowledgeProfile::where('business_id', $business->id)->count());
        $this->assertSame($business->id, $profile->business_id);
    }

    /**
     * Exercises the actual race window (the getOrCreate()'s own initial
     * lookup finds nothing, then the create() itself collides) rather
     * than only the fast path above: a genuinely concurrent insert
     * cannot be interleaved deterministically inside one PHPUnit
     * process/connection, so this pre-creates the "winning" row (as if
     * it committed between the lookup and the insert) and then invokes
     * the private createProfileRaceSafe() step directly via reflection
     * -- the exact method getOrCreate() falls through to once its own
     * lookup has already returned null. This proves the catch block
     * itself resolves the 1062 duplicate-key error to the existing row,
     * never a second one.
     */
    public function test_get_or_create_resolves_a_genuine_insert_time_race_to_one_profile(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $winner = BusinessKnowledgeProfile::create(['business_id' => $business->id, 'reviews_source' => 'none']);

        $method = new \ReflectionMethod(BusinessKnowledgeProfileManager::class, 'createProfileRaceSafe');
        $method->setAccessible(true);
        $resolved = $method->invoke($this->manager, $business);

        $this->assertSame($winner->id, $resolved->id);
        $this->assertSame(1, BusinessKnowledgeProfile::where('business_id', $business->id)->count());
    }

    /**
     * An unrelated database failure (here: a foreign-key violation,
     * MySQL error 1452, because $business->id references no real row --
     * Laravel classifies this as a plain QueryException, never
     * UniqueConstraintViolationException, per MySqlConnection::
     * isUniqueConstraintError()'s own 1062-only check) must never be
     * reinterpreted as "someone else already created the Profile." It
     * propagates unchanged, and no Profile row is left behind.
     */
    public function test_get_or_create_does_not_swallow_an_unrelated_database_error(): void
    {
        $phantomBusiness = new \App\Models\Business();
        $phantomBusiness->id = 999999999;

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/1452|foreign key constraint/i');

        try {
            $this->manager->getOrCreate($phantomBusiness);
        } finally {
            $this->assertSame(0, BusinessKnowledgeProfile::where('business_id', 999999999)->count());
        }
    }

    /**
     * A UniqueConstraintViolationException IS caught, but only resolved
     * to an existing row when the violated constraint is genuinely
     * business_id's own unique index -- confirmed here by asserting the
     * exact constraint name the narrowing check depends on is still the
     * one actually enforced by migration 1's schema (a schema/migration
     * rename that silently broke this string match would otherwise pass
     * every other test in this suite while quietly widening or
     * disabling the race-safety check).
     */
    public function test_the_narrow_race_check_names_the_actual_enforced_constraint(): void
    {
        [$business] = $this->profileFixtureBusiness();
        BusinessKnowledgeProfile::create(['business_id' => $business->id, 'reviews_source' => 'none']);

        try {
            BusinessKnowledgeProfile::create(['business_id' => $business->id, 'reviews_source' => 'none']);
            $this->fail('Expected a UniqueConstraintViolationException.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('business_knowledge_profiles_business_id_unique', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // updateFields() — allowlist and seam rejections
    // -----------------------------------------------------------------

    public function test_update_fields_rejects_an_unknown_field_key(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);

        $this->manager->updateFields($business, ['not_a_real_field' => 'x'], 'manual_edit', $this->actorUserId());
    }

    public function test_update_fields_rejects_hours_as_a_key(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);

        $this->manager->updateFields($business, ['hours' => ['monday' => []]], 'manual_edit', $this->actorUserId());
    }

    public function test_update_fields_rejects_a_direct_reviews_source_write(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);

        $this->manager->updateFields($business, ['reviews_source' => 'manual_verified'], 'manual_edit', $this->actorUserId());
    }

    public function test_update_fields_rejects_an_unknown_source(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);

        $this->manager->updateFields($business, ['brand_voice' => 'Friendly.'], 'not_a_real_source', $this->actorUserId());
    }

    // -----------------------------------------------------------------
    // Field validation — every JSON/text/enum field
    // -----------------------------------------------------------------

    public function test_pricing_method_accepts_only_its_four_contracted_values_and_casts_to_the_enum(): void
    {
        [$business] = $this->profileFixtureBusiness();

        foreach (BusinessPricingMethod::cases() as $case) {
            $profile = $this->manager->updateFields($business, ['pricing_method' => $case->value], 'manual_edit', $this->actorUserId());
            $this->assertInstanceOf(BusinessPricingMethod::class, $profile->pricing_method);
            $this->assertSame($case, $profile->pricing_method);

            $raw = \Illuminate\Support\Facades\DB::table('business_knowledge_profiles')->where('business_id', $business->id)->value('pricing_method');
            $this->assertSame($case->value, $raw);
        }

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['pricing_method' => 'financing_available'], 'manual_edit', $this->actorUserId());
    }

    public function test_pricing_method_hydrates_as_an_enum_instance_after_a_fresh_fetch(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->manager->updateFields($business, ['pricing_method' => 'fixed'], 'manual_edit', $this->actorUserId());

        $reloaded = BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $this->assertInstanceOf(BusinessPricingMethod::class, $reloaded->pricing_method);
        $this->assertSame(BusinessPricingMethod::Fixed, $reloaded->pricing_method);
    }

    public function test_financing_available_is_independent_of_pricing_method(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $profile = $this->manager->updateFields($business, [
            'pricing_method' => 'fixed',
            'financing_available' => true,
        ], 'manual_edit', $this->actorUserId());

        $this->assertSame(BusinessPricingMethod::Fixed, $profile->pricing_method);
        $this->assertTrue($profile->financing_available);
    }

    public function test_primary_conversion_goal_accepts_only_its_five_contracted_values_and_casts_to_the_enum(): void
    {
        [$business] = $this->profileFixtureBusiness();

        foreach (BusinessPrimaryConversionGoal::cases() as $case) {
            $profile = $this->manager->updateFields($business, ['primary_conversion_goal' => $case->value], 'manual_edit', $this->actorUserId());
            $this->assertInstanceOf(BusinessPrimaryConversionGoal::class, $profile->primary_conversion_goal);
            $this->assertSame($case, $profile->primary_conversion_goal);

            $raw = \Illuminate\Support\Facades\DB::table('business_knowledge_profiles')->where('business_id', $business->id)->value('primary_conversion_goal');
            $this->assertSame($case->value, $raw);
        }

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['primary_conversion_goal' => 'other'], 'manual_edit', $this->actorUserId());
    }

    public function test_primary_conversion_goal_rejects_a_non_string_value(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['primary_conversion_goal' => 123], 'manual_edit', $this->actorUserId());
    }

    public function test_pricing_method_no_op_detection_works_with_the_enum_cast_and_creates_no_false_change_row(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $actorId = $this->actorUserId();

        $this->manager->updateFields($business, ['pricing_method' => 'fixed'], 'manual_edit', $actorId);
        $countAfterFirstWrite = BusinessKnowledgeProfileChange::where('business_id', $business->id)->where('field_key', 'pricing_method')->count();
        $this->assertSame(1, $countAfterFirstWrite);

        // Re-submitting the identical contracted string is a true no-op
        // once cast back to the same enum instance -- no second change
        // row, no spurious "changed" detection caused by the cast.
        $this->manager->updateFields($business, ['pricing_method' => 'fixed'], 'manual_edit', $actorId);
        $countAfterNoOp = BusinessKnowledgeProfileChange::where('business_id', $business->id)->where('field_key', 'pricing_method')->count();
        $this->assertSame(1, $countAfterNoOp);

        // A genuine change still produces exactly one more row.
        $this->manager->updateFields($business, ['pricing_method' => 'hourly'], 'manual_edit', $actorId);
        $countAfterRealChange = BusinessKnowledgeProfileChange::where('business_id', $business->id)->where('field_key', 'pricing_method')->count();
        $this->assertSame(2, $countAfterRealChange);
    }

    public function test_conversion_target_only_accepts_tel_mailto_https(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $profile = $this->manager->updateFields($business, ['conversion_target' => 'tel:+15551234567'], 'manual_edit', $this->actorUserId());
        $this->assertSame('tel:+15551234567', $profile->conversion_target);

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['conversion_target' => 'http://example.test'], 'manual_edit', $this->actorUserId());
    }

    public function test_offers_enforces_bounds_and_shape(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $offers = array_fill(0, 12, ['name' => 'Offer', 'description' => null, 'price_label' => null, 'pricing_method_override' => null]);
        $profile = $this->manager->updateFields($business, ['offers' => $offers], 'manual_edit', $this->actorUserId());
        $this->assertCount(12, $profile->offers);

        $tooMany = array_fill(0, 13, ['name' => 'Offer']);
        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['offers' => $tooMany], 'manual_edit', $this->actorUserId());
    }

    public function test_offer_pricing_method_override_must_be_a_valid_pricing_method(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $profile = $this->manager->updateFields($business, [
            'offers' => [['name' => 'Install', 'pricing_method_override' => 'package_tiers']],
        ], 'manual_edit', $this->actorUserId());

        $this->assertSame('package_tiers', $profile->offers[0]['pricing_method_override']);

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, [
            'offers' => [['name' => 'Install', 'pricing_method_override' => 'not_a_method']],
        ], 'manual_edit', $this->actorUserId());
    }

    public function test_differentiators_enforces_max_count_and_max_length(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $profile = $this->manager->updateFields($business, [
            'differentiators' => array_fill(0, 6, 'x'),
        ], 'manual_edit', $this->actorUserId());
        $this->assertCount(6, $profile->differentiators);

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['differentiators' => array_fill(0, 7, 'x')], 'manual_edit', $this->actorUserId());
    }

    public function test_differentiators_rejects_an_overlong_entry(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['differentiators' => [str_repeat('a', 121)]], 'manual_edit', $this->actorUserId());
    }

    public function test_ideal_customers_enforces_max_length(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $profile = $this->manager->updateFields($business, ['ideal_customers' => str_repeat('a', 500)], 'manual_edit', $this->actorUserId());
        $this->assertSame(500, strlen($profile->ideal_customers));

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['ideal_customers' => str_repeat('a', 501)], 'manual_edit', $this->actorUserId());
    }

    public function test_customer_problems_enforces_bounds(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['customer_problems' => array_fill(0, 7, 'x')], 'manual_edit', $this->actorUserId());
    }

    public function test_credentials_requires_label_and_boolean_verified(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $profile = $this->manager->updateFields($business, [
            'credentials' => [['label' => 'Licensed Contractor', 'verified' => true]],
        ], 'manual_edit', $this->actorUserId());
        $this->assertTrue($profile->credentials[0]['verified']);

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['credentials' => [['label' => 'Licensed Contractor']]], 'manual_edit', $this->actorUserId());
    }

    public function test_credentials_enforces_max_count_of_ten(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, [
            'credentials' => array_fill(0, 11, ['label' => 'x', 'verified' => false]),
        ], 'manual_edit', $this->actorUserId());
    }

    public function test_years_operating_must_be_a_non_negative_integer(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $profile = $this->manager->updateFields($business, ['years_operating' => 12], 'manual_edit', $this->actorUserId());
        $this->assertSame(12, $profile->years_operating);

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['years_operating' => -1], 'manual_edit', $this->actorUserId());
    }

    public function test_warranties_guarantees_enforces_max_length(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['warranties_guarantees' => str_repeat('a', 501)], 'manual_edit', $this->actorUserId());
    }

    public function test_brand_voice_enforces_max_length(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['brand_voice' => str_repeat('a', 501)], 'manual_edit', $this->actorUserId());
    }

    public function test_prohibited_claims_enforces_bounds(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $profile = $this->manager->updateFields($business, [
            'prohibited_claims' => array_fill(0, 15, 'no guarantees'),
        ], 'manual_edit', $this->actorUserId());
        $this->assertCount(15, $profile->prohibited_claims);

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['prohibited_claims' => array_fill(0, 16, 'x')], 'manual_edit', $this->actorUserId());
    }

    public function test_vertical_key_cannot_be_set_until_the_catalog_exists_slice_1_behavior(): void
    {
        [$business] = $this->profileFixtureBusiness();

        // Null is always valid (clearing / never-set).
        $profile = $this->manager->updateFields($business, ['vertical_key' => null], 'manual_edit', $this->actorUserId());
        $this->assertNull($profile->vertical_key);

        // business_verticals does not exist in Slice 1 -- any non-null
        // value fails safely rather than writing an unvalidated value.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('business_verticals'));

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $this->actorUserId());
    }

    // -----------------------------------------------------------------
    // Testimonials + derived reviews_source
    // -----------------------------------------------------------------

    public function test_testimonials_enforces_bounds_and_shape(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $profile = $this->manager->updateFields($business, [
            'testimonials' => [['quote' => 'Great work!', 'author_name' => 'Jane Doe', 'author_title' => 'Homeowner']],
        ], 'manual_edit', $this->actorUserId());

        $this->assertCount(1, $profile->testimonials);
        $this->assertSame('manual_verified', $profile->reviews_source);

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, [
            'testimonials' => array_fill(0, 6, ['quote' => 'x', 'author_name' => 'y', 'author_title' => null]),
        ], 'manual_edit', $this->actorUserId());
    }

    public function test_testimonials_requires_author_name(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['testimonials' => [['quote' => 'Nice!']]], 'manual_edit', $this->actorUserId());
    }

    public function test_reviews_source_is_none_when_testimonials_is_empty_and_manual_verified_otherwise(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $profile = $this->manager->getOrCreate($business);
        $this->assertSame('none', $profile->reviews_source);

        $profile = $this->manager->updateFields($business, [
            'testimonials' => [['quote' => 'Great!', 'author_name' => 'A', 'author_title' => null]],
        ], 'manual_edit', $this->actorUserId());
        $this->assertSame('manual_verified', $profile->reviews_source);

        $profile = $this->manager->updateFields($business, ['testimonials' => []], 'manual_edit', $this->actorUserId());
        $this->assertSame('none', $profile->reviews_source);
    }

    public function test_reviews_source_never_reaches_gbp_future(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $profile = $this->manager->updateFields($business, [
            'testimonials' => [['quote' => 'Great!', 'author_name' => 'A', 'author_title' => null]],
        ], 'manual_edit', $this->actorUserId());

        $this->assertContains($profile->reviews_source, ['none', 'manual_verified']);
        $this->assertNotSame('gbp_future', $profile->reviews_source);
    }

    // -----------------------------------------------------------------
    // Cross-Business ownership for growth-priority IDs
    // -----------------------------------------------------------------

    public function test_growth_priority_service_ids_must_belong_to_this_business(): void
    {
        [$business] = $this->profileFixtureBusiness();
        [$otherBusiness] = $this->profileFixtureBusiness();

        $ownService = $this->addService($business);
        $foreignService = $this->addService($otherBusiness);

        $profile = $this->manager->updateFields($business, [
            'growth_priority_service_ids' => [$ownService->id],
        ], 'manual_edit', $this->actorUserId());
        $this->assertSame([$ownService->id], $profile->growth_priority_service_ids);

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, [
            'growth_priority_service_ids' => [$ownService->id, $foreignService->id],
        ], 'manual_edit', $this->actorUserId());
    }

    public function test_growth_priority_location_ids_must_belong_to_this_business(): void
    {
        [$business] = $this->profileFixtureBusiness();
        [$otherBusiness] = $this->profileFixtureBusiness();

        $foreignLocation = $this->addLocation($otherBusiness);

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, [
            'growth_priority_location_ids' => [$foreignLocation->id],
        ], 'manual_edit', $this->actorUserId());
    }

    public function test_growth_priority_service_ids_preserves_stored_order(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $a = $this->addService($business);
        $b = $this->addService($business);

        $profile = $this->manager->updateFields($business, [
            'growth_priority_service_ids' => [$b->id, $a->id],
        ], 'manual_edit', $this->actorUserId());

        $this->assertSame([$b->id, $a->id], $profile->growth_priority_service_ids);
    }

    // -----------------------------------------------------------------
    // Atomicity, no-op behavior, change history, field-state provenance
    // -----------------------------------------------------------------

    public function test_a_single_invalid_field_rejects_the_entire_batch(): void
    {
        [$business] = $this->profileFixtureBusiness();

        try {
            $this->manager->updateFields($business, [
                'brand_voice' => 'Friendly and direct.',
                'years_operating' => -5,
            ], 'manual_edit', $this->actorUserId());
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $this->assertNull($profile?->brand_voice);
        $this->assertSame(0, BusinessKnowledgeProfileChange::where('business_id', $business->id)->count());
    }

    public function test_no_change_row_is_created_for_a_true_no_op(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $actorId = $this->actorUserId();

        $this->manager->updateFields($business, ['brand_voice' => 'Friendly.'], 'manual_edit', $actorId);
        $this->assertSame(1, BusinessKnowledgeProfileChange::where('business_id', $business->id)->count());

        // Identical value again — a true no-op.
        $this->manager->updateFields($business, ['brand_voice' => 'Friendly.'], 'manual_edit', $actorId);
        $this->assertSame(1, BusinessKnowledgeProfileChange::where('business_id', $business->id)->count());
    }

    public function test_one_change_row_is_appended_per_genuinely_changed_field(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $actorId = $this->actorUserId();

        $this->manager->updateFields($business, [
            'brand_voice' => 'Friendly.',
            'ideal_customers' => 'Homeowners.',
        ], 'manual_edit', $actorId);

        $this->assertSame(2, BusinessKnowledgeProfileChange::where('business_id', $business->id)->count());

        // Changing only one of the two previously-set fields appends
        // exactly one more change row, not two.
        $this->manager->updateFields($business, [
            'brand_voice' => 'Friendly.',
            'ideal_customers' => 'Homeowners and landlords.',
        ], 'manual_edit', $actorId);

        $this->assertSame(3, BusinessKnowledgeProfileChange::where('business_id', $business->id)->count());

        $change = BusinessKnowledgeProfileChange::where('business_id', $business->id)
            ->where('field_key', 'ideal_customers')
            ->latest('id')
            ->first();

        $this->assertSame('Homeowners.', $change->old_value);
        $this->assertSame('Homeowners and landlords.', $change->new_value);
    }

    public function test_field_state_provenance_and_verification_timestamps_are_recorded(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $actorId = $this->actorUserId();

        $this->manager->updateFields($business, ['brand_voice' => 'Friendly.'], 'onboarding', $actorId, markVerified: true);

        $state = BusinessKnowledgeProfileFieldState::where('business_id', $business->id)
            ->where('field_key', 'brand_voice')
            ->first();

        $this->assertSame('onboarding', $state->source);
        $this->assertSame(BusinessKnowledgeProfileFieldState::STATUS_CUSTOMER_CONFIRMED, $state->verification_status);
        $this->assertSame($actorId, $state->verified_by_user_id);
        $this->assertNotNull($state->verified_at);
    }

    public function test_field_state_is_unverified_when_mark_verified_is_false(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->manager->updateFields($business, ['brand_voice' => 'Friendly.'], 'manual_edit', $this->actorUserId());

        $state = BusinessKnowledgeProfileFieldState::where('business_id', $business->id)
            ->where('field_key', 'brand_voice')
            ->first();

        $this->assertSame(BusinessKnowledgeProfileFieldState::STATUS_UNVERIFIED, $state->verification_status);
        $this->assertNull($state->verified_by_user_id);
        $this->assertNull($state->verified_at);
    }

    public function test_update_fields_writes_atomically_inside_one_transaction(): void
    {
        // Mechanical proof: the method body wraps its writes in
        // DB::transaction(), matching WebsiteDraftPageService's own
        // transactional shape (§4.3).
        $source = file_get_contents(app_path('Library/Business/BusinessKnowledgeProfileManager.php'));
        $this->assertStringContainsString('DB::transaction(function ()', $source);
    }
}
