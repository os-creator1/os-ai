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
     * @return array{name: string, description: ?string, availability: bool, source_questionnaire_item_key: ?string}
     */
    private function validate(mixed $name, mixed $description, mixed $availability, mixed $sourceQuestionnaireItemKey): array
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

        return [
            'name' => $name,
            'description' => $description,
            'availability' => (bool) $availability,
            'source_questionnaire_item_key' => $sourceQuestionnaireItemKey !== null && $sourceQuestionnaireItemKey !== ''
                ? (string) $sourceQuestionnaireItemKey
                : null,
        ];
    }
}
