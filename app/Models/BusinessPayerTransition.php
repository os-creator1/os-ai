<?php

namespace App\Models;

use App\Enums\Usage\PayerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only — never updated or deleted after insert.
 */
class BusinessPayerTransition extends Model
{
    public $timestamps = false;

    protected $table = 'business_payer_transitions';

    protected $fillable = [
        'business_id',
        'from_payer_type',
        'to_payer_type',
        'from_instrument_id',
        'to_instrument_id',
        'actor_user_id',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'from_payer_type' => PayerType::class,
        'to_payer_type' => PayerType::class,
        'created_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
