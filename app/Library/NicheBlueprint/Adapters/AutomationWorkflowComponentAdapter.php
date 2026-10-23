<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Automation\Workflow\WorkflowVersionState;
use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Library\Automation\Workflow\NodeTypeRegistry;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Crm\TagManager;
use App\Library\NicheBlueprint\Workspace\BlueprintChecksum;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\CrmPipelineStage;
use App\Models\Form;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Blueprint V2 — one Automation workflow DEFINITION.
 *
 * INSTALLED AS A DRAFT, NEVER PUBLISHED. Publishing is what starts enrollments
 * and external sends (SMS/email), and it enforces quota and Location authority;
 * a niche default must not start messaging a Business's contacts before its
 * owner has seen it. The owner reviews the draft and publishes it themselves.
 * Delegates to `WorkflowDraftService` (the canonical create + autosave).
 *
 * The payload is a linear step list so the Blueprint never stores Business
 * row ids: tags are named and resolved (or created) inside the Business at
 * install time. Supported steps: add_tag, wait, send_sms, send_email,
 * internal_notification.
 */
final class AutomationWorkflowComponentAdapter implements BlueprintComponentAdapter, BlueprintComponentDefinition, FingerprintsInstalledComponent
{
    use InteractsWithBlueprintPayload;

    public const TYPE = 'automation_workflow';

    public const TRIGGERS = [
        'contact_created',
        'form_submitted',
        'appointment_scheduled',
        'opportunity_won',
        'contact_tag_added',
        'opportunity_stage_changed',
        'opportunity_lost',
        'questionnaire_submitted',
        'payment_succeeded',
    ];

    private const STEP_TYPES = ['add_tag', 'wait', 'send_sms', 'send_email', 'internal_notification', 'end_if_replied', 'end_if_unsubscribed', 'end_if_tagged', 'end_if_no_open_deal', 'end_if_no_review_link', 'end_if_no_questionnaire_link', 'wait_for_send_window'];

    /**
     * Guard steps compile to an If / Else whose YES path is an End and whose NO
     * path carries every later step — "stop here if the person replied (or is
     * unsubscribed), otherwise continue". They are how a Blueprint expresses
     * reply detection and consent without a free-form condition language.
     */
    private const GUARD_SUBJECTS = [
        'end_if_replied' => 'contact.replied_since_enrollment',
        'end_if_unsubscribed' => 'contact.subscribed',
        'end_if_tagged' => 'contact.has_tag',
        // Stop when the deal is won or lost (no open deal remains) — covers booked / lost / closed states.
        'end_if_no_open_deal' => 'contact.has_open_deal',
        // Never promise a link the Business has not set up.
        'end_if_no_review_link' => 'business.review_link_set',
        'end_if_no_questionnaire_link' => 'business.questionnaire_link_set',
    ];

    public function __construct(
        private readonly WorkflowDraftService $drafts,
        private readonly TagManager $tags,
        private readonly NodeTypeRegistry $nodes,
    ) {}

    public function componentType(): string
    {
        return self::TYPE;
    }

    public function validateDescriptor(array $payload): void
    {
        $parsed = $this->parse($payload);

        // Reuse the Automations node validators on every step, so a payload
        // the workflow editor would reject cannot be published in a Blueprint.
        foreach ($parsed['steps'] as $step) {
            if (isset(self::GUARD_SUBJECTS[$step['type']])) {
                continue;
            }

            $node = $this->nodeFor($step, 1);
            $errors = $this->nodes->validateConfig(WorkflowNodeType::from($node['type']), $node['config']);

            if ($errors !== []) {
                throw new InvalidArgumentException($errors[0]);
            }
        }
    }

    public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
    {
        $parsed = $this->parse($payload);
        $ownerId = $actorUserId ?? $this->ownerUserId($business);

        $workflow = $this->drafts->createWorkflowWithDraft(
            $business,
            $parsed['name'],
            WorkflowTriggerType::from($parsed['trigger_type']),
            $ownerId,
        );

        $draft = AutomationWorkflowVersion::query()
            ->where('workflow_id', $workflow->getKey())
            ->where('state', WorkflowVersionState::Draft->value)
            ->firstOrFail();

        $definition = $draft->definition;
        // Pack journeys are sequences of independent channels: log a failed step and carry on rather than halting the whole journey.
        $definition['root']['config'] = array_merge($definition['root']['config'], ['failure_policy' => 'continue'], $this->triggerConfig($business, $parsed));
        $definition['root']['next'] = $this->buildSequence($business, $parsed['steps']);

        $this->drafts->autosave($draft, $definition, (int) $draft->definition_revision);

        return new InstalledComponentReference('automation_workflow', (int) $workflow->getKey());
    }

    public function fingerprint(Business $business, InstalledComponentReference $reference, array $payload): ?string
    {
        $workflow = AutomationWorkflow::query()->where('business_id', $business->id)->whereKey($reference->recordId)->first();

        if ($workflow === null || $workflow->status->value === 'archived') {
            return null;
        }

        // The owner editing or publishing creates/promotes a version, so the
        // highest version number plus the name is a cheap, faithful signal.
        $version = (int) AutomationWorkflowVersion::query()->where('workflow_id', $workflow->getKey())->max('version_number');
        $revision = (int) AutomationWorkflowVersion::query()->where('workflow_id', $workflow->getKey())->sum('definition_revision');

        return BlueprintChecksum::of([$workflow->name, $workflow->status->value, $version, $revision]);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function buildSequence(Business $business, array $steps): array
    {
        $nodes = [];

        foreach ($steps as $i => $step) {
            if (isset(self::GUARD_SUBJECTS[$step['type']])) {
                // Consecutive guards become ONE If / Else ("stop if ANY of these is true"): the engine allows only 5 levels
                // of nesting and a guard's NO lane carries everything after it.
                $conditions = [];
                $k = $i;
                while (isset($steps[$k]) && isset(self::GUARD_SUBJECTS[$steps[$k]['type']]) && count($conditions) < 5) {
                    $conditions[] = $this->guardCondition($business, $steps[$k]);
                    $k++;
                }

                $nodes[] = [
                    'key' => (string) Str::uuid(),
                    'type' => 'if_else',
                    'config' => ['match' => 'any', 'conditions' => $conditions],
                    'yes' => [['key' => (string) Str::uuid(), 'type' => 'end', 'config' => [], 'next' => []]],
                    'no' => $this->buildSequence($business, array_slice($steps, $k)),
                ];

                return $nodes;
            }

            $nodes[] = $this->nodeFor($step, $this->resolveTagId($business, $step));
        }

        return $nodes;
    }

    /** @return array{subject: string, operator: string} */
    private function guardCondition(Business $business, array $step): array
    {
        return [
            'subject' => $step['type'] === 'end_if_tagged'
                ? 'contact.has_tag:'.$this->resolveTag($business, $step['tag'])
                : self::GUARD_SUBJECTS[$step['type']],
            // "replied" and "tagged" end on true; "subscribed", "has an open deal" and "link is set" end on false.
            'operator' => in_array($step['type'], ['end_if_replied', 'end_if_tagged'], true) ? 'is_true' : 'is_false',
        ];
    }

    /** @return array<string, mixed> */
    private function triggerConfig(Business $business, array $parsed): array
    {
        if ($parsed['trigger_type'] === 'opportunity_stage_changed') {
            $stage = CrmPipelineStage::query()
                ->where('business_id', $business->id)
                ->whereNull('archived_at')
                ->where('name', $parsed['trigger_stage'])
                ->orderBy('id')
                ->first();

            if ($stage === null) {
                throw new InvalidArgumentException("Install the pipeline first: no stage named \"{$parsed['trigger_stage']}\" exists in this Business.");
            }

            return ['pipeline_id' => (int) $stage->pipeline_id, 'to_stage_id' => (int) $stage->id];
        }

        if ($parsed['trigger_type'] === 'form_submitted' && $parsed['trigger_form'] !== null) {
            $formId = Form::query()->where('business_id', $business->id)->where('name', $parsed['trigger_form'])->orderBy('id')->value('id');

            if ($formId === null) {
                throw new InvalidArgumentException("Install the form first: no form named \"{$parsed['trigger_form']}\" exists in this Business.");
            }

            return ['form_id' => (int) $formId];
        }

        if ($parsed['trigger_type'] === 'contact_tag_added' && $parsed['trigger_tag'] !== null) {
            return ['tag_id' => $this->resolveTag($business, $parsed['trigger_tag'])];
        }

        return [];
    }

    /** @return array<string, mixed> a workflow node */
    private function nodeFor(array $step, int $tagId): array
    {
        $config = match ($step['type']) {
            'add_tag' => ['tag_id' => $tagId],
            'wait' => ['mode' => 'duration', 'amount' => $step['amount'], 'unit' => $step['unit']],
            'send_sms' => ['body' => $step['body']],
            'send_email' => ['subject' => $step['subject'], 'body' => $step['body']],
            'internal_notification' => ['message' => $step['message']] + (isset($step['task']) ? ['task' => $step['task']] : []),
            'wait_for_send_window' => ['mode' => 'until_send_window', 'business_hours' => (bool) $step['business_hours']],
        };

        return ['key' => (string) Str::uuid(), 'type' => $step['type'] === 'wait_for_send_window' ? 'wait' : $step['type'], 'config' => $config, 'next' => []];
    }

    private function resolveTagId(Business $business, array $step): int
    {
        return $step['type'] === 'add_tag' ? $this->resolveTag($business, $step['tag']) : 1;
    }

    private function resolveTag(Business $business, string $name): int
    {
        $normalized = mb_strtolower(trim($name));
        $existing = $this->tags->tagsForBusiness($business, true)->first(fn ($t) => (string) $t->normalized_name === $normalized);

        return (int) ($existing ?? $this->tags->createTag($business, $name))->id;
    }

    private function ownerUserId(Business $business): ?int
    {
        $owner = $business->workspace()->value('owner_user_id');

        return $owner === null ? null : (int) $owner;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{name: string, trigger_type: string, trigger_tag: ?string, trigger_stage: ?string, steps: list<array<string, mixed>>}
     */
    private function parse(array $payload): array
    {
        $name = $this->requireString($payload, 'name', 120);
        $trigger = (string) ($payload['trigger_type'] ?? '');

        if (! in_array($trigger, self::TRIGGERS, true)) {
            throw new InvalidArgumentException('"trigger_type" must be one of: '.implode(', ', self::TRIGGERS).'.');
        }

        $triggerTag = $this->optionalString($payload, 'trigger_tag', 191);
        $triggerStage = $this->optionalString($payload, 'trigger_stage', 100);
        $triggerForm = $this->optionalString($payload, 'trigger_form', 120);

        if ($trigger === 'opportunity_stage_changed' && $triggerStage === null) {
            throw new InvalidArgumentException('An "opportunity_stage_changed" trigger needs a "trigger_stage" (the stage name the deal moves into).');
        }

        if ($trigger === 'contact_tag_added' && $triggerTag === null) {
            throw new InvalidArgumentException('A "contact_tag_added" trigger needs a "trigger_tag".');
        }

        $rawSteps = $payload['steps'] ?? null;

        if (! is_array($rawSteps) || ! array_is_list($rawSteps) || $rawSteps === []) {
            throw new InvalidArgumentException('"steps" needs at least one step.');
        }

        if (count($rawSteps) > 25) {
            throw new InvalidArgumentException('An automation blueprint may have at most 25 steps.');
        }

        $steps = [];

        foreach ($rawSteps as $i => $raw) {
            $n = $i + 1;
            $type = is_array($raw) ? (string) ($raw['type'] ?? '') : '';

            if (! in_array($type, self::STEP_TYPES, true)) {
                throw new InvalidArgumentException("Step {$n} has an unsupported type.");
            }

            $steps[] = match ($type) {
                'add_tag' => ['type' => $type, 'tag' => $this->requireString($raw, 'tag', 191)],
                'wait' => [
                    'type' => $type,
                    'amount' => $this->intInRange($raw['amount'] ?? null, 'amount', 1, 365),
                    'unit' => in_array($raw['unit'] ?? null, ['minutes', 'hours', 'days'], true)
                        ? $raw['unit']
                        : throw new InvalidArgumentException("Step {$n}: wait unit must be minutes, hours or days."),
                ],
                'send_sms' => ['type' => $type, 'body' => $this->requireString($raw, 'body', 1600)],
                'send_email' => ['type' => $type, 'subject' => $this->requireString($raw, 'subject', 200), 'body' => $this->requireString($raw, 'body', 20000)],
                'internal_notification' => ['type' => $type, 'message' => $this->requireString($raw, 'message', 255)]
                    + (is_array($raw['task'] ?? null) ? ['task' => $raw['task']] : []),
                'end_if_replied', 'end_if_unsubscribed' => ['type' => $type],
                'end_if_tagged' => ['type' => $type, 'tag' => $this->requireString($raw, 'tag', 191)],
                'end_if_no_open_deal', 'end_if_no_review_link', 'end_if_no_questionnaire_link' => ['type' => $type],
                'wait_for_send_window' => ['type' => $type, 'business_hours' => (bool) ($raw['business_hours'] ?? false)],
            };
        }

        return ['name' => $name, 'trigger_type' => $trigger, 'trigger_tag' => $triggerTag, 'trigger_stage' => $triggerStage, 'trigger_form' => $triggerForm, 'steps' => $steps];
    }

    public function surface(): string
    {
        return 'automations';
    }

    public function updatePolicy(): BlueprintUpdatePolicy
    {
        return BlueprintUpdatePolicy::Copy;
    }

    public function featureKey(): string
    {
        return 'automations';
    }

    public function typeLabel(): string
    {
        return 'Automation';
    }

    public function summary(array $payload): string
    {
        return ($payload['name'] ?? '?').': '.count($payload['steps'] ?? []).' steps (draft on install)';
    }

    public function formFields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Automation name', 'type' => 'text', 'required' => true],
            ['name' => 'trigger_type', 'label' => 'Starts when', 'type' => 'select', 'required' => true,
                'options' => array_combine(self::TRIGGERS, array_map(fn ($t) => WorkflowTriggerType::from($t)->label(), self::TRIGGERS))],
            ['name' => 'trigger_tag', 'label' => 'Trigger tag', 'type' => 'text', 'required' => false, 'help' => 'Only for "A tag is added".'],
            ['name' => 'trigger_stage', 'label' => 'Trigger stage', 'type' => 'text', 'required' => false, 'help' => 'Only for "A deal changes stage": the stage name.'],
            ['name' => 'trigger_form', 'label' => 'Trigger form', 'type' => 'text', 'required' => false, 'help' => 'Only for "A form is submitted": the form name (blank = any form).'],
            ['name' => 'steps', 'label' => 'Steps', 'type' => 'lines', 'required' => true,
                'help' => "One step per line:\nadd_tag: Tag name\nwait: 1 day   (minutes / hours / days)\nsend_sms: message text\nsend_email: Subject | Body\nnotify: message for your team\nwait_for_send_window: quiet_hours | business_hours   (never overnight)\nend_if_replied: / end_if_unsubscribed: / end_if_no_open_deal: / end_if_tagged: Tag name"],
        ];
    }

    public function payloadFromInput(array $input): array
    {
        $steps = [];

        foreach ($this->linesOf($input['steps'] ?? '') as $line) {
            [$kind, $rest] = array_pad(explode(':', $line, 2), 2, '');
            $kind = strtolower(trim($kind));
            $rest = trim($rest);

            $steps[] = match ($kind) {
                'add_tag' => ['type' => 'add_tag', 'tag' => $rest],
                'wait' => $this->waitStep($rest),
                'send_sms' => ['type' => 'send_sms', 'body' => $rest],
                'send_email' => $this->emailStep($rest),
                'notify', 'internal_notification' => $this->notifyStep($rest),
                'wait_for_send_window' => ['type' => 'wait_for_send_window', 'business_hours' => in_array(strtolower($rest), ['business_hours', 'business hours'], true)],
                'end_if_replied', 'end_if_unsubscribed', 'end_if_no_open_deal', 'end_if_no_review_link', 'end_if_no_questionnaire_link' => ['type' => $kind],
                'end_if_tagged' => ['type' => 'end_if_tagged', 'tag' => $rest],
                default => throw new InvalidArgumentException("Unknown step \"{$kind}\"."),
            };
        }

        $payload = [
            'name' => trim((string) ($input['name'] ?? '')),
            'trigger_type' => (string) ($input['trigger_type'] ?? ''),
            'steps' => $steps,
        ];

        foreach (['trigger_tag', 'trigger_stage', 'trigger_form'] as $key) {
            if (trim((string) ($input[$key] ?? '')) !== '') {
                $payload[$key] = trim((string) $input[$key]);
            }
        }

        $this->validateDescriptor($payload);

        return $payload;
    }

    public function inputFromPayload(array $payload): array
    {
        $lines = [];

        foreach ($payload['steps'] ?? [] as $s) {
            $lines[] = match ($s['type']) {
                'add_tag' => 'add_tag: '.$s['tag'],
                'wait' => 'wait: '.$s['amount'].' '.$s['unit'],
                'send_sms' => 'send_sms: '.$s['body'],
                'send_email' => 'send_email: '.$s['subject'].' | '.$s['body'],
                'wait_for_send_window' => 'wait_for_send_window: '.(! empty($s['business_hours']) ? 'business_hours' : 'quiet_hours'),
                'end_if_tagged' => 'end_if_tagged: '.$s['tag'],
                'end_if_replied', 'end_if_unsubscribed', 'end_if_no_open_deal', 'end_if_no_review_link', 'end_if_no_questionnaire_link' => $s['type'].':',
                default => 'notify: '.($s['message'] ?? '').(isset($s['task']) ? ' | task='.$s['task']['title'].' | due_hours='.($s['task']['due_in_hours'] ?? '') : ''),
            };
        }

        return [
            'name' => $payload['name'] ?? '',
            'trigger_type' => $payload['trigger_type'] ?? '',
            'trigger_tag' => $payload['trigger_tag'] ?? '',
            'trigger_stage' => $payload['trigger_stage'] ?? '',
            'trigger_form' => $payload['trigger_form'] ?? '',
            'steps' => implode("\n", $lines),
        ];
    }

    /** "message" or "message | task=Title | due_hours=2": the call task a notification creates survives an edit. @return array<string, mixed> */
    private function notifyStep(string $rest): array
    {
        $parts = array_map('trim', explode(' | task=', $rest, 2));
        $step = ['type' => 'internal_notification', 'message' => $parts[0]];

        if (isset($parts[1])) {
            [$title, $due] = array_pad(explode(' | due_hours=', $parts[1], 2), 2, '');
            $step['task'] = ['title' => trim($title)] + (trim($due) !== '' ? ['due_in_hours' => (int) $due] : []);
        }

        return $step;
    }

    /** @return array<string, mixed> */
    private function waitStep(string $rest): array
    {
        if (preg_match('/^(\d+)\s*(minute|minutes|hour|hours|day|days)$/i', $rest, $m) !== 1) {
            throw new InvalidArgumentException('A wait step looks like "wait: 2 days".');
        }

        $unit = strtolower($m[2]);
        $unit = str_ends_with($unit, 's') ? $unit : $unit.'s';

        return ['type' => 'wait', 'amount' => (int) $m[1], 'unit' => $unit];
    }

    /** @return array<string, mixed> */
    private function emailStep(string $rest): array
    {
        [$subject, $body] = $this->pipeParts($rest, 2);

        return ['type' => 'send_email', 'subject' => (string) $subject, 'body' => (string) $body];
    }
}
