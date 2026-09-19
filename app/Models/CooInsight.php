<?php

namespace App\Models;

use App\Enums\Coo\CooInsightInvalidationReason;
use App\Enums\Coo\CooInsightKind;
use App\Enums\Coo\CooInsightOrigin;
use App\Enums\Coo\CooScope;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Coo\Context\AuthorizationScopeFingerprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

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

    /**
     * Contract 19 §5.9 R-25 — write-once attribution and identity. No read
     * path, presenter, job or repository may change any of these after the row
     * exists: doing so would let a cached answer be re-attributed to whoever
     * read it next, or re-scoped to an audience it was never computed for.
     * Enforced by the `updating` guard below and by
     * CooInsightAttributionTest.
     *
     * @var array<int, string>
     */
    public const IMMUTABLE_AFTER_INSERT = [
        'scope',
        'origin',
        'authorization_scope_fingerprint',
        'actor_user_id',
        'audience_user_id',
        'view_as_session_id',
        'business_id',
        'workspace_id',
        'business_location_id',
    ];

    protected $fillable = [
        'uid',
        'scope',
        'origin',
        'business_id',
        'business_location_id',
        'workspace_id',
        'actor_user_id',
        'audience_user_id',
        'view_as_session_id',
        'authorization_scope_fingerprint',
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
        'scope' => CooScope::class,
        'origin' => CooInsightOrigin::class,
        'business_id' => 'integer',
        'business_location_id' => 'integer',
        'workspace_id' => 'integer',
        'actor_user_id' => 'integer',
        'audience_user_id' => 'integer',
        'view_as_session_id' => 'integer',
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

        static::saving(function (self $insight): void {
            // Immutability first, so an attempt to rewrite attribution is
            // reported as exactly that rather than as whichever scope
            // invariant the rewritten row happens to violate on the way out.
            if ($insight->exists) {
                $insight->assertAttributionUnchanged();
            }

            $insight->assertScopeInvariants();
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

    /**
     * Contract 19 §8 — the scope/tenancy/attribution shapes a row may take,
     * enforced by the writer rather than assumed.
     *
     * Business and Agency scope both carry a real Workspace and a real
     * Business (Workspace:Business is 1:1 under Contract 13, so Agency needs
     * no nullable Business). Platform scope carries neither, and never a
     * fabricated stand-in (R-19).
     *
     * A `system` row has no acting human and must not claim one; an
     * `on_demand` row must name the real one (§5.9).
     *
     * SLICE BOUNDARY: 19.A is the context/cache foundation. The columns admit
     * Agency and Platform rows so the schema, the fingerprint document and
     * these invariants are right from the start, but nothing in this slice may
     * WRITE one — that is 19.H's work, and it lifts the guard below when its
     * fact composition, entitlement subject and budget authority exist.
     */
    public function assertScopeInvariants(): void
    {
        $scope = $this->scope instanceof CooScope ? $this->scope : CooScope::tryFrom((string) $this->scope);

        if ($scope === null) {
            throw new InvalidArgumentException('coo_insights.scope must be a known CooScope.');
        }

        if (! $scope->isWritableInThisSlice()) {
            throw new InvalidArgumentException($scope->value . ' scope insights are not written before Contract 19 sub-slice 19.H.');
        }

        if ($scope->requiresTenancy() && ($this->workspace_id === null || $this->business_id === null)) {
            throw new InvalidArgumentException($scope->value . ' scope requires both workspace_id and business_id.');
        }

        if (! $scope->requiresTenancy() && ($this->workspace_id !== null || $this->business_id !== null)) {
            throw new InvalidArgumentException('platform scope carries no tenant: workspace_id and business_id must both be null.');
        }

        $origin = $this->origin instanceof CooInsightOrigin ? $this->origin : CooInsightOrigin::tryFrom((string) $this->origin);

        if ($origin === null) {
            throw new InvalidArgumentException('coo_insights.origin must be a known CooInsightOrigin.');
        }

        if ($origin->requiresActor() && $this->actor_user_id === null) {
            throw new InvalidArgumentException('an on_demand insight must name the real acting human.');
        }

        if (! $origin->requiresActor() && $this->actor_user_id !== null) {
            throw new InvalidArgumentException('a system insight has no acting human: actor_user_id must be null (Contract 19 §5.9).');
        }

        if (! AuthorizationScopeFingerprint::looksComputed((string) $this->authorization_scope_fingerprint)) {
            throw new InvalidArgumentException('coo_insights.authorization_scope_fingerprint must be a computed fingerprint, never the retirement sentinel or an invented value.');
        }
    }

    /** Contract 19 R-25/R-27 — write-once attribution and identity. */
    public function assertAttributionUnchanged(): void
    {
        foreach (self::IMMUTABLE_AFTER_INSERT as $column) {
            if ($this->isDirty($column)) {
                throw new InvalidArgumentException('coo_insights.' . $column . ' is immutable after insert (Contract 19 R-25).');
            }
        }
    }
}
