<?php

namespace Tests\Feature\V1Acceptance\CalendarBooking;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\StaffAvailabilityRule;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Acceptance journey B — availability is configured through the real UI by the
 * people §6 allows, is scoped to one Location, and feeds the public scheduler.
 */
class StaffAvailabilityJourneyTest extends CalendarJourneyTestCase
{
    private function rule(int $staffId, int $day = 1, string $from = '09:00', string $to = '12:00'): array
    {
        return ['staff_user_id' => $staffId, 'day_of_week' => $day, 'start_time' => $from, 'end_time' => $to];
    }

    /** A staff member eligible at $location ONLY (Selected scope, one grant). */
    private function grantedOnlyAt($location): User
    {
        return $this->memberGrantedOnly($location, WorkspaceMembershipRole::Staff);
    }

    public function test_owner_configures_availability_and_the_public_scheduler_offers_exactly_that_window(): void
    {
        $staff = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);
        $type = $this->typeWithStaff($this->locationA, $staff);

        $this->actAsOwner();
        $this->get($this->cal('availability.index', $this->locationA))
            ->assertOk()
            ->assertSee('data-section="availability-rules"', false)
            ->assertSee('No availability set for this location yet.');

        // Nobody has hours yet, so the public page is up but offers no times.
        $this->assertSame([], $this->offeredSlots($type, '2027-03-01'));

        $this->post($this->cal('availability.rules.store', $this->locationA), $this->rule((int) $staff->id, 1, '09:00', '12:00'))
            ->assertRedirect($this->cal('availability.index', $this->locationA));

        $this->assertDatabaseHas('staff_availability_rules', [
            'business_location_id' => $this->locationA->id,
            'staff_user_id' => $staff->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]);
        $this->get($this->cal('availability.index', $this->locationA))->assertOk()->assertSee('Monday');

        // A 60-minute type inside a 09:00–12:00 window: 09:00 … 11:00 and nothing else.
        $this->assertSame(['09:00', '09:30', '10:00', '10:30', '11:00'], $this->offeredSlots($type, '2027-03-01'));
        // Tuesday has no window.
        $this->assertSame([], $this->offeredSlots($type, '2027-03-02'));
    }

    public function test_a_split_shift_and_removal_through_the_ui(): void
    {
        $staff = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);
        $type = $this->typeWithStaff($this->locationA, $staff);
        $this->actAsOwner();

        $this->post($this->cal('availability.rules.store', $this->locationA), $this->rule((int) $staff->id, 1, '09:00', '10:00'));
        $this->post($this->cal('availability.rules.store', $this->locationA), $this->rule((int) $staff->id, 1, '14:00', '15:00'));
        $this->assertSame(['09:00', '14:00'], $this->offeredSlots($type, '2027-03-01'));

        $rule = StaffAvailabilityRule::query()->where('start_time', '14:00:00')->firstOrFail();
        $this->post($this->cal('availability.rules.destroy', $this->locationA, [$rule->id]))->assertRedirect();
        $this->assertSame(['09:00'], $this->offeredSlots($type, '2027-03-01'));

        // End before start is a validation error and writes nothing.
        $before = StaffAvailabilityRule::query()->count();
        $this->post($this->cal('availability.rules.store', $this->locationA), $this->rule((int) $staff->id, 1, '12:00', '09:00'))
            ->assertSessionHasErrors('end_time');
        $this->assertSame($before, StaffAvailabilityRule::query()->count());
    }

    public function test_availability_is_scoped_to_the_location_it_was_set_at(): void
    {
        $staff = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);
        $typeA = $this->typeWithStaff($this->locationA, $staff);
        $typeB = $this->typeWithStaff($this->locationB, $staff);

        $this->actAsOwner();
        $this->post($this->cal('availability.rules.store', $this->locationA), $this->rule((int) $staff->id));

        $this->assertSame(0, StaffAvailabilityRule::query()->where('business_location_id', $this->locationB->id)->count());
        $this->assertNotSame([], $this->offeredSlots($typeA, '2027-03-01'));
        // Same person, same hour, other Location: hours at A grant nothing at B.
        $this->assertSame([], $this->offeredSlots($typeB, '2027-03-01'));

        $this->get($this->cal('availability.index', $this->locationB))
            ->assertOk()
            ->assertSee('No availability set for this location yet.');

        // A rule id from A cannot be removed through B's route.
        $rule = StaffAvailabilityRule::query()->firstOrFail();
        $this->post($this->cal('availability.rules.destroy', $this->locationB, [$rule->id]))->assertNotFound();
        $this->assertDatabaseHas('staff_availability_rules', ['id' => $rule->id]);
    }

    public function test_selected_location_staff_cannot_reach_or_write_a_foreign_locations_schedule(): void
    {
        $atA = $this->grantedOnlyAt($this->locationA);
        $this->actAs($atA);

        $this->get($this->cal('availability.index', $this->locationA))->assertOk();
        $this->get($this->cal('availability.index', $this->locationB))->assertNotFound();

        // Writing at B — for themselves — is a 404 and persists nothing.
        $this->post($this->cal('availability.rules.store', $this->locationB), $this->rule((int) $atA->id))->assertNotFound();
        $this->post($this->cal('availability.time-off.store', $this->locationB), [
            'staff_user_id' => $atA->id, 'start_at' => '2027-03-01 08:00', 'end_at' => '2027-03-01 09:00',
        ])->assertNotFound();
        $this->assertSame(0, StaffAvailabilityRule::query()->count());
        $this->assertSame(0, StaffTimeOff::query()->count());

        // At their own Location they may set their OWN hours…
        $this->post($this->cal('availability.rules.store', $this->locationA), $this->rule((int) $atA->id))->assertRedirect();
        $this->assertSame(1, StaffAvailabilityRule::query()->where('staff_user_id', $atA->id)->count());
    }

    public function test_staff_may_only_manage_their_own_hours_and_the_owner_may_manage_anyones(): void
    {
        $staff = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);
        $colleague = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);
        $this->actAs($staff);

        // Another person's hours: 403 (visible resource, refused authority; the app renders every AuthorizationException as 401), nothing written.
        $this->post($this->cal('availability.rules.store', $this->locationA), $this->rule((int) $colleague->id))->assertStatus(self::REFUSED_AUTHORITY);
        $this->assertSame(0, StaffAvailabilityRule::query()->count());

        $this->post($this->cal('availability.rules.store', $this->locationA), $this->rule((int) $staff->id))->assertRedirect();
        $this->assertSame(1, StaffAvailabilityRule::query()->count());

        // The colleague's own rule cannot be removed by the first member.
        $this->actAs($colleague);
        $this->post($this->cal('availability.rules.store', $this->locationA), $this->rule((int) $colleague->id, 2));
        $theirs = StaffAvailabilityRule::query()->where('staff_user_id', $colleague->id)->firstOrFail();

        $this->actAs($staff);
        $this->post($this->cal('availability.rules.destroy', $this->locationA, [$theirs->id]))->assertStatus(self::REFUSED_AUTHORITY);
        $this->assertDatabaseHas('staff_availability_rules', ['id' => $theirs->id]);

        // The owner manages both.
        $this->actAsOwner();
        $this->post($this->cal('availability.rules.destroy', $this->locationA, [$theirs->id]))->assertRedirect();
        $this->assertDatabaseMissing('staff_availability_rules', ['id' => $theirs->id]);
    }

    public function test_no_foreign_or_stale_or_wrong_location_staff_can_be_given_availability(): void
    {
        [, , $foreignOwner] = $this->foreignBusinessWithLocation();
        $onlyB = $this->grantedOnlyAt($this->locationB);
        $lapsed = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);
        DB::table('workspace_memberships')->where('user_id', $lapsed->id)->update(['is_active' => false]);

        $this->actAsOwner();

        foreach ([
            'outsider (no membership)' => $this->outsider->id,
            'another Business\'s owner' => $foreignOwner->user_id,
            'staff granted only the sibling Location' => $onlyB->id,
            'lapsed membership' => $lapsed->id,
            'nonexistent user' => 987654,
        ] as $label => $targetId) {
            $rule = $this->post($this->cal('availability.rules.store', $this->locationA), $this->rule((int) $targetId));
            $this->assertSame(self::REFUSED_AUTHORITY, $rule->status(), "rule for {$label}");
            $off = $this->post($this->cal('availability.time-off.store', $this->locationA), [
                'staff_user_id' => $targetId, 'start_at' => '2027-03-01 08:00', 'end_at' => '2027-03-01 09:00',
            ]);
            $this->assertSame(self::REFUSED_AUTHORITY, $off->status(), "time off for {$label}");
        }

        $this->assertSame(0, StaffAvailabilityRule::query()->count());
        $this->assertSame(0, StaffTimeOff::query()->count());
    }

    public function test_time_off_is_user_global_and_removes_the_person_everywhere_until_deleted(): void
    {
        $staff = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);
        $this->giveMondayAvailability((int) $staff->id, $this->locationA);
        $this->giveMondayAvailability((int) $staff->id, $this->locationB);
        $typeA = $this->typeWithStaff($this->locationA, $staff);
        $typeB = $this->typeWithStaff($this->locationB, $staff);
        $this->assertNotSame([], $this->offeredSlots($typeA, '2027-03-01'));
        $this->assertNotSame([], $this->offeredSlots($typeB, '2027-03-01'));

        $this->actAsOwner();
        $this->post($this->cal('availability.time-off.store', $this->locationA), [
            'staff_user_id' => $staff->id,
            'start_at' => '2027-02-28 00:00', 'end_at' => '2027-03-03 00:00', 'reason' => 'Holiday',
        ])->assertRedirect();

        $off = StaffTimeOff::query()->where('staff_user_id', $staff->id)->firstOrFail();
        $this->assertSame([], $this->offeredSlots($typeA, '2027-03-01'));
        $this->assertSame([], $this->offeredSlots($typeB, '2027-03-01'), 'Time off entered via A must apply at B.');

        $this->post($this->cal('availability.time-off.destroy', $this->locationA, [$off->id]))->assertRedirect();
        $this->assertNotSame([], $this->offeredSlots($typeA, '2027-03-01'));
        $this->assertNotSame([], $this->offeredSlots($typeB, '2027-03-01'));
    }
}
