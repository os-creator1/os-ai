<?php

namespace Tests\Feature\Automations\Workflow\Foundations;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Models\AutomationWorkflowNode;
use App\Models\BusinessEmailMessage;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * The Send email automation node must use the SAME address a person typed into the New-contact modal:
 * the built-in EMAIL identity field, never a custom "Email" field the customer had to invent.
 */
class SendEmailCanonicalIdentityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessEmailFixtures;
    use BuildsFoundationWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();
    }

    public function test_an_email_entered_in_the_new_contact_modal_is_what_the_automation_sends_to(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $business = $this->activate($business);
        $this->activeAccount($business);
        $group = ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'Leads', 'status' => true]);
        $this->authenticateAs($owner);

        $this->postJson(route('customer.workspaces.businesses.people.store', [$workspace->uid, $business->uid]), [
            'country' => 'US', 'phone' => '(720) 664-0969', 'first_name' => 'Pat', 'email' => 'Pat@Example.com', 'group' => $group->uid,
        ])->assertOk();
        $contact = Contacts::query()->where('business_id', $business->id)->sole();

        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, [], [$this->emailStep('Hello {FIRST_NAME}', 'Body'), $this->endStep()]);
        $this->assertNotNull(AutomationWorkflowNode::query()->where('version_id', $workflow->published_version_id)->where('node_type', 'send_email')->first());

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, 'manual-' . uniqid());
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame('pat@example.com', $this->fakeGoogle->sent[0]->toEmail);
        $this->assertSame(1, BusinessEmailMessage::query()->where('business_id', $business->id)->where('contact_id', $contact->id)->count());
    }

    public function test_a_contact_with_only_a_custom_field_called_email_is_not_emailed(): void
    {
        [, $business] = $this->emailTenant();
        $business = $this->activate($business);
        $this->activeAccount($business);
        $group = ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'Leads', 'status' => true]);
        $field = $group->contactGroupFields()->create(['contact_group_id' => $group->id, 'type' => 'text', 'label' => 'Email', 'tag' => 'MY_EMAIL', 'required' => false, 'visible' => true]);
        $contact = Contacts::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'group_id' => $group->id, 'phone' => '17206640969', 'status' => 'subscribe']);
        \App\Models\ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $field->id, 'value' => 'custom@example.com']);

        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, [], [$this->emailStep('Hello', 'Body'), $this->endStep()]);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, 'manual-' . uniqid());
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertSame(0, $this->fakeGoogle->callCount('send'), 'An arbitrary custom field is never an authoritative address.');
    }
}
