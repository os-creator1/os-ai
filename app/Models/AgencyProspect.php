<?php

namespace App\Models;

use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyProspect extends Model
{
    use HasUid;

    protected $fillable = [
        'workspace_id',
        'company_name',
        'contact_name',
        'phone',
        'email',
        'website',
        'source',
        'location',
        'status',
        'stopped_at',
        'booked_at',
        'stop_reason',
    ];

    public const STOP_OPT_OUT = 'opt_out';
    public const STOP_REJECTED = 'rejected';
    public const STOP_MANUAL = 'manual';

    protected $casts = [
        'status' => AgencyProspectStatus::class,
        'stopped_at' => 'datetime',
        'booked_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaignMemberships(): HasMany
    {
        return $this->hasMany(AgencyProspectCampaignMember::class, 'prospect_id');
    }
}
