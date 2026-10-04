<?php

namespace App\Models;

use App\Enums\PlatformAutomation\PlatformSafetyClass;
use App\Enums\PlatformAutomation\PlatformStepState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of one run: the unit that is claimed, retried, approved and audited. */
class PlatformAutomationStep extends Model
{
    protected $table = 'platform_automation_steps';

    protected $fillable = [
        'run_id', 'step_index', 'step_key', 'action_type', 'safety_class', 'config', 'state', 'attempts',
        'run_at', 'executed_at', 'result', 'safe_error', 'operation_ref', 'decided_by_user_id', 'decided_at',
    ];

    protected $casts = [
        'safety_class' => PlatformSafetyClass::class,
        'state' => PlatformStepState::class,
        'config' => 'array',
        'result' => 'array',
        'step_index' => 'integer',
        'attempts' => 'integer',
        'run_at' => 'datetime',
        'executed_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PlatformAutomationRun::class, 'run_id');
    }
}
