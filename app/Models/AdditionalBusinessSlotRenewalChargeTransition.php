<?php

namespace App\Models;

use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\TransitionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdditionalBusinessSlotRenewalChargeTransition extends Model
{
    protected $table = 'additional_business_slot_renewal_charge_transitions';

    public $timestamps = false;

    protected $fillable = [
        'renewal_charge_id',
        'from_state',
        'to_state',
        'source',
        'provider_event_id',
        'actor_user_id',
        'created_at',
    ];

    protected $casts = [
        'from_state' => FundingAttemptState::class,
        'to_state' => FundingAttemptState::class,
        'source' => TransitionSource::class,
        'created_at' => 'datetime',
    ];

    public function renewalCharge(): BelongsTo
    {
        return $this->belongsTo(AdditionalBusinessSlotRenewalCharge::class, 'renewal_charge_id');
    }
}
