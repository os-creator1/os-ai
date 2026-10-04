<?php

namespace App\Models;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyProspectCampaign extends Model
{
    use HasUid;

    protected $fillable = [
        'workspace_id',
        'channel_id',
        'name',
        'status',
        'context',
        'opening_message',
        'sending_mode',
    ];

    /** BYO-channel Prospecting runtime (every pre-Outreach campaign). */
    public const MODE_CHANNEL = 'channel';

    /** Agency Outreach: canonical messaging + the deterministic engine. */
    public const MODE_MANAGED = 'managed';

    public function isManaged(): bool
    {
        return $this->sending_mode === self::MODE_MANAGED;
    }

    protected $casts = [
        'status' => AgencyProspectCampaignStatus::class,
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AgencyProspectingChannel::class, 'channel_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(AgencyProspectCampaignMember::class, 'campaign_id');
    }
}
