<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Agency V1 completion — the ONE white-label identity an Agency Workspace
 * owns (unique per agency_workspace_id). Branding belongs to the Agency; a
 * client never holds a copy and is branded only by resolving its managing
 * Agency through the persisted management relationship
 * (ClientWorkspaceBrandResolver).
 *
 * Nothing here is trusted raw: AgencyWhiteLabelManager validates on write and
 * ClientWorkspaceBrandResolver re-normalizes on read.
 */
class AgencyWhiteLabelSetting extends Model
{
    use HasUid;

    protected $fillable = [
        'agency_workspace_id',
        'is_enabled',
        'display_name',
        'tagline',
        'accent_color',
        'support_email',
        'logo_path',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
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
