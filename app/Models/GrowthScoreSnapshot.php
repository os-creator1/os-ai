<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day's Growth Score for one Business at one algorithm version
 * (see the 2026_10_27_100002 migration). Immutable history: written by
 * GrowthScoreRecorder only, never edited by a request.
 */
class GrowthScoreSnapshot extends Model
{
    protected $fillable = [
        'business_id',
        'snapshot_date',
        'algorithm_version',
        'overall_score',
        'scored_category_count',
        'total_category_count',
        'applicable_rule_count',
        'category_scores',
        'breakdown',
        'positives',
        'metrics',
        'computed_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'category_scores' => 'array',
        'breakdown' => 'array',
        'positives' => 'array',
        'metrics' => 'array',
        'computed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
