<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A carrier number order that is not simply "done": see the creating
 * migration. Status values are the only states; transitions are made by
 * App\Library\Messaging\NumberOrderReconciler under a row lock.
 */
class BusinessMessagingNumberOrder extends Model
{
    public const PLACING = 'placing';

    /** Accepted by the carrier (or outcome unseen); finished automatically by the reconciler. */
    public const PROCESSING = 'processing';

    /** Still unresolved after the escalation window: operators were alerted; polling continues. */
    public const NEEDS_ATTENTION = 'needs_attention';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const OPEN_STATUSES = [self::PLACING, self::PROCESSING, self::NEEDS_ATTENTION];

    protected $table = 'business_messaging_number_orders';

    protected $guarded = [];

    protected $casts = [
        'business_id' => 'integer',
        'usage_reservation_id' => 'integer',
        'upfront_cost_micro' => 'integer',
        'monthly_cost_micro' => 'integer',
        'attempts' => 'integer',
        'accepted_at' => 'datetime',
        'purchased_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'completed_at' => 'datetime',
        'escalated_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
