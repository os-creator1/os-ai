<?php

namespace App\Library\Assistant\Tools;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Assistant\ActionOutcome;
use App\Library\Assistant\ActionPreview;
use App\Library\Assistant\AssistantActionRefused;
use App\Library\Assistant\AssistantContext;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Models\AutomationWorkflow;

/**
 * Create a DRAFT automation (a name and its starting event) through WorkflowDraftService. The workflow is never
 * published or switched on here: its steps are built in the automation builder, and publishing is the customer's own
 * action there. The assistant says so in the preview.
 */
final class PrepareAutomationTool implements ActionTool
{
    public function __construct(private readonly WorkflowDraftService $drafts)
    {
    }

    public function key(): string
    {
        return 'prepare_automation';
    }

    public function description(): string
    {
        return 'Start a DRAFT automation (not active). args: name (string), trigger = one of: ' . implode(', ', array_map(fn (WorkflowTriggerType $t): string => $t->value . ' ("' . $t->label() . '")', self::triggers())) . '. Steps are built by the customer in the builder.';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'trigger' => ['required', 'string', 'in:' . implode(',', array_map(fn (WorkflowTriggerType $t): string => $t->value, self::triggers()))],
        ];
    }

    public function mode(): string
    {
        return self::MODE_EXECUTE;
    }

    public function available(AssistantContext $context): bool
    {
        return $context->entitled('automations') && $context->can('automations');
    }

    public function prepare(AssistantContext $context, array $args): ActionPreview
    {
        $trigger = WorkflowTriggerType::from((string) $args['trigger']);
        $name = trim((string) $args['name']);

        if ($this->nameTaken($context, $name)) {
            throw new AssistantActionRefused('duplicate_name', "You already have an automation called \"{$name}\". Choose a different name.");
        }

        return new ActionPreview(
            title: 'Start a draft automation',
            lines: [
                ['label' => 'Name', 'value' => $name],
                ['label' => 'Starts when', 'value' => $trigger->label()],
            ],
            effects: [
                'A draft automation is created. It is NOT switched on and does nothing until you build its steps and publish it yourself.',
                'Nothing is sent to anyone and nothing is charged.',
            ],
            recipients: [],
            cost: null,
            resolved: ['name' => $name, 'trigger' => $trigger->value],
            confirmLabel: 'Create draft automation',
        );
    }

    public function fingerprint(AssistantContext $context, array $resolved): string
    {
        return hash('sha256', json_encode([(int) $context->business->id, $this->nameTaken($context, (string) $resolved['name'])]));
    }

    public function execute(AssistantContext $context, array $resolved): ActionOutcome
    {
        $workflow = $this->drafts->createWorkflowWithDraft(
            $context->business,
            (string) $resolved['name'],
            WorkflowTriggerType::from((string) $resolved['trigger']),
            (int) $context->user->id,
        );

        $stored = AutomationWorkflow::query()->where('business_id', $context->business->id)->whereKey($workflow->getKey())->first();

        if ($stored === null) {
            throw new AssistantActionRefused('not_verified', 'The draft could not be confirmed after saving, so it was not reported as created.');
        }

        [$workspaceUid, $businessUid] = $context->scoped;

        return new ActionOutcome(
            "Draft automation \"{$stored->name}\" was created. It is not active yet.",
            route('customer.workspaces.businesses.automations.workflows.show', [$workspaceUid, $businessUid, $stored->uid]),
            ['workflow_uid' => (string) $stored->uid],
        );
    }

    private function nameTaken(AssistantContext $context, string $name): bool
    {
        return AutomationWorkflow::query()->where('business_id', $context->business->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
    }

    /** @return array<int, WorkflowTriggerType> */
    private static function triggers(): array
    {
        return array_values(array_filter(WorkflowTriggerType::cases(), fn (WorkflowTriggerType $t): bool => $t->isIngestableInThisSlice()));
    }
}
