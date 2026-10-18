<?php

namespace App\Library\CustomFields;

use App\Enums\CustomFields\CustomFieldType;
use App\Library\Merge\MergeFieldRegistry;
use App\Models\Business;
use App\Models\CustomFieldDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one boundary allowed to create, edit, archive and reorder a Business's
 * Custom Field definitions.
 *
 * KEYS. A key is derived from the label ONCE, at creation ("Event Date" ->
 * `event_date`, a collision -> `event_date_2`) and is never touched again: the
 * label is presentation, the key is identity. Keys may not shadow a built-in
 * Contact merge field (`first_name`, `email`, ...).
 *
 * TYPE is likewise fixed at creation. Options (select / multi_select) can be
 * edited; each carries a stable id so a label edit never corrupts stored values.
 */
class CustomFieldDefinitionManager
{
    public const MAX_FIELDS = 100;

    public const MAX_OPTIONS = 50;

    public const MAX_LABEL = 80;

    public const MAX_KEY = 40;

    /** @return Collection<int, CustomFieldDefinition> */
    public function forBusiness(Business $business, bool $includeArchived = false, string $entity = CustomFieldDefinition::ENTITY_CONTACT): Collection
    {
        return CustomFieldDefinition::query()
            ->where('business_id', (int) $business->id)
            ->where('entity', $entity)
            ->when(! $includeArchived, fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /** Fail closed: a uid of another Business is "not found", never a hint. */
    public function findByUid(Business $business, string $uid, string $entity = CustomFieldDefinition::ENTITY_CONTACT): ?CustomFieldDefinition
    {
        return CustomFieldDefinition::query()
            ->where('business_id', (int) $business->id)
            ->where('entity', $entity)
            ->where('uid', $uid)
            ->first();
    }

    public function findByKey(Business $business, string $key, string $entity = CustomFieldDefinition::ENTITY_CONTACT): ?CustomFieldDefinition
    {
        return CustomFieldDefinition::query()
            ->where('business_id', (int) $business->id)
            ->where('entity', $entity)
            ->where('key', $key)
            ->first();
    }

    /**
     * @param list<array{id?: string, label: string}|string>|null $options
     */
    public function create(Business $business, string $label, string $type, ?array $options = null): CustomFieldDefinition
    {
        $label = $this->cleanLabel($label);
        $fieldType = CustomFieldType::tryFrom($type) ?? throw new CustomFieldRuleException('Choose a field type.');
        $normalizedOptions = $fieldType->hasOptions() ? $this->cleanOptions($options ?? [], []) : null;

        if ($fieldType->hasOptions() && $normalizedOptions === []) {
            throw new CustomFieldRuleException('Add at least one option.');
        }

        $this->assertLabelFree($business, $label, null);

        if (CustomFieldDefinition::query()->where('business_id', (int) $business->id)->count() >= self::MAX_FIELDS) {
            throw new CustomFieldRuleException(sprintf('A business can have up to %d custom fields.', self::MAX_FIELDS));
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $key = $this->availableKey($business, $label);

            try {
                return DB::transaction(fn () => CustomFieldDefinition::query()->create([
                    'business_id' => (int) $business->id,
                    'entity' => CustomFieldDefinition::ENTITY_CONTACT,
                    'key' => $key,
                    'label' => $label,
                    'type' => $fieldType->value,
                    'options' => $normalizedOptions,
                    'position' => (int) CustomFieldDefinition::query()->where('business_id', (int) $business->id)->max('position') + 1,
                ]));
            } catch (QueryException $exception) {
                // A concurrent create took the same key: the unique index is the
                // authority, so recompute the next free suffix rather than overwrite.
                if (! str_contains($exception->getMessage(), 'cfd_business_entity_key_unique')) {
                    throw $exception;
                }
            }
        }

        throw new CustomFieldRuleException('That name is already in use. Try a different one.');
    }

    /**
     * Rename and/or edit options. The key and type are never touched.
     *
     * @param list<array{id?: string, label: string}|string>|null $options null = leave options as they are
     */
    public function update(Business $business, CustomFieldDefinition $definition, string $label, ?array $options = null): CustomFieldDefinition
    {
        $this->assertOwned($business, $definition);
        $label = $this->cleanLabel($label);
        $this->assertLabelFree($business, $label, $definition);

        $attributes = ['label' => $label];

        if ($definition->fieldType()->hasOptions() && $options !== null) {
            $clean = $this->cleanOptions($options, $definition->optionList());

            if ($clean === []) {
                throw new CustomFieldRuleException('Add at least one option.');
            }

            $attributes['options'] = $clean;
        }

        $definition->forceFill($attributes)->save();

        return $definition;
    }

    public function archive(Business $business, CustomFieldDefinition $definition): void
    {
        $this->assertOwned($business, $definition);

        if ($definition->archived_at === null) {
            $definition->forceFill(['archived_at' => now()])->save();
        }
    }

    public function restore(Business $business, CustomFieldDefinition $definition): void
    {
        $this->assertOwned($business, $definition);
        $definition->forceFill(['archived_at' => null])->save();
    }

    /**
     * @param list<string> $orderedUids every uid must belong to this Business
     */
    public function reorder(Business $business, array $orderedUids): void
    {
        $definitions = $this->forBusiness($business, true)->keyBy('uid');

        foreach ($orderedUids as $uid) {
            if (! $definitions->has($uid)) {
                throw new CustomFieldRuleException('That field could not be found.');
            }
        }

        DB::transaction(function () use ($orderedUids, $definitions): void {
            $position = 1;
            $named = array_values(array_unique($orderedUids));

            foreach ($named as $uid) {
                $definitions[$uid]->forceFill(['position' => $position++])->save();
            }

            // Fields not named keep their relative order after the named ones.
            foreach ($definitions as $uid => $definition) {
                if (! in_array($uid, $named, true)) {
                    $definition->forceFill(['position' => $position++])->save();
                }
            }
        });
    }

    /** Swap a field with its neighbour in the Business's order (archived ones included). */
    public function move(Business $business, CustomFieldDefinition $definition, string $direction): void
    {
        $this->assertOwned($business, $definition);

        $uids = $this->forBusiness($business, true)->pluck('uid')->all();
        $index = array_search($definition->uid, $uids, true);
        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || ! isset($uids[$target])) {
            return;
        }

        [$uids[$index], $uids[$target]] = [$uids[$target], $uids[$index]];
        $this->reorder($business, $uids);
    }

    private function assertOwned(Business $business, CustomFieldDefinition $definition): void
    {
        if ((int) $definition->business_id !== (int) $business->id) {
            throw new CustomFieldRuleException('That field could not be found.');
        }
    }

    private function cleanLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');

        if ($label === '') {
            throw new CustomFieldRuleException('Give the field a name.');
        }

        if (mb_strlen($label) > self::MAX_LABEL) {
            throw new CustomFieldRuleException(sprintf('Keep the name under %d characters.', self::MAX_LABEL));
        }

        return $label;
    }

    private function assertLabelFree(Business $business, string $label, ?CustomFieldDefinition $except): void
    {
        $taken = CustomFieldDefinition::query()
            ->where('business_id', (int) $business->id)
            ->where('entity', CustomFieldDefinition::ENTITY_CONTACT)
            ->whereNull('archived_at')
            ->when($except !== null, fn ($query) => $query->where('id', '!=', (int) $except->id))
            ->get(['label'])
            ->contains(fn ($row): bool => mb_strtolower((string) $row->label) === mb_strtolower($label));

        if ($taken) {
            throw new CustomFieldRuleException('You already have a field with that name.');
        }
    }

    private function availableKey(Business $business, string $label): string
    {
        $base = Str::of(Str::ascii($label))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        if ($base === '' || ! ctype_alpha($base[0])) {
            $base = rtrim('field_' . $base, '_');
        }

        $base = rtrim(substr($base, 0, self::MAX_KEY - 4), '_');

        $taken = CustomFieldDefinition::query()
            ->where('business_id', (int) $business->id)
            ->where('entity', CustomFieldDefinition::ENTITY_CONTACT)
            ->where('key', 'like', $base . '%')
            ->pluck('key')
            ->all();
        $unavailable = array_merge($taken, MergeFieldRegistry::reservedContactKeys());

        $candidate = $base;
        $suffix = 1;

        while (in_array($candidate, $unavailable, true)) {
            $suffix++;
            $candidate = $base . '_' . $suffix;
        }

        return $candidate;
    }

    /**
     * @param list<array{id?: string, label?: string}|string> $input
     * @param list<array{id: string, label: string}> $existing
     *
     * @return list<array{id: string, label: string}>
     */
    private function cleanOptions(array $input, array $existing): array
    {
        $knownIds = array_column($existing, 'id');
        $clean = [];
        $seenLabels = [];

        foreach ($input as $option) {
            $label = is_array($option) ? (string) ($option['label'] ?? '') : (string) $option;
            $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');

            if ($label === '') {
                continue;
            }

            if (mb_strlen($label) > self::MAX_LABEL) {
                throw new CustomFieldRuleException(sprintf('Keep each option under %d characters.', self::MAX_LABEL));
            }

            if (isset($seenLabels[mb_strtolower($label)])) {
                throw new CustomFieldRuleException('Each option needs a different name.');
            }

            $seenLabels[mb_strtolower($label)] = true;

            $id = is_array($option) ? (string) ($option['id'] ?? '') : '';

            // Only an id this field already owns is kept; anything else is a new
            // option and gets a fresh stable id.
            if (! in_array($id, $knownIds, true)) {
                $id = 'opt_' . Str::lower(Str::random(8));
            }

            $clean[] = ['id' => $id, 'label' => $label];
        }

        if (count($clean) > self::MAX_OPTIONS) {
            throw new CustomFieldRuleException(sprintf('Use at most %d options.', self::MAX_OPTIONS));
        }

        return $clean;
    }
}
