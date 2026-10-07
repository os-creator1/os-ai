<?php

namespace Tests\Feature\V1Acceptance\LeadLifecycle;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Crm\TagManager;
use App\Library\Forms\FormOperationToken;
use App\Models\Appointment;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\FormSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * V1 FINAL LEAD LIFECYCLE ACCEPTANCE — Flows A, B and D on one Photo Booth Business with two
 * Locations: a website visitor submits the embedded Form, becomes a Contact (+ canonical custom
 * fields) and an Opportunity, an automation fires once, and the SAME person then books publicly
 * without becoming a second Contact. Every handoff uses its module's own public seam.
 */
class LeadLifecycleJourneyTest extends LeadLifecycleTestCase
{
    public function test_flow_a_the_embedded_form_is_publicly_framable_and_makes_one_contact_with_canonical_fields_and_a_deal(): void
    {
        $page = $this->get(route('public.forms.show', [$this->formA->uid]))->assertOk();
        $this->assertNull($page->headers->get('X-Frame-Options'), 'An embedded form must be frameable by the website.');
        $this->assertStringNotContainsString("frame-ancestors 'none'", (string) $page->headers->get('Content-Security-Policy'));

        $token = FormOperationToken::issue($this->formA);
        $this->submitForm($this->formA, ['event_type' => 'Wedding'], $token)->assertRedirect();
        $this->submitForm($this->formA, ['event_type' => 'Wedding'], $token)->assertRedirect(); // double click

        $submission = FormSubmission::query()->sole();
        $contact = Contacts::query()->sole();
        $deal = CrmOpportunity::query()->sole();

        $this->assertSame((int) $this->locationA->id, (int) $contact->location_id);
        $this->assertSame((int) $contact->id, (int) $submission->contact_id);
        $this->assertSame((int) $deal->id, (int) $submission->crm_opportunity_id);
        $this->assertSame('new_inquiry', $deal->stage->semantic_key);
        $this->assertSame('2027-06-01', $this->canonical($contact, $this->eventDate));
        $this->assertSame('Wedding', $this->canonical($contact, $this->eventType));

        // The owner can find the lead: the CRM board and the people directory.
        $this->actAsOwnerInCrm();
        $this->get($this->boardUrl())->assertOk()->assertSee($deal->title);
        $this->get($this->peopleShow($contact))->assertOk()->assertSee('Wedding');
    }

    public function test_flows_a_b_d_the_same_person_books_without_a_duplicate_and_each_automation_fires_once(): void
    {
        $tag = app(TagManager::class)->createTag($this->business, 'Web lead');
        $workflow = $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted, ['form_id' => $this->form->id], [$this->addTagStep((int) $tag->id), $this->endStep()]);

        $pipeline = CrmPipeline::query()->where('business_id', $this->business->id)->firstOrFail();
        $qualified = CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('position')->get()[1];
        $booked = $this->triggerWorkflow($this->business, WorkflowTriggerType::AppointmentScheduled, [], [
            ['key' => (string) Str::uuid(), 'type' => 'move_opportunity', 'config' => ['pipeline_id' => $pipeline->id, 'stage_id' => $qualified->id]],
            $this->endStep(),
        ]);

        $staff = $this->bookableStaff();
        $type = $this->typeWithStaff($this->locationA, $staff);

        // A: the lead.
        $this->submitForm($this->formA)->assertRedirect();
        $contact = Contacts::query()->sole();
        $deal = CrmOpportunity::query()->sole();
        $this->assertSame(1, $this->enrollmentCount($workflow));
        $this->xAdvanceAll();
        $this->assertSame(['Web lead'], app(TagManager::class)->tagsForContact($this->business, $contact)->pluck('name')->all());

        // B: the same person books on the public page.
        $this->from($this->publicShow($type))
            ->post($this->publicStore($type), $this->guest(['phone' => '+1 (415) 555-1234', 'email' => 'ada@example.test']))
            ->assertRedirect(route('public.booking.confirmed', [$type->public_booking_uuid]));

        $this->assertSame(1, Contacts::query()->count(), 'No duplicate Contact.');
        $this->assertSame(1, Appointment::query()->count(), 'No duplicate Appointment.');
        $appointment = Appointment::query()->sole();
        $this->assertSame((int) $contact->id, (int) $appointment->contact_id);
        $this->assertSame((int) $this->locationA->id, (int) $appointment->business_location_id);
        $this->assertSame(1, DB::table('appointment_notifications')->where('appointment_id', $appointment->id)->where('occurrence_key', 'confirmation')->count());

        // CRM relationship: the booking and the lead's deal meet at the Contact (V1 sets no
        // appointment->deal pointer on the public path; the booking automation re-finds the open deal).
        $this->assertSame((int) $contact->id, (int) $deal->fresh()->contact_id);
        $this->assertSame(1, CrmOpportunity::query()->count(), 'Booking never creates a second deal.');

        // D: the form workflow did not fire again; the booking workflow fired exactly once and
        // moved THE lead's deal.
        $this->assertSame(1, $this->enrollmentCount($workflow));
        $this->assertSame(1, $this->enrollmentCount($booked));
        $this->xAdvanceAll();
        $this->assertSame((int) $qualified->id, (int) DB::table('crm_opportunities')->where('id', $deal->id)->value('stage_id'));
        $this->assertSame(2, AutomationEnrollment::query()->count());
    }
}
