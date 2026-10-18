<?php

namespace App\Library\PlatformOwner;

use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\PlatformBilling\PlatformPriceVerifier;
use App\Models\WorkspacePlanAssignment;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use App\Repositories\Contracts\WorkspacePlanFeatureRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform Owner V1 final — the ONE writer of Workspace plan catalog edits.
 *
 * The three customer SaaS tiers (Core, Growth, Agency) are a closed enum and
 * every history table (assignments, subscriptions, transitions, pricing
 * changes, slot agreements) restricts deletion of a catalog row. So there is
 * deliberately no create and no delete: a plan is retired by ARCHIVING it
 * (is_active=false, available_for_signup=false), which stops new sales and
 * new assignments while every existing subscription and its history stay
 * intact.
 *
 * Reuses, never re-implements:
 *  - price / currency / slot price ratio -> EntitlementManager::updateCatalogPricing()
 *    (the only price-history authority);
 *  - Stripe Price parity -> PlatformPriceVerifier;
 *  - packaged features -> WorkspacePlanFeatureRepository (packaging only; code
 *    availability in PlatformFeatureRegistry is still checked first by the
 *    entitlement resolver).
 */
class PlatformPlanAdministrator
{
    public function __construct(
        private readonly WorkspacePlanCatalogRepository $catalogs,
        private readonly WorkspacePlanFeatureRepository $features,
        private readonly EntitlementManager $entitlements,
        private readonly PlatformPriceVerifier $prices,
        private readonly PlatformOwnerAuthority $authority,
        private readonly PlatformAdminAuditLog $audit,
    ) {
    }

    /** Number of non-ended assignments on this catalog row — shown beside archive/packaging warnings. */
    public function assignmentCount(WorkspacePlanCatalog $catalog): int
    {
        return WorkspacePlanAssignment::query()->where('workspace_plan_catalog_id', $catalog->id)->count();
    }

    /**
     * Apply every key present in $data; absent keys are left unchanged, so the
     * legacy Billing & Revenue form (commercial fields only) and the full plan
     * editor share this one path.
     *
     * Recognised keys: display_name, is_active, available_for_signup,
     * price, currency_id, billing_cycle, trial_enabled, trial_days,
     * provider_price_id, additional_business_slot_price_ratio,
     * business_slot_included, business_slot_max, unlimited_business_slots,
     * location_slot_included, location_slot_max, unlimited_location_slots,
     * feature_keys (list<string>), reason (required).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array{from: mixed, to: mixed}> the fields that actually changed
     *
     * @throws ValidationException
     */
    public function apply(WorkspacePlanCatalog $catalog, array $data, int $actorUserId): array
    {
        $this->authority->assertAdministrator($actorUserId);

        $reason = trim((string) ($data['reason'] ?? ''));

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => __('Say why you are changing this plan (at least 3 characters).')]);
        }

        $this->assertInternallyConsistent($catalog, $data);
        $this->verifyStripePrice($catalog, $data);

        return DB::transaction(function () use ($catalog, $data, $actorUserId, $reason) {
            $locked = $this->catalogs->findForUpdate($catalog->id) ?? $catalog;
            $before = $locked->only($this->trackedColumns());
            $beforeFeatures = $this->features->featureKeysForCatalog($locked)->sort()->values()->all();

            // 1. Structure + commercial switches (no price history of their own).
            $columns = [];

            foreach (['display_name', 'is_active', 'available_for_signup', 'billing_cycle', 'trial_enabled', 'provider_price_id',
                'business_slot_included', 'business_slot_max', 'unlimited_business_slots',
                'location_slot_included', 'location_slot_max', 'unlimited_location_slots'] as $key) {
                if (array_key_exists($key, $data)) {
                    $columns[$key] = $data[$key];
                }
            }

            if (array_key_exists('trial_enabled', $data) || array_key_exists('trial_days', $data)) {
                $enabled = (bool) ($data['trial_enabled'] ?? $locked->trial_enabled);
                $columns['trial_enabled'] = $enabled;
                // A disabled trial carries no duration (Contract 21 §11).
                $columns['trial_days'] = $enabled ? (int) ($data['trial_days'] ?? $locked->trial_days) : null;
            }

            foreach (['unlimited_business_slots' => 'business_slot_max', 'unlimited_location_slots' => 'location_slot_max'] as $flag => $max) {
                if (! empty($columns[$flag])) {
                    $columns[$max] = null;
                }
            }

            // Archived plans can never be offered for signup.
            if (array_key_exists('is_active', $columns) && ! $columns['is_active']) {
                $columns['available_for_signup'] = false;
            }

            if (isset($columns['available_for_signup']) && $columns['available_for_signup']
                && ! ($columns['is_active'] ?? $locked->is_active)) {
                throw ValidationException::withMessages(['available_for_signup' => __('An archived plan cannot be offered for signup. Reactivate it first.')]);
            }

            if ($columns !== []) {
                $locked = $this->catalogs->updateStructure($locked, $columns);
            }

            // 2. Price / currency / slot price ratio — the audited authority.
            if (array_key_exists('price', $data) || array_key_exists('currency_id', $data)
                || array_key_exists('additional_business_slot_price_ratio', $data)) {
                $locked = $this->entitlements->updateCatalogPricing(
                    $locked,
                    array_key_exists('price', $data) ? ($data['price'] === null || $data['price'] === '' ? null : (string) $data['price']) : ($locked->price === null ? null : (string) $locked->price),
                    array_key_exists('currency_id', $data) ? ($data['currency_id'] === null || $data['currency_id'] === '' ? null : (int) $data['currency_id']) : $locked->currency_id,
                    array_key_exists('additional_business_slot_price_ratio', $data)
                        ? ($data['additional_business_slot_price_ratio'] === null || $data['additional_business_slot_price_ratio'] === '' ? null : (string) $data['additional_business_slot_price_ratio'])
                        : ($locked->additional_business_slot_price_ratio === null ? null : (string) $locked->additional_business_slot_price_ratio),
                    $actorUserId,
                    $reason,
                );
            }

            // 3. Feature packaging.
            $featureDiff = ['added' => [], 'removed' => []];

            if (array_key_exists('feature_keys', $data)) {
                $featureDiff = $this->features->syncFeatureKeys($locked, array_values((array) $data['feature_keys']));
            }

            $locked = $locked->fresh();
            $after = $locked->only($this->trackedColumns());
            $changes = [];

            foreach ($this->trackedColumns() as $col) {
                if ((string) ($before[$col] ?? '') !== (string) ($after[$col] ?? '')) {
                    $changes[$col] = ['from' => $before[$col] ?? null, 'to' => $after[$col] ?? null];
                }
            }

            if ($featureDiff['added'] !== [] || $featureDiff['removed'] !== []) {
                $changes['features'] = ['from' => $beforeFeatures, 'to' => $this->features->featureKeysForCatalog($locked)->sort()->values()->all()];
            }

            if ($changes !== []) {
                $archived = isset($changes['is_active']) ? ($after['is_active'] ? 'reactivated' : 'archived') : 'updated';
                $this->audit->record(
                    $actorUserId,
                    'plan_catalog.' . $archived,
                    'plan',
                    $locked->tier->value,
                    ucfirst($locked->tier->value) . ' plan ' . $archived . ': ' . implode(', ', array_keys($changes)),
                    $reason,
                    ['changes' => $changes],
                );
            }

            return $changes;
        });
    }

    /** @return list<string> */
    private function trackedColumns(): array
    {
        return ['display_name', 'is_active', 'available_for_signup', 'billing_cycle', 'trial_enabled', 'trial_days',
            'provider_price_id', 'price', 'currency_id', 'additional_business_slot_price_ratio',
            'business_slot_included', 'business_slot_max', 'unlimited_business_slots',
            'location_slot_included', 'location_slot_max', 'unlimited_location_slots'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertInternallyConsistent(WorkspacePlanCatalog $catalog, array $data): void
    {
        $errors = [];

        if (array_key_exists('display_name', $data) && mb_strlen(trim((string) $data['display_name'])) < 2) {
            $errors['display_name'] = __('The plan needs a display name.');
        }

        if (array_key_exists('trial_enabled', $data) && (bool) $data['trial_enabled'] && (int) ($data['trial_days'] ?? $catalog->trial_days) < 1) {
            $errors['trial_days'] = __('Set how many days the trial lasts, or turn the trial off.');
        }

        foreach (['business' => 'Business', 'location' => 'Location'] as $prefix => $label) {
            $inc = array_key_exists("{$prefix}_slot_included", $data) ? $data["{$prefix}_slot_included"] : $catalog->{"{$prefix}_slot_included"};
            $max = array_key_exists("{$prefix}_slot_max", $data) ? $data["{$prefix}_slot_max"] : $catalog->{"{$prefix}_slot_max"};
            $unlimited = array_key_exists("unlimited_{$prefix}_slots", $data)
                ? (bool) $data["unlimited_{$prefix}_slots"]
                : (bool) $catalog->{"unlimited_{$prefix}_slots"};

            if ((int) $inc < ($prefix === 'business' ? 1 : 0)) {
                $errors["{$prefix}_slot_included"] = __(':x slots included cannot be below :n.', ['x' => $label, 'n' => $prefix === 'business' ? 1 : 0]);
            }

            if (! $unlimited && $max !== null && $max !== '' && (int) $max < (int) $inc) {
                $errors["{$prefix}_slot_max"] = __('The maximum cannot be lower than the number included.');
            }

            if (! $unlimited && ($max === null || $max === '') && (array_key_exists("{$prefix}_slot_max", $data) || array_key_exists("unlimited_{$prefix}_slots", $data))) {
                $errors["{$prefix}_slot_max"] = __('Set a maximum, or choose unlimited.');
            }
        }

        if (array_key_exists('feature_keys', $data)) {
            $valid = PlatformFeatureGroups::allKeys();

            foreach ((array) $data['feature_keys'] as $key) {
                if (! in_array($key, $valid, true)) {
                    $errors['feature_keys'] = __('Unknown feature selected.');
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * §11 parity check, before any row changes: a Stripe Price id must exist,
     * be active and recurring, and charge exactly the saved terms.
     *
     * @param  array<string, mixed>  $data
     */
    private function verifyStripePrice(WorkspacePlanCatalog $catalog, array $data): void
    {
        $priceId = $data['provider_price_id'] ?? null;

        if (blank($priceId)) {
            return;
        }

        $price = (string) ($data['price'] ?? $catalog->price);
        $currencyId = (int) ($data['currency_id'] ?? $catalog->currency_id);
        $cycle = (string) ($data['billing_cycle'] ?? $catalog->billing_cycle);
        $currencyCode = (string) DB::table('currencies')->where('id', $currencyId)->value('code');

        try {
            $mismatches = $this->prices->mismatches((string) $priceId, $price, $currencyCode, $cycle);
        } catch (PlatformBillingException $e) {
            throw ValidationException::withMessages(['provider_price_id' => $e->customerMessage()]);
        }

        if ($mismatches !== []) {
            throw ValidationException::withMessages(['provider_price_id' => $mismatches]);
        }
    }
}
