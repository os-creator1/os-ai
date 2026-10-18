<?php

namespace Tests\Feature\V1Acceptance\LeadLifecycle;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Calendar\AppointmentScheduled;
use App\Library\Crm\TagManager;
use App\Models\Appointment;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\FormSubmission;
use Illuminate\Support\Facades\DB;

/**
 * Flow C (manual CRM) and the two-Location guarantees on the same Photo Booth Business.
 */
class ManualCrmAndLocationJourneyTest extends LeadLifecycleTestCase
{
    private function contactAt($location, string $phone): Contacts
    {
        $group = ContactGroups::query()->where('business_id', $this->business->id)->first()
            ?? ContactGroups::create(['customer_id' => $this->business->customer_id, 'business_id' => $this->business->id, 'name' => 'Contacts', 'status' => true]);

        return Contacts::create([
            'customer_id' => $group->customer_id, 'business_id' => $this->business->id, 'location_id' => $location->id,
            'group_id' => $group->id, 'phone' => $phone, 'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
    }

    private function crmUrl(string $name, array $args = []): string
    {
        return route('customer.workspaces.businesses.crm.' . $name, array_merge([$this->workspace->uid, $this->business->uid], $args));
    }

    public function test_flow_c_manual_contact_deal_stage_drag_automation_and_staff_booking(): void
    {
        $pipeline = CrmPipeline::query()->where('business_id', $this->business->id)->firstOrFail();
        $stages = CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('position')->get();
        $tag = app(TagManager::class)->createTag($this->business, 'In conversation');
        $workflow = $this->triggerWorkflow($this->business, WorkflowTriggerType::OpportunityStageChanged, ['pipeline_id' => $pipeline->id, 'to_stage_id' => $stages[1]->id], [$this->addTagStep((int) $tag->id), $this->endStep()]);

        $contact = $this->contactAt($this->locationA, '14155550188');
        $this->actAsOwnerInCrm();

        // Create the Opportunity through the CRM form.
        $this->post($this->crmUrl('opportunities.store'), ['title' => 'Corporate gala booth', 'contact' => $contact->uid, 'pipeline' => $pipeline->uid, 'value' => '900'])->assertRedirect();
        $deal = CrmOpportunity::query()->sole();
        $this->assertSame((int) $this->locationA->id, (int) $deal->location_id, 'The deal belongs to the Contact\'s Location.');
        $this->assertSame(0, $this->enrollmentCount($workflow), 'Creating a deal in the first stage is not a stage change.');

        // Drag it to the stage the workflow watches — twice (a double drop).
        $this->postJson($this->crmUrl('opportunities.move', [$deal->uid]), ['stage' => $stages[1]->uid])->assertOk();
        $this->postJson($this->crmUrl('opportunities.move', [$deal->uid]), ['stage' => $stages[1]->uid]);
        $this->assertSame((int) $stages[1]->id, (int) $deal->fresh()->stage_id);
        $this->assertSame(1, $this->enrollmentCount($workflow), 'One stage change, one run.');
        $this->xAdvanceAll();
        $this->assertSame(['In conversation'], app(TagManager::class)->tagsForContact($this->business, $contact)->pluck('name')->all());

        // Edit the deal.
        $this->post($this->crmUrl('opportunities.update', [$deal->uid]), ['title' => 'Corporate gala booth (updated)', 'value' => '950'])->assertRedirect();
        $this->assertSame('Corporate gala booth (updated)', $deal->fresh()->title);

        // The staff book the Contact an appointment at the same Location.
        $staff = $this->bookableStaff();
        $type = $this->typeWithStaff($this->locationA, $staff);
        $this->post($this->cal('appointments.store', $this->locationA), [
            'booking_type_uid' => $type->uid, 'contact_uid' => $contact->uid, 'staff' => (string) $staff->id,
            'date' => '2027-03-01', 'time' => '10:00',
        ])->assertRedirect();
        $this->assertCount(1, $this->eventsOf(AppointmentScheduled::class));
        $appointment = Appointment::query()->sole();
        $this->assertSame([(int) $contact->id, (int) $this->locationA->id], [(int) $appointment->contact_id, (int) $appointment->business_location_id]);

        // Everything about the person is reachable from the Contact page, and it is still ONE contact and ONE deal.
        $this->get($this->peopleShow($contact))->assertOk();
        $this->get($this->crmUrl('opportunities.show', [$deal->uid]))->assertOk()->assertSee('Corporate gala booth (updated)')->assertSee($contact->uid);
        $this->get($this->boardUrl())->assertOk()->assertSee('Corporate gala booth (updated)');
        $this->assertSame(1, Contacts::query()->count());
        $this->assertSame(1, CrmOpportunity::query()->count());
    }

    public function test_two_locations_never_mix_leads_bookings_forms_or_staff_access(): void
    {
        $staffA = $this->bookableStaff($this->locationA);
        $typeA = $this->typeWithStaff($this->locationA, $staffA);
        $staffB = $this->memberWithFullReach(\App\Enums\Workspace\WorkspaceMembershipRole::Staff);
        $this->giveMondayAvailability((int) $staffB->id, $this->locationB);
        $typeB = $this->typeWithStaff($this->locationB, $staffB);

        // The same person enquires at BOTH Locations: two Contacts, two deals, each at its own Location.
        $this->submitForm($this->formA)->assertRedirect();
        $this->submitForm($this->formB, ['your_name' => 'Bea Builder'])->assertRedirect();
        $this->assertSame(2, Contacts::query()->count());
        $this->assertEqualsCanonicalizing([(int) $this->locationA->id, (int) $this->locationB->id], Contacts::query()->pluck('location_id')->map(fn ($i) => (int) $i)->all());
        foreach (FormSubmission::query()->get() as $submission) {
            $this->assertSame((int) $submission->business_location_id, (int) Contacts::query()->findOrFail($submission->contact_id)->location_id);
            $this->assertSame((int) $submission->business_location_id, (int) CrmOpportunity::query()->findOrFail($submission->crm_opportunity_id)->location_id);
        }

        // Booking at B reuses B's lead — never A's — and creates nothing at A.
        $this->from($this->publicShow($typeB))
            ->post($this->publicStore($typeB), $this->guest(['phone' => '+1 (415) 555-1234']))
            ->assertRedirect(route('public.booking.confirmed', [$typeB->public_booking_uuid]));
        $this->assertSame(2, Contacts::query()->count(), 'No third Contact.');
        $this->assertSame(0, Appointment::query()->where('business_location_id', $this->locationA->id)->count());
        $atB = Appointment::query()->sole();
        $this->assertSame((int) $this->locationB->id, (int) Contacts::query()->findOrFail($atB->contact_id)->location_id);

        // Staff granted only Location A reach A's lead and schedule, and nothing of B's.
        $aContact = Contacts::query()->where('location_id', $this->locationA->id)->sole();
        $bContact = Contacts::query()->where('location_id', $this->locationB->id)->sole();
        $aDeal = CrmOpportunity::query()->where('location_id', $this->locationA->id)->sole();
        $bDeal = CrmOpportunity::query()->where('location_id', $this->locationB->id)->sole();
        $onlyA = $this->memberGrantedOnly($this->locationA);
        $this->authenticate($onlyA);
        $this->withSession(['permissions' => collect(['access_backend', 'view_contact', 'update_contact', 'view_contact_group'])]);

        $this->get($this->peopleShow($aContact))->assertOk();
        $this->get($this->peopleShow($bContact))->assertNotFound();
        $this->assertNotSame($aDeal->title, $bDeal->title);
        $this->get($this->boardUrl())->assertOk()->assertSee($aDeal->title)->assertDontSee($bDeal->title);
        $this->get($this->crmUrl('opportunities.show', [$bDeal->uid]))->assertNotFound();
        $this->get($this->cal('schedule', $this->locationB) . '?view=week&date=2027-03-01')->assertNotFound();
        $this->get($this->cal('appointments.show', $this->locationB, [$atB->uid]))->assertNotFound();
    }

    public function test_a_form_switched_off_at_one_location_takes_no_lead_there_and_does_not_affect_the_other(): void
    {
        $this->deploy($this->business, $this->form, $this->locationB, false);

        $this->get(route('public.forms.show', [$this->formB->uid]))->assertNotFound();
        $this->submitForm($this->formB);
        $this->assertSame(0, Contacts::query()->count());
        $this->assertSame(0, FormSubmission::query()->count());

        $this->get(route('public.forms.show', [$this->formA->uid]))->assertOk();
        $this->submitForm($this->formA)->assertRedirect();
        $this->assertSame([(int) $this->locationA->id], Contacts::query()->pluck('location_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(1, DB::table('crm_opportunities')->count());
    }
}
