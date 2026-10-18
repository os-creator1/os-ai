<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\CustomFields\CustomFieldType;
use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\NicheBlueprint\Workspace\BlueprintChecksum;
use App\Models\Business;
use App\Models\CustomFieldDefinition;
use InvalidArgumentException;

/**
 * Blueprint V2 — one Contact custom field DEFINITION (never a value).
 * Delegates to `CustomFieldDefinitionManager::create`. A field the Business
 * already has with the same label is adopted, not duplicated.
 */
final class CustomFieldComponentAdapter implements BlueprintComponentAdapter, BlueprintComponentDefinition, FingerprintsInstalledComponent
{
    use InteractsWithBlueprintPayload;

    public const TYPE = 'crm_custom_field';

    public function __construct(private readonly CustomFieldDefinitionManager $fields) {}

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
        [$label, $type, $options] = $this->parse($payload);

        $existing = $this->fields->forBusiness($business, true)
            ->first(fn (CustomFieldDefinition $d) => mb_strtolower((string) $d->label) === mb_strtolower($label));

        $definition = $existing ?? $this->fields->create($business, $label, $type, $options);

        return new InstalledComponentReference('custom_field_definition', (int) $definition->id);
    }

    public function fingerprint(Business $business, InstalledComponentReference $reference, array $payload): ?string
    {
        $definition = CustomFieldDefinition::query()
            ->where('business_id', $business->id)
            ->whereKey($reference->recordId)
            ->first();

        if ($definition === null || $definition->archived_at !== null) {
            return null;
        }

        return BlueprintChecksum::of([$definition->label, $definition->type, $definition->options]);
    }

    /** @return array{0: string, 1: string, 2: ?list<string>} */
    private function parse(array $payload): array
    {
        $label = $this->requireString($payload, 'label', 80);
        $type = CustomFieldType::tryFrom((string) ($payload['type'] ?? ''))
            ?? throw new InvalidArgumentException('"type" is not a known custom field type.');

        $options = null;

        if ($type->hasOptions()) {
            $options = $this->stringList($payload, 'options', 50, 100);
        }

        return [$label, $type->value, $options];
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
        return 'Custom field';
    }

    public function summary(array $payload): string
    {
        return ($payload['label'] ?? '?').' ('.($payload['type'] ?? '?').')';
    }

    public function formFields(): array
    {
        return [
            ['name' => 'label', 'label' => 'Field name', 'type' => 'text', 'required' => true],
            ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true,
                'options' => collect(CustomFieldType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])->all()],
            ['name' => 'options', 'label' => 'Choices', 'type' => 'lines', 'required' => false, 'help' => 'One per line, for select / multi_select types.'],
        ];
    }

    public function payloadFromInput(array $input): array
    {
        $payload = ['label' => trim((string) ($input['label'] ?? '')), 'type' => (string) ($input['type'] ?? '')];
        $options = $this->linesOf($input['options'] ?? '');

        if ($options !== []) {
            $payload['options'] = $options;
        }

        $this->parse($payload);

        return $payload;
    }

    public function inputFromPayload(array $payload): array
    {
        return [
            'label' => $payload['label'] ?? '',
            'type' => $payload['type'] ?? '',
            'options' => implode("\n", $payload['options'] ?? []),
        ];
    }
}
