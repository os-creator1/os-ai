<?php

namespace Tests\Feature\Business;

use App\Library\Business\BusinessKnowledgeProfileManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessKnowledgeProfileFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §6.3, §16 Slice 2 -- the locked
 * pack-resolution order, wired through
 * BusinessKnowledgeProfileManager::completenessCheck(): exact vertical,
 * then broad industry, then the general fallback (both applies_to_*
 * columns null) -- each step filtered to is_active = true and taking
 * the highest version. A plain, deterministic lookup; no AI involved.
 */
class QuestionPackResolutionTest extends TestCase
{
    use CreatesBusinessKnowledgeProfileFixtures;
    use RefreshDatabase;

    private BusinessKnowledgeProfileManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(BusinessKnowledgeProfileManager::class);
    }

    public function test_no_pack_resolves_when_none_exist(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $result = $this->manager->completenessCheck($business);

        $this->assertNull($result->questionPack);
    }

    public function test_general_fallback_resolves_when_only_the_general_shape_exists(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'other']);
        $general = $this->createQuestionPack(['key' => 'general', 'applies_to_industry' => null, 'applies_to_vertical_key' => null]);

        $result = $this->manager->completenessCheck($business);

        $this->assertNotNull($result->questionPack);
        $this->assertSame($general->id, $result->questionPack->id);
    }

    public function test_broad_industry_pack_wins_over_general_fallback(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        $this->createQuestionPack(['key' => 'general']);
        $homeServices = $this->createQuestionPack(['key' => 'home_services', 'applies_to_industry' => 'home_services']);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($homeServices->id, $result->questionPack->id);
    }

    public function test_exact_vertical_pack_wins_over_broad_industry_and_general(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        $this->createVertical(['key' => 'roofing', 'broad_industry' => 'home_services']);
        $actorId = $this->actorUserId();
        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $actorId, markVerified: true);

        $this->createQuestionPack(['key' => 'general']);
        $this->createQuestionPack(['key' => 'home_services', 'applies_to_industry' => 'home_services']);
        $roofing = $this->createQuestionPack(['key' => 'roofing', 'applies_to_vertical_key' => 'roofing']);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($roofing->id, $result->questionPack->id);
    }

    public function test_falls_back_to_industry_when_the_confirmed_vertical_has_no_pack(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        $this->createVertical(['key' => 'roofing', 'broad_industry' => 'home_services']);
        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $this->actorUserId(), markVerified: true);

        $homeServices = $this->createQuestionPack(['key' => 'home_services', 'applies_to_industry' => 'home_services']);
        // No pack targets 'roofing' at all.

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($homeServices->id, $result->questionPack->id);
    }

    public function test_an_unconfirmed_vertical_key_is_never_used_for_exact_pack_resolution(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        $this->createVertical(['key' => 'roofing', 'broad_industry' => 'home_services']);
        // markVerified is deliberately omitted (defaults false) -- an
        // AI-inferred or imported vertical_key that has not yet been
        // customer-confirmed must never unlock the exact-vertical pack.
        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $this->actorUserId());

        $this->createQuestionPack(['key' => 'roofing', 'applies_to_vertical_key' => 'roofing']);
        $homeServices = $this->createQuestionPack(['key' => 'home_services', 'applies_to_industry' => 'home_services']);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($homeServices->id, $result->questionPack->id);
    }

    public function test_a_vertical_deactivated_after_confirmation_is_never_used_for_exact_pack_resolution(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        $this->createVertical(['key' => 'roofing', 'broad_industry' => 'home_services']);
        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $this->actorUserId(), markVerified: true);

        // The operator later deactivates the vertical the customer
        // already confirmed.
        \App\Models\BusinessVertical::where('key', 'roofing')->update(['is_active' => false]);

        $this->createQuestionPack(['key' => 'roofing', 'applies_to_vertical_key' => 'roofing']);
        $homeServices = $this->createQuestionPack(['key' => 'home_services', 'applies_to_industry' => 'home_services']);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($homeServices->id, $result->questionPack->id);
    }

    /**
     * businesses.industry is a required (non-nullable) column, so a
     * null industry can only occur on an in-memory Business instance
     * (e.g. a future nullable-industry migration, or a not-yet-fully-
     * populated import). The industry step must still be provably
     * skipped rather than accidentally matching the general-shaped
     * fallback row's `applies_to_industry IS NULL` shape.
     */
    public function test_a_null_industry_never_leaks_into_selecting_an_unrelated_vertical_targeted_pack(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        $business->industry = null;
        $this->createVertical(['key' => 'roofing']);
        // A vertical-targeted pack also has applies_to_industry = null
        // (§6.2: at most one of the two columns is non-null). The
        // uncorrected industry-step query filtered only on
        // applies_to_industry, so a null $business->industry could
        // wrongly match this row instead of skipping the step entirely.
        $this->createQuestionPack(['key' => 'roofing', 'applies_to_vertical_key' => 'roofing']);
        $general = $this->createQuestionPack(['key' => 'general']);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($general->id, $result->questionPack->id);
    }

    public function test_a_null_industry_business_with_no_general_pack_resolves_no_pack(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        $business->industry = null;
        $this->createQuestionPack(['key' => 'home_services', 'applies_to_industry' => 'home_services']);

        $result = $this->manager->completenessCheck($business);

        $this->assertNull($result->questionPack);
    }

    public function test_two_competing_active_families_for_the_same_scope_are_rejected_at_write_time(): void
    {
        $this->createQuestionPack(['key' => 'home_services', 'applies_to_industry' => 'home_services']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->createQuestionPack(['key' => 'home_services_alt', 'applies_to_industry' => 'home_services']);
    }

    public function test_a_second_version_of_the_same_family_is_not_rejected_as_a_competing_family(): void
    {
        $this->createQuestionPack(['key' => 'home_services', 'applies_to_industry' => 'home_services', 'version' => 1]);
        $v2 = $this->createQuestionPack(['key' => 'home_services', 'applies_to_industry' => 'home_services', 'version' => 2]);

        $this->assertSame(2, $v2->version);
    }

    public function test_two_competing_active_families_targeting_the_same_vertical_are_rejected_at_write_time(): void
    {
        $this->createVertical(['key' => 'roofing']);
        $this->createQuestionPack(['key' => 'roofing', 'applies_to_vertical_key' => 'roofing']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->createQuestionPack(['key' => 'roofing_specialists', 'applies_to_vertical_key' => 'roofing']);
    }

    public function test_deactivating_the_competing_family_allows_a_new_family_to_take_the_scope(): void
    {
        $this->createQuestionPack(['key' => 'home_services', 'applies_to_industry' => 'home_services', 'is_active' => false]);
        $replacement = $this->createQuestionPack(['key' => 'home_services_v2', 'applies_to_industry' => 'home_services']);

        $this->assertTrue($replacement->is_active);
    }

    public function test_two_competing_general_shaped_families_are_rejected_since_the_shape_is_reserved_to_the_general_key(): void
    {
        $this->createQuestionPack(['key' => 'general']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->createQuestionPack(['key' => 'general_alt', 'applies_to_industry' => null, 'applies_to_vertical_key' => null]);
    }

    public function test_the_highest_active_version_is_selected(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $this->createQuestionPack(['key' => 'general', 'version' => 1]);
        $this->createQuestionPack(['key' => 'general', 'version' => 2]);
        $v3 = $this->createQuestionPack(['key' => 'general', 'version' => 3]);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($v3->id, $result->questionPack->id);
        $this->assertSame(3, $result->questionPack->version);
    }

    public function test_an_inactive_pack_is_never_selected_even_at_the_highest_version(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $active = $this->createQuestionPack(['key' => 'general', 'version' => 1, 'is_active' => true]);
        $this->createQuestionPack(['key' => 'general', 'version' => 2, 'is_active' => false]);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($active->id, $result->questionPack->id);
    }

    public function test_an_inactive_vertical_specific_pack_falls_through_to_industry(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        $this->createVertical(['key' => 'roofing', 'broad_industry' => 'home_services']);
        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $this->actorUserId(), markVerified: true);

        $this->createQuestionPack(['key' => 'roofing', 'applies_to_vertical_key' => 'roofing', 'is_active' => false]);
        $homeServices = $this->createQuestionPack(['key' => 'home_services', 'applies_to_industry' => 'home_services']);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($homeServices->id, $result->questionPack->id);
    }

    public function test_deterministic_question_ordering_is_preserved_from_storage(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $questions = [
            ['field_key' => 'brand_voice', 'prompt' => 'First question', 'input_type' => 'textarea', 'options' => null, 'required' => false],
            ['field_key' => 'ideal_customers', 'prompt' => 'Second question', 'input_type' => 'textarea', 'options' => null, 'required' => false],
            ['field_key' => 'years_operating', 'prompt' => 'Third question', 'input_type' => 'text', 'options' => null, 'required' => false],
        ];
        $this->createQuestionPack(['key' => 'general'], $questions);

        $result = $this->manager->completenessCheck($business);
        $prompts = array_column($result->questionPack->questions, 'prompt');

        $this->assertSame(['First question', 'Second question', 'Third question'], $prompts);
    }

    public function test_resolution_uses_the_stable_vertical_key_never_the_display_name(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        // Deliberately confusing display names -- if resolution ever
        // compared by display_name instead of the stable key, this
        // fixture would make it pick the wrong catalog entry.
        $this->createVertical(['key' => 'roofing', 'display_name' => 'Gutter Specialists']);
        $this->createVertical(['key' => 'gutters', 'display_name' => 'Roofing & Gutters Specialist']);
        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $this->actorUserId(), markVerified: true);

        // A pack keyed by business_verticals.key -- even one whose OWN
        // vertical has a display name that looks like it should match
        // the OTHER vertical -- resolves purely by the stable key.
        $wrong = $this->createQuestionPack(['key' => 'wrong', 'applies_to_vertical_key' => 'gutters']);
        $right = $this->createQuestionPack(['key' => 'roofing', 'applies_to_vertical_key' => 'roofing']);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($right->id, $result->questionPack->id);
        $this->assertNotSame($wrong->id, $result->questionPack->id);
    }

    public function test_completeness_check_remains_read_only_when_resolving_a_pack(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $this->createQuestionPack(['key' => 'general']);

        $before = \App\Models\BusinessKnowledgeProfile::count();
        $this->manager->completenessCheck($business);
        $this->manager->completenessCheck($business);
        $after = \App\Models\BusinessKnowledgeProfile::count();

        $this->assertSame($before, $after);
        $this->assertSame(0, \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->count());
    }
}
