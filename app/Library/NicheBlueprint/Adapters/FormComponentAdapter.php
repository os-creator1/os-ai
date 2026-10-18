<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\Forms\FormFieldType;
use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\Forms\FormManager;
use App\Library\NicheBlueprint\Workspace\BlueprintChecksum;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\Form;
use InvalidArgumentException;

/**
 * Blueprint V2 — one Form definition (field schema, mappings, style).
 *
 * INSTALLED AS A DRAFT: `FormManager::create` always makes a draft, and
 * activating/deploying a form (which needs a Location and makes it public) is
 * the owner's decision. Custom-field mappings are by field LABEL and resolved
 * inside the Business; a label the Business does not have simply leaves that
 * question unmapped. No submissions, no Contacts.
 */
final class FormComponentAdapter implements BlueprintComponentAdapter, BlueprintComponentDefinition, FingerprintsInstalledComponent
{
    use InteractsWithBlueprintPayload;

    public const TYPE = 'form';

    public function __construct(
        private readonly FormManager $forms,
        private readonly CustomFieldDefinitionManager $customFields,
    ) {}

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
        $parsed = $this->parse($payload);
        $owner = $actorUserId ?? ($business->workspace()->value('owner_user_id') ?: null);

        $labels = $this->customFields->forBusiness($business)->mapWithKeys(
            fn ($d) => [mb_strtolower((string) $d->label) => (string) $d->uid]
        );

        $fields = array_map(function (array $field) use ($labels): array {
            $label = $field['custom_field_label'] ?? null;
            unset($field['custom_field_label']);

            if ($label !== null && $labels->has(mb_strtolower($label))) {
                $field['custom_field_uid'] = $labels->get(mb_strtolower($label));
            }

            return $field;
        }, $parsed['fields']);

        $form = $this->forms->create($business, [
            'name' => $parsed['name'],
            'intro' => $parsed['intro'],
            'submit_label' => $parsed['submit_label'],
            'success_message' => $parsed['success_message'],
            'fields' => $fields,
            'design' => $parsed['design'],
            'create_opportunity' => $parsed['create_opportunity'],
            'opportunity_pipeline_id' => $parsed['pipeline_component_key'] === null
                ? null
                : $this->installedPipelineId($business, $parsed['pipeline_component_key']),
        ], $owner === null ? null : (int) $owner);

        return new InstalledComponentReference('form', (int) $form->id);
    }

    public function fingerprint(Business $business, InstalledComponentReference $reference, array $payload): ?string
    {
        $form = Form::query()->where('business_id', $business->id)->whereKey($reference->recordId)->first();

        if ($form === null) {
            return null;
        }

        return BlueprintChecksum::of([$form->name, (int) $form->current_version]);
    }

    /**
     * The Business's pipeline that THIS Business already received from the
     * named `crm_pipeline` component. Pipelines install first (CRM surface
     * precedes Forms), so by the time a form installs the record exists; if it
     * does not (component skipped or failed) the form still installs and simply
     * routes to the Business's first pipeline, exactly like a hand-made form.
     */
    private function installedPipelineId(Business $business, string $componentKey): ?int
    {
        $id = BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $business->id)
            ->where('component_type', CrmPipelineComponentAdapter::TYPE)
            ->where('component_key', $componentKey)
            ->where('state', BlueprintComponentInstallationState::Installed->value)
            ->value('installed_record_id');

        return $id === null ? null : (int) $id;
    }

    /** @return array<string, mixed> */
    private function parse(array $payload): array
    {
        $name = $this->requireString($payload, 'name', 120);
        $rawFields = $payload['fields'] ?? null;

        if (! is_array($rawFields) || ! array_is_list($rawFields) || $rawFields === []) {
            throw new InvalidArgumentException('A form needs at least one field.');
        }

        $fields = [];
        $seen = [];

        foreach ($rawFields as $i => $raw) {
            $n = $i + 1;

            if (! is_array($raw)) {
                throw new InvalidArgumentException("Field {$n} must be an object.");
            }

            $key = $this->requireString($raw, 'key', 40);

            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Field key \"{$key}\" is used twice.");
            }

            $seen[$key] = true;

            $type = FormFieldType::tryFrom((string) ($raw['type'] ?? ''))
                ?? throw new InvalidArgumentException("Field \"{$key}\" has an unknown type.");

            $field = [
                'key' => $key,
                'label' => $this->requireString($raw, 'label', 120),
                'type' => $type->value,
                'required' => (bool) ($raw['required'] ?? false),
            ];

            if (! empty($raw['options'])) {
                $field['options'] = $this->stringList($raw, 'options', 50, 100);
            }

            if (! empty($raw['contact_name'])) {
                $field['contact_name'] = true;
            }

            $custom = $this->optionalString($raw, 'custom_field_label', 80);

            if ($custom !== null) {
                $field['custom_field_label'] = $custom;
            }

            $fields[] = $field;
        }

        $design = [];

        foreach (['accent', 'background'] as $color) {
            $value = $this->optionalString($payload['design'] ?? [], $color, 7);

            if ($value !== null) {
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                    throw new InvalidArgumentException("Form style \"{$color}\" must be a #RRGGBB colour.");
                }

                $design[$color] = $value;
            }
        }

        return [
            'name' => $name,
            'intro' => $this->optionalString($payload, 'intro', 1000),
            'submit_label' => $this->optionalString($payload, 'submit_label', 40),
            'success_message' => $this->optionalString($payload, 'success_message', 300),
            'fields' => $fields,
            'design' => $design,
            'create_opportunity' => (bool) ($payload['create_opportunity'] ?? false),
            // The key of the crm_pipeline component this form's responses open an
            // Opportunity in (null = the Business's first pipeline, as before).
            'pipeline_component_key' => $this->optionalString($payload, 'pipeline_component_key', 120),
        ];
    }

    public function surface(): string
    {
        return 'forms';
    }

    public function updatePolicy(): BlueprintUpdatePolicy
    {
        return BlueprintUpdatePolicy::Copy;
    }

    public function featureKey(): string
    {
        return 'forms';
    }

    public function typeLabel(): string
    {
        return 'Form';
    }

    public function summary(array $payload): string
    {
        return ($payload['name'] ?? '?').': '.count($payload['fields'] ?? []).' fields (draft on install)';
    }

    public function formFields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Form name', 'type' => 'text', 'required' => true],
            ['name' => 'intro', 'label' => 'Introduction', 'type' => 'textarea', 'required' => false],
            ['name' => 'submit_label', 'label' => 'Button label', 'type' => 'text', 'required' => false],
            ['name' => 'success_message', 'label' => 'Thank-you message', 'type' => 'text', 'required' => false],
            ['name' => 'fields', 'label' => 'Questions', 'type' => 'lines', 'required' => true,
                'help' => "One per line: key | Label | type | required or optional | choices (comma separated) | custom=Custom field label\nUse key full_name for the person's name. Types: text, textarea, email, phone, select, date, number, checkbox, radio, yes_no, ..."],
            ['name' => 'accent', 'label' => 'Accent colour', 'type' => 'text', 'required' => false, 'help' => '#RRGGBB'],
            ['name' => 'background', 'label' => 'Background colour', 'type' => 'text', 'required' => false, 'help' => '#RRGGBB'],
            ['name' => 'create_opportunity', 'label' => 'Create an opportunity from each response', 'type' => 'select', 'required' => false,
                'options' => ['0' => 'No', '1' => 'Yes (needs a phone question)']],
            ['name' => 'pipeline_component_key', 'label' => 'Pipeline component key', 'type' => 'text', 'required' => false,
                'help' => 'Optional. The key of a CRM pipeline component of this Blueprint; responses open their Opportunity in that pipeline instead of the first one.'],
        ];
    }

    public function payloadFromInput(array $input): array
    {
        $fields = [];

        foreach ($this->linesOf($input['fields'] ?? '') as $line) {
            $parts = array_map('trim', explode('|', $line));
            $field = [
                'key' => $parts[0] ?? '',
                'label' => $parts[1] ?? '',
                'type' => $parts[2] ?? '',
                'required' => strtolower($parts[3] ?? '') === 'required',
            ];

            if (($parts[0] ?? '') === 'full_name') {
                $field['contact_name'] = true;
            }

            foreach (array_slice($parts, 4) as $extra) {
                if (str_starts_with(strtolower($extra), 'custom=')) {
                    $field['custom_field_label'] = trim(substr($extra, 7));
                } elseif ($extra !== '') {
                    $field['options'] = array_values(array_filter(array_map('trim', explode(',', $extra))));
                }
            }

            $fields[] = $field;
        }

        $payload = ['name' => trim((string) ($input['name'] ?? '')), 'fields' => $fields];

        foreach (['intro', 'submit_label', 'success_message'] as $key) {
            if (trim((string) ($input[$key] ?? '')) !== '') {
                $payload[$key] = trim((string) $input[$key]);
            }
        }

        $design = array_filter([
            'accent' => trim((string) ($input['accent'] ?? '')),
            'background' => trim((string) ($input['background'] ?? '')),
        ], fn ($v) => $v !== '');

        if ($design !== []) {
            $payload['design'] = $design;
        }

        $payload['create_opportunity'] = (string) ($input['create_opportunity'] ?? '0') === '1';

        if (trim((string) ($input['pipeline_component_key'] ?? '')) !== '') {
            $payload['pipeline_component_key'] = trim((string) $input['pipeline_component_key']);
        }

        $this->parse($payload);

        return $payload;
    }

    public function inputFromPayload(array $payload): array
    {
        $lines = [];

        foreach ($payload['fields'] ?? [] as $f) {
            $line = $f['key'].' | '.$f['label'].' | '.$f['type'].' | '.(! empty($f['required']) ? 'required' : 'optional');

            if (! empty($f['options'])) {
                $line .= ' | '.implode(', ', $f['options']);
            }

            if (! empty($f['custom_field_label'])) {
                $line .= ' | custom='.$f['custom_field_label'];
            }

            $lines[] = $line;
        }

        return [
            'name' => $payload['name'] ?? '',
            'intro' => $payload['intro'] ?? '',
            'submit_label' => $payload['submit_label'] ?? '',
            'success_message' => $payload['success_message'] ?? '',
            'fields' => implode("\n", $lines),
            'accent' => $payload['design']['accent'] ?? '',
            'background' => $payload['design']['background'] ?? '',
            'create_opportunity' => ! empty($payload['create_opportunity']) ? '1' : '0',
            'pipeline_component_key' => $payload['pipeline_component_key'] ?? '',
        ];
    }
}
