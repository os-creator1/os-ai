<?php

namespace Tests\Feature\V1Acceptance\CalendarBooking;

use App\Models\BookingType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Acceptance journey A — an owner builds a Booking Type through the real
 * routes, with the real entitlement stack, and nothing foreign can be injected.
 */
class BookingTypeJourneyTest extends CalendarJourneyTestCase
{
    public function test_owner_creates_edits_and_lists_a_booking_type_at_a_location(): void
    {
        $this->actAsOwner();

        // The Business has two Locations, so the Calendar entry is a picker.
        $this->get(route('customer.workspaces.businesses.calendar.index', [$this->workspace->uid, $this->business->uid]))
            ->assertOk()
            ->assertSee('Location A')
            ->assertSee('Location B');

        // Fresh Location: an understandable empty state, not a blank page.
        $this->get($this->cal('booking-types.index', $this->locationA))
            ->assertOk()
            ->assertSee('data-section="booking-types-list"', false)
            ->assertSee('No booking types yet.');

        $this->get($this->cal('booking-types.create', $this->locationA))
            ->assertOk()
            ->assertSee('name="duration_minutes"', false);

        $response = $this->post($this->cal('booking-types.store', $this->locationA), [
            'name' => 'Initial consultation',
            'description' => 'Meet the team',
            'duration_minutes' => 45,
            'color' => '#125a9c',
        ]);

        $type = BookingType::query()->where('name', 'Initial consultation')->firstOrFail();
        $response->assertRedirect($this->cal('booking-types.edit', $this->locationA, [$type->uid]));

        $this->assertSame(1, BookingType::query()->count());
        $this->assertDatabaseHas('booking_types', [
            'id' => $type->id,
            'business_location_id' => $this->locationA->id,
            'duration_minutes' => 45,
            'description' => 'Meet the team',
            'is_active' => 1,
            'created_by_user_id' => $this->owner->user_id,
        ]);

        // The values survive a round trip through the real edit page.
        $this->get($this->cal('booking-types.edit', $this->locationA, [$type->uid]))
            ->assertOk()
            ->assertSee('value="Initial consultation"', false)
            ->assertSee('value="45"', false);

        $this->post($this->cal('booking-types.update', $this->locationA, [$type->uid]), [
            'name' => 'Initial consultation (long)',
            'duration_minutes' => 90,
        ])->assertRedirect();

        $this->assertDatabaseHas('booking_types', [
            'id' => $type->id,
            'name' => 'Initial consultation (long)',
            'duration_minutes' => 90,
            'business_location_id' => $this->locationA->id,
        ]);

        // Listed at A. Nobody serves it yet, so the public page would 404: the
        // list says so instead of offering that link ...
        $this->get($this->cal('booking-types.index', $this->locationA))
            ->assertOk()
            ->assertSee('Initial consultation (long)')
            ->assertSee('90 min')
            ->assertSee('Not bookable yet')
            ->assertDontSee(route('public.booking.show', [$type->public_booking_uuid]));

        // ... and shows the link once someone with working hours is assigned.
        $type->staff()->attach($this->bookableStaff()->id);
        $this->get($this->cal('booking-types.index', $this->locationA))
            ->assertOk()
            ->assertDontSee('Not bookable yet')
            ->assertSee(route('public.booking.show', [$type->public_booking_uuid]));
        $this->get($this->cal('booking-types.edit', $this->locationA, [$type->uid]))
            ->assertOk()
            ->assertSee('data-role="open-public-page"', false);
        $this->get($this->cal('booking-types.index', $this->locationB))
            ->assertOk()
            ->assertDontSee('Initial consultation (long)');
    }

    public function test_invalid_input_is_refused_and_writes_nothing(): void
    {
        $this->actAsOwner();

        foreach ([
            ['name' => '', 'duration_minutes' => 30],
            ['name' => 'Zero', 'duration_minutes' => 0],
            ['name' => 'Day and a bit', 'duration_minutes' => 1441],
            ['name' => 'Words', 'duration_minutes' => 'thirty'],
        ] as $payload) {
            $this->post($this->cal('booking-types.store', $this->locationA), $payload)->assertSessionHasErrors();
        }

        $this->assertSame(0, BookingType::query()->count());
    }

    public function test_a_foreign_business_or_location_can_never_be_injected(): void
    {
        [$foreignBusiness, $foreignLocation, $foreignOwner] = $this->foreignBusinessWithLocation();
        $foreignWorkspace = $foreignBusiness->workspace;

        $this->actAsOwner();

        // Our Business, a Location that belongs to ANOTHER Business.
        $this->post(
            route('customer.workspaces.businesses.calendar.booking-types.store', [
                $this->workspace->uid, $this->business->uid, $foreignLocation->uid,
            ]),
            ['name' => 'Injected', 'duration_minutes' => 30]
        )->assertNotFound();

        // Another Business's coordinates entirely.
        $this->post(
            route('customer.workspaces.businesses.calendar.booking-types.store', [
                $foreignWorkspace->uid, $foreignBusiness->uid, $foreignLocation->uid,
            ]),
            ['name' => 'Injected', 'duration_minutes' => 30]
        )->assertNotFound();

        // Mixed coordinates: our Workspace, their Business.
        $this->post(
            route('customer.workspaces.businesses.calendar.booking-types.store', [
                $this->workspace->uid, $foreignBusiness->uid, $foreignLocation->uid,
            ]),
            ['name' => 'Injected', 'duration_minutes' => 30]
        )->assertNotFound();

        // A body that names a foreign Location/Business is ignored: the route decides.
        $this->post($this->cal('booking-types.store', $this->locationA), [
            'name' => 'Body injection',
            'duration_minutes' => 30,
            'business_location_id' => $foreignLocation->id,
            'business_id' => $foreignBusiness->id,
            'created_by_user_id' => $foreignOwner->user_id,
        ])->assertRedirect();

        $injected = BookingType::query()->where('name', 'Body injection')->firstOrFail();
        $this->assertSame((int) $this->locationA->id, (int) $injected->business_location_id);
        $this->assertSame((int) $this->owner->user_id, (int) $injected->created_by_user_id);
        $this->assertSame(0, BookingType::query()->where('name', 'Injected')->count());
        $this->assertSame(0, BookingType::query()->where('business_location_id', $foreignLocation->id)->count());

        // And the foreign owner is just as locked out of OUR Business.
        $this->actAs($foreignOwner->user)
            ->post($this->cal('booking-types.store', $this->locationA), ['name' => 'Reverse', 'duration_minutes' => 30])
            ->assertNotFound();
        $this->assertSame(0, BookingType::query()->where('name', 'Reverse')->count());
    }

    public function test_a_booking_type_cannot_be_reached_through_a_sibling_locations_route(): void
    {
        $atB = $this->bookingType($this->locationB, ['name' => 'Only at B']);
        $this->actAsOwner();

        $this->get($this->cal('booking-types.edit', $this->locationA, [$atB->uid]))->assertNotFound();
        $this->post($this->cal('booking-types.update', $this->locationA, [$atB->uid]), ['name' => 'Hijack', 'duration_minutes' => 5])->assertNotFound();
        $this->post($this->cal('booking-types.active', $this->locationA, [$atB->uid]), ['is_active' => 0])->assertNotFound();
        $this->post($this->cal('booking-types.staff', $this->locationA, [$atB->uid]), ['staff_user_ids' => ['']])->assertNotFound();

        $atB->refresh();
        $this->assertSame('Only at B', $atB->name);
        $this->assertTrue($atB->is_active);
    }

    public function test_deactivate_and_reactivate_follow_the_domain_rules(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->typeWithStaff($this->locationA, $staff);
        $url = $this->publicShow($type, '2027-03-01');
        $existing = $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('09:00:00'));

        $this->actAsOwner();
        $this->post($this->cal('booking-types.active', $this->locationA, [$type->uid]), ['is_active' => 0])->assertRedirect();
        $this->assertFalse($type->fresh()->is_active);

        // The inactive type's public page is indistinguishable from a missing one…
        $this->get($url)->assertNotFound();
        $before = $this->appointmentCount();
        $this->post($this->publicStore($type), $this->guest(['time' => '11:00']))->assertNotFound();
        $this->assertSame($before, $this->appointmentCount());

        // …its listing row says Inactive and offers no public link…
        $this->get($this->cal('booking-types.index', $this->locationA))
            ->assertOk()
            ->assertSee('Inactive')
            ->assertDontSee(route('public.booking.show', [$type->public_booking_uuid]));

        // …and the staff-side booking form refuses it too.
        $this->post($this->cal('appointments.store', $this->locationA), [
            'booking_type_uid' => $type->uid, 'contact_uid' => 'whatever', 'staff' => (string) $staff->id,
            'date' => '2027-03-01', 'time' => '12:00',
        ])->assertNotFound();

        // Existing appointments of an inactive type stay reschedulable (contract: creation only).
        $this->post($this->cal('appointments.reschedule', $this->locationA, [$existing->uid]), [
            'date' => '2027-03-01', 'time' => '13:00',
        ])->assertRedirect();
        $this->assertSame(
            $this->slotStart('13:00:00')->format('Y-m-d H:i:s'),
            Carbon::parse($existing->fresh()->start_at)->utc()->format('Y-m-d H:i:s')
        );

        // Reactivate: the SAME public address works again.
        $this->post($this->cal('booking-types.active', $this->locationA, [$type->uid]), ['is_active' => 1])->assertRedirect();
        $this->assertTrue($type->fresh()->is_active);
        $this->get($url)->assertOk()->assertSee($type->name);
        $this->assertSame($type->public_booking_uuid, $type->fresh()->public_booking_uuid);
    }

    public function test_duration_persists_and_is_what_the_public_scheduler_sizes_slots_with(): void
    {
        $staff = $this->bookableStaff();
        $this->actAsOwner();

        $this->post($this->cal('booking-types.store', $this->locationA), ['name' => 'Long visit', 'duration_minutes' => 90]);
        $type = BookingType::query()->where('name', 'Long visit')->firstOrFail();
        $this->post($this->cal('booking-types.staff', $this->locationA, [$type->uid]), ['staff_user_ids' => ['', (string) $staff->id]])
            ->assertRedirect();

        // Window is Monday 08:00–20:00. A 90-minute visit's last start is 18:30.
        $slots = $this->offeredSlots($type, '2027-03-01');
        $this->assertContains('18:30', $slots);
        $this->assertNotContains('19:00', $slots);

        $this->post($this->cal('booking-types.update', $this->locationA, [$type->uid]), ['name' => 'Long visit', 'duration_minutes' => 30]);
        $slots = $this->offeredSlots($type->fresh(), '2027-03-01');
        $this->assertContains('19:30', $slots);
        $this->assertNotContains('20:00', $slots);
    }

    public function test_staff_pool_nominations_are_re_derived_so_no_foreign_or_stale_member_attaches(): void
    {
        $eligible = $this->bookableStaff();
        $type = $this->bookingType();
        [, , $foreignOwner] = $this->foreignBusinessWithLocation();

        $this->actAsOwner();
        $response = $this->post($this->cal('booking-types.staff', $this->locationA, [$type->uid]), [
            'staff_user_ids' => ['', (string) $eligible->id, (string) $this->outsider->id, (string) $foreignOwner->user_id, '999999'],
        ]);

        $response->assertRedirect()->assertSessionHas('flash_error');
        $this->assertSame([(int) $eligible->id], $type->staff()->pluck('users.id')->map(fn ($i) => (int) $i)->all());

        // Their membership ends: the stale row is flagged, never treated as authority.
        DB::table('workspace_memberships')->where('user_id', $eligible->id)->update(['is_active' => false]);
        $this->get($this->cal('booking-types.edit', $this->locationA, [$type->uid]))
            ->assertOk()
            ->assertSee('no longer authorized for this location');

        // Saving the form with nobody ticked clears it.
        $this->post($this->cal('booking-types.staff', $this->locationA, [$type->uid]), ['staff_user_ids' => ['']])->assertRedirect();
        $this->assertSame(0, $type->staff()->count());
    }
}
