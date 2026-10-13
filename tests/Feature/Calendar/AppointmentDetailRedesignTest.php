<?php

namespace Tests\Feature\Calendar;

use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * The redesigned appointment detail page: the layout per status, and that the
 * lifecycle controls exist only for a scheduled appointment. Transitions
 * themselves stay proven by CalendarAppointmentActionsTest.
 */
class AppointmentDetailRedesignTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
        $this->entitledCalendar();
        $this->authenticate($this->owner->user);
    }

    private function booked(): Appointment
    {
        return $this->engine()->book($this->bookingType(), (int) $this->bookableStaff()->id, $this->contactId(), $this->slotStart('10:00:00'));
    }

    private function show(Appointment $appointment): string
    {
        return $this->get($this->calendarUrl('appointments.show', array_merge($this->scopeFor($this->locationA), [$appointment->uid])))
            ->assertOk()->getContent();
    }

    private function url(string $action, Appointment $appointment): string
    {
        return $this->calendarUrl('appointments.' . $action, array_merge($this->scopeFor($this->locationA), [$appointment->uid]));
    }

    public function test_scheduled_shows_header_details_and_every_lifecycle_form(): void
    {
        $appointment = $this->booked();
        $html = $this->show($appointment);

        $this->assertStringContainsString('data-role="appointment-back"', $html);
        $this->assertStringContainsString('Back to calendar', $html);
        $this->assertStringContainsString('data-role="appointment-date-tile"', $html);
        $this->assertStringContainsString('data-role="appointment-status"', $html);
        $this->assertMatchesRegularExpression('/data-role="appointment-status"[^>]*>\s*Scheduled\s*</', $html);
        $this->assertStringContainsString('(America/New_York)', $html);
        $this->assertStringContainsString('10:00–', $html);

        foreach (['appointment-details', 'appointment-reschedule', 'appointment-outcome', 'appointment-cancel'] as $section) {
            $this->assertStringContainsString('data-section="' . $section . '"', $html);
        }
        foreach (['reschedule', 'complete', 'no-show', 'cancel'] as $action) {
            $this->assertStringContainsString('action="' . $this->url($action, $appointment) . '"', $html);
        }
        foreach (['name="date"', 'name="time"', 'name="staff"', 'name="reason"'] as $field) {
            $this->assertStringContainsString($field, $html);
        }
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringNotContainsString('data-role="appointment-closed"', $html);
    }

    public function test_view_contact_links_to_the_real_contact_profile_for_an_authorized_user(): void
    {
        // The destination authorizes with view_contact; pin the gate so the test does not
        // depend on which fixture user happens to be id 1 (the legacy super-admin).
        \Illuminate\Support\Facades\Gate::define('view_contact', fn () => true);
        $appointment = $this->booked();
        $contactUid = DB::table('contacts')->where('id', $appointment->contact_id)->value('uid');

        $html = $this->show($appointment);

        $this->assertStringContainsString('data-role="appointment-contact-link"', $html);
        $this->assertStringContainsString('/people/' . $contactUid, $html);
    }

    public function test_view_contact_is_omitted_when_the_user_may_not_open_contacts(): void
    {
        \Illuminate\Support\Facades\Gate::define('view_contact', fn () => false);
        $appointment = $this->booked();

        $html = $this->show($appointment);

        $this->assertStringNotContainsString('appointment-contact-link', $html);
        $this->assertStringNotContainsString('View contact', $html);
        $this->assertStringContainsString('data-section="appointment-details"', $html);
    }

    public function test_cancelled_is_read_only_with_the_persisted_reason_and_no_controls(): void
    {
        $appointment = $this->booked();
        $this->post($this->url('cancel', $appointment), ['reason' => 'Customer rang to cancel'])->assertRedirect();

        $html = $this->show($appointment);

        $this->assertMatchesRegularExpression('/data-role="appointment-status"[^>]*>\s*Cancelled\s*</', $html);
        $this->assertStringContainsString('data-role="appointment-cancellation-banner"', $html);
        $this->assertStringContainsString('Customer rang to cancel', $html);
        $this->assertStringContainsString('data-section="appointment-details"', $html);
        $this->assertStringContainsString('This appointment is cancelled and can no longer be changed.', $html);
        $this->assertStringContainsString('mg-appt-grid is-single', $html);

        foreach (['appointment-reschedule', 'appointment-outcome', 'appointment-cancel'] as $section) {
            $this->assertStringNotContainsString('data-section="' . $section . '"', $html);
        }
        foreach (['reschedule', 'complete', 'no-show', 'cancel'] as $action) {
            $this->assertStringNotContainsString('data-role="' . $action . '"', $html);
            $this->assertStringNotContainsString('action="' . $this->url($action, $appointment) . '"', $html);
        }
    }

    public function test_cancelled_without_a_reason_shows_no_empty_banner(): void
    {
        $appointment = $this->booked();
        $this->post($this->url('cancel', $appointment))->assertRedirect();

        $this->assertStringNotContainsString('appointment-cancellation-banner', $this->show($appointment));
    }

    public function test_completed_and_no_show_are_read_only_with_accurate_wording(): void
    {
        $completed = $this->booked();
        $this->post($this->url('complete', $completed))->assertRedirect();
        $html = $this->show($completed);
        $this->assertMatchesRegularExpression('/data-role="appointment-status"[^>]*>\s*Completed\s*</', $html);
        $this->assertStringContainsString('This appointment is completed and can no longer be changed.', $html);
        $this->assertStringNotContainsString('data-role="reschedule"', $html);
        $this->assertStringNotContainsString('data-role="cancel"', $html);
        $this->assertStringNotContainsString('appointment-cancellation-banner', $html);

        $missed = $this->engine()->book($this->bookingType(), (int) $this->bookableStaff()->id, $this->contactId(), $this->slotStart('13:00:00'));
        $this->post($this->url('no-show', $missed))->assertRedirect();
        $html = $this->show($missed);
        $this->assertMatchesRegularExpression('/data-role="appointment-status"[^>]*>\s*No-show\s*</', $html);
        $this->assertStringContainsString('This appointment is no-show and can no longer be changed.', $html);
        $this->assertStringNotContainsString('data-role="complete"', $html);
    }

    public function test_the_reschedule_staff_selector_lists_eligible_staff_with_the_current_one_selected(): void
    {
        $appointment = $this->booked();

        $html = $this->show($appointment);

        $this->assertMatchesRegularExpression('/<option value="' . $appointment->staff_user_id . '"\s+selected/', $html);
        $this->assertSame(1, preg_match_all('/<option value="\d+"\s+selected/', $html), 'Exactly one staff member is preselected.');
    }

    public function test_a_validation_error_and_old_input_survive_the_redesign(): void
    {
        $appointment = $this->booked();

        $this->from($this->url('reschedule', $appointment))
            ->post($this->url('reschedule', $appointment), ['date' => 'nonsense', 'time' => '09:30'])
            ->assertSessionHasErrors('date');
    }
}
