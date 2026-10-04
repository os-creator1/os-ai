<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\Catalog\CatalogItemType;
use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Library\Catalog\CatalogItemManager;
use App\Library\NicheBlueprint\Workspace\BlueprintChecksum;
use App\Models\Business;
use App\Models\CatalogItem;
use InvalidArgumentException;

/**
 * Blueprint V2 — a package or add-on TEMPLATE for the Business catalog.
 * Delegates to `CatalogItemManager::create` (system-authored, no actor).
 *
 * A template carries NO price unless the Blueprint author deliberately sets a
 * "suggested" one; the Business owner is expected to set their own. An add-on
 * is a catalog `product` (the catalog has two types: product, package).
 * Idempotency: the row carries `source_questionnaire_item_key =
 * "blueprint:<hash>"`, and an existing row with that key is adopted, so a
 * re-run can never duplicate it.
 */
final class PackageTemplateComponentAdapter implements BlueprintComponentAdapter, BlueprintComponentDefinition, FingerprintsInstalledComponent
{
    use InteractsWithBlueprintPayload;

    public const TYPE = 'package_template';

    public function __construct(private readonly CatalogItemManager $catalog) {}

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
        $sourceKey = 'blueprint:'.substr(BlueprintChecksum::of([$p['kind'], mb_strtolower($p['name'])]), 0, 40);

        $existing = $this->catalog->findBySourceKey($business, $sourceKey);

        $item = $existing ?? $this->catalog->create($business, [
            'type' => $p['kind'] === 'add_on' ? CatalogItemType::Product->value : CatalogItemType::Package->value,
            'name' => $p['name'],
            'description' => $p['description'],
            'price_minor' => $p['suggested_price_minor'],
            'currency_code' => $p['suggested_price_minor'] === null ? null : $p['currency_code'],
            'featured' => false,
            'source_questionnaire_item_key' => $sourceKey,
        ], $actorUserId);

        return new InstalledComponentReference('catalog_item', (int) $item->id);
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
