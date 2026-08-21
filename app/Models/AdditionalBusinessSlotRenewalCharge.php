<?php

namespace App\Models;

use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Enums\Usage\SlotRenewalChargeInitiatedBy;
use App\Enums\Usage\SlotRenewalChargeKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdditionalBusinessSlotRenewalCharge extends Model
{
    protected $table = 'additional_business_slot_renewal_charges';

    public $timestamps = false;

    protected $fillable = [
        'agreement_id',
        'charge_kind',
        'period_start',
        'period_end',
        'amount_micro_snapshot',
        'requesting_customer_email_snapshot',
        'payer_type_snapshot',
        'provider_customer_external_id_snapshot',
        'payment_method_display_snapshot',
        'currency_id_snapshot',
        'plan_catalog_id_snapshot',
        'plan_tier_snapshot',
        'ratio_snapshot',
        'initiated_by',
        'actor_user_id',
        'change_operation_id',
        'local_idempotency_key',
        'provider_session_or_intent_reference',
        'allocation_delta',
        'state',
        'failure_reason',
        'created_at',
    ];

    protected $casts = [
        'charge_kind' => SlotRenewalChargeKind::class,
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'amount_micro_snapshot' => 'integer',
        'payer_type_snapshot' => PayerType::class,
        'ratio_snapshot' => 'string',
        'initiated_by' => SlotRenewalChargeInitiatedBy::class,
        'allocation_delta' => 'integer',
        'state' => FundingAttemptState::class,
        'created_at' => 'datetime',
    ];

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(AdditionalBusinessSlotAgreement::class, 'agreement_id');
    }
}
