<?php

namespace App\Models;

use App\Enums\Crm\CrmOpportunityHistoryEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only entry in a CRM deal's history.
 *
 * @property int $id
 * @property int $business_id
 * @property int $opportunity_id
 * @property CrmOpportunityHistoryEvent $event
 * @property ?int $from_stage_id
 * @property ?int $to_stage_id
 * @property ?string $from_stage_name
 * @property ?string $to_stage_name
 * @property ?string $from_value
 * @property ?string $to_value
 * @property ?int $actor_user_id
 */
class CrmOpportunityHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'crm_opportunity_history';

    protected $fillable = [
        'business_id',
        'opportunity_id',
        'event',
        'from_stage_id',
        'to_stage_id',
        'from_stage_name',
        'to_stage_name',
        'from_value',
        'to_value',
        'actor_user_id',
    ];

    protected $casts = [
        'event' => CrmOpportunityHistoryEvent::class,
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(CrmOpportunity::class, 'opportunity_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
