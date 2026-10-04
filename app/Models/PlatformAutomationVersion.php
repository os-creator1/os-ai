<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An immutable snapshot of a PlatformAutomation definition; runs are pinned to one. */
class PlatformAutomationVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'platform_automation_versions';

    protected $fillable = [
        'automation_id', 'version_number', 'trigger_type', 'definition', 'definition_hash', 'created_by_user_id',
    ];

    protected $casts = [
        'definition' => 'array',
        'version_number' => 'integer',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(PlatformAutomation::class, 'automation_id');
    }
}
