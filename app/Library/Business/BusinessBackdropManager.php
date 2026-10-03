<?php

namespace App\Library\Business;

use App\Library\Business\Exceptions\BusinessBackdropRuleException;
use App\Models\Business;
use App\Models\BusinessBackdrop;
use App\Models\BusinessBackdropImage;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Support\Facades\DB;

/**
 * Website Builder redesign — the ONE canonical service boundary for a
 * Business's Backdrops collection. Mirrors
 * `App\Library\Catalog\CatalogItemManager` exactly: create/update, and
 * every mutation that receives an existing `BusinessBackdrop` re-loads it
 * fresh, under lock, by primary key, then verifies its own persisted
 * `business_id` against the given `Business` — never trusting the
 * caller's copy of the model.
 */
final class BusinessBackdropManager
{
    public const NAME_MAX = 160;

    public const DESCRIPTION_MAX = 500;

    public function __construct(private readonly BusinessRepository $businessRepository)
    {
    }

    public function create(Business $business, array $attributes): BusinessBackdrop
    {
        $validated = $this->validate(
            $attributes['name'] ?? null,
            $attributes['description'] ?? null,
            $attributes['availability'] ?? true,
            $attributes['source_questionnaire_item_key'] ?? null,
            $attributes['category'] ?? null,
        );

        return DB::transaction(function () use ($business, $validated) {
            $lockedBusiness = $this->lockBusiness($business);

            $nextPosition = (int) (BusinessBackdrop::where('business_id', $lockedBusiness->id)->max('position') ?? -1) + 1;

            return BusinessBackdrop::create($validated + [
                'business_id' => $lockedBusiness->id,
                'position' => $nextPosition,
            ]);
        });
    }

    public function update(Business $business, BusinessBackdrop $backdrop, array $attributes): BusinessBackdrop
    {
        return DB::transaction(function () use ($business, $backdrop, $attributes) {
            $locked = $this->lockBackdropForBusiness($business, $backdrop);

            $validated = $this->validate(
                array_key_exists('name', $attributes) ? $attributes['name'] : $locked->name,
                array_key_exists('description', $attributes) ? $attributes['description'] : $locked->description,
                array_key_exists('availability', $attributes) ? $attributes['availability'] : $locked->availability,
                array_key_exists('source_questionnaire_item_key', $attributes) ? $attributes['source_questionnaire_item_key'] : $locked->source_questionnaire_item_key,
                array_key_exists('category', $attributes) ? $attributes['category'] : $locked->category,
            );

            $locked->fill($validated);
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * @param  array{disk: string, path: string, mime_type: string, size: int, width: ?int, height: ?int, alt_text: ?string}  $imageAttributes
     */
    public function addImage(Business $business, BusinessBackdrop $backdrop, array $imageAttributes): BusinessBackdropImage
    {
        return DB::transaction(function () use ($business, $backdrop, $imageAttributes) {
            $locked = $this->lockBackdropForBusiness($business, $backdrop);

            $nextPosition = (int) (BusinessBackdropImage::where('business_backdrop_id', $locked->id)->max('position') ?? -1) + 1;

            return BusinessBackdropImage::create($imageAttributes + [
                'business_backdrop_id' => $locked->id,
                'position' => $nextPosition,
            ]);
        });
    }

    /**
     * Applies the owner's chosen order to the backdrops a setup created:
     * the listed source keys take positions 0..n-1 in that order. Other
     * backdrops (created elsewhere) keep their relative order after them.
     *
     * @param  array<int, string>  $sourceKeysInOrder
     */
    public function reorderBySourceKeys(Business $business, array $sourceKeysInOrder): void
    {
        if ($sourceKeysInOrder === []) {
            return;
        }

        DB::transaction(function () use ($business, $sourceKeysInOrder) {
            $this->lockBusiness($business);

            $position = 0;
            foreach ($sourceKeysInOrder as $key) {
                BusinessBackdrop::where('business_id', $business->id)
                    ->where('source_questionnaire_item_key', $key)
                    ->update(['position' => $position++]);
            }

            BusinessBackdrop::where('business_id', $business->id)
                ->whereNotIn('source_questionnaire_item_key', $sourceKeysInOrder)
                ->orWhere(fn ($q) => $q->where('business_id', $business->id)->whereNull('source_questionnaire_item_key'))
                ->orderBy('position')
                ->get()
                ->each(function (BusinessBackdrop $other) use (&$position) {
                    $other->forceFill(['position' => $position++])->save();
                });
        });
    }

    /**
     * Makes $imageAttributes the backdrop's one picture: any other image
     * rows are removed and the paths they pointed at are returned so the
     * caller can clean up files nothing references any more (the backdrop
     * UI holds exactly one image per backdrop).
     *
     * @param  array{disk: string, path: string, mime_type: string, size: int, width: ?int, height: ?int, alt_text: ?string}  $imageAttributes
     * @return array<int, string> paths of the superseded images' files
     */
    public function replaceImage(Business $business, BusinessBackdrop $backdrop, array $imageAttributes): array
    {
        return DB::transaction(function () use ($business, $backdrop, $imageAttributes) {
            $locked = $this->lockBackdropForBusiness($business, $backdrop);

            $existing = BusinessBackdropImage::where('business_backdrop_id', $locked->id)->get();
            $current = $existing->firstWhere('path', $imageAttributes['path']);

            if ($current !== null) {
                // Same file: only the alt text may have changed.
                $current->forceFill(['alt_text' => $imageAttributes['alt_text'] ?? null])->save();

                return [];
            }

            $superseded = $existing->pluck('path')->all();
            BusinessBackdropImage::where('business_backdrop_id', $locked->id)->delete();
            BusinessBackdropImage::create($imageAttributes + ['business_backdrop_id' => $locked->id, 'position' => 0]);

            return $superseded;
        });
    }

    /**
     * Removes every picture of the backdrop; returns the file paths freed.
     *
     * @return array<int, string>
     */
    public function clearImages(Business $business, BusinessBackdrop $backdrop): array
    {
        return DB::transaction(function () use ($business, $backdrop) {
            $locked = $this->lockBackdropForBusiness($business, $backdrop);
            $paths = BusinessBackdropImage::where('business_backdrop_id', $locked->id)->pluck('path')->all();
            BusinessBackdropImage::where('business_backdrop_id', $locked->id)->delete();

            return $paths;
        });
    }

    /**
     * Website Builder redesign — "Edit setup answers" resolves a backdrop
     * it already created back to its canonical row by this idempotency
     * key, so re-running the wizard updates the existing backdrop instead
     * of creating a duplicate.
     */
    public function findBySourceKey(Business $business, string $sourceQuestionnaireItemKey): ?BusinessBackdrop
    {
        return BusinessBackdrop::where('business_id', $business->id)
            ->where('source_questionnaire_item_key', $sourceQuestionnaireItemKey)
            ->first();
    }

    private function lockBackdropForBusiness(Business $business, BusinessBackdrop $backdrop): BusinessBackdrop
    {
        $locked = BusinessBackdrop::query()->whereKey($backdrop->id)->lockForUpdate()->first();

        if ($locked === null || (int) $locked->business_id !== (int) $business->id) {
            throw new BusinessBackdropRuleException('That backdrop does not belong to this Business.');
        }

        return $locked;
    }

    private function lockBusiness(Business $business): Business
    {
        $locked = $this->businessRepository->findForUpdate($business->id);

        if ($locked === null) {
            throw new BusinessBackdropRuleException('That Business no longer exists.');
        }

        return $locked;
    }

    /**
     * @return array{name: string, description: ?string, category: ?string, availability: bool, source_questionnaire_item_key: ?string}
     */
    private function validate(mixed $name, mixed $description, mixed $availability, mixed $sourceQuestionnaireItemKey, mixed $category = null): array
    {
        $name = trim((string) $name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new BusinessBackdropRuleException('Use a name of 1 to ' . self::NAME_MAX . ' characters.');
        }

        $description = $description !== null ? trim((string) $description) : null;

        if ($description === '') {
            $description = null;
        }

        if ($description !== null && mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new BusinessBackdropRuleException('Use a description of at most ' . self::DESCRIPTION_MAX . ' characters.');
        }

        $category = $category !== null ? trim((string) $category) : null;
        if ($category === '') {
            $category = null;
        }
        if ($category !== null && preg_match('/^[a-z0-9_]{1,40}$/', $category) !== 1) {
            throw new BusinessBackdropRuleException('Use a valid backdrop category.');
        }

        return [
            'name' => $name,
            'description' => $description,
            'category' => $category,
            'availability' => (bool) $availability,
            'source_questionnaire_item_key' => $sourceQuestionnaireItemKey !== null && $sourceQuestionnaireItemKey !== ''
                ? (string) $sourceQuestionnaireItemKey
                : null,
        ];
    }
}
