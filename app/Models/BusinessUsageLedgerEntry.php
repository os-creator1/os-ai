<?php

namespace App\Models;

use App\Enums\Usage\RoundingRule;
use App\Enums\Usage\UsageLedgerEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only — never updated or deleted after insert (RFC-005 §12, M1
 * contract §6.7). No manager method ever calls update()/delete() on this
 * model.
 */
class BusinessUsageLedgerEntry extends Model
{
    public $timestamps = false;

    protected $table = 'business_usage_ledger_entries';

    protected $fillable = [
        'business_id',
        'wallet_id',
        'entry_type',
        'available_delta_micro',
        'reserved_delta_micro',
        'debt_delta_micro',
        'gross_amount_micro',
        'currency_id',
        'feature_key',
        'meter_key',
        'period_key',
        'quantity',
        'rate_id',
        'rate_version',
        'retail_rate_micro',
        'provider_cost_micro',
        'unit_label',
        'rounding_rule',
        'reservation_id',
        'funding_attempt_id',
        'correlation_key',
        'provider_reference',
        'actor_user_id',
        'reason',
        'reversed_entry_id',
        'created_at',
    ];

    protected $casts = [
        'entry_type' => UsageLedgerEntryType::class,
        'available_delta_micro' => 'integer',
        'reserved_delta_micro' => 'integer',
        'debt_delta_micro' => 'integer',
        'gross_amount_micro' => 'integer',
        'quantity' => 'decimal:6',
        'rate_version' => 'integer',
        'retail_rate_micro' => 'integer',
        'provider_cost_micro' => 'integer',
        'rounding_rule' => RoundingRule::class,
        'created_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(BusinessUsageWallet::class, 'wallet_id');
    }
}
