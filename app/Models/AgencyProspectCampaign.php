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
    ];

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
