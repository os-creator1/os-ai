<?php

namespace Tests\Feature\Business;

use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Models\BusinessKnowledgeProfileChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Business\Concerns\CreatesBusinessKnowledgeProfileFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §5.5, §16 Slice 1 --
 * BusinessKnowledgeProfileManager::updateLocationHours() validation,
 * provenance, and change-history behavior.
 */
class BusinessKnowledgeProfileHoursTest extends TestCase
{
    use CreatesBusinessKnowledgeProfileFixtures;
    use RefreshDatabase;

    private BusinessKnowledgeProfileManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(BusinessKnowledgeProfileManager::class);
    }

    private function fullWeek(array $overrides = []): array
    {
        return array_merge([
            'monday' => [], 'tuesday' => [], 'wednesday' => [], 'thursday' => [],
            'friday' => [], 'saturday' => [], 'sunday' => [],
        ], $overrides);
    }

    public function test_hours_belong_to_the_location_not_the_profile(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $updated = $this->manager->updateLocationHours(
            $business,
            $location,
            $this->fullWeek(['monday' => [['open' => '09:00', 'close' => '17:00']]]),
            'manual_edit',
            $this->actorUserId(),
        );

        $this->assertSame([['open' => '09:00', 'close' => '17:00']], $updated->hours['monday']);

        $profile = $this->manager->getOrCreate($business);
        $this->assertArrayNotHasKey('hours', $profile->getAttributes());
    }

    public function test_updating_hours_for_a_location_belonging_to_another_business_is_rejected(): void
    {
        [$business] = $this->profileFixtureBusiness();
        [$otherBusiness] = $this->profileFixtureBusiness();
        $foreignLocation = $this->addLocation($otherBusiness);

        $this->expectException(ValidationException::class);
        $this->manager->updateLocationHours($business, $foreignLocation, $this->fullWeek(), 'manual_edit', $this->actorUserId());
    }

    public function test_four_non_overlapping_periods_in_one_day_are_accepted(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $periods = [
            ['open' => '06:00', 'close' => '08:00'],
            ['open' => '09:00', 'close' => '11:00'],
            ['open' => '12:00', 'close' => '14:00'],
            ['open' => '15:00', 'close' => '17:00'],
        ];

        $updated = $this->manager->updateLocationHours($business, $location, $this->fullWeek(['monday' => $periods]), 'manual_edit', $this->actorUserId());

        $this->assertCount(4, $updated->hours['monday']);
    }

    public function test_a_fifth_period_in_one_day_is_rejected(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $periods = [
            ['open' => '00:00', 'close' => '01:00'],
            ['open' => '02:00', 'close' => '03:00'],
            ['open' => '04:00', 'close' => '05:00'],
            ['open' => '06:00', 'close' => '07:00'],
            ['open' => '08:00', 'close' => '09:00'],
        ];

        $this->expectException(ValidationException::class);
        $this->manager->updateLocationHours($business, $location, $this->fullWeek(['monday' => $periods]), 'manual_edit', $this->actorUserId());
    }

    public function test_overlapping_periods_are_rejected(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $periods = [
            ['open' => '09:00', 'close' => '13:00'],
            ['open' => '12:00', 'close' => '17:00'],
        ];

        $this->expectException(ValidationException::class);
        $this->manager->updateLocationHours($business, $location, $this->fullWeek(['monday' => $periods]), 'manual_edit', $this->actorUserId());
    }

    public function test_periods_are_sorted_deterministically_regardless_of_input_order(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $periods = [
            ['open' => '15:00', 'close' => '17:00'],
            ['open' => '09:00', 'close' => '11:00'],
        ];

        $updated = $this->manager->updateLocationHours($business, $location, $this->fullWeek(['monday' => $periods]), 'manual_edit', $this->actorUserId());

        $this->assertSame('09:00', $updated->hours['monday'][0]['open']);
        $this->assertSame('15:00', $updated->hours['monday'][1]['open']);
    }

    public function test_close_less_than_or_equal_to_open_within_the_same_day_is_rejected(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $this->expectException(ValidationException::class);
        $this->manager->updateLocationHours($business, $location, $this->fullWeek([
            'monday' => [['open' => '17:00', 'close' => '09:00']],
        ]), 'manual_edit', $this->actorUserId());
    }

    public function test_close_exactly_equal_to_open_is_rejected(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $this->expectException(ValidationException::class);
        $this->manager->updateLocationHours($business, $location, $this->fullWeek([
            'monday' => [['open' => '09:00', 'close' => '09:00']],
        ]), 'manual_edit', $this->actorUserId());
    }

    public function test_24_00_is_accepted_only_as_a_closing_sentinel(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $updated = $this->manager->updateLocationHours($business, $location, $this->fullWeek([
            'monday' => [['open' => '20:00', 'close' => '24:00']],
        ]), 'manual_edit', $this->actorUserId());

        $this->assertSame('24:00', $updated->hours['monday'][0]['close']);
    }

    public function test_24_00_as_an_opening_time_is_rejected(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $this->expectException(ValidationException::class);
        $this->manager->updateLocationHours($business, $location, $this->fullWeek([
            'monday' => [['open' => '24:00', 'close' => '01:00']],
        ]), 'manual_edit', $this->actorUserId());
    }

    public function test_malformed_time_strings_are_rejected(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $this->expectException(ValidationException::class);
        $this->manager->updateLocationHours($business, $location, $this->fullWeek([
            'monday' => [['open' => '9:00', 'close' => '17:00']],
        ]), 'manual_edit', $this->actorUserId());
    }

    public function test_an_overnight_schedule_round_trips_as_two_entries_across_two_days(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $updated = $this->manager->updateLocationHours($business, $location, $this->fullWeek([
            'friday' => [['open' => '20:00', 'close' => '24:00']],
            'saturday' => [['open' => '00:00', 'close' => '02:00']],
        ]), 'manual_edit', $this->actorUserId());

        $this->assertSame([['open' => '20:00', 'close' => '24:00']], $updated->hours['friday']);
        $this->assertSame([['open' => '00:00', 'close' => '02:00']], $updated->hours['saturday']);
    }

    public function test_an_empty_array_for_every_day_means_closed_every_day_distinct_from_never_answered(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $this->assertNull($location->hours);

        $updated = $this->manager->updateLocationHours($business, $location, $this->fullWeek(), 'manual_edit', $this->actorUserId());

        $this->assertNotNull($updated->hours);
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $this->assertSame([], $updated->hours[$day]);
        }
    }

    public function test_a_missing_day_key_is_rejected(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $incomplete = $this->fullWeek();
        unset($incomplete['sunday']);

        $this->expectException(ValidationException::class);
        $this->manager->updateLocationHours($business, $location, $incomplete, 'manual_edit', $this->actorUserId());
    }

    public function test_writing_nothing_if_any_period_is_invalid(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $this->manager->updateLocationHours($business, $location, $this->fullWeek([
            'monday' => [['open' => '09:00', 'close' => '17:00']],
        ]), 'manual_edit', $this->actorUserId());

        try {
            $this->manager->updateLocationHours($business, $location, $this->fullWeek([
                'monday' => [['open' => '09:00', 'close' => '17:00']],
                'tuesday' => [['open' => '17:00', 'close' => '09:00']],
            ]), 'manual_edit', $this->actorUserId());
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $location->refresh();
        $this->assertSame([['open' => '09:00', 'close' => '17:00']], $location->hours['monday']);
        $this->assertSame([], $location->hours['tuesday']);
    }

    public function test_hours_provenance_and_verification_are_updated(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();
        $actorId = $this->actorUserId();

        $updated = $this->manager->updateLocationHours($business, $location, $this->fullWeek(), 'onboarding', $actorId, markVerified: true);

        $this->assertSame('onboarding', $updated->hours_source);
        $this->assertSame('customer_confirmed', $updated->hours_verification_status);
        $this->assertSame($actorId, $updated->hours_verified_by_user_id);
        $this->assertNotNull($updated->hours_verified_at);
    }

    public function test_hours_change_is_appended_through_the_manager_seam(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $this->manager->updateLocationHours($business, $location, $this->fullWeek([
            'monday' => [['open' => '09:00', 'close' => '17:00']],
        ]), 'manual_edit', $this->actorUserId());

        $change = BusinessKnowledgeProfileChange::where('business_id', $business->id)
            ->where('field_key', 'hours')
            ->first();

        $this->assertNotNull($change);
        $this->assertSame($location->id, $change->new_value['business_location_id']);
    }

    public function test_no_change_row_for_a_true_hours_no_op(): void
    {
        [$business, $location] = $this->profileFixtureBusinessWithPrimaryLocation();

        $this->manager->updateLocationHours($business, $location, $this->fullWeek(), 'manual_edit', $this->actorUserId());
        $this->assertSame(1, BusinessKnowledgeProfileChange::where('business_id', $business->id)->where('field_key', 'hours')->count());

        $this->manager->updateLocationHours($business, $location, $this->fullWeek(), 'manual_edit', $this->actorUserId());
        $this->assertSame(1, BusinessKnowledgeProfileChange::where('business_id', $business->id)->where('field_key', 'hours')->count());
    }
}
