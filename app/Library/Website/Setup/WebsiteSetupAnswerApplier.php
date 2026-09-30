<?php

namespace App\Library\Website\Setup;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Business\BusinessBackdropManager;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Business\BusinessLocationManager;
use App\Library\Catalog\CatalogItemManager;
use App\Models\Business;
use App\Models\BusinessService;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Library\Website\WebsiteFormPresets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Website Builder redesign — on a COMPLETED QuestionnaireResponse,
 * transactionally applies its answers into the real, canonical Business
 * OS records each question's own `target_module` names — never a
 * website-only copy. Runs once per completion (WebsiteWizardController's
 * generate() action) and again, idempotently, whenever the owner uses
 * "Edit setup answers": every repeatable-group entry (packages,
 * backdrops, services) is matched back to its existing canonical row by
 * `source_questionnaire_item_key` (each entry's own client-assigned
 * `key`), so a re-run updates rather than duplicates.
 *
 * Deliberately never touches WebsiteGuidedGenerationCommitService or any
 * page/section content — it only prepares the FACTS guided generation
 * will read afterward (WebsitePageStrategy::buildPlan() already reads
 * Business/BusinessLocation/BusinessService/CatalogItem/BusinessBackdrop
 * directly), so re-running this from "Edit setup answers" alone can never
 * silently overwrite manually edited page content.
 */
final class WebsiteSetupAnswerApplier
{
    public function __construct(
        private readonly BusinessKnowledgeProfileManager $profiles,
        private readonly BusinessLocationManager $locations,
        private readonly CatalogItemManager $catalogItems,
        private readonly BusinessBackdropManager $backdrops,
    ) {
    }

    public function apply(Business $business, Website $website, QuestionnaireResponse $response, int $actorUserId): void
    {
        $steps = $response->version->steps();
        $answers = $response->answers ?? [];

        DB::transaction(function () use ($business, $website, $steps, $answers, $actorUserId) {
            foreach ($steps as $step) {
                $value = $answers[$step['key']] ?? null;

                if ($value === null || $value === '' || $value === []) {
                    continue;
                }

                match ($step['target_module']) {
                    'business' => $this->applyBusinessField($business, $step['target_field'], $value),
                    'business_location' => $this->applyLocationFields($business, $value),
                    'knowledge_profile' => $this->applyKnowledgeProfileField($business, $step['target_field'], $value, $actorUserId),
                    'business_service' => $this->applyServices($business, $value),
                    'catalog_item' => $this->applyPackages($business, $value),
                    'backdrop' => $this->applyBackdrops($business, $value),
                    'website_form' => $this->applyForm($business, $website, $value),
                    // 'gallery', 'answers', 'custom_section': presentation-
                    // only or already-canonical-elsewhere — left in
                    // QuestionnaireResponse.answers, read directly by
                    // WebsitePageStrategy/MediaBindingService at generation.
                    default => null,
                };
            }
        });
    }

    private function applyBusinessField(Business $business, ?string $field, mixed $value): void
    {
        if ($field === null || ! in_array($field, ['name', 'phone', 'email', 'description'], true)) {
            return;
        }

        $business->forceFill([$field => $value])->save();
    }

    /**
     * Most `business_location`-targeted questions answer with a structured
     * dict (address_line_1/city/region/service_area_cities). The
     * Photobooth "which cities do you serve?" question is simpler — a
     * single comma-separated text answer — so a plain string or a bare
     * list is normalized into `service_area_cities` here rather than
     * asking every such question to pre-shape a dict.
     *
     * @param  array<string, mixed>|string  $value
     */
    private function applyLocationFields(Business $business, array|string $value): void
    {
        if (is_string($value)) {
            $value = ['service_area_cities' => array_values(array_filter(array_map('trim', explode(',', $value))))];
        } elseif (array_is_list($value)) {
            $value = ['service_area_cities' => $value];
        }

        $this->locations->upsertPrimaryLocation($business, array_filter([
            'address_line_1' => $value['address_line_1'] ?? null,
            'city' => $value['city'] ?? null,
            'region' => $value['region'] ?? null,
            'service_area_cities' => $value['service_area_cities'] ?? null,
        ], fn ($v) => $v !== null));
    }

    private function applyKnowledgeProfileField(Business $business, ?string $field, mixed $value, int $actorUserId): void
    {
        if ($field === null) {
            return;
        }

        $this->profiles->updateFields($business, [$field => $value], 'website_setup', $actorUserId);
    }

    /**
     * @param  array<int, array{key: string, name: string, description: ?string, starting_price: ?int, currency_code: ?string}>  $items
     */
    private function applyServices(Business $business, array $items): void
    {
        foreach ($items as $position => $item) {
            $sourceKey = $item['key'] ?? null;
            $name = (string) ($item['name'] ?? '');
            $attributes = [
                'business_id' => $business->id,
                'name' => $name,
                'slug' => Str::slug($name) ?: 'service-' . ($sourceKey ?? $position),
                'description' => $item['description'] ?? null,
                'starting_price' => $item['starting_price'] ?? null,
                'currency_code' => $item['currency_code'] ?? null,
                'status' => BusinessServiceStatus::Active->value,
                'sort_order' => $position,
                'source_questionnaire_item_key' => $sourceKey,
            ];

            $existing = $sourceKey !== null
                ? BusinessService::where('business_id', $business->id)->where('source_questionnaire_item_key', $sourceKey)->first()
                : null;

            if ($existing !== null) {
                $existing->fill($attributes)->save();
            } else {
                BusinessService::create($attributes);
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, description: ?string, price_minor: ?int, currency_code: ?string, featured: bool, features: array<int, string>, image: ?array}>  $items
     */
    private function applyPackages(Business $business, array $items): void
    {
        foreach ($items as $item) {
            $sourceKey = $item['key'] ?? null;
            $attributes = [
                'type' => 'package',
                'name' => (string) ($item['name'] ?? ''),
                'description' => $this->describeWithFeatures($item['description'] ?? null, $item['features'] ?? []),
                'price_minor' => $item['price_minor'] ?? null,
                'currency_code' => $item['currency_code'] ?? null,
                'featured' => (bool) ($item['featured'] ?? false),
                'source_questionnaire_item_key' => $sourceKey,
            ];

            $existing = $sourceKey !== null ? $this->catalogItems->findBySourceKey($business, $sourceKey) : null;

            $catalogItem = $existing !== null
                ? $this->catalogItems->update($business, $existing, $attributes)
                : $this->catalogItems->create($business, $attributes);

            $image = $item['image'] ?? null;
            if ($image !== null && $catalogItem->images()->count() === 0) {
                $this->catalogItems->attachImage($business, $catalogItem, $image);
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, description: ?string, availability: bool, images: array<int, array>}>  $items
     */
    private function applyBackdrops(Business $business, array $items): void
    {
        foreach ($items as $item) {
            $sourceKey = $item['key'] ?? null;
            $attributes = [
                'name' => (string) ($item['name'] ?? ''),
                'description' => $item['description'] ?? null,
                'availability' => (bool) ($item['availability'] ?? true),
                'source_questionnaire_item_key' => $sourceKey,
            ];

            $existing = $sourceKey !== null ? $this->backdrops->findBySourceKey($business, $sourceKey) : null;

            $backdrop = $existing !== null
                ? $this->backdrops->update($business, $existing, $attributes)
                : $this->backdrops->create($business, $attributes);

            if ($backdrop->images()->count() === 0) {
                foreach (($item['images'] ?? []) as $image) {
                    $this->backdrops->addImage($business, $backdrop, $image);
                }
            }
        }
    }

    /**
     * @param  array{required_fields: array<int, string>}  $value
     */
    private function applyForm(Business $business, Website $website, array $value): void
    {
        $requiredFields = $value['required_fields'] ?? [];

        $fields = array_map(
            fn (array $field) => array_merge($field, ['required' => in_array($field['key'], $requiredFields, true)]),
            WebsiteFormPresets::photoBoothQuoteRequest(),
        );

        $form = WebsiteForm::where('website_id', $website->id)
            ->where('type', WebsiteForm::TYPE_QUOTE_REQUEST)
            ->first();

        if ($form !== null) {
            $form->update(['fields' => $fields]);

            return;
        }

        WebsiteForm::create([
            'website_id' => $website->id,
            'business_id' => $business->id,
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Photo Booth Quote Request',
            'fields' => $fields,
            'submit_label' => 'Request a quote',
        ]);
    }

    /**
     * @param  array<int, string>  $features
     */
    private function describeWithFeatures(?string $description, array $features): ?string
    {
        $description = $description !== null ? trim($description) : '';

        if ($features === []) {
            return $description !== '' ? $description : null;
        }

        $featureList = implode("\n", array_map(fn ($f) => '- ' . $f, $features));

        return trim($description . "\n\n" . $featureList);
    }
}
