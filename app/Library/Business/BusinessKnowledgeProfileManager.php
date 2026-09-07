<?php

namespace App\Library\Business;

use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Enums\Business\BusinessPricingMethod;
use App\Enums\Business\BusinessPrimaryConversionGoal;
use App\Library\Website\WebsiteUrlRules;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessKnowledgeProfileChange;
use App\Models\BusinessKnowledgeProfileFieldState;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\Website;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Website Guided Generation contract §4.3 -- the SOLE authorized write
 * seam for business_knowledge_profiles, business_knowledge_profile_
 * field_states, and business_knowledge_profile_changes. No controller,
 * job, or other service is authorized to Model::create()/update() these
 * tables directly (mirrors WebsiteDraftPageService's seam discipline).
 *
 * Slice 1 scope only: no AI call, no vertical/question-pack tables (not
 * yet created by Slice 2), no template/generation concerns.
 */
final class BusinessKnowledgeProfileManager
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    private const VALID_SOURCES = ['onboarding', 'website_setup', 'manual_edit', 'imported'];

    /**
     * business_knowledge_profiles' unique index on business_id
     * (migration 1), by its exact, mechanically-verified Laravel-
     * convention name.
     */
    private const BUSINESS_ID_UNIQUE_CONSTRAINT = 'business_knowledge_profiles_business_id_unique';

    /**
     * Idempotent and race-safe against the unique business_id constraint
     * (migration 1): two concurrent first-touch calls for the same
     * Business never produce two Profile rows.
     */
    public function getOrCreate(Business $business): BusinessKnowledgeProfile
    {
        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();

        return $profile ?? $this->createProfileRaceSafe($business);
    }

    /**
     * Catches ONLY the narrow, framework-classified
     * UniqueConstraintViolationException (Laravel dispatches this exact
     * subtype for a MySQL 1062 "Duplicate entry" error,
     * MySqlConnection::isUniqueConstraintError() -- never for an
     * unrelated failure such as a foreign-key violation, which raises a
     * plain QueryException instead) -- and only when the violated
     * constraint is genuinely business_id's own unique index, confirmed
     * by name. Any other exception, or a UniqueConstraintViolationException
     * naming a different constraint, propagates unchanged: it is never
     * treated as "someone else already created it."
     */
    private function createProfileRaceSafe(Business $business): BusinessKnowledgeProfile
    {
        try {
            return BusinessKnowledgeProfile::create([
                'business_id' => $business->id,
                'reviews_source' => 'none',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), self::BUSINESS_ID_UNIQUE_CONSTRAINT)) {
                throw $e;
            }

            $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();

            if ($profile !== null) {
                return $profile;
            }

            throw $e;
        }
    }

    /**
     * @throws ValidationException
     */
    public function updateFields(Business $business, array $fields, string $source, int $actorUserId, bool $markVerified = false): BusinessKnowledgeProfile
    {
        $this->assertKnownSource($source);
        $this->assertKnownFieldKeys($fields);

        $normalized = $this->validateFields($business, $fields);

        return DB::transaction(function () use ($business, $normalized, $source, $actorUserId, $markVerified) {
            $profile = $this->getOrCreate($business);

            $changed = [];

            foreach ($normalized as $key => $value) {
                if ($profile->{$key} !== $value) {
                    $changed[$key] = ['old' => $profile->{$key}, 'new' => $value];
                    $profile->{$key} = $value;
                }
            }

            if ($changed === []) {
                return $profile;
            }

            $finalTestimonials = array_key_exists('testimonials', $normalized)
                ? $normalized['testimonials']
                : ($profile->testimonials ?? []);
            $profile->reviews_source = empty($finalTestimonials) ? 'none' : 'manual_verified';

            $profile->save();

            $now = now();

            foreach ($changed as $key => $delta) {
                BusinessKnowledgeProfileFieldState::updateOrCreate(
                    ['business_id' => $business->id, 'field_key' => $key],
                    [
                        'source' => $source,
                        'verification_status' => $markVerified
                            ? BusinessKnowledgeProfileFieldState::STATUS_CUSTOMER_CONFIRMED
                            : BusinessKnowledgeProfileFieldState::STATUS_UNVERIFIED,
                        'verified_by_user_id' => $markVerified ? $actorUserId : null,
                        'verified_at' => $markVerified ? $now : null,
                    ],
                );

                BusinessKnowledgeProfileChange::create([
                    'business_id' => $business->id,
                    'field_key' => $key,
                    'old_value' => $delta['old'],
                    'new_value' => $delta['new'],
                    'source' => $source,
                    'actor_user_id' => $actorUserId,
                ]);
            }

            return $profile->fresh();
        });
    }

    /**
     * @param  array<string, array<string, array{open: string, close: string}>|string|null>  $hoursByDay
     *
     * @throws ValidationException
     */
    public function updateLocationHours(Business $business, BusinessLocation $location, array $hoursByDay, string $source, int $actorUserId, bool $markVerified = false): BusinessLocation
    {
        $this->assertKnownSource($source);

        if ((int) $location->business_id !== (int) $business->id) {
            throw ValidationException::withMessages([
                'location' => ['This location does not belong to the given Business.'],
            ]);
        }

        $normalizedHours = $this->normalizeHours($hoursByDay);

        return DB::transaction(function () use ($business, $location, $normalizedHours, $source, $actorUserId, $markVerified) {
            $locked = BusinessLocation::where('id', $location->id)->lockForUpdate()->firstOrFail();

            $now = now();
            $newStatus = $markVerified
                ? BusinessKnowledgeProfileFieldState::STATUS_CUSTOMER_CONFIRMED
                : BusinessKnowledgeProfileFieldState::STATUS_UNVERIFIED;
            $newVerifiedBy = $markVerified ? $actorUserId : null;
            $newVerifiedAt = $markVerified ? $now : null;

            $oldHours = $locked->hours;
            $hoursChanged = $oldHours !== $normalizedHours;

            $provenanceChanged = $hoursChanged
                || $locked->hours_source !== $source
                || $locked->hours_verification_status !== $newStatus
                || (int) $locked->hours_verified_by_user_id !== (int) $newVerifiedBy;

            if (! $provenanceChanged) {
                return $locked;
            }

            $locked->hours = $normalizedHours;
            $locked->hours_source = $source;
            $locked->hours_verification_status = $newStatus;
            $locked->hours_verified_by_user_id = $newVerifiedBy;
            $locked->hours_verified_at = $newVerifiedAt;
            $locked->save();

            if ($hoursChanged) {
                BusinessKnowledgeProfileChange::create([
                    'business_id' => $business->id,
                    'field_key' => BusinessKnowledgeProfileFieldKey::Hours->value,
                    'old_value' => ['business_location_id' => $location->id, 'hours' => $oldHours],
                    'new_value' => ['business_location_id' => $location->id, 'hours' => $normalizedHours],
                    'source' => $source,
                    'actor_user_id' => $actorUserId,
                ]);
            }

            return $locked->fresh();
        });
    }

    /**
     * Genuinely read-only -- a plain query, never getOrCreate(). If the
     * Business has no Profile row at all, every Profile-owned fact is
     * simply "missing" (an absent row cannot itself carry any confirmed
     * fact); this method creates, updates, and deletes no row, and
     * changes no timestamp, in either case. Repeated calls are entirely
     * write-free. v1 (§4.3): hours are evaluated against
     * Business::primaryLocation() only, whether or not $website is
     * supplied -- Website is the only consumer of "which location(s)
     * this Business features" in this contract's slices, and the
     * platform already enforces a single Website per Business.
     */
    public function completenessCheck(Business $business, ?Website $website = null): BusinessKnowledgeProfileCompleteness
    {
        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();

        $fieldStates = BusinessKnowledgeProfileFieldState::where('business_id', $business->id)
            ->get()
            ->keyBy('field_key');

        $now = now();
        $missing = [];
        $stale = [];
        $present = [];

        foreach (BusinessKnowledgeProfileFieldKey::cases() as $case) {
            if ($case === BusinessKnowledgeProfileFieldKey::Hours) {
                continue;
            }

            if ($profile === null) {
                $missing[] = $case->value;

                continue;
            }

            $value = $profile->{$case->value};

            if ($this->isEmptyValue($value)) {
                $missing[] = $case->value;

                continue;
            }

            if ($this->isFieldStateFresh($fieldStates->get($case->value), $case->reconfirmAfterDays(), $now)) {
                $present[] = $case->value;
            } else {
                $stale[] = $case->value;
            }
        }

        // A fresh query, never the cached primaryLocation relation --
        // a caller may have already resolved (and cached) $business->
        // primaryLocation on this same $business instance before an
        // updateLocationHours() call, which must not make this read see
        // stale, pre-update hours data.
        $location = $business->primaryLocation()->first();

        if ($location === null || $this->isEmptyValue($location->hours)) {
            $missing[] = BusinessKnowledgeProfileFieldKey::Hours->value;
        } elseif ($this->isLocationHoursFresh($location, $now)) {
            $present[] = BusinessKnowledgeProfileFieldKey::Hours->value;
        } else {
            $stale[] = BusinessKnowledgeProfileFieldKey::Hours->value;
        }

        return new BusinessKnowledgeProfileCompleteness($missing, $stale, $present);
    }

    // -----------------------------------------------------------------
    // Field validation
    // -----------------------------------------------------------------

    private function assertKnownSource(string $source): void
    {
        if (! in_array($source, self::VALID_SOURCES, true)) {
            throw ValidationException::withMessages([
                'source' => ["Unknown provenance source: {$source}."],
            ]);
        }
    }

    private function assertKnownFieldKeys(array $fields): void
    {
        $allowed = array_column(BusinessKnowledgeProfileFieldKey::cases(), 'value');
        $unknown = array_diff(array_keys($fields), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'fields' => ['Unknown Business Knowledge Profile field key(s): ' . implode(', ', $unknown) . '.'],
            ]);
        }

        if (array_key_exists(BusinessKnowledgeProfileFieldKey::Hours->value, $fields)) {
            throw ValidationException::withMessages([
                'hours' => ['Hours must be written through updateLocationHours(), not updateFields().'],
            ]);
        }
    }

    /**
     * Validates and normalizes every submitted field BEFORE any write --
     * a single invalid field rejects the entire batch, with every
     * invalid field's message aggregated into one ValidationException.
     */
    private function validateFields(Business $business, array $fields): array
    {
        $normalized = [];
        $errors = [];

        foreach ($fields as $key => $value) {
            try {
                $normalized[$key] = $this->normalizeField($business, $key, $value);
            } catch (InvalidArgumentException $e) {
                $errors[$key] = [$e->getMessage()];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function normalizeField(Business $business, string $key, mixed $value): mixed
    {
        return match ($key) {
            'vertical_key' => $this->normalizeVerticalKey($value),
            'pricing_method' => $this->normalizePricingMethod($value),
            'financing_available' => $this->normalizeNullableBool($value, 'financing_available'),
            'offers' => $this->normalizeOffers($value),
            'differentiators' => $this->normalizeStringList($value, 6, 120, 'differentiators'),
            'ideal_customers' => $this->normalizeNullableString($value, 500, 'ideal_customers'),
            'customer_problems' => $this->normalizeStringList($value, 6, 160, 'customer_problems'),
            'credentials' => $this->normalizeCredentials($value),
            'years_operating' => $this->normalizeYearsOperating($value),
            'warranties_guarantees' => $this->normalizeNullableString($value, 500, 'warranties_guarantees'),
            'primary_conversion_goal' => $this->normalizePrimaryConversionGoal($value),
            'conversion_target' => $this->normalizeConversionTarget($value),
            'brand_voice' => $this->normalizeNullableString($value, 500, 'brand_voice'),
            'prohibited_claims' => $this->normalizeStringList($value, 15, 160, 'prohibited_claims'),
            'growth_priority_service_ids' => $this->normalizeOwnedIds($business, $value, BusinessService::class, 'growth_priority_service_ids'),
            'growth_priority_location_ids' => $this->normalizeOwnedIds($business, $value, BusinessLocation::class, 'growth_priority_location_ids'),
            'testimonials' => $this->normalizeTestimonials($value),
            default => throw new InvalidArgumentException("Unhandled field key: {$key}."),
        };
    }

    /**
     * Slice 1 behavior (documented, tested): business_verticals (Slice 2)
     * does not exist yet, so a non-null vertical_key can never be
     * validated against an active catalog entry -- it fails safely here
     * rather than writing an unvalidated value or querying a table that
     * does not exist. A null value (clearing/never-set) is always valid.
     */
    private function normalizeVerticalKey(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        throw new InvalidArgumentException('vertical_key cannot be set until the vertical catalog (Slice 2) exists.');
    }

    private function normalizePricingMethod(mixed $value): ?BusinessPricingMethod
    {
        if ($value === null) {
            return null;
        }

        $enum = is_string($value) ? BusinessPricingMethod::tryFrom($value) : null;

        if ($enum === null) {
            throw new InvalidArgumentException('Invalid pricing_method value.');
        }

        return $enum;
    }

    private function normalizePrimaryConversionGoal(mixed $value): ?BusinessPrimaryConversionGoal
    {
        if ($value === null) {
            return null;
        }

        $enum = is_string($value) ? BusinessPrimaryConversionGoal::tryFrom($value) : null;

        if ($enum === null) {
            throw new InvalidArgumentException('Invalid primary_conversion_goal value.');
        }

        return $enum;
    }

    private function normalizeNullableBool(mixed $value, string $field): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (! is_bool($value)) {
            throw new InvalidArgumentException("{$field} must be a boolean or null.");
        }

        return $value;
    }

    private function normalizeNullableString(mixed $value, int $max, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$field} must be a string of at most {$max} characters.");
        }

        return $value;
    }

    private function normalizeStringList(mixed $value, int $maxItems, int $maxLength, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || count($value) > $maxItems) {
            throw new InvalidArgumentException("{$field} must be an array of at most {$maxItems} strings.");
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_string($item) || $item === '' || mb_strlen($item) > $maxLength) {
                throw new InvalidArgumentException("Each {$field} entry must be a non-empty string of at most {$maxLength} characters.");
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private function normalizeOffers(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || count($value) > 12) {
            throw new InvalidArgumentException('offers must be an array of at most 12 entries.');
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Each offers entry must be an object.');
            }

            $name = $item['name'] ?? null;

            if (! is_string($name) || $name === '' || mb_strlen($name) > 80) {
                throw new InvalidArgumentException('Each offer requires a name of at most 80 characters.');
            }

            $description = $item['description'] ?? null;

            if ($description !== null && (! is_string($description) || mb_strlen($description) > 300)) {
                throw new InvalidArgumentException('offers.description must be at most 300 characters.');
            }

            $priceLabel = $item['price_label'] ?? null;

            if ($priceLabel !== null && (! is_string($priceLabel) || mb_strlen($priceLabel) > 40)) {
                throw new InvalidArgumentException('offers.price_label must be at most 40 characters.');
            }

            $override = $item['pricing_method_override'] ?? null;

            if ($override !== null) {
                $overrideEnum = is_string($override) ? BusinessPricingMethod::tryFrom($override) : null;

                if ($overrideEnum === null) {
                    throw new InvalidArgumentException('offers.pricing_method_override is invalid.');
                }

                // Stored as the enum's plain string value, not the enum
                // instance itself: `offers` is a JSON blob column (no
                // per-element Eloquent cast), so a decoded round-trip
                // from the database always yields a plain string here.
                // Normalizing the freshly-validated value to the same
                // plain-string shape keeps old-vs-new comparisons for
                // no-op/change detection (updateFields()) type-consistent
                // -- an enum instance would otherwise never equal the
                // string produced by a prior read, causing every write to
                // look like a spurious change.
                $override = $overrideEnum->value;
            }

            $normalized[] = [
                'name' => $name,
                'description' => $description,
                'price_label' => $priceLabel,
                'pricing_method_override' => $override,
            ];
        }

        return $normalized;
    }

    private function normalizeCredentials(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || count($value) > 10) {
            throw new InvalidArgumentException('credentials must be an array of at most 10 entries.');
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Each credentials entry must be an object.');
            }

            $label = $item['label'] ?? null;

            if (! is_string($label) || $label === '' || mb_strlen($label) > 120) {
                throw new InvalidArgumentException('Each credential requires a label of at most 120 characters.');
            }

            $verified = $item['verified'] ?? null;

            if (! is_bool($verified)) {
                throw new InvalidArgumentException('Each credential requires a boolean verified flag.');
            }

            $normalized[] = ['label' => $label, 'verified' => $verified];
        }

        return $normalized;
    }

    private function normalizeYearsOperating(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 0 || $value > 65535) {
            throw new InvalidArgumentException('years_operating must be a non-negative integer.');
        }

        return $value;
    }

    private function normalizeConversionTarget(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || mb_strlen($value) > 255 || ! WebsiteUrlRules::isValid($value)) {
            throw new InvalidArgumentException('conversion_target must be a valid tel:, mailto:, or https:// value.');
        }

        return $value;
    }

    /**
     * @param  class-string<BusinessService|BusinessLocation>  $modelClass
     */
    private function normalizeOwnedIds(Business $business, mixed $value, string $modelClass, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("{$field} must be an array of IDs.");
        }

        $ids = [];

        foreach ($value as $id) {
            if (! is_int($id)) {
                throw new InvalidArgumentException("{$field} entries must be integer IDs.");
            }

            $ids[] = $id;
        }

        if ($ids === []) {
            return [];
        }

        $ownedCount = $modelClass::where('business_id', $business->id)->whereIn('id', $ids)->count();

        if ($ownedCount !== count(array_unique($ids))) {
            throw new InvalidArgumentException("{$field} references an ID that does not belong to this Business.");
        }

        return $ids;
    }

    private function normalizeTestimonials(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || count($value) > 5) {
            throw new InvalidArgumentException('testimonials must be an array of at most 5 entries.');
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Each testimonials entry must be an object.');
            }

            $quote = $item['quote'] ?? null;

            if (! is_string($quote) || $quote === '' || mb_strlen($quote) > 400) {
                throw new InvalidArgumentException('Each testimonial requires a quote of at most 400 characters.');
            }

            $authorName = $item['author_name'] ?? null;

            if (! is_string($authorName) || $authorName === '' || mb_strlen($authorName) > 80) {
                throw new InvalidArgumentException('Each testimonial requires an author_name of at most 80 characters.');
            }

            $authorTitle = $item['author_title'] ?? null;

            if ($authorTitle !== null && (! is_string($authorTitle) || mb_strlen($authorTitle) > 80)) {
                throw new InvalidArgumentException('testimonials.author_title must be at most 80 characters.');
            }

            $normalized[] = ['quote' => $quote, 'author_name' => $authorName, 'author_title' => $authorTitle];
        }

        return $normalized;
    }

    // -----------------------------------------------------------------
    // Hours validation (§5.5)
    // -----------------------------------------------------------------

    private function normalizeHours(array $hoursByDay): array
    {
        $unknownKeys = array_diff(array_keys($hoursByDay), [...self::DAYS, 'notes']);

        if ($unknownKeys !== []) {
            throw ValidationException::withMessages([
                'hours' => ['Unknown hours key(s): ' . implode(', ', $unknownKeys) . '.'],
            ]);
        }

        $normalized = [];

        try {
            foreach (self::DAYS as $day) {
                if (! array_key_exists($day, $hoursByDay)) {
                    throw new InvalidArgumentException("Missing hours for {$day}.");
                }

                $normalized[$day] = $this->normalizeDayPeriods($day, $hoursByDay[$day]);
            }

            $notes = $hoursByDay['notes'] ?? null;

            if ($notes !== null && (! is_string($notes) || mb_strlen($notes) > 200)) {
                throw new InvalidArgumentException('hours.notes must be a string of at most 200 characters.');
            }

            $normalized['notes'] = $notes;
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['hours' => [$e->getMessage()]]);
        }

        return $normalized;
    }

    private function normalizeDayPeriods(string $day, mixed $periods): array
    {
        if (! is_array($periods)) {
            throw new InvalidArgumentException("Hours for {$day} must be an array of periods.");
        }

        if (count($periods) > 4) {
            throw new InvalidArgumentException("Hours for {$day} may have at most 4 periods.");
        }

        $parsed = [];

        foreach ($periods as $period) {
            if (! is_array($period) || ! array_key_exists('open', $period) || ! array_key_exists('close', $period)) {
                throw new InvalidArgumentException("Each period for {$day} requires open and close times.");
            }

            $openMinutes = $this->parseTime($day, $period['open'], isCloseTime: false);
            $closeMinutes = $this->parseTime($day, $period['close'], isCloseTime: true);

            if ($closeMinutes <= $openMinutes) {
                throw new InvalidArgumentException("A period's close time for {$day} must be strictly after its open time (no overnight wraparound within one day).");
            }

            $parsed[] = ['open' => $period['open'], 'close' => $period['close'], '_open' => $openMinutes, '_close' => $closeMinutes];
        }

        usort($parsed, fn ($a, $b) => $a['_open'] <=> $b['_open']);

        for ($i = 0; $i < count($parsed) - 1; $i++) {
            if ($parsed[$i]['_close'] > $parsed[$i + 1]['_open']) {
                throw new InvalidArgumentException("Overlapping periods for {$day}.");
            }
        }

        return array_map(fn ($p) => ['open' => $p['open'], 'close' => $p['close']], $parsed);
    }

    private function parseTime(string $day, mixed $value, bool $isCloseTime): int
    {
        if (! is_string($value) || ! preg_match('/^(\d{2}):(\d{2})$/', $value, $m)) {
            throw new InvalidArgumentException("Malformed time value for {$day}.");
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour === 24 && $minute === 0) {
            if (! $isCloseTime) {
                throw new InvalidArgumentException("\"24:00\" is only valid as a close time ({$day}).");
            }

            return 24 * 60;
        }

        if ($hour > 23 || $minute > 59) {
            throw new InvalidArgumentException("Malformed time value for {$day}.");
        }

        return $hour * 60 + $minute;
    }

    // -----------------------------------------------------------------
    // Completeness helpers
    // -----------------------------------------------------------------

    /**
     * "Missing" means never answered at all. A `null` value (never set)
     * or an empty string (free text with no real content) is missing;
     * an explicitly submitted empty array is a genuine, complete answer
     * (e.g. "no growth-priority services to highlight," "no testimonials
     * yet collected") and is never treated as missing -- mirrors the
     * same null-vs-empty-array distinction §5.5 establishes for hours.
     */
    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function isFieldStateFresh(?BusinessKnowledgeProfileFieldState $state, int $reconfirmAfterDays, Carbon $now): bool
    {
        if ($state === null || $state->verification_status !== BusinessKnowledgeProfileFieldState::STATUS_CUSTOMER_CONFIRMED || $state->verified_at === null) {
            return false;
        }

        return $state->verified_at->diffInDays($now) < $reconfirmAfterDays;
    }

    private function isLocationHoursFresh(BusinessLocation $location, Carbon $now): bool
    {
        if ($location->hours_verification_status !== BusinessKnowledgeProfileFieldState::STATUS_CUSTOMER_CONFIRMED || $location->hours_verified_at === null) {
            return false;
        }

        return $location->hours_verified_at->diffInDays($now) < BusinessKnowledgeProfileFieldKey::Hours->reconfirmAfterDays();
    }
}
