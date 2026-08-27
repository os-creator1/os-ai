<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessBillingReceipt extends Model
{
    public $timestamps = false;

    protected $table = 'business_billing_receipts';

    protected $fillable = [
        'business_id',
        'ledger_entry_id',
        'provider_receipt_url',
        'provider_reference',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(BusinessUsageLedgerEntry::class, 'ledger_entry_id');
    }
}
