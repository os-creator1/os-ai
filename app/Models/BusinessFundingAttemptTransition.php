<?php

namespace App\Models;

use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\TransitionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessFundingAttemptTransition extends Model
{
    protected $table = 'business_funding_attempt_transitions';

    public $timestamps = false;

    protected $fillable = [
        'funding_attempt_id',
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

    public function fundingAttempt(): BelongsTo
    {
        return $this->belongsTo(BusinessFundingAttempt::class);
    }
}
