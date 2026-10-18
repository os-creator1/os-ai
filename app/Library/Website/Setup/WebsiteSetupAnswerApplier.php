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
        private readonly \App\Library\Business\BusinessServiceImageManager $serviceImages,
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
            // A questionnaire may carry more than one `business_service` step
            // (booth types, then events/services). Positions must CONTINUE
            // across them: each step restarting at 0 gave two services the
            // same sort_order, so the page planner's "first N services in the
            // owner's order" interleaved the steps and could drop a core
            // service (a 360 or mirror booth) in favour of an event type.
            $servicePosition = 0;

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
                    'knowledge_profile' => $this->applyKnowledgeProfileField($business, $step['target_field'], $this->profileValue($business, $step, $value), $actorUserId, (bool) ($step['owner_confirms'] ?? false)),
                    'business_service' => $submittedServiceKeys = array_merge($submittedServiceKeys, $this->applyServices($business, $value, $servicePosition, $step)),
                    'catalog_item' => ($step['input_type'] ?? null) === 'catalog_selection'
                        ? null
                        : $submittedPackageKeys = array_merge($submittedPackageKeys, $this->applyPackages($business, $value, $step)),
                    'backdrop' => $submittedBackdropKeys = array_merge($submittedBackdropKeys, $this->applyBackdrops($business, $value, $step)),
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

    /**
     * A multi-select answer is a list of OPTION KEYS; the profile stores the facts themselves. For the
     * business's advantages that means the option labels — and a fact the owner already confirmed that this
     * question never offered is kept, not silently dropped by someone ticking a few boxes.
     *
     * @param  array<string, mixed>  $step
     */
    /**
     * The wizard's "what should visitors do first?" choices (`primary_cta`) are website CTAs, not the canonical
     * Business Knowledge Profile goals (BusinessPrimaryConversionGoal). Two of the three offered values are the same
     * words; "Book now" (reserve a date online) is canonically a calendar booking. The raw answer is never rewritten —
     * the blueprint and review read `book_now` from the answers — only the value handed to the canonical profile is
     * translated. A value not listed here passes through unchanged, so a genuinely invalid goal is still rejected.
     */
    public const PRIMARY_CTA_TO_GOAL = ['book_now' => 'calendar_booking'];

    /** The profile can state at most this many differentiators (BusinessKnowledgeProfileManager). */
    private const MAX_DIFFERENTIATORS = 6;

    private function profileValue(Business $business, array $step, mixed $value): mixed
    {
        if (($step['target_field'] ?? null) === 'primary_conversion_goal' && is_string($value)) {
            return self::PRIMARY_CTA_TO_GOAL[$value] ?? $value;
        }

        if (($step['target_field'] ?? null) !== 'differentiators' || ! is_array($value) || ! is_array($step['options'] ?? null)) {
            return $value;
        }

        $options = $step['options'];
        $labels = [];
        foreach ($value as $key) {
            $labels[] = (string) ($options[(string) $key] ?? $key);
        }

        $offered = array_map(fn ($label) => mb_strtolower(trim((string) $label)), array_values($options));
        $kept = array_values(array_filter(
            (array) (\App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->value('differentiators') ?? []),
            fn ($fact) => ! in_array(mb_strtolower(trim((string) $fact)), $offered, true),
        ));

        // The questionnaire offers more choices than the profile can hold: the owner's selections win, earlier
        // facts fill what room is left. (The saved answer itself is never trimmed.)
        $labels = array_slice(array_values(array_unique($labels)), 0, self::MAX_DIFFERENTIATORS);
        $kept = array_slice($kept, 0, max(0, self::MAX_DIFFERENTIATORS - count($labels)));

        return array_values(array_unique(array_merge($kept, $labels)));
    }

    /**
     * `$ownerConfirms`: the screen asked the owner to confirm this as a fact about their business (their own
     * advantages, their own real reviews), so it is recorded as customer-confirmed — which is exactly what lets the
     * website state it. Anything not so flagged stays unverified and is never published as a claim.
     */
    private function applyKnowledgeProfileField(Business $business, ?string $field, mixed $value, int $actorUserId, bool $ownerConfirms = false): void
    {
        if ($field === null) {
            return;
        }

        $this->profiles->updateFields($business, [$field => $value], 'website_setup', $actorUserId, $ownerConfirms, $ownerConfirms ? [$field] : []);
    }

    /**
     * @param  array<int, array{key: string, name: string, description: ?string, starting_price: ?int, currency_code: ?string}>  $items
     * @return array<int, string> the submitted entries' own source keys (nulls excluded), for reconcileRemovedServices()
     */
    private function applyServices(Business $business, array $items, int &$nextPosition = 0, array $step = []): array
    {
        $submittedKeys = [];
        $submittedAdopted = [];

        foreach (array_values($items) as $index => $item) {
            $position = $nextPosition + $index;
            $sourceKey = $item['key'] ?? null;
            if ($sourceKey !== null) {
                $submittedKeys[] = $sourceKey;
            }

            $name = (string) ($item['name'] ?? '');
            $features = array_values(array_filter((array) ($item['features'] ?? []), fn ($f) => is_string($f) && trim($f) !== ''));
            $attributes = [
                'business_id' => $business->id,
                'name' => $name,
                'slug' => Str::slug($name) ?: 'service-' . ($sourceKey ?? $position),
                // Real features the owner confirmed ride in the description (the same convention a package uses),
                // so they render as a bullet list wherever the service is shown.
                'description' => $features !== []
                    ? \App\Library\Catalog\CatalogFeatureList::join((string) ($item['description'] ?? ''), $features)
                    : ($item['description'] ?? null),
                'starting_price' => $item['starting_price'] ?? null,
                'currency_code' => $item['currency_code'] ?? null,
                'status' => BusinessServiceStatus::Active->value,
                'sort_order' => $position,
                'source_questionnaire_item_key' => $sourceKey,
            ];

            // Only a card list that models them sets these; v1/v2 service rows keep the column defaults.
            if (isset($step['row_fields'])) {
                $attributes['kind'] = $item['kind'] ?? ($step['item_kind'] ?? BusinessService::KIND_SERVICE);
                $attributes['website_visible'] = (bool) ($item['visible'] ?? true);
            }

            // A card prefilled from an existing service carries `svc:<uid>`: that exact record is updated (and
            // keeps whatever source key it has — a manually created service stays manual).
            $adoptUid = is_string($sourceKey) && str_starts_with($sourceKey, 'svc:') ? substr($sourceKey, 4) : null;
            if ($adoptUid !== null) {
                unset($attributes['source_questionnaire_item_key']);
                $attributes['slug'] = BusinessService::where('business_id', $business->id)->where('uid', $adoptUid)->value('slug') ?? $attributes['slug'];
                $submittedAdopted[] = $adoptUid;
            }

            $existing = $adoptUid !== null
                ? BusinessService::where('business_id', $business->id)->where('uid', $adoptUid)->first()
                : ($sourceKey !== null
                    ? BusinessService::where('business_id', $business->id)->where('source_questionnaire_item_key', $sourceKey)->first()
                    : null);

            try {
                if ($existing !== null) {
                    $existing->fill($attributes)->save();
                    $service = $existing;
                } else {
                    $service = BusinessService::create($attributes);
                }

                $this->syncServicePhotos($business, $service, $item, $step);
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
                $this->syncServicePhotos($business, $winner, $item, $step);
            }
        }

        $nextPosition += count($items);

        // A guided card list is the COMPLETE list of what the website shows for this kind: an existing service
        // that was not kept as a card is hidden from the website (never deactivated or deleted for the rest of
        // MotionGrove), so removing a card really removes it from the site.
        if (isset($step['row_fields'])) {
            $kept = array_merge($submittedAdopted, BusinessService::where('business_id', $business->id)
                ->whereIn('source_questionnaire_item_key', $submittedKeys === [] ? [''] : $submittedKeys)->pluck('uid')->all());

            BusinessService::where('business_id', $business->id)
                ->where('kind', $step['item_kind'] ?? BusinessService::KIND_SERVICE)
                ->where('status', BusinessServiceStatus::Active->value)
                ->whereNotIn('uid', $kept === [] ? [''] : $kept)
                ->update(['website_visible' => false]);
        }

        return $submittedKeys;
    }

    /**
     * The photos a service card carries become the service's own canonical
     * images (attached by its id). Only a card list that offers photos touches
     * them; an older questionnaire's rows never do.
     */
    private function syncServicePhotos(Business $business, BusinessService $service, array $item, array $step): void
    {
        $fields = $step['row_fields'] ?? [];

        if (! in_array('cover', $fields, true) && ! in_array('extra_images', $fields, true)) {
            return;
        }

        $this->serviceImages->sync($business, $service, array_values((array) ($item['images'] ?? [])));
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
    private function applyPackages(Business $business, array $items, array $step = []): array
    {
        $submittedKeys = [];

        foreach ($items as $item) {
            $sourceKey = $item['key'] ?? null;
            if ($sourceKey !== null) {
                $submittedKeys[] = $sourceKey;
            }

            $attributes = [
                // A guided "extras" card list creates add-on products; every other list creates packages.
                'type' => $step['item_type'] ?? 'package',
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
    private function applyBackdrops(Business $business, array $items, array $step = []): array
    {
        $submittedKeys = [];
        $keptUids = [];

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

            // A card prefilled from an existing backdrop carries `bd:<uid>`: that record is updated, and a
            // backdrop created elsewhere stays "manual" (its own source key is left alone).
            $adoptUid = is_string($sourceKey) && str_starts_with($sourceKey, 'bd:') ? substr($sourceKey, 3) : null;
            if ($adoptUid !== null) {
                unset($attributes['source_questionnaire_item_key']);
            }

            $existing = $adoptUid !== null
                ? BusinessBackdrop::where('business_id', $business->id)->where('uid', $adoptUid)->first()
                : ($sourceKey !== null ? $this->backdrops->findBySourceKey($business, $sourceKey) : null);

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

            $keptUids[] = $backdrop->uid;
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

        // A guided list is the COMPLETE list of backdrops the website offers: one that is not kept as a card is
        // simply no longer offered (its photos are left alone), never deleted.
        if ($step['complete_list'] ?? false) {
            BusinessBackdrop::where('business_id', $business->id)
                ->where('availability', true)
                ->whereNotIn('uid', $keptUids === [] ? [''] : $keptUids)
                ->update(['availability' => false]);
        }

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

        // Forms V1 fails closed: a form with no Location accepts nothing. The only Location knowable here is the
        // Business's single Active one (never a guess among several); a multi-Location Business chooses it on the
        // Forms screen, and Website Health says so until it has.
        $locationId = \App\Models\Contacts::singleActiveLocationIdFor($business->id);

        if ($form !== null) {
            $form->update(['fields' => $fields] + ($form->location_id === null && $locationId !== null ? ['location_id' => $locationId] : []));

            return;
        }

        WebsiteForm::create([
            'website_id' => $website->id,
            'business_id' => $business->id,
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Photo Booth Quote Request',
            'fields' => $fields,
            'submit_label' => 'Request a quote',
            'location_id' => $locationId,
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
