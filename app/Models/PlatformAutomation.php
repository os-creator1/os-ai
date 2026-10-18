<?php

namespace App\Models;

use App\Enums\PlatformAutomation\PlatformAutomationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Platform-scoped automation definition (trigger + conditions + ordered steps).
 * Platform scope is deliberately NOT a flag on the Business engine's
 * `automation_workflows`: see the 2026_11_03_090001 migration. Written only by
 * App\Library\PlatformAutomation\PlatformAutomationManager.
 */
class PlatformAutomation extends Model
{
    protected $table = 'platform_automations';

    protected $fillable = [
        'uid', 'name', 'description', 'status', 'trigger_type', 'definition', 'version',
        'recipe_key', 'created_by_user_id', 'updated_by_user_id', 'archived_at',
    ];

    protected $casts = [
        'status' => PlatformAutomationStatus::class,
        'definition' => 'array',
        'version' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(PlatformAutomationVersion::class, 'automation_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PlatformAutomationRun::class, 'automation_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }

    public function isEnabled(): bool
    {
        return $this->status === PlatformAutomationStatus::Enabled;
    }
}
