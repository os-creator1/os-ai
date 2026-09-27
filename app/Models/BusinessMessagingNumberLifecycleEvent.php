<?php

namespace App\Models;

use App\Enums\Messaging\NumberLifecycleEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phone Numbers + A2P lane — messaging contract §13.2/§13.3's audit trail.
 * Written exclusively by NumberLifecycleManager; admin-visible only, never
 * read by a customer-facing request path.
 */
class BusinessMessagingNumberLifecycleEvent extends Model
{
    protected $table = 'business_messaging_number_lifecycle_events';

    protected $fillable = [
        'business_id',
        'business_messaging_number_id',
        'event_type',
        'actor_user_id',
        'note',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'business_messaging_number_id' => 'integer',
        'event_type' => NumberLifecycleEventType::class,
        'actor_user_id' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(BusinessMessagingNumber::class, 'business_messaging_number_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
