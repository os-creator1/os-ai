<?php

namespace App\Models;

use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Enums\AiUsageEntryStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Contract §10.2 — the append-only provider-cost ledger. Rows are never
 * updated in place except by `App\Library\Ai\AiUsageLedgerManager`'s own
 * reserve -> commit|release|failed transitions, and never by any other
 * caller. Admin-only visibility (§15.4); this model carries no attribute
 * that could be customer-visible.
 */
class AiUsageLedgerEntry extends Model
{
    use HasUid;

    public const UPDATED_AT = null;

    protected $table = 'ai_usage_ledger';

    protected $fillable = [
        'uid',
        'workspace_id',
        'business_id',
        'category',
        'lane',
        'model_route',
        'provider',
        'provider_model',
        'price_version',
        'status',
        'refusal_reason',
        'input_tokens',
        'cached_input_tokens',
        'output_tokens',
        'estimated_cost_microusd',
        'actual_cost_microusd',
        'period_key',
        'idempotency_key',
        'actor_user_id',
        'created_at',
        'settled_at',
    ];

    protected $casts = [
        'workspace_id' => 'integer',
        'business_id' => 'integer',
        'category' => AiUsageCategory::class,
        'lane' => AiLane::class,
        'model_route' => AiModelRoute::class,
        'price_version' => 'integer',
        'status' => AiUsageEntryStatus::class,
        'refusal_reason' => AiRefusalReason::class,
        'input_tokens' => 'integer',
        'cached_input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'estimated_cost_microusd' => 'integer',
        'actual_cost_microusd' => 'integer',
        'actor_user_id' => 'integer',
        'created_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * `ai_usage_ledger.uid` is a real database UUID column, per the
     * newer HasUid convention (Website, Business) rather than the
     * trait's legacy `uniqid()` default.
     */
    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }
}
