<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyProspectingSetting extends Model
{
    use HasUid;

    protected $fillable = [
        'workspace_id',
        'agency_name',
        'offer',
        'niche',
        'value_proposition',
        'pricing_context',
        'qualification_context',
        'geography_context',
        'tone',
        'faqs_objections',
        'booking_context',
        'follow_up_policy',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
