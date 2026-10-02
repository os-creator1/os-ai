<?php

namespace Tests\Feature\V1Acceptance\CalendarBooking;

use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentCompleted;
use App\Events\Calendar\AppointmentNoShow;
use App\Events\Calendar\AppointmentRescheduled;
use App\Events\Calendar\AppointmentScheduled;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Models\Appointment;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;

/**
 * Acceptance journeys F, G, I, J, K (compact) — lifecycle through the real UI,
 * Location authority by URL, canonical event keys, nav reachability and
 * fail-closed foreign ids. Reuses the real-entitlement harness.
 */
class LifecycleAuthorityJourneyTest extends CalendarJourneyTestCase
{
    public function test_full_lifecycle_through_the_ui_fires_each_event_once_and_keeps_identity(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->typeWithStaff($this->locationA, $staff);
        $contactId = $this->contactId();
        $contact = Contacts::findOrFail($contactId);
        $pipeline = app(CrmPipelineService::class)->setUpStandardPipeline($this->business);
        $opportunity = app(CrmOpportunityService::class)->create($this->business, $pipeline, $contact, 'Deal', null, null);

        // Schedule (engine path with an opportunity; the UI never passes one) then view in the UI.
        $appointment = $this->engine()->book($type, (int) $staff->id, $contactId, $this->slotStart('10:00:00'), (int) $this->owner->user_id, (int) $opportunity->id);
        $this->actAsOwner();
        $this->get($this->cal('appointments.show', $this->locationA, [$appointment->uid]))->assertOk();

        // Schedule a second and third through the staff form.
        $uid = $contact->uid;
        foreach (['13:00', '15:00'] as $time) {
            $this->post($this->cal('appointments.store', $this->locationA), [
                'booking_type_uid' => $type->uid, 'contact_uid' => $uid, 'staff' => (string) $staff->id,
                'date' => '2027-03-01', 'time' => $time,
            ])->assertRedirect();
        }
        $this->assertCount(3, $this->eventsOf(AppointmentScheduled::class));
        $this->forgetEvents();

        // Reschedule twice: occurrence keys are deterministic per count and never repeat.
        $this->post($this->cal('appointments.reschedule', $this->locationA, [$appointment->uid]), ['date' => '2027-03-01', 'time' => '11:00'])->assertRedirect();
        $this->post($this->cal('appointments.reschedule', $this->locationA, [$appointment->uid]), ['date' => '2027-03-01', 'time' => '12:00'])->assertRedirect();
        $keys = array_map(fn ($e) => $e->occurrenceKey(), $this->eventsOf(AppointmentRescheduled::class));
        $this->assertSame(["appointment_rescheduled:{$appointment->id}:1", "appointment_rescheduled:{$appointment->id}:2"], $keys);

        // Cancel / complete / no-show each fire exactly once; repeating a terminal action fires nothing.
        $others = Appointment::query()->where('id', '!=', $appointment->id)->orderBy('id')->get();
        $this->post($this->cal('appointments.cancel', $this->locationA, [$appointment->uid]), ['reason' => 'x'])->assertRedirect();
        $this->post($this->cal('appointments.cancel', $this->locationA, [$appointment->uid]))->assertSessionHas('flash_error');
        $this->post($this->cal('appointments.complete', $this->locationA, [$others[0]->uid]))->assertRedirect();
        $this->post($this->cal('appointments.no-show', $this->locationA, [$others[1]->uid]))->assertRedirect();

        $cancelled = $this->eventsOf(AppointmentCancelled::class);
        $this->assertCount(1, $cancelled);
        $this->assertSame("appointment_cancelled:{$appointment->id}", $cancelled[0]->occurrenceKey());
        $this->assertCount(1, $this->eventsOf(AppointmentCompleted::class));
        $this->assertCount(1, $this->eventsOf(AppointmentNoShow::class));
        $this->assertCount(2, $this->eventsOf(AppointmentRescheduled::class));

        // Identity stayed put through all of it.
        $fresh = $appointment->fresh();
        $this->assertSame((int) $this->locationA->id, (int) $fresh->business_location_id);
        $this->assertSame($contactId, (int) $fresh->contact_id);
        $this->assertSame((int) $opportunity->id, (int) $fresh->crm_opportunity_id);
        foreach (array_merge($this->eventsOf(AppointmentCancelled::class), $this->eventsOf(AppointmentRescheduled::class)) as $e) {
            $this->assertSame((int) $this->business->id, (int) $e->businessId);
            $this->assertSame((int) $this->locationA->id, (int) $e->businessLocationId);
        }
        $this->assertSame(2, (int) $fresh->reschedule_count);
    }

    public function test_location_authority_by_url_and_the_picker_never_widens_it(): void
    {
        $staffA = $this->memberGrantedOnly($this->locationA);
        $atA = $this->engine()->book($this->bookingType($this->locationA), (int) $this->bookableStaff($this->locationA)->id, $this->contactId(), $this->slotStart('10:00:00'));
        $bStaff = $this->bookableStaff($this->locationB);
        $atB = $this->engine()->book($this->bookingType($this->locationB), (int) $bStaff->id, $this->contactId(), $this->slotStart('10:00:00'));

        $this->actAs($staffA);
        $week = '?view=week&date=2027-03-01';
        $this->get($this->cal('schedule', $this->locationA) . $week)->assertOk()->assertSee($atA->uid)->assertDontSee($atB->uid);
        $this->get($this->cal('schedule', $this->locationB) . $week)->assertNotFound();
        $this->get($this->cal('appointments.show', $this->locationA, [$atB->uid]))->assertNotFound();      // B's uid via A's route
        $this->get($this->cal('appointments.show', $this->locationB, [$atB->uid]))->assertNotFound();      // via B's route
        foreach (['reschedule' => ['date' => '2027-03-01', 'time' => '16:00'], 'cancel' => [], 'complete' => [], 'no-show' => []] as $action => $body) {
            $this->post($this->cal("appointments.{$action}", $this->locationB, [$atB->uid]), $body)->assertNotFound();
            $this->post($this->cal("appointments.{$action}", $this->locationA, [$atB->uid]), $body)->assertNotFound();
        }
        $this->get($this->cal('booking-types.index', $this->locationB))->assertNotFound();
        $this->assertSame('scheduled', $atB->fresh()->status->value);

        // The picker is a single-Location shortcut for them; it never lists B.
        $index = route('customer.workspaces.businesses.calendar.index', [$this->workspace->uid, $this->business->uid]);
        $this->get($index)->assertRedirect($this->cal('schedule', $this->locationA));

        // The owner reaches both.
        $this->actAsOwner();
        $this->get($index)->assertOk()->assertSee('Location A')->assertSee('Location B');
        $this->get($this->cal('schedule', $this->locationB) . $week)->assertOk()->assertSee($atB->uid);
    }

    public function test_nav_reachability_empty_states_and_foreign_ids_fail_closed(): void
    {
        $this->actAsOwner();
        $home = $this->get(route('customer.workspaces.businesses.calendar.index', [$this->workspace->uid, $this->business->uid]))->assertOk();
        $home->assertSee('Location A');

        // Normal V1 nav carries a Calendar entry on a Business page.
        $menu = $this->get(route('customer.workspaces.businesses.calendar.index', [$this->workspace->uid, $this->business->uid]))->getContent();
        $this->assertStringContainsString(route('customer.workspaces.businesses.calendar.index', [$this->workspace->uid, $this->business->uid]), $menu);

        // Module sub-nav: Calendar view, Booking types, Staff availability, each reachable and empty-state clear.
        $schedule = $this->get($this->cal('schedule', $this->locationA))->assertOk();
        $schedule->assertSee($this->cal('booking-types.index', $this->locationA), false)
            ->assertSee($this->cal('availability.index', $this->locationA), false);
        $this->get($this->cal('booking-types.index', $this->locationA))->assertOk()->assertSee('No booking types yet.');
        $this->get($this->cal('availability.index', $this->locationA))->assertOk()->assertSee('No availability set for this location yet.');

        // Foreign Business appointment / booking type ids fail closed, for the owner of another Business too.
        [$fb, $fl, $fo] = $this->foreignBusinessWithLocation();
        DB::table('businesses')->where('id', $fb->id)->update(['status' => 'active']);
        $ftype = $this->bookingType($fl);
        $fcontact = DB::table('contacts')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => $fb->customer_id, 'business_id' => $fb->id,
            'location_id' => $fl->id, 'group_id' => \App\Models\ContactGroups::create(['customer_id' => $fb->customer_id, 'business_id' => $fb->id, 'name' => 'g', 'status' => true])->id,
            'phone' => '14155550999', 'status' => Contacts::STATUS_SUBSCRIBE, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fstaff = $this->memberWithFullReach(\App\Enums\Workspace\WorkspaceMembershipRole::Staff);
        $fappt = Appointment::query()->create([
            'business_location_id' => $fl->id, 'booking_type_id' => $ftype->id, 'staff_user_id' => $fstaff->id,
            'contact_id' => $fcontact, 'status' => 'scheduled', 'start_at' => $this->slotStart('10:00:00'),
            'end_at' => $this->slotStart('11:00:00'), 'reschedule_count' => 0,
        ]);
        $theirs = [$fb->workspace->uid, $fb->uid, $fl->uid];
        $this->get(route('customer.workspaces.businesses.calendar.appointments.show', array_merge($theirs, [$fappt->uid])))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.calendar.appointments.cancel', array_merge($theirs, [$fappt->uid])))->assertNotFound();
        $this->get($this->cal('booking-types.edit', $this->locationA, [$ftype->uid]))->assertNotFound();
        $this->get($this->cal('appointments.show', $this->locationA, [$fappt->uid]))->assertNotFound();
        $this->assertSame('scheduled', $fappt->fresh()->status->value);

        // Ids fail closed the other way too.
        $this->actAs($fo->user);
        $mine = $this->engine()->book($this->bookingType($this->locationA), (int) $this->bookableStaff()->id, $this->contactId(), $this->slotStart('10:00:00'));
        $this->get($this->cal('appointments.show', $this->locationA, [$mine->uid]))->assertNotFound();
    }
}
