<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Automation\Workflow\WorkflowVersionState;
use App\Library\Automation\Workflow\WorkflowCapabilities;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\Form;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The Forms Notifications tab's bridge to Automations.
 *
 * A form has NO notification system of its own: a finished response emits the
 * existing "a form is submitted" trigger and Automations decide who is told. So
 * "Set up" here does exactly one thing — it opens the real workflow editor on a
 * new DRAFT workflow that is already pointed at this form and carries one starter
 * step for the chosen action. It never publishes: nothing is sent until the
 * person reviews the step and publishes it in the editor, and `linked()` reports
 * only workflows that really are live, so the tab never claims a notification is on.
 *
 * Consent and eligibility stay where they already are — the SMS / email step
 * executors enforce the contact's consent and the account's sending path when the
 * workflow runs; `eligibility()` only reads WorkflowCapabilities so a card whose
 * channel cannot work is shown disabled, with the reason.
 */
final class FormNotificationWorkflows
{
    public const TEAM = 'team';

    public const SMS = 'sms';

    public const EMAIL = 'email';

    public const ACTIONS = [self::TEAM, self::SMS, self::EMAIL];

    public function __construct(
        private readonly WorkflowDraftService $drafts,
        private readonly WorkflowCapabilities $capabilities,
    ) {
    }

    /** @return array<string, array{available: bool, reason: ?string}> keyed by action */
    public function eligibility(Business $business): array
    {
        $caps = $this->capabilities->forBusiness($business);

        return [
            self::TEAM => ['available' => true, 'reason' => null],
            self::SMS => $caps[WorkflowCapabilities::SMS] ?? ['available' => false, 'reason' => null],
            self::EMAIL => $caps[WorkflowCapabilities::EMAIL] ?? ['available' => false, 'reason' => null],
        ];
    }

    /**
     * Create the draft and return it. The caller has already proven Forms and
     * Automations access; this only builds the document.
     */
    public function start(Business $business, Form $form, string $action, ?int $userId): AutomationWorkflow
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Unknown notification action.');
        }

        $workflow = $this->drafts->createWorkflowWithDraft(
            $business,
            Str::limit($form->name, 80, '').' — '.$this->title($action),
            WorkflowTriggerType::FormSubmitted,
            $userId,
        );

        $draft = AutomationWorkflowVersion::query()
            ->where('workflow_id', $workflow->getKey())
            ->where('state', WorkflowVersionState::Draft->value)
            ->firstOrFail();

        $definition = $draft->definition;
        $definition['root']['config'] = array_merge($definition['root']['config'], ['form_id' => (int) $form->getKey()]);
        $definition['root']['next'] = [$this->node($action, $form)];

        $this->drafts->autosave($draft, $definition, (int) $draft->definition_revision);

        return $workflow;
    }

    /**
     * Live workflows that fire for this form (this form exactly, or any form):
     * published and running, or paused. A draft or archived workflow is not listed.
     *
     * @return list<array{uid: string, name: string, status: string, paused: bool}>
     */
    public function linked(Business $business, Form $form): array
    {
        $workflows = AutomationWorkflow::query()
            ->where('business_id', $business->getKey())
            ->whereNotNull('published_version_id')
            ->whereIn('status', [WorkflowStatus::Published->value, WorkflowStatus::Paused->value])
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        if ($workflows->isEmpty()) {
            return [];
        }

        $versions = AutomationWorkflowVersion::query()
            ->whereIn('id', $workflows->pluck('published_version_id'))
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($workflows as $workflow) {
            $config = $versions->get($workflow->published_version_id)?->definition['root']['config'] ?? [];

            if (($config['trigger_type'] ?? null) !== WorkflowTriggerType::FormSubmitted->value) {
                continue;
            }

            $wanted = $config['form_id'] ?? null;

            if ($wanted !== null && $wanted !== '' && (int) $wanted !== (int) $form->getKey()) {
                continue;
            }

            $out[] = [
                'uid' => (string) $workflow->uid,
                'name' => (string) $workflow->name,
                'status' => $workflow->status->value,
                'paused' => $workflow->status === WorkflowStatus::Paused,
            ];
        }

        return $out;
    }

    private function title(string $action): string
    {
        return match ($action) {
            self::TEAM => 'notify the team',
            self::SMS => 'text them back',
            default => 'email them back',
        };
    }

    /** @return array<string, mixed> one starter workflow node the person reviews and edits */
    private function node(string $action, Form $form): array
    {
        $config = match ($action) {
            self::TEAM => ['message' => 'New response on "'.$form->name.'". Open the response to see the answers.'],
            self::SMS => ['body' => 'Thanks for reaching out! We got your request and will be in touch shortly.'],
            default => [
                'subject' => 'Thanks for getting in touch',
                'body' => "Thanks for getting in touch! We received your request and will follow up shortly.\n\nIf you need anything in the meantime, just reply to this email.",
            ],
        };

        $type = match ($action) {
            self::TEAM => 'internal_notification',
            self::SMS => 'send_sms',
            default => 'send_email',
        };

        return ['key' => (string) Str::uuid(), 'type' => $type, 'config' => $config, 'next' => []];
    }
}
