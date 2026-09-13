<?php

namespace App\Models;

use App\Enums\Coo\CooInsightInvalidationReason;
use App\Enums\Coo\CooInsightKind;
use App\Library\Ai\Enums\AiModelRoute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Contract §9.1 — one cached, validated COO insight for one Business.
 *
 * Written only by App\Jobs\Coo\GenerateCooInsight; invalidated only by
 * App\Library\Coo\Insight\CooInsightInvalidator. `facts_snapshot`,
 * `provider_model` and the ledger link are admin provenance and never reach a
 * customer view: the Home reads `output` and `generated_at`, nothing else.
 */
class CooInsight extends Model
{
    public const SUBJECT_BUSINESS = 'business';

    public const SUBJECT_OPPORTUNITY = 'opportunity';

    protected $table = 'coo_insights';

    protected $fillable = [
        'uid',
        'business_id',
        'workspace_id',
        'kind',
        'subject_type',
        'subject_id',
        'period_key',
        'signal_fingerprint',
        'facts_snapshot',
        'output',
        'prompt_version',
        'policy_version',
        'model_route',
        'provider_model',
        'ai_usage_ledger_entry_id',
        'generated_at',
        'expires_at',
        'invalidated_at',
        'invalidation_reason',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'workspace_id' => 'integer',
        'subject_id' => 'integer',
        'kind' => CooInsightKind::class,
        'facts_snapshot' => 'array',
        'output' => 'array',
        'prompt_version' => 'integer',
        'policy_version' => 'integer',
        'model_route' => AiModelRoute::class,
        'ai_usage_ledger_entry_id' => 'integer',
        'generated_at' => 'datetime',
        'expires_at' => 'datetime',
        'invalidated_at' => 'datetime',
        'invalidation_reason' => CooInsightInvalidationReason::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $insight): void {
            $insight->uid ??= (string) Str::uuid();
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isInvalidated(): bool
    {
        return $this->invalidated_at !== null;
    }
}
