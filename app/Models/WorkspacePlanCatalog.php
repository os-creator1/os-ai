<?php

namespace App\Models;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspacePlanCatalog extends Model
{
    protected $table = 'workspace_plan_catalog';

    protected $fillable = [
        'tier',
        'display_name',
        'price',
        'currency_id',
        'billing_cycle',
        'business_slot_included',
        'business_slot_max',
        'unlimited_business_slots',
        'additional_business_slot_price_ratio',
        // Customer Experience Slice 1A (RFC-004 §33.4) — PHYSICAL-LOCATION
        // capacity, per Business. Never a reinterpretation of business_slot_*,
        // which keeps meaning Business/client-account capacity.
        'location_slot_included',
        'location_slot_max',
        'unlimited_location_slots',
        'additional_location_slot_price_ratio',
        'is_active',
        // Implementation Contract 21 §11 — lane-A commercial configuration,
        // owned by this catalog rather than by a payments-only settings table.
        'trial_enabled',
        'trial_days',
        'available_for_signup',
        'provider_price_id',
    ];

    protected $casts = [
        'tier' => WorkspacePlanTier::class,
        'price' => 'decimal:2',
        'business_slot_included' => 'integer',
        'business_slot_max' => 'integer',
        'unlimited_business_slots' => 'boolean',
        'additional_business_slot_price_ratio' => 'decimal:4',
        'location_slot_included' => 'integer',
        'location_slot_max' => 'integer',
        'unlimited_location_slots' => 'boolean',
        'additional_location_slot_price_ratio' => 'decimal:4',
        'is_active' => 'boolean',
        'trial_enabled' => 'boolean',
        'trial_days' => 'integer',
        'available_for_signup' => 'boolean',
    ];

    /**
     * Implementation Contract 21 §7/§11 — whether this tier can be SOLD to a
     * new customer right now: offered for signup, assignable at all, and
     * carrying complete commercial terms.
     *
     * `is_active` and `available_for_signup` are deliberately both required
     * and deliberately different. `is_active` governs assignability in general
     * (turning it off would strand existing subscribers who need to change
     * plans); `available_for_signup` is the narrower commercial switch that
     * stops new sales without touching anybody already on the tier.
     */
    public function isSellable(): bool
    {
        return (bool) $this->is_active
            && (bool) $this->available_for_signup
            && $this->price !== null
            && $this->currency_id !== null
            && ! blank($this->provider_price_id);
    }

    /**
     * The trial length to SNAPSHOT at signup (§8), or null for no trial.
     * `trial_days` is meaningless while `trial_enabled` is false, so an
     * operator who switches trials off for a week does not lose the duration
     * they had configured.
     */
    public function configuredTrialDays(): ?int
    {
        if (! (bool) $this->trial_enabled) {
            return null;
        }

        $days = (int) $this->trial_days;

        return $days >= 1 ? $days : null;
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(WorkspacePlanFeature::class, 'workspace_plan_catalog_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkspacePlanAssignment::class, 'workspace_plan_catalog_id');
    }
}
