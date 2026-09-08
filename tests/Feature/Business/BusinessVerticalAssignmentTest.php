<?php

namespace Tests\Feature\Business;

use App\Library\Business\BusinessKnowledgeProfileManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Business\Concerns\CreatesBusinessKnowledgeProfileFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §6.1, §16 Slice 2 --
 * BusinessKnowledgeProfileManager::updateFields()'s vertical_key
 * validation against the now-existing business_verticals catalog.
 */
class BusinessVerticalAssignmentTest extends TestCase
{
    use CreatesBusinessKnowledgeProfileFixtures;
    use RefreshDatabase;

    private BusinessKnowledgeProfileManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(BusinessKnowledgeProfileManager::class);
    }

    public function test_a_valid_active_vertical_key_is_accepted(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $this->createVertical(['key' => 'roofing', 'display_name' => 'Roofing']);

        $profile = $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $this->actorUserId());

        $this->assertSame('roofing', $profile->vertical_key);
    }

    public function test_an_unknown_vertical_key_is_rejected(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['vertical_key' => 'not_a_real_vertical'], 'manual_edit', $this->actorUserId());
    }

    public function test_an_inactive_vertical_key_is_rejected(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $this->createVertical(['key' => 'retired_trade', 'is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['vertical_key' => 'retired_trade'], 'manual_edit', $this->actorUserId());
    }

    public function test_a_malformed_vertical_key_value_is_rejected(): void
    {
        [$business] = $this->profileFixtureBusiness();

        $this->expectException(ValidationException::class);
        $this->manager->updateFields($business, ['vertical_key' => 12345], 'manual_edit', $this->actorUserId());
    }

    public function test_clearing_vertical_key_to_null_is_always_valid(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $this->createVertical(['key' => 'roofing']);
        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $this->actorUserId());

        $profile = $this->manager->updateFields($business, ['vertical_key' => null], 'manual_edit', $this->actorUserId());

        $this->assertNull($profile->vertical_key);
    }

    public function test_selecting_a_vertical_is_tracked_via_field_states_like_any_other_field(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $this->createVertical(['key' => 'roofing']);
        $actorId = $this->actorUserId();

        $this->manager->updateFields($business, ['vertical_key' => 'roofing'], 'manual_edit', $actorId, markVerified: true);

        $state = \App\Models\BusinessKnowledgeProfileFieldState::where('business_id', $business->id)
            ->where('field_key', 'vertical_key')
            ->first();

        $this->assertNotNull($state);
        $this->assertSame('customer_confirmed', $state->verification_status);
    }

    public function test_a_vertical_never_becomes_a_new_business_industry_case(): void
    {
        $this->createVertical(['key' => 'roofing']);
        $cases = array_column(\App\Enums\Business\BusinessIndustry::cases(), 'value');

        $this->assertNotContains('roofing', $cases);
    }
}
