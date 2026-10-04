<?php

namespace App\Models;

use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankRunState;
use App\Enums\Seo\SeoRankTrigger;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * SEO Keyword Rank Tracking V1 — one provider task. Written only by the rank
 * check pipeline (SeoRankCheckPlanner / SeoRankCheckExecutor). Never exposed
 * to customers: provider task ids stay server-side.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property int|null $workspace_id
 * @property int $seo_rank_target_id
 * @property SeoRankCheckType $check_type
 * @property SeoRankTrigger $trigger
 * @property string $idempotency_key
 * @property SeoRankRunState $state
 * @property string|null $provider_task_id
 * @property int $depth
 * @property int $attempts
 * @property int $poll_attempts
 * @property int $reserved_micros
 * @property int|null $actual_micros
 */
class SeoRankCheckRun extends Model
{
    use HasUid;

    protected $table = 'seo_rank_check_runs';

    protected $guarded = ['*'];

    protected $casts = [
        'check_type' => SeoRankCheckType::class,
        'trigger' => SeoRankTrigger::class,
        'state' => SeoRankRunState::class,
        'next_attempt_at' => 'datetime',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SeoRankTarget::class, 'seo_rank_target_id');
    }

    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(SeoRankProviderLedger::class, 'seo_rank_check_run_id');
    }
}
