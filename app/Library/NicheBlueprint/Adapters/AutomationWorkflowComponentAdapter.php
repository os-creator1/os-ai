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
    ];

    private const STEP_TYPES = ['add_tag', 'wait', 'send_sms', 'send_email', 'internal_notification'];

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
        $definition['root']['config'] = array_merge($definition['root']['config'], $this->triggerConfig($business, $parsed));
        $definition['root']['next'] = array_map(
            fn (array $step) => $this->nodeFor($step, $this->resolveTagId($business, $step)),
            $parsed['steps'],
        );

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

    /** @return array<string, mixed> */
    private function triggerConfig(Business $business, array $parsed): array
    {
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
            'internal_notification' => ['message' => $step['message']],
        };

        return ['key' => (string) Str::uuid(), 'type' => $step['type'], 'config' => $config, 'next' => []];
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
     * @return array{name: string, trigger_type: string, trigger_tag: ?string, steps: list<array<string, mixed>>}
     */
    private function parse(array $payload): array
    {
        $name = $this->requireString($payload, 'name', 120);
        $trigger = (string) ($payload['trigger_type'] ?? '');

        if (! in_array($trigger, self::TRIGGERS, true)) {
            throw new InvalidArgumentException('"trigger_type" must be one of: '.implode(', ', self::TRIGGERS).'.');
        }

        $triggerTag = $this->optionalString($payload, 'trigger_tag', 191);

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
                'internal_notification' => ['type' => $type, 'message' => $this->requireString($raw, 'message', 255)],
            };
        }

        return ['name' => $name, 'trigger_type' => $trigger, 'trigger_tag' => $triggerTag, 'steps' => $steps];
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
            ['name' => 'steps', 'label' => 'Steps', 'type' => 'lines', 'required' => true,
                'help' => "One step per line:\nadd_tag: Tag name\nwait: 1 day   (minutes / hours / days)\nsend_sms: message text\nsend_email: Subject | Body\nnotify: message for your team"],
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
                'notify', 'internal_notification' => ['type' => 'internal_notification', 'message' => $rest],
                default => throw new InvalidArgumentException("Unknown step \"{$kind}\"."),
            };
        }

        $payload = [
            'name' => trim((string) ($input['name'] ?? '')),
            'trigger_type' => (string) ($input['trigger_type'] ?? ''),
            'steps' => $steps,
        ];

        if (trim((string) ($input['trigger_tag'] ?? '')) !== '') {
            $payload['trigger_tag'] = trim((string) $input['trigger_tag']);
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
                default => 'notify: '.($s['message'] ?? ''),
            };
        }

        return [
            'name' => $payload['name'] ?? '',
            'trigger_type' => $payload['trigger_type'] ?? '',
            'trigger_tag' => $payload['trigger_tag'] ?? '',
            'steps' => implode("\n", $lines),
        ];
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
