<?php

namespace App\Library\Website\Setup;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Business\BusinessBackdropManager;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Business\BusinessLocationManager;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Website\Setup\QuestionnaireStepResolver;
use App\Library\Website\WebsiteFormPresets;
use App\Models\Business;
use App\Models\BusinessBackdrop;
use App\Models\BusinessService;
use App\Models\CatalogItem;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteForm;
use Illuminate\Database\UniqueConstraintViolationException;
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
        private readonly \App\Library\Business\BusinessImageStore $imageStore,
        private readonly QuestionnaireStepResolver $stepResolver,
    ) {
    }

    /**
     * Independent-review correction round: a step an earlier answer has
     * since hidden (e.g. "backdrops" once "offers_backdrops" was changed
     * to false) keeps whatever stale value it still carries in
     * `answers`, but that value is never applied/written here — the
     * requirement that a hidden step's stale answer must not later be
     * applied.
     *
     * Independent-review correction round 2 — reconciliation is NOT
     * conditioned on visibility the way application is: this method
     * iterates the FULL step list (not merely the currently-visible
     * subset) so that a now-HIDDEN backdrop/service/package step still
     * reconciles as an empty submission — the owner switching
     * "offers_backdrops" from true to false must deactivate any
     * wizard-created backdrop, not just stop it from being re-applied
     * with its stale value. Concretely: the accumulator for a
     * reconcilable module is initialized to `[]` the moment ANY step of
     * that module exists anywhere in the definition, whether visible or
     * not; only a VISIBLE step with a real answer ever calls the actual
     * write path (applyServices()/applyPackages()/applyBackdrops()) and
     * contributes keys to it. `null` means "this questionnaire has no
     * such step at all," which alone skips reconciliation entirely.
     *
     * Also reconciles, not merely upserts: for each of the three
     * source-keyed repeatable modules, a submitted entry's own
     * source_questionnaire_item_key set that no longer includes a
     * previously-created canonical row's key means the owner removed
     * that entry — the row is archived/deactivated (never hard-deleted,
     * never touched if it carries a NULL or different source key, i.e.
     * was created manually outside the wizard).
     */
    public function apply(Business $business, Website $website, QuestionnaireResponse $response, int $actorUserId): void
    {
        $allSteps = $response->version->steps();
        $answers = $response->answers ?? [];
        $visibleKeys = array_column($this->stepResolver->visibleSteps($allSteps, $answers), 'key');

        DB::transaction(function () use ($business, $website, $allSteps, $answers, $visibleKeys, $actorUserId) {
            $submittedServiceKeys = null;
            $submittedPackageKeys = null;
            $submittedBackdropKeys = null;

            foreach ($allSteps as $step) {
                $module = $step['target_module'];
                // A `catalog_selection` step only SELECTS canonical packages (it
                // never creates or owns rows through this applier), so it takes no
                // part in reconciliation: removing a package from the website
                // never archives it in Packages & Products.
                $isReconcilable = in_array($module, ['business_service', 'catalog_item', 'backdrop'], true)
                    && ! ($module === 'catalog_item' && ($step['input_type'] ?? null) === 'catalog_selection');

                if ($isReconcilable) {
                    // Every step of a reconcilable module participates in
                    // reconciliation regardless of visibility — this is
                    // what makes "null" mean "no such step exists" rather
                    // than "not visible right now."
                    match ($module) {
                        'business_service' => $submittedServiceKeys ??= [],
                        'catalog_item' => $submittedPackageKeys ??= [],
                        'backdrop' => $submittedBackdropKeys ??= [],
                        default => null,
                    };
                }

                if (! in_array($step['key'], $visibleKeys, true)) {
                    // Hidden: never read or apply its stale value —
                    // reconciliation above already accounted for its
                    // module without needing its (possibly stale) answer.
                    continue;
                }

                $value = $answers[$step['key']] ?? null;

                if ($value === null || $value === '' || $value === []) {
                    continue;
                }

                match ($module) {
                    'business' => $this->applyBusinessField($business, $step['target_field'], $value),
                    'business_location' => $this->applyLocationFields($business, $value),
                    'knowledge_profile' => $this->applyKnowledgeProfileField($business, $step['target_field'], $value, $actorUserId),
                    'business_service' => $submittedServiceKeys = array_merge($submittedServiceKeys, $this->applyServices($business, $value)),
                    'catalog_item' => ($step['input_type'] ?? null) === 'catalog_selection'
                        ? null
                        : $submittedPackageKeys = array_merge($submittedPackageKeys, $this->applyPackages($business, $value)),
                    'backdrop' => $submittedBackdropKeys = array_merge($submittedBackdropKeys, $this->applyBackdrops($business, $value)),
                    'website_form' => $this->applyForm($business, $website, $value),
                    // 'gallery', 'answers', 'custom_section': presentation-
                    // only or already-canonical-elsewhere — left in
                    // QuestionnaireResponse.answers, read directly by
                    // WebsitePageStrategy/MediaBindingService at generation.
                    default => null,
                };
            }

            if ($submittedServiceKeys !== null) {
                $this->reconcileRemovedServices($business, $submittedServiceKeys);
            }

            if ($submittedPackageKeys !== null) {
                $this->reconcileRemovedPackages($business, $submittedPackageKeys);
            }

            if ($submittedBackdropKeys !== null) {
                $this->reconcileRemovedBackdrops($business, $submittedBackdropKeys);
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
            // Legacy (v1) free-text answer: the one place a delimiter is parsed.
            $value = ['service_area_cities' => array_values(array_filter(array_map('trim', explode(',', $value))))];
        } elseif (array_is_list($value)) {
            $value = ['service_area_cities' => $value];
        }

        if (isset($value['service_area_cities']) && is_array($value['service_area_cities'])) {
            $value['service_area_cities'] = \App\Library\Website\Setup\ServiceAreaList::normalize($value['service_area_cities']);
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
     * @return array<int, string> the submitted entries' own source keys (nulls excluded), for reconcileRemovedServices()
     */
    private function applyServices(Business $business, array $items): array
    {
        $submittedKeys = [];

        foreach ($items as $position => $item) {
            $sourceKey = $item['key'] ?? null;
            if ($sourceKey !== null) {
                $submittedKeys[] = $sourceKey;
            }

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

            try {
                if ($existing !== null) {
                    $existing->fill($attributes)->save();
                } else {
                    BusinessService::create($attributes);
                }
            } catch (UniqueConstraintViolationException) {
                // Independent-review correction round: two concurrent
                // submissions can both miss the find-by-source-key lookup
                // above and both attempt a create — the ci_business_
                // source_item_unique-style index on business_services
                // refuses the loser, which re-fetches the winner's row and
                // updates it instead of surfacing a 500.
                $winner = $sourceKey !== null
                    ? BusinessService::where('business_id', $business->id)->where('source_questionnaire_item_key', $sourceKey)->first()
                    : null;

                if ($winner === null) {
                    throw new \DomainException('Could not save this service.');
                }

                $winner->fill($attributes)->save();
            }
        }

        return $submittedKeys;
    }

    /**
     * Independent-review correction round: any of this Business's OWN
     * wizard-created services (a non-null source key) that is no longer
     * present in this submission is deactivated — never hard-deleted,
     * never touching a manually created service (source key NULL).
     *
     * @param  array<int, string>  $submittedKeys
     */
    private function reconcileRemovedServices(Business $business, array $submittedKeys): void
    {
        BusinessService::where('business_id', $business->id)
            ->whereNotNull('source_questionnaire_item_key')
            ->whereNotIn('source_questionnaire_item_key', $submittedKeys === [] ? [''] : $submittedKeys)
            ->where('status', BusinessServiceStatus::Active->value)
            ->update(['status' => BusinessServiceStatus::Inactive->value]);
    }

    /**
     * @param  array<int, array{key: string, name: string, description: ?string, price_minor: ?int, currency_code: ?string, featured: bool, features: array<int, string>, image: ?array}>  $items
     * @return array<int, string> the submitted entries' own source keys (nulls excluded), for reconcileRemovedPackages()
     */
    private function applyPackages(Business $business, array $items): array
    {
        $submittedKeys = [];

        foreach ($items as $item) {
            $sourceKey = $item['key'] ?? null;
            if ($sourceKey !== null) {
                $submittedKeys[] = $sourceKey;
            }

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

            try {
                $catalogItem = $existing !== null
                    ? $this->catalogItems->update($business, $existing, $attributes)
                    : $this->catalogItems->create($business, $attributes);
            } catch (UniqueConstraintViolationException) {
                $winner = $sourceKey !== null ? $this->catalogItems->findBySourceKey($business, $sourceKey) : null;

                if ($winner === null) {
                    throw new \DomainException('Could not save this package.');
                }

                $catalogItem = $this->catalogItems->update($business, $winner, $attributes);
            }

            $image = $item['image'] ?? null;
            if ($image !== null && $catalogItem->images()->count() === 0) {
                $this->catalogItems->attachImage($business, $catalogItem, $image);
            }
        }

        return $submittedKeys;
    }

    /**
     * @param  array<int, string>  $submittedKeys
     */
    private function reconcileRemovedPackages(Business $business, array $submittedKeys): void
    {
        $removed = CatalogItem::where('business_id', $business->id)
            ->whereNotNull('source_questionnaire_item_key')
            ->whereNotIn('source_questionnaire_item_key', $submittedKeys === [] ? [''] : $submittedKeys)
            ->where('lifecycle_state', \App\Enums\Catalog\CatalogItemLifecycleState::Active->value)
            ->get();

        foreach ($removed as $item) {
            $this->catalogItems->archive($business, $item);
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, description: ?string, availability: bool, images: array<int, array>}>  $items
     * @return array<int, string> the submitted entries' own source keys (nulls excluded), for reconcileRemovedBackdrops()
     */
    private function applyBackdrops(Business $business, array $items): array
    {
        $submittedKeys = [];

        foreach ($items as $item) {
            $sourceKey = $item['key'] ?? null;
            if ($sourceKey !== null) {
                $submittedKeys[] = $sourceKey;
            }

            $attributes = [
                'name' => (string) ($item['name'] ?? ''),
                'description' => $item['description'] ?? null,
                'availability' => (bool) ($item['availability'] ?? true),
                'source_questionnaire_item_key' => $sourceKey,
            ];

            // Only a category-aware (v2) entry carries the key; a v1 entry
            // leaves an existing backdrop's category untouched.
            if (array_key_exists('category', $item)) {
                $attributes['category'] = $item['category'];
            }

            $existing = $sourceKey !== null ? $this->backdrops->findBySourceKey($business, $sourceKey) : null;

            try {
                $backdrop = $existing !== null
                    ? $this->backdrops->update($business, $existing, $attributes)
                    : $this->backdrops->create($business, $attributes);
            } catch (UniqueConstraintViolationException) {
                $winner = $sourceKey !== null ? $this->backdrops->findBySourceKey($business, $sourceKey) : null;

                if ($winner === null) {
                    throw new \DomainException('Could not save this backdrop.');
                }

                $backdrop = $this->backdrops->update($business, $winner, $attributes);
            }

            $images = $item['images'] ?? [];

            if ($images === []) {
                // No picture on this entry (never had one, or the owner
                // removed it): the canonical backdrop keeps none either.
                $freed = $this->backdrops->clearImages($business, $backdrop);
            } else {
                // The entry's one picture is authoritative; any earlier
                // picture it replaced is freed.
                $freed = $this->backdrops->replaceImage($business, $backdrop, $images[0]);
            }

            foreach ($freed as $freedPath) {
                $this->imageStore->deleteIfUnreferenced($business, $freedPath);
            }
        }

        // The owner's list order is the order the backdrops are shown in.
        $this->backdrops->reorderBySourceKeys($business, $submittedKeys);

        return $submittedKeys;
    }

    /**
     * Deactivation for a backdrop means `availability = false` — the
     * exact flag WebsitePageStrategy::backdropsEligible() and
     * MediaBindingService::bindBackdrops() already filter on, so a
     * removed backdrop simply stops being offered/generated without a
     * second "active" concept.
     *
     * @param  array<int, string>  $submittedKeys
     */
    private function reconcileRemovedBackdrops(Business $business, array $submittedKeys): void
    {
        $removed = BusinessBackdrop::where('business_id', $business->id)
            ->whereNotNull('source_questionnaire_item_key')
            ->whereNotIn('source_questionnaire_item_key', $submittedKeys === [] ? [''] : $submittedKeys)
            ->where('availability', true)
            ->get();

        foreach ($removed as $backdrop) {
            $backdrop->forceFill(['availability' => false])->save();

            // A removed backdrop no longer shows a picture: free its image
            // rows and any file nothing else references.
            foreach ($this->backdrops->clearImages($business, $backdrop) as $freedPath) {
                $this->imageStore->deleteIfUnreferenced($business, $freedPath);
            }
        }
    }

    /**
     * The wizard's multi-select answer is a flat list of the form field
     * keys the owner wants visitors to fill in: exactly those preset fields
     * are used (in preset order, with the preset's own required flags). A
     * list that selects nothing known falls back to the whole preset rather
     * than publishing a form with no fields. The older
     * `['required_fields' => [...]]` shape keeps its meaning — every preset
     * field, the listed ones required.
     *
     * @param  array<int|string, mixed>  $value
     */
    private function applyForm(Business $business, Website $website, array $value): void
    {
        $preset = WebsiteFormPresets::photoBoothQuoteRequest();

        if (array_is_list($value)) {
            $selected = array_map('strval', $value);
            $fields = array_values(array_filter($preset, fn (array $field) => in_array($field['key'], $selected, true)));

            if ($fields === []) {
                $fields = $preset;
            }
        } else {
            $requiredFields = (array) ($value['required_fields'] ?? []);
            $fields = array_map(
                fn (array $field) => array_merge($field, ['required' => in_array($field['key'], $requiredFields, true)]),
                $preset,
            );
        }

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
        return \App\Library\Catalog\CatalogFeatureList::join($description, $features);
    }
}
