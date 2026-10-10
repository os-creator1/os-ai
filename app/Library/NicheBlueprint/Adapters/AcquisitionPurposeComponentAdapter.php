<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Library\Acquisition\Economics\EconomicsCalculators;
use App\Library\NicheBlueprint\Workspace\BlueprintChecksum;
use App\Models\AcquisitionPurpose;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\CrmPipeline;
use App\Models\Form;
use InvalidArgumentException;

/**
 * Acquisition Purpose V1 — one Blueprint component that becomes one
 * `acquisition_purposes` row for the Business.
 *
 * The component DATA owns only: the purpose's name and outcome noun, which
 * code-owned calculator applies, the wording of the economics questions
 * (optionally with suggested ranges), strategy guidance and the website
 * intent. It owns NO number a Business would act on: no price, cost, target
 * or rate. It cannot name an input its calculator does not declare, so a niche
 * author can reword and re-order questions but can never invent arithmetic.
 *
 * The purpose REFERENCES the pipeline and the form this same Blueprint
 * installed (looked up through the installation records, by component key);
 * nothing is created or copied here. Provenance is the installation record the
 * installer writes for this component.
 */
final class AcquisitionPurposeComponentAdapter implements BlueprintComponentAdapter, BlueprintComponentDefinition, FingerprintsInstalledComponent
{
    use InteractsWithBlueprintPayload;

    public const TYPE = 'acquisition_purpose';

    public const RECORD_TYPE = 'acquisition_purpose';

    public function __construct(private readonly EconomicsCalculators $calculators)
    {
    }

    public function componentType(): string
    {
        return self::TYPE;
    }

    public function validateDescriptor(array $payload): void
    {
        $this->parse($payload);
    }

    public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
    {
        $p = $this->parse($payload);

        $purpose = AcquisitionPurpose::query()->create([
            'business_id' => $business->id,
            'purpose_key' => $p['purpose_key'],
            'name' => $p['name'],
            'outcome_type' => $p['outcome_type'],
            'calculator_key' => $p['calculator'],
            'is_active' => true,
            'sort_order' => $p['sort_order'],
            'crm_pipeline_id' => $p['pipeline_component_key'] === null ? null : $this->installedPipelineId($business, $p['pipeline_component_key']),
            'form_id' => $p['form_component_key'] === null ? null : $this->installedFormId($business, $p['form_component_key']),
            'destination_type' => AcquisitionPurpose::DESTINATION_NONE,
            'labels' => $p['labels'],
            'question_schema' => $p['question_schema'],
            'economics' => null,
            'guidance' => $p['guidance'],
            'website_intent' => $p['website_intent'],
        ]);

        return new InstalledComponentReference(self::RECORD_TYPE, (int) $purpose->id);
    }

    /** The Business edits its answers freely, so only the definition is fingerprinted, never the answers. */
    public function fingerprint(Business $business, InstalledComponentReference $reference, array $payload): ?string
    {
        $purpose = AcquisitionPurpose::query()->where('business_id', $business->id)->whereKey($reference->recordId)->first();

        return $purpose === null ? null : BlueprintChecksum::of([$purpose->name, $purpose->calculator_key, $purpose->crm_pipeline_id !== null]);
    }

    /** @return array<string, mixed> */
    private function parse(array $payload): array
    {
        $calculatorKey = $this->requireString($payload, 'calculator', 48);

        if (! $this->calculators->has($calculatorKey)) {
            throw new InvalidArgumentException("\"calculator\" [{$calculatorKey}] is not a known economics calculator.");
        }

        $purposeKey = $this->requireString($payload, 'purpose_key', 64);

        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $purposeKey) !== 1) {
            throw new InvalidArgumentException('"purpose_key" must be lowercase letters, digits and underscores.');
        }

        $inputs = $this->calculators->get($calculatorKey)->inputs();
        $questions = $payload['questions'] ?? [];

        if (! is_array($questions) || ! array_is_list($questions)) {
            throw new InvalidArgumentException('"questions" must be a list.');
        }

        $schema = [];

        foreach ($questions as $i => $question) {
            if (! is_array($question)) {
                throw new InvalidArgumentException('Question ' . ($i + 1) . ' must be an object.');
            }

            $key = $this->requireString($question, 'key', 64);

            if (! isset($inputs[$key])) {
                throw new InvalidArgumentException("Question \"{$key}\" is not an input of the [{$calculatorKey}] calculator.");
            }

            if (isset($schema[$key])) {
                throw new InvalidArgumentException("Question \"{$key}\" is listed twice.");
            }

            $entry = ['key' => $key] + $inputs[$key];
            $entry['label'] = $this->optionalString($question, 'label', 200) ?? $entry['label'];
            $entry['help'] = $this->optionalString($question, 'help', 500) ?? $entry['help'];

            foreach (['suggested_min', 'suggested_max'] as $range) {
                if (isset($question[$range])) {
                    if (! is_numeric($question[$range]) || (float) $question[$range] < 0) {
                        throw new InvalidArgumentException("\"{$range}\" for question \"{$key}\" must be a number that is zero or more.");
                    }

                    // Guidance shown beside the question; never an answer, never a target.
                    $entry[$range] = (float) $question[$range];
                }
            }

            $schema[$key] = $entry;
        }

        // Every input the calculator understands stays answerable, in the author's order first.
        foreach ($inputs as $key => $definition) {
            $schema[$key] ??= ['key' => $key] + $definition;
        }

        $labels = [];

        foreach ((array) ($payload['labels'] ?? []) as $key => $value) {
            if (! is_string($key) || ! is_string($value) || trim($value) === '' || mb_strlen($value) > 120) {
                throw new InvalidArgumentException('"labels" must map names to short text.');
            }

            $labels[$key] = trim($value);
        }

        $guidance = [];

        foreach ((array) ($payload['guidance'] ?? []) as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('"guidance" entries must be objects.');
            }

            $guidance[] = [
                'title' => $this->requireString($item, 'title', 120),
                'body' => $this->requireString($item, 'body', 1200),
            ];
        }

        $intent = $payload['website_intent'] ?? null;

        if ($intent !== null) {
            if (! is_array($intent)) {
                throw new InvalidArgumentException('"website_intent" must be an object.');
            }

            $pages = [];

            foreach ((array) ($intent['pages'] ?? []) as $page) {
                if (! is_array($page)) {
                    throw new InvalidArgumentException('"website_intent.pages" entries must be objects.');
                }

                $pages[] = [
                    'page_key' => $this->requireString($page, 'page_key', 60),
                    'title' => $this->requireString($page, 'title', 120),
                    'summary' => $this->optionalString($page, 'summary', 300),
                ];
            }

            if ($pages === []) {
                throw new InvalidArgumentException('"website_intent" needs at least one page.');
            }

            $intent = [
                'audience' => $this->requireString($intent, 'audience', 160),
                'cta' => $this->requireString($intent, 'cta', 120),
                'pages' => $pages,
                'emphasis' => $this->stringList($intent, 'emphasis', 20, 200, false),
                'content_prompts' => $this->stringList($intent, 'content_prompts', 20, 500, false),
            ];
        }

        return [
            'purpose_key' => $purposeKey,
            'name' => $this->requireString($payload, 'name', 120),
            'outcome_type' => $this->requireString($payload, 'outcome_type', 32),
            'calculator' => $calculatorKey,
            'sort_order' => $this->intInRange($payload['sort_order'] ?? null, 'sort_order', 0, 100, 0),
            'pipeline_component_key' => $this->optionalString($payload, 'pipeline_component_key', 120),
            'form_component_key' => $this->optionalString($payload, 'form_component_key', 120),
            'labels' => $labels,
            'question_schema' => array_values($schema),
            'guidance' => $guidance,
            'website_intent' => $intent,
        ];
    }

    private function installedPipelineId(Business $business, string $componentKey): ?int
    {
        $id = $this->installedRecordId($business, CrmPipelineComponentAdapter::TYPE, $componentKey);

        return $id !== null && CrmPipeline::query()->where('business_id', $business->id)->whereKey($id)->exists() ? $id : null;
    }

    private function installedFormId(Business $business, string $componentKey): ?int
    {
        $id = $this->installedRecordId($business, FormComponentAdapter::TYPE, $componentKey);

        return $id !== null && Form::query()->where('business_id', $business->id)->whereKey($id)->exists() ? $id : null;
    }

    private function installedRecordId(Business $business, string $type, string $componentKey): ?int
    {
        $id = BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $business->id)
            ->where('component_type', $type)
            ->where('component_key', $componentKey)
            ->where('state', BlueprintComponentInstallationState::Installed->value)
            ->value('installed_record_id');

        return $id === null ? null : (int) $id;
    }

    public function surface(): string
    {
        return 'acquisition';
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
        return 'Acquisition goal';
    }

    public function summary(array $payload): string
    {
        return ($payload['name'] ?? '?') . ': ' . ($payload['calculator'] ?? '?') . ' economics, ' . count($payload['questions'] ?? []) . ' questions';
    }

    public function formFields(): array
    {
        return [
            ['name' => 'purpose_key', 'label' => 'Goal key', 'type' => 'text', 'required' => true, 'help' => 'Lowercase, e.g. student_enrollment. Stable: never change it after Businesses installed it.'],
            ['name' => 'name', 'label' => 'Goal name', 'type' => 'text', 'required' => true],
            ['name' => 'outcome_type', 'label' => 'Outcome', 'type' => 'text', 'required' => true, 'help' => 'The noun the goal ends in: student, hire, customer, booking.'],
            ['name' => 'calculator', 'label' => 'Economics calculator', 'type' => 'select', 'required' => true,
                'options' => array_combine($this->calculators->keys(), $this->calculators->keys())],
            ['name' => 'pipeline_component_key', 'label' => 'Pipeline component key', 'type' => 'text', 'required' => false],
            ['name' => 'form_component_key', 'label' => 'Form component key', 'type' => 'text', 'required' => false],
            ['name' => 'definition_json', 'label' => 'Questions, labels, guidance and website intent (JSON)', 'type' => 'textarea', 'required' => false,
                'help' => 'An object with optional keys: labels, questions (key, label, help, suggested_min, suggested_max), guidance (title, body), website_intent.'],
        ];
    }

    public function payloadFromInput(array $input): array
    {
        $payload = [];

        foreach (['purpose_key', 'name', 'outcome_type', 'calculator', 'pipeline_component_key', 'form_component_key'] as $key) {
            if (trim((string) ($input[$key] ?? '')) !== '') {
                $payload[$key] = trim((string) $input[$key]);
            }
        }

        $definition = trim((string) ($input['definition_json'] ?? ''));

        if ($definition !== '') {
            $decoded = json_decode($definition, true);

            if (! is_array($decoded)) {
                throw new InvalidArgumentException('The definition must be valid JSON.');
            }

            $payload += array_intersect_key($decoded, array_flip(['labels', 'questions', 'guidance', 'website_intent', 'sort_order']));
        }

        $this->parse($payload);

        return $payload;
    }

    public function inputFromPayload(array $payload): array
    {
        $input = array_intersect_key($payload, array_flip(['purpose_key', 'name', 'outcome_type', 'calculator', 'pipeline_component_key', 'form_component_key']));
        $rest = array_intersect_key($payload, array_flip(['labels', 'questions', 'guidance', 'website_intent', 'sort_order']));
        $input['definition_json'] = $rest === [] ? '' : json_encode($rest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $input;
    }
}
