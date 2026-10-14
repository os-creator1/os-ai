<?php

namespace App\Library\Demo\BrightFrame;

use App\Library\Catalog\CatalogFeatureList;
use App\Library\Catalog\CatalogItemManager;
use App\Models\BusinessService;
use App\Models\CatalogItem;
use App\Repositories\Contracts\BusinessServiceRepository;
use Illuminate\Support\Str;

/** Services (what the business does) and Packages (what it sells, priced). */
final class ServicesStage implements DemoStage
{
    public function __construct(
        private readonly BusinessServiceRepository $services,
        private readonly CatalogItemManager $catalog,
    ) {
    }

    public function run(DemoContext $context): void
    {
        $business = $context->business;
        $existing = BusinessService::query()->where('business_id', $business->id)->get()->keyBy('name');
        $payload = [];

        foreach (BrightFrameData::services() as $i => $service) {
            $payload[] = array_filter([
                'id' => $existing->get($service['name'])?->id,
                'name' => $service['name'],
                'description' => $service['description'],
                'starting_price' => $service['price'],
                'currency_code' => 'USD',
                'sort_order' => $i,
                'is_primary' => $service['primary'] ?? false,
            ], fn ($value) => $value !== null);
        }

        // Only this tenant's own services are passed, so nothing else is deactivated.
        $synced = $this->services->syncForBusiness($business, $payload);

        foreach ($synced as $service) {
            $context->refs['services'][$service->name] = $service;
        }

        foreach (BrightFrameData::packages() as $package) {
            $key = 'demo_brightframe_' . Str::slug($package['name'], '_');
            $attributes = [
                'type' => 'package',
                'name' => $package['name'],
                'description' => CatalogFeatureList::join($package['description'], $package['features']),
                'price_minor' => $package['price_minor'],
                'currency_code' => 'USD',
                'featured' => $package['featured'],
                'source_questionnaire_item_key' => $key,
            ];

            $item = $this->catalog->findBySourceKey($business, $key);
            $item = $item === null
                ? $this->catalog->create($business, $attributes, $context->user->id)
                : $this->catalog->update($business, $item, $attributes);

            $context->refs['packages'][$package['name']] = $item;
        }

        $context->note('services', BusinessService::query()->where('business_id', $business->id)->count());
        $context->note('packages', CatalogItem::query()->where('business_id', $business->id)->count());
    }
}
