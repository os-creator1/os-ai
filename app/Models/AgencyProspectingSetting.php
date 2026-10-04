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
        'booking_url',
        'follow_up_delay_hours',
        'website_url',
        'message_1',
        'message_2',
        'message_3',
        'pricing_answer',
        'location_answer',
        'found_you_answer',
        'what_we_do_answer',
        'website_answer',
        'clarify_answer',
        'followup_message',
        'followup_enabled',
        'ai_enabled',
        'scheduling_mode',
        'script_version',
    ];

    protected $casts = [
        'followup_enabled' => 'boolean',
        'ai_enabled' => 'boolean',
        'script_version' => 'integer',
        'follow_up_delay_hours' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
