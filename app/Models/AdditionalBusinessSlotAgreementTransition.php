<?php

namespace App\Models;

use App\Enums\Usage\SlotAgreementState;
use App\Enums\Usage\TransitionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdditionalBusinessSlotAgreementTransition extends Model
{
    protected $table = 'additional_business_slot_agreement_transitions';

    public $timestamps = false;

    protected $fillable = [
        'agreement_id',
        'from_state',
        'to_state',
        'source',
        'provider_event_id',
        'actor_user_id',
        'created_at',
    ];

    protected $casts = [
        'from_state' => SlotAgreementState::class,
        'to_state' => SlotAgreementState::class,
        'source' => TransitionSource::class,
        'created_at' => 'datetime',
    ];

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(AdditionalBusinessSlotAgreement::class, 'agreement_id');
    }
}
