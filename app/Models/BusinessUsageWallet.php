<?php

namespace App\Models;

use App\Enums\Usage\WalletBillingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessUsageWallet extends Model
{
    protected $table = 'business_usage_wallets';

    protected $fillable = [
        'business_id',
        'currency_id',
        'available_balance_micro',
        'reserved_balance_micro',
        'debt_balance_micro',
        'monthly_spend_cap_micro',
        'spend_period_key',
        'spend_period_start_utc',
        'spend_period_end_utc',
        'auto_recharge_enabled',
        'auto_recharge_threshold_micro',
        'auto_recharge_amount_micro',
        'monthly_recharge_cap_micro',
        'recharge_period_key',
        'recharge_period_start_utc',
        'recharge_period_end_utc',
        'committed_spend_this_period_micro',
        'reserved_spend_this_period_micro',
        'recharged_this_period_micro',
        'consecutive_recharge_failures',
        'low_balance_notified_at',
        'billing_status',
    ];

    protected $casts = [
        'available_balance_micro' => 'integer',
        'reserved_balance_micro' => 'integer',
        'debt_balance_micro' => 'integer',
        'monthly_spend_cap_micro' => 'integer',
        'spend_period_start_utc' => 'datetime',
        'spend_period_end_utc' => 'datetime',
        'auto_recharge_enabled' => 'boolean',
        'auto_recharge_threshold_micro' => 'integer',
        'auto_recharge_amount_micro' => 'integer',
        'monthly_recharge_cap_micro' => 'integer',
        'recharge_period_start_utc' => 'datetime',
        'recharge_period_end_utc' => 'datetime',
        'committed_spend_this_period_micro' => 'integer',
        'reserved_spend_this_period_micro' => 'integer',
        'recharged_this_period_micro' => 'integer',
        'consecutive_recharge_failures' => 'integer',
        'low_balance_notified_at' => 'datetime',
        'billing_status' => WalletBillingStatus::class,
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
