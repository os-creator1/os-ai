<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Content Autopilot (Contract 25) - one evaluation outcome: what was decided, the score behind it, the structured brief
 * (when there is one), the single open question for the owner (when there is one), and the article it produced.
 */
class ContentAutopilotDecision extends Model
{
    use HasUid;

    public const KIND_CREATE = 'create';
    public const KIND_UPDATE = 'update';
    public const KIND_MAINTAIN = 'maintain';

    public const DECISION_CREATE = 'create';
    public const DECISION_UPDATE = 'update';
    public const DECISION_HOLD = 'hold';
    public const DECISION_NEEDS_INPUT = 'needs_input';
    public const DECISION_NONE = 'none';

    public const STATE_BRIEFED = 'briefed';
    public const STATE_DRAFTING = 'drafting';
    public const STATE_VALIDATING = 'validating';
    public const STATE_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_PUBLISHED = 'published';
    public const STATE_DEFERRED_BUDGET = 'deferred_budget';
    public const STATE_HELD = 'held';
    public const STATE_REJECTED = 'rejected';
    public const STATE_NEEDS_INPUT = 'needs_input';
    public const STATE_RESOLVED = 'resolved';
    public const STATE_NONE = 'none';

    /** States in which a topic is already being worked on - it must not be selected again. */
    public const IN_FLIGHT = [
        self::STATE_BRIEFED, self::STATE_DRAFTING, self::STATE_VALIDATING, self::STATE_AWAITING_APPROVAL,
        self::STATE_SCHEDULED, self::STATE_DEFERRED_BUDGET,
    ];

    protected $fillable = [
        'business_id', 'kind', 'decision', 'state', 'opportunity_key', 'score', 'score_breakdown', 'reason_code',
        'brief', 'brief_hash', 'fact_hash', 'needs_input', 'article_id', 'cost_microusd', 'period_key', 'evaluated_at', 'resolved_at',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'score' => 'integer',
        'score_breakdown' => 'array',
        'brief' => 'array',
        'needs_input' => 'array',
        'article_id' => 'integer',
        'cost_microusd' => 'integer',
        'evaluated_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(WebsiteArticle::class, 'article_id');
    }
}
