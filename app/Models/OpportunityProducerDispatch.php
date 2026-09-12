<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * COO C-1 — one (Business, worker) coordination row for automatic producer
 * triggering. Coordination state only; it never describes an opportunity.
 *
 * Writes go through EloquentOpportunityProducerDispatchRepository's atomic
 * claims, never through save() on a hydrated instance: the affected-row count
 * of a conditional UPDATE is what makes two processes disagree impossible, and
 * a read-modify-write would throw that away.
 */
class OpportunityProducerDispatch extends Model
{
    protected $table = 'opportunity_producer_dispatches';

    protected $fillable = [
        'business_id',
        'worker_key',
        'last_dispatched_at',
        'last_swept_on',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'last_dispatched_at' => 'datetime',
        'last_swept_on' => 'date',
    ];
}
