<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Library\NicheBlueprint\Workspace\BlueprintChecksum;
use App\Models\Business;
use App\Models\CatalogItem;
use InvalidArgumentException;
use LogicException;

/**
 * Blueprint V2 — a package or add-on TEMPLATE: a reusable suggestion, NOT a
 * customer offering. It is template-only, so installation runs never create
 * catalog items; a catalog item exists only after a deliberate customer action
 * or an explicitly authorised import. Existing blueprint-origin items are left
 * untouched (fingerprint still reads them).
 *
 * A template carries NO price unless the Blueprint author deliberately sets a
 * "suggested" one.
 */
final class PackageTemplateComponentAdapter implements BlueprintComponentAdapter, BlueprintComponentDefinition, FingerprintsInstalledComponent, TemplateOnlyComponent
{
    use InteractsWithBlueprintPayload;

    public const TYPE = 'package_template';

    public function componentType(): string
    {
        return self::TYPE;
    }

    public function validateDescriptor(array $payload): void
    {
        $this->parse($payload);
    }

    /**
     * Never called by the installer (see TemplateOnlyComponent): a template is
     * a suggestion, so no automated run creates a catalog item from it.
     */
    public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
    {
        throw new LogicException('Package templates are suggestions and are never installed automatically.');
    }

    public function fingerprint(Business $business, InstalledComponentReference $reference, array $payload): ?string
    {
        $item = CatalogItem::query()->where('business_id', $business->id)->whereKey($reference->recordId)->first();

        if ($item === null || $item->archived_at !== null) {
            return null;
        }

        return BlueprintChecksum::of([$item->name, $item->description, $item->price_minor, $item->currency_code]);
    }

    /** @return array<string, mixed> */
    private function parse(array $payload): array
    {
        $kind = (string) ($payload['kind'] ?? '');

        if (! in_array($kind, ['package', 'add_on'], true)) {
            throw new InvalidArgumentException('"kind" must be package or add_on.');
        }

        $price = $this->intInRange($payload['suggested_price_minor'] ?? null, 'suggested_price_minor', 0, 100000000);
        $currency = $this->optionalString($payload, 'currency_code', 3);

        if ($price !== null && ($currency === null || strlen($currency) !== 3)) {
            throw new InvalidArgumentException('A suggested price needs a 3-letter "currency_code".');
        }

        return [
            'kind' => $kind,
            'name' => $this->requireString($payload, 'name', 160),
            'description' => $this->optionalString($payload, 'description', 5000),
            'suggested_price_minor' => $price,
            'currency_code' => $currency === null ? null : strtoupper($currency),
        ];
    }

    public function surface(): string
    {
        return 'packages';
    }

    public function updatePolicy(): BlueprintUpdatePolicy
    {
        return BlueprintUpdatePolicy::Copy;
    }

    public function featureKey(): string
    {
        return 'packages_products';
    }

    public function typeLabel(): string
    {
        return 'Package / add-on';
    }

    public function summary(array $payload): string
    {
        return ucfirst(str_replace('_', '-', (string) ($payload['kind'] ?? '?'))).': '.($payload['name'] ?? '?')
            .(isset($payload['suggested_price_minor']) ? ' (suggested price)' : ' (no price)');
    }

    public function formFields(): array
    {
        return [
            ['name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'required' => true, 'options' => ['package' => 'Package', 'add_on' => 'Add-on']],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => false],
            ['name' => 'suggested_price_minor', 'label' => 'Suggested price (minor units, optional)', 'type' => 'number', 'required' => false,
                'help' => 'Leave empty for no price. 49900 = 499.00'],
            ['name' => 'currency_code', 'label' => 'Currency (3 letters)', 'type' => 'text', 'required' => false],
        ];
    }

    public function payloadFromInput(array $input): array
    {
        $payload = [];

        foreach ($this->formFields() as $field) {
            $value = trim((string) ($input[$field['name']] ?? ''));

            if ($value !== '') {
                $payload[$field['name']] = $field['name'] === 'suggested_price_minor' ? (int) $value : $value;
            }
        }

        $this->parse($payload);

        return $payload;
    }

    public function inputFromPayload(array $payload): array
    {
        return $payload;
    }
}
