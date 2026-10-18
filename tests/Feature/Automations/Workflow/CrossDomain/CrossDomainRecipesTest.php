<?php

namespace Tests\Feature\Automations\Workflow\CrossDomain;

use App\Library\Automation\Workflow\NodeTypeRegistry;
use App\Library\Automation\Workflow\WorkflowDefinitionValidator;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Models\CrmPipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Automations\Workflow\CrossDomain\Support\BuildsCrossDomainFixtures;
use Tests\TestCase;

/**
 * Automations — the starter recipes are ordinary editable DRAFTS of the one workflow
 * definition system (no second template engine), and never run until published.
 *
 * Each fixture below is a transcription of one
 * resources/js/automations/workflow-builder/recipes.js factory's output (the same
 * convention RecipeDocumentTest uses): the chooser saves exactly this document into a
 * new draft. Running it through the REAL validator, then publishing it once the
 * Business's own resources are filled in, is what proves a recipe is a valid workflow
 * rather than an assumption — and a recipe that drifted out of the schema fails here.
 */
class CrossDomainRecipesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCrossDomainFixtures;

    private const OCCURRENCE = ['enrollment_policy' => 'once_per_occurrence', 'enrollment_policy_source' => 'default', 'failure_policy' => 'halt'];

    /**
     * @param array<string, mixed> $trigger
     * @param list<array<string, mixed>> $steps
     *
     * @return array<string, mixed>
     */
    private function recipe(array $trigger, array $steps): array
    {
        return ['schema_version' => 1, 'root' => ['key' => 'trigger-1', 'type' => 'trigger', 'config' => $trigger + self::OCCURRENCE, 'next' => $steps]];
    }

    private function step(string $key, string $type, array $config): array
    {
        return ['key' => $key, 'type' => $type, 'config' => $config, 'next' => []];
    }

    /** @return array<string, array<string, mixed>> */
    private function recipes(): array
    {
        $branch = $this->step('if-1', 'if_else', ['match' => 'all', 'conditions' => [['subject' => 'document.signed', 'operator' => 'is_false']]]);
        $branch['yes'] = [$this->step('mail-2', 'send_email', ['subject' => 'A reminder about your proposal', 'body' => 'Hi {first_name},\n\nJust a reminder that your proposal is waiting for your signature.'])];
        $branch['no'] = [['key' => 'end-1', 'type' => 'end', 'config' => []]];
        unset($branch['next']);

        return [
            'new_lead_follow_up' => $this->recipe(['trigger_type' => 'form_submitted', 'form_id' => null], [
                $this->step('mail-1', 'send_email', ['subject' => 'Thanks for getting in touch', 'body' => 'Hi {first_name},\n\nThanks for reaching out.']),
                $this->step('wait-1', 'wait', ['mode' => 'duration', 'amount' => 1, 'unit' => 'days']),
                $this->step('sms-1', 'send_sms', ['body' => 'Hi {first_name}, just checking you got our email.']),
            ]),
            'booking_follow_up' => $this->recipe(['trigger_type' => 'appointment_scheduled'], [
                $this->step('move-1', 'move_opportunity', ['pipeline_id' => null, 'stage_id' => null]),
                $this->step('mail-1', 'send_email', ['subject' => 'Your appointment is booked', 'body' => 'Hi {first_name},\n\nYour appointment is booked.']),
            ]),
            'proposal_follow_up' => $this->recipe(['trigger_type' => 'document_sent', 'document_kind' => 'proposal'], [
                $this->step('wait-1', 'wait', ['mode' => 'duration', 'amount' => 3, 'unit' => 'days']),
                $branch,
            ]),
            'signed_to_payment' => $this->recipe(['trigger_type' => 'document_signed', 'document_kind' => 'proposal'], [
                $this->step('pay-1', 'request_payment', ['source' => 'document']),
            ]),
            'payment_complete' => $this->recipe(['trigger_type' => 'payment_succeeded'], [
                $this->step('move-1', 'move_opportunity', ['pipeline_id' => null, 'stage_id' => null]),
                $this->step('q-1', 'send_questionnaire', ['form_id' => null, 'channels' => ['email'], 'subject' => '', 'message' => '']),
                $this->step('note-1', 'internal_notification', ['message' => 'Payment received from {first_name} {last_name}']),
            ]),
        ];
    }

    public function test_every_recipe_is_a_structurally_valid_document_that_only_asks_for_what_it_cannot_know(): void
    {
        $validator = new WorkflowDefinitionValidator(new NodeTypeRegistry());
        $mustChoose = ['Choose which pipeline', 'Choose which stage', 'Choose which form', 'Choose which questionnaire'];

        foreach ($this->recipes() as $key => $document) {
            foreach ($validator->validate($document) as $node => $messages) {
                foreach ($messages as $message) {
                    $allowed = collect($mustChoose)->contains(fn (string $prefix): bool => str_starts_with($message, $prefix));
                    $this->assertTrue($allowed, "Recipe [{$key}] has an unexpected problem on [{$node}]: {$message}");
                }
            }
        }
    }

    public function test_a_recipe_is_an_editable_draft_that_never_runs_until_it_is_published(): void
    {
        $world = $this->xWorld();
        $drafts = app(WorkflowDraftService::class);

        foreach ($this->recipes() as $key => $document) {
            $workflow = $drafts->createWorkflowWithDraft($world['business'], 'Recipe ' . $key, WorkflowTriggerType::from($document['root']['config']['trigger_type']));
            $draft = $workflow->draftVersion();
            $drafts->autosave($draft, $document, (int) $draft->definition_revision);

            $workflow = $workflow->fresh();
            $this->assertNull($workflow->published_version_id, "Recipe [{$key}] is a draft: it has no live version.");
            $this->assertSame(0, $this->enrollmentCount($workflow));
        }
    }

    public function test_an_unfinished_recipe_cannot_publish_and_names_what_is_missing(): void
    {
        $world = $this->xWorld();
        $drafts = app(WorkflowDraftService::class);
        $document = $this->recipes()['booking_follow_up'];
        $workflow = $drafts->createWorkflowWithDraft($world['business'], 'Booking', WorkflowTriggerType::AppointmentScheduled);
        $draft = $workflow->draftVersion();
        $drafts->autosave($draft, $document, (int) $draft->definition_revision);

        try {
            app(WorkflowPublisher::class)->publish($workflow->fresh());
            $this->fail('A recipe with no pipeline chosen must not publish.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('pipeline', json_encode($exception->errors()));
        }
    }

    public function test_every_recipe_publishes_once_the_businesss_own_resources_are_filled_in(): void
    {
        Notification::fake();
        $world = $this->xWorld();
        $this->xTextingReady($world['business']);
        $pipeline = $this->standardPipeline($world['business']);
        $stage = CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('position')->skip(1)->firstOrFail();
        $questionnaire = $this->makeQuestionnaire($world['business']);
        [$form] = $this->liveForm($world['business'], $world['location']);
        $drafts = app(WorkflowDraftService::class);

        foreach ($this->recipes() as $key => $document) {
            $json = json_encode($document);
            $json = str_replace('"pipeline_id":null', '"pipeline_id":' . $pipeline->id, $json);
            $json = str_replace('"stage_id":null', '"stage_id":' . $stage->id, $json);
            $document = json_decode($json, true);

            if ($key === 'new_lead_follow_up') {
                $document['root']['config']['form_id'] = (int) $form->id;
            }

            if ($key === 'payment_complete') {
                $document['root']['next'][1]['config']['form_id'] = (int) $questionnaire->id;
            }

            $workflow = $drafts->createWorkflowWithDraft($world['business'], 'Recipe ' . $key, WorkflowTriggerType::from($document['root']['config']['trigger_type']));
            $draft = $workflow->draftVersion();
            $drafts->autosave($draft, $document, (int) $draft->definition_revision);
            app(WorkflowPublisher::class)->publish($workflow->fresh());

            $this->assertNotNull($workflow->fresh()->published_version_id, "Recipe [{$key}] publishes once its resources are chosen.");
        }

        Notification::assertNothingSent();
    }

    public function test_recipes_respect_the_accounts_entitlements_at_publish(): void
    {
        // A Business with no texting number, no mailbox and no Stripe account cannot publish what needs them.
        $bare = $this->sendableTenant('Bare Studio');
        $drafts = app(WorkflowDraftService::class);

        $workflow = $drafts->createWorkflowWithDraft($bare['business'], 'Pay', WorkflowTriggerType::DocumentSigned);
        $draft = $workflow->draftVersion();
        $drafts->autosave($draft, $this->recipes()['signed_to_payment'], (int) $draft->definition_revision);

        try {
            app(WorkflowPublisher::class)->publish($workflow->fresh());
            $this->fail('No Stripe account: the payment recipe cannot publish.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Stripe', json_encode($exception->errors()));
        }
    }
}
