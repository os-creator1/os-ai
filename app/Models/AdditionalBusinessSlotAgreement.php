<?php

namespace App\Models;

use App\Enums\Usage\SlotAgreementBillingCadence;
use App\Enums\Usage\SlotAgreementState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdditionalBusinessSlotAgreement extends Model
{
    protected $table = 'additional_business_slot_agreements';

    protected $fillable = [
        'workspace_id',
        'current_allocation_count',
        'target_allocation_count',
        'paid_delta',
        'price_per_slot_micro_snapshot',
        'total_amount_micro_snapshot',
        'currency_id_snapshot',
        'ratio_snapshot',
        'plan_catalog_id_snapshot',
        'plan_tier_snapshot',
        'requesting_customer_user_id',
        'requesting_customer_email_snapshot',
        'provider_customer_id',
        'payment_method_display_snapshot',
        'local_idempotency_key',
        'provider_session_or_intent_reference',
        'billing_cadence',
        'next_renewal_at',
        'cancel_at_period_end',
        'cancellation_requested_at',
        'cancellation_effective_at',
        'payment_lapsed',
        'payment_lapsed_at',
        'payment_lapsed_cleared_at',
        'state',
    ];

    protected $casts = [
        'current_allocation_count' => 'integer',
        'target_allocation_count' => 'integer',
        'paid_delta' => 'integer',
        'price_per_slot_micro_snapshot' => 'integer',
        'total_amount_micro_snapshot' => 'integer',
        'ratio_snapshot' => 'string',
        'billing_cadence' => SlotAgreementBillingCadence::class,
        'next_renewal_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'cancellation_requested_at' => 'datetime',
        'cancellation_effective_at' => 'datetime',
        'payment_lapsed' => 'boolean',
        'payment_lapsed_at' => 'datetime',
        'payment_lapsed_cleared_at' => 'datetime',
        'state' => SlotAgreementState::class,
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function providerCustomer(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderCustomer::class, 'provider_customer_id');
    }
}
