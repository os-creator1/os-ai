<?php

namespace App\Models;

use App\Enums\Usage\FundingAttemptPurpose;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessFundingAttempt extends Model
{
    protected $table = 'business_funding_attempts';

    protected $fillable = [
        'business_id',
        'wallet_id',
        'purpose',
        'payer_type_snapshot',
        'billing_contact_name_snapshot',
        'billing_contact_email_snapshot',
        'provider_customer_external_id_snapshot',
        'provider_customer_id',
        'payment_method_display_snapshot',
        'requesting_actor_user_id',
        'expected_currency_id',
        'expected_amount_micro',
        'local_idempotency_key',
        'provider_session_or_intent_reference',
        'provider_payment_intent_reference',
        'provider_charge_reference',
        'state',
        'failure_reason',
    ];

    protected $casts = [
        'purpose' => FundingAttemptPurpose::class,
        'payer_type_snapshot' => PayerType::class,
        'state' => FundingAttemptState::class,
        'expected_amount_micro' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function providerCustomer(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderCustomer::class, 'provider_customer_id');
    }
}
