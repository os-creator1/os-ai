<?php

namespace Tests\Feature\Automations\Workflow\Runtime\Support;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\Contacts;
use Illuminate\Support\Str;

/**
 * Builds real, published workflows through the merged V2-0 services.
 *
 * Deliberately not raw inserts: these tests are about the runtime, and the
 * runtime should be exercised against graphs produced by the actual compiler and
 * publisher. A hand-built graph could accidentally be shaped in a way the
 * compiler would never emit, and then prove nothing.
 */
trait BuildsWorkflows
{
    /** A step type with no real executor yet, used to prove progression. */
    protected function recordingExecutor(): RecordingNodeExecutor
    {
        $executor = new RecordingNodeExecutor();

        app(NodeExecutorRegistry::class)->register($executor);

        return $executor;
    }

    /**
     * Publish a workflow whose body is the given steps, in order.
     *
     * @param list<array<string, mixed>> $steps
     *
     * @return array{0: AutomationWorkflow, 1: AutomationWorkflowVersion}
     */
    protected function publishWorkflow(
        Business $business,
        array $steps = [],
        WorkflowTriggerType $trigger = WorkflowTriggerType::ContactCreated,
        string $name = 'Runtime workflow',
    ): array {
        $drafts = app(WorkflowDraftService::class);

        $workflow = $drafts->createWorkflowWithDraft($business, $name, $trigger);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition($trigger);
        $definition['root']['next'] = $steps;

        $drafts->autosave($draft, $definition, $draft->definition_revision);

        $version = app(WorkflowPublisher::class)->publish($workflow->fresh());

        return [$workflow->fresh(), $version];
    }

    /** A step of the recording type, which the fake executor handles. */
    protected function recordedStep(string $label = 'step'): array
    {
        return [
            'key' => (string) Str::uuid(),
            'type' => 'send_sms',
            'config' => ['body' => $label],
        ];
    }

    protected function endStep(): array
    {
        return ['key' => (string) Str::uuid(), 'type' => 'end', 'config' => []];
    }

    /** A contact belonging to the given Business. */
    protected function contactFor(Business $business, string $groupName = 'Runtime'): Contacts
    {
        $group = $this->contactGroup($business, $groupName . ' ' . uniqid());

        return $this->contact($business, $group, '1202555' . random_int(1000, 9999));
    }
}
