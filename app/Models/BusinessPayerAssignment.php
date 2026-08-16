<?php

namespace App\Models;

use App\Enums\Usage\PayerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessPayerAssignment extends Model
{
    protected $table = 'business_payer_assignments';

    protected $fillable = [
        'business_id',
        'payer_type',
        'effective_payment_instrument_id',
    ];

    protected $casts = [
        'payer_type' => PayerType::class,
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
