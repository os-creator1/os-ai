<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Agency V1 completion — one insert-only audit row for one change to an
 * Agency's white-label identity (who, what, when). Never updated, never
 * deleted; created_at is the only timestamp.
 */
class AgencyWhiteLabelChange extends Model
{
    use HasUid;

    public const UPDATED_AT = null;

    public const TYPE_CREATED = 'created';

    public const TYPE_UPDATED = 'updated';

    public const TYPE_ENABLED = 'enabled';

    public const TYPE_DISABLED = 'disabled';

    public const TYPE_LOGO_REPLACED = 'logo_replaced';

    public const TYPE_LOGO_REMOVED = 'logo_removed';

    protected $fillable = [
        'agency_workspace_id',
        'changed_by_user_id',
        'change_type',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function agencyWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'agency_workspace_id');
    }
}
