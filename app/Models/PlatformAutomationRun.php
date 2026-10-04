<?php

namespace App\Models;

use App\Enums\PlatformAutomation\PlatformRunState;
use App\Enums\PlatformAutomation\PlatformTargetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One occurrence of one Platform automation against one explicit target. */
class PlatformAutomationRun extends Model
{
    protected $table = 'platform_automation_runs';

    protected $fillable = [
        'uid', 'automation_id', 'version_id', 'trigger_type', 'occurrence_key', 'target_type', 'target_id',
        'workspace_id', 'business_id', 'user_id', 'context', 'state', 'scheduled_at', 'started_at',
        'finished_at', 'safe_error',
    ];

    protected $casts = [
        'state' => PlatformRunState::class,
        'target_type' => PlatformTargetType::class,
        'context' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(PlatformAutomation::class, 'automation_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PlatformAutomationVersion::class, 'version_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(PlatformAutomationStep::class, 'run_id')->orderBy('step_index');
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }
}
