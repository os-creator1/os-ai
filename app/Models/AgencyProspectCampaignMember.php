<?php

namespace App\Models;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyProspectCampaignMember extends Model
{
    use HasUid;

    protected $fillable = [
        'workspace_id',
        'campaign_id',
        'prospect_id',
        'stage',
        'enrolled_at',
    ];

    protected $casts = [
        'stage' => AgencyProspectStage::class,
        'enrolled_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AgencyProspectCampaign::class, 'campaign_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(AgencyProspect::class, 'prospect_id');
    }
}
