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
        $general = $this->createQuestionPack(['key' => 'general_v1', 'applies_to_industry' => null, 'applies_to_vertical_key' => null]);

        $result = $this->manager->completenessCheck($business);

        $this->assertNotNull($result->questionPack);
        $this->assertSame($general->id, $result->questionPack->id);
    }

    public function test_broad_industry_pack_wins_over_general_fallback(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        $this->createQuestionPack(['key' => 'general_v1']);
        $homeServices = $this->createQuestionPack(['key' => 'home_services_v1', 'applies_to_industry' => 'home_services']);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($homeServices->id, $result->questionPack->id);
    }

    public function test_exact_vertical_pack_wins_over_broad_industry_and_general(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        $this->createVertical(['key' => 'roofing', 'broad_industry' => 'home_services']);
        $actorId = $this->actorUserId();
        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $actorId);

        $this->createQuestionPack(['key' => 'general_v1']);
        $this->createQuestionPack(['key' => 'home_services_v1', 'applies_to_industry' => 'home_services']);
        $roofing = $this->createQuestionPack(['key' => 'roofing_v1', 'applies_to_vertical_key' => 'roofing']);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($roofing->id, $result->questionPack->id);
    }

    public function test_falls_back_to_industry_when_the_confirmed_vertical_has_no_pack(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        $this->createVertical(['key' => 'roofing', 'broad_industry' => 'home_services']);
        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $this->actorUserId());

        $homeServices = $this->createQuestionPack(['key' => 'home_services_v1', 'applies_to_industry' => 'home_services']);
        // No pack targets 'roofing' at all.

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($homeServices->id, $result->questionPack->id);
    }

    public function test_the_highest_active_version_is_selected(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $this->createQuestionPack(['key' => 'general_v1', 'version' => 1]);
        $this->createQuestionPack(['key' => 'general_v1', 'version' => 2]);
        $v3 = $this->createQuestionPack(['key' => 'general_v1', 'version' => 3]);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($v3->id, $result->questionPack->id);
        $this->assertSame(3, $result->questionPack->version);
    }

    public function test_an_inactive_pack_is_never_selected_even_at_the_highest_version(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $active = $this->createQuestionPack(['key' => 'general_v1', 'version' => 1, 'is_active' => true]);
        $this->createQuestionPack(['key' => 'general_v1', 'version' => 2, 'is_active' => false]);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($active->id, $result->questionPack->id);
    }

    public function test_an_inactive_vertical_specific_pack_falls_through_to_industry(): void
    {
        [$business] = $this->profileFixtureBusiness(['industry' => 'home_services']);
        $this->createVertical(['key' => 'roofing', 'broad_industry' => 'home_services']);
        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $this->actorUserId());

        $this->createQuestionPack(['key' => 'roofing_v1', 'applies_to_vertical_key' => 'roofing', 'is_active' => false]);
        $homeServices = $this->createQuestionPack(['key' => 'home_services_v1', 'applies_to_industry' => 'home_services']);

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
        $this->createQuestionPack(['key' => 'general_v1'], $questions);

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
        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $this->actorUserId());

        // A pack keyed by business_verticals.key -- even one whose OWN
        // vertical has a display name that looks like it should match
        // the OTHER vertical -- resolves purely by the stable key.
        $wrong = $this->createQuestionPack(['key' => 'wrong', 'applies_to_vertical_key' => 'gutters']);
        $right = $this->createQuestionPack(['key' => 'roofing_v1', 'applies_to_vertical_key' => 'roofing']);

        $result = $this->manager->completenessCheck($business);

        $this->assertSame($right->id, $result->questionPack->id);
        $this->assertNotSame($wrong->id, $result->questionPack->id);
    }

    public function test_completeness_check_remains_read_only_when_resolving_a_pack(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $this->createQuestionPack(['key' => 'general_v1']);

        $before = \App\Models\BusinessKnowledgeProfile::count();
        $this->manager->completenessCheck($business);
        $this->manager->completenessCheck($business);
        $after = \App\Models\BusinessKnowledgeProfile::count();

        $this->assertSame($before, $after);
        $this->assertSame(0, \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->count());
    }
}
