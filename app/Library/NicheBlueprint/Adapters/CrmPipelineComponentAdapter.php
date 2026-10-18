<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Library\Crm\Templates\BusinessTemplate;
use App\Library\Crm\Templates\BusinessTemplateApplier;
use App\Library\Crm\Templates\PipelineBlueprint;
use App\Library\Crm\Templates\StageBlueprint;
use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Library\NicheBlueprint\Workspace\BlueprintChecksum;
use App\Models\Business;
use App\Models\CrmPipeline;
use InvalidArgumentException;

/**
 * Contract 20 §5.5/§10/§12.D — the first real Blueprint component adapter.
 *
 * CONTAINS NO COPYING LOGIC OF ITS OWN. It translates the component's JSON
 * payload into the existing, validated `BusinessTemplate` + `PipelineBlueprint`
 * + `StageBlueprint` graph and calls the existing, unmodified
 * `BusinessTemplateApplier::copyPipeline()` — the same seam
 * `CrmPipelinesController::setup()`'s lazy path already uses (§3.2, §9.3).
 * Nothing under `app/Library/Crm/` is edited by this class.
 *
 * `copyPipeline()` is used rather than `applyPipelines()` DELIBERATELY (§10):
 * the installer already re-reads the installation record under the Business
 * lock before ever reaching this class (§7.2 step 2/3), so that is the one
 * idempotency authority. Adding a second "already copied?" probe here against
 * `crm_pipelines` would be a second, potentially-driftable idempotency rule
 * underneath the one that actually governs whether this class is even called
 * again — exactly what Contract 20 §10 says to avoid.
 *
 * VALIDATES EVERYTHING `install()` WILL NEED, AT PUBLISH TIME.
 * `validateDescriptor()` parses the full payload the same way `install()`
 * does — including `template_key`/`template_version`, not only the stage
 * shape `PipelineBlueprint` itself checks — so a payload malformed in any
 * field this adapter reads fails Blueprint publication (§6.2 gate 5) and
 * never reaches a Business's installation transaction.
 */
final class CrmPipelineComponentAdapter implements BlueprintComponentAdapter, BlueprintComponentDefinition, FingerprintsInstalledComponent
{
    /** The `niche_blueprint_components.component_type` this adapter claims. */
    public const TYPE = 'crm_pipeline';

    public function __construct(
        private readonly BusinessTemplateApplier $applier,
    ) {}

    public function componentType(): string
    {
        return self::TYPE;
    }

    public function validateDescriptor(array $payload): void
    {
        $this->parse($payload);
    }

    /**
     * Called only for an ALLOWED entitlement decision (§6.3), inside the
     * installer's per-component transaction, with the Business row already
     * locked (§7.2). This class never consults EntitlementManager or
     * PlatformFeatureRegistry itself — there is no second authority here.
     */
    public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
    {
        [$templateKey, $templateVersion, $blueprint] = $this->parse($payload);

        // The template's own `name` is never read by copyPipeline() (only
        // `key` and `version` are) and is not persisted anywhere, so the
        // template key doubles as an inert placeholder for it.
        $template = new BusinessTemplate($templateKey, $templateVersion, $templateKey, [$blueprint]);

        $pipeline = $this->applier->copyPipeline($business, $template, $blueprint, $blueprint->name, $actorUserId);

        return new InstalledComponentReference('crm_pipeline', (int) $pipeline->id);
    }

    /**
     * Parses and fully validates a component payload shaped like Contract 20
     * §5.5's example. `PipelineBlueprint`'s and `StageBlueprint`'s own
     * constructors perform the stage-shape checks (non-empty, first stage
     * `new_inquiry`, well-formed/unique semantic keys, name lengths) "for
     * free" (§10) — this method validates only the fields those classes do
     * not know about (`template_key`, `template_version`, `pipeline_key`).
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: int, 2: PipelineBlueprint}
     */
    private function parse(array $payload): array
    {
        $templateKey = $this->requireString($payload, 'template_key');
        $templateVersion = $this->requireInt($payload, 'template_version');
        $pipelineKey = $this->requireString($payload, 'pipeline_key');
        $name = $this->requireString($payload, 'name');
        $stages = $payload['stages'] ?? null;

        if (! is_array($stages) || $stages === [] || ! array_is_list($stages)) {
            throw new InvalidArgumentException('A crm_pipeline component payload must carry a non-empty "stages" list.');
        }

        $stageBlueprints = [];

        foreach ($stages as $index => $stage) {
            if (! is_array($stage)) {
                throw new InvalidArgumentException("A crm_pipeline component payload stage at index [{$index}] must be an object.");
            }

            $stageName = $this->requireString($stage, 'name', "stage at index [{$index}]");
            $semanticKey = $stage['semantic_key'] ?? null;

            if ($semanticKey !== null && ! is_string($semanticKey)) {
                throw new InvalidArgumentException("A crm_pipeline component payload stage at index [{$index}] has a non-string \"semantic_key\".");
            }

            $stageBlueprints[] = new StageBlueprint($stageName, $semanticKey);
        }

        // PipelineBlueprint's own constructor enforces: at least one stage
        // (already true here), every name 1-100 characters, semantic keys
        // well-formed and unique, and the first stage's semantic key exactly
        // `new_inquiry` (Contract 20 §5.5 point 1).
        $blueprint = new PipelineBlueprint($pipelineKey, $name, $stageBlueprints);

        return [$templateKey, $templateVersion, $blueprint];
    }

    private function requireString(array $payload, string $key, ?string $context = null): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            $subject = $context ?? "\"{$key}\"";

            throw new InvalidArgumentException("A crm_pipeline component payload {$subject} must carry a non-empty string".($context !== null ? " \"{$key}\"." : '.'));
        }

        return $value;
    }

    private function requireInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("A crm_pipeline component payload must carry a positive integer \"{$key}\".");
        }

        return $value;
    }
    public function fingerprint(Business $business, InstalledComponentReference $reference, array $payload): ?string
    {
        $pipeline = CrmPipeline::query()->where('business_id', $business->id)->whereKey($reference->recordId)->first();

        if ($pipeline === null || $pipeline->archived_at !== null) {
            return null;
        }

        return BlueprintChecksum::of([
            $pipeline->name,
            $pipeline->stages()->orderBy('position')->orderBy('id')->get()->map(fn ($s) => [$s->name, $s->semantic_key ?? null])->all(),
        ]);
    }

    public function surface(): string
    {
        return 'crm';
    }

    public function updatePolicy(): BlueprintUpdatePolicy
    {
        return BlueprintUpdatePolicy::Copy;
    }

    public function featureKey(): string
    {
        return 'crm';
    }

    public function typeLabel(): string
    {
        return 'Pipeline';
    }

    public function summary(array $payload): string
    {
        return ($payload['name'] ?? '?').': '.count($payload['stages'] ?? []).' stages';
    }

    public function formFields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Pipeline name', 'type' => 'text', 'required' => true],
            ['name' => 'stages', 'label' => 'Stages', 'type' => 'lines', 'required' => true,
                'help' => 'One per line: "Stage name | semantic_key" (key optional). The first stage must be "new_inquiry".'],
        ];
    }

    public function payloadFromInput(array $input): array
    {
        $stages = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) ($input['stages'] ?? '')) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 2));
            $stages[] = ['name' => $parts[0], 'semantic_key' => ($parts[1] ?? '') !== '' ? $parts[1] : null];
        }

        $name = trim((string) ($input['name'] ?? ''));
        $payload = [
            'template_key' => 'blueprint',
            'template_version' => 1,
            'pipeline_key' => \Illuminate\Support\Str::slug($name, '_') ?: 'sales',
            'name' => $name,
            'stages' => $stages,
        ];

        $this->parse($payload);

        return $payload;
    }

    public function inputFromPayload(array $payload): array
    {
        $lines = array_map(
            fn (array $stage) => $stage['name'].(! empty($stage['semantic_key']) ? ' | '.$stage['semantic_key'] : ''),
            $payload['stages'] ?? [],
        );

        return ['name' => $payload['name'] ?? '', 'stages' => implode("\n", $lines)];
    }
}