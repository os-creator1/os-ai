<?php

namespace Tests\Feature\V1Acceptance\LeadLifecycle;

use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldValueService;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;
use App\Models\CustomFieldDefinition;
use App\Models\Form;
use App\Models\FormDeployment;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\Feature\V1Acceptance\CalendarBooking\CalendarJourneyTestCase;

/**
 * V1 FINAL LEAD LIFECYCLE ACCEPTANCE — the shared Photo Booth world: one Business, two Locations
 * (Location A / Location B from the Calendar journey harness), a sales pipeline, two canonical
 * Contact custom fields (Event Date, Event Type) and ONE lead form mapped to them and deployed at
 * both Locations. Real Core plan, real tenancy, real Location guard — the same stance as the
 * Calendar acceptance journeys.
 */
abstract class LeadLifecycleTestCase extends CalendarJourneyTestCase
{
    use CreatesFormsFixtures;
    use BuildsFoundationWorkflows;

    protected Form $form;

    protected FormDeployment $formA;

    protected FormDeployment $formB;

    protected CustomFieldDefinition $eventDate;

    protected CustomFieldDefinition $eventType;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([AdvanceWorkflowEnrollment::class]);

        $this->formsPipeline($this->business);
        $fields = app(CustomFieldDefinitionManager::class);
        $this->eventDate = $fields->create($this->business, 'Event Date', 'date');
        $this->eventType = $fields->create($this->business, 'Event Type', 'text');

        $input = $this->leadFormInput(['create_opportunity' => true]);
        $input['fields'] = array_map(fn (array $f): array => match ($f['label']) {
            'Event date' => $f + ['custom_field_uid' => $this->eventDate->uid],
            'Event type' => $f + ['custom_field_uid' => $this->eventType->uid],
            default => $f,
        }, $input['fields']);

        $manager = app(FormManager::class);
        $this->form = $manager->activate($this->business, $manager->create($this->business, $input));
        $this->formA = $this->deploy($this->business, $this->form, $this->locationA);
        $this->formB = $this->deploy($this->business, $this->form, $this->locationB);
    }

    /** What the visitor's browser posts to the public (embedded) form. */
    protected function submitForm(FormDeployment $deployment, array $answers = [], ?string $token = null)
    {
        return $this->post(route('public.forms.submit', [$deployment->uid]), $this->submitInput($deployment, $answers, $token ?? FormOperationToken::issue($deployment)));
    }

    protected function canonical(Contacts $contact, CustomFieldDefinition $definition): mixed
    {
        return app(CustomFieldValueService::class)->valuesFor($this->business, $contact)
            ->firstWhere(fn (array $e) => $e['definition']->id === $definition->id)['value'] ?? null;
    }

    /** Run every active journey to rest, as the queue would. */
    protected function xAdvanceAll(): void
    {
        for ($round = 0; $round < 6; $round++) {
            $active = AutomationEnrollment::query()->where('status', 'active')->get();

            if ($active->isEmpty()) {
                return;
            }

            $active->each(fn (AutomationEnrollment $e) => app(WorkflowAdvancer::class)->advance($e->fresh()));
        }
    }

    /** The owner, signed in with the customer permissions the CRM and people pages ask for. */
    protected function actAsOwnerInCrm(): static
    {
        $this->actAsOwner();
        $this->withSession(['permissions' => collect(['access_backend', 'view_contact', 'create_contact', 'update_contact', 'delete_contact', 'view_contact_group'])]);

        return $this;
    }

    protected function peopleShow(Contacts $contact): string
    {
        return route('customer.workspaces.businesses.people.show', [$this->workspace->uid, $this->business->uid, $contact->uid]);
    }

    protected function boardUrl(): string
    {
        return route('customer.workspaces.businesses.crm.board', [$this->workspace->uid, $this->business->uid]);
    }
}
