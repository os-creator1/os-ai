<?php

namespace Tests\Feature\Automations\Workflow\Foundations\Support;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Business\BusinessStatus;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;

/**
 * Workflow builders shared by the merged-foundation integration tests.
 *
 * Everything here goes through the real draft service and publisher, so a
 * workflow is validated and compiled exactly as a customer's would be.
 */
trait BuildsFoundationWorkflows
{
    use BuildsWorkflows;

    /** The fixtures' Business must be Active for the runtime checkpoint to let a step run. */
    protected function activate(Business $business): Business
    {
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        return $business->fresh();
    }

    /**
     * A PUBLISHED workflow on a trigger, with optional trigger-node filters
     * (`tag_id`, `form_id`) and optional steps after it.
     *
     * @param array<string, mixed> $triggerConfig
     * @param list<array<string, mixed>>|null $steps null = a single End step
     */
    protected function triggerWorkflow(
        Business $business,
        WorkflowTriggerType $type,
        array $triggerConfig = [],
        ?array $steps = null,
        ?string $name = null,
    ): AutomationWorkflow {
        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, $name ?? ('Foundation ' . $type->value . ' ' . uniqid()), $type);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition($type);
        $definition['root']['config'] = array_merge($definition['root']['config'], $triggerConfig);
        $definition['root']['next'] = $steps ?? [$this->endStep()];

        $drafts->autosave($draft, $definition, (int) $draft->definition_revision);
        app(WorkflowPublisher::class)->publish($workflow->fresh());

        return $workflow->fresh();
    }

    protected function enrollmentCount(AutomationWorkflow $workflow): int
    {
        return AutomationEnrollment::query()->where('workflow_id', $workflow->id)->count();
    }

    /** Close every journey so only the occurrence key can refuse a second enrollment. */
    protected function finishJourneys(): void
    {
        DB::table('automation_enrollments')->update(['status' => 'completed', 'completed_at' => now()]);
    }

    /** @return array{key: string, type: string, config: array<string, mixed>} */
    protected function emailStep(string $subject = 'Hello {first_name}', string $body = 'Thanks for getting in touch.'): array
    {
        return ['key' => (string) Str::uuid(), 'type' => 'send_email', 'config' => ['subject' => $subject, 'body' => $body]];
    }

    /** @return array{key: string, type: string, config: array<string, mixed>} */
    protected function waitMinutes(int $minutes): array
    {
        return ['key' => (string) Str::uuid(), 'type' => 'wait', 'config' => ['mode' => 'duration', 'amount' => $minutes, 'unit' => 'minutes']];
    }

    /** @return array{key: string, type: string, config: array<string, mixed>} */
    protected function addTagStep(int $tagId): array
    {
        return ['key' => (string) Str::uuid(), 'type' => 'add_tag', 'config' => ['tag_id' => $tagId]];
    }

    /** @return array{key: string, type: string, config: array<string, mixed>} */
    protected function removeTagStep(int $tagId): array
    {
        return ['key' => (string) Str::uuid(), 'type' => 'remove_tag', 'config' => ['tag_id' => $tagId]];
    }
}
