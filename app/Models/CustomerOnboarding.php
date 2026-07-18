<?php

namespace App\Models;

use App\Enums\Business\OnboardingStatus;
use App\Enums\Business\OnboardingStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOnboarding extends Model
{
    protected $fillable = [
        'customer_id',
        'business_id',
        'is_required',
        'primary_goals',
        'metadata',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'analysis_version' => 'integer',
        'status' => OnboardingStatus::class,
        'current_step' => OnboardingStep::class,
        'primary_goals' => 'array',
        'completed_steps' => 'array',
        'metadata' => 'array',
        'analysis_payload' => 'array',
        'analysis_started_at' => 'datetime',
        'analysis_completed_at' => 'datetime',
        'first_value_action_completed_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'user_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
