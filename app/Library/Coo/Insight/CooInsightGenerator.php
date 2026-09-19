<?php

namespace App\Library\Coo\Insight;

use App\Enums\Coo\CooInsightKind;
use App\Enums\Coo\CooInsightOrigin;
use App\Enums\Coo\CooInsightTrigger;
use App\Enums\Entitlement\PlatformFeature;
use App\Library\Ai\AiBusinessActivityGate;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\AiUsageLedgerManager;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Coo\Context\CooContextEnvelope;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\CooInsight;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Contract §8, §9, §10.1 (slice AI-3) — decides whether one COO insight may be
 * paid for, and if so buys it through the AI-1 gateway and caches it.
 *
 * Runs only inside the queued GenerateCooInsight job. Never on a Home render,
 * never inside a web request.
 *
 * THE ORDER, cheapest refusal first, so work that may not happen costs nothing:
 *
 *  1. AI switched off (`services.openai.active`)          → nothing read, nothing spent
 *  2. `ai_coo_basic` not allowed (incl. unassigned, inactive, suspended plans)
 *  3. dormant Business, for the three scheduled triggers   (E-4 is the customer asking)
 *  4. the context envelope agrees with the trigger and the Business (Contract 19)
 *  5. read the facts; invalidate same-window rows whose fingerprint moved (free)
 *  6. the trigger's own condition (E-1/E-2 multi-signal, E-3 fingerprint moved)
 *  7. an answer this actor could already read exists       → reuse, never pay twice
 *  8. the ledger already paid for, or is holding, this identity → skip
 *  9. ask the gateway — which re-checks entitlement, dormancy and the budget
 * 10. validate the output; cache it only if every statement passes
 *
 * Implementation Contract 19 sub-slice 19.A added the caller-supplied
 * CooContextEnvelope: the cache identity is now the authorization scope as
 * well as the facts, so two actors whose authorization differs get two rows
 * and two separate payments, and neither can ever read the other's.
 *
 * A refusal (budget exhausted included) or a provider failure ends the
 * attempt: there is no retry loop, the job runs once, and whatever was cached
 * before stays exactly as it was.
 */
final class CooInsightGenerator
{
    public function __construct(
        private readonly CooInsightFactsReader $factsReader,
        private readonly CooInsightPromptBuilder $prompts,
        private readonly CooInsightOutputValidator $validator,
        private readonly CooInsightInvalidator $invalidator,
        private readonly AiGateway $gateway,
        private readonly AiModelRouter $router,
        private readonly AiUsageLedgerManager $ledger,
        private readonly EntitlementManager $entitlements,
        private readonly AiBusinessActivityGate $activity,
    ) {
    }

    public function generate(Business $business, CooInsightTrigger $trigger, AnalyticsDateRange $range, CooContextEnvelope $envelope): CooInsightOutcome
    {
        $business->loadMissing('workspace');
        $workspace = $business->workspace;

        if ($workspace === null) {
            return CooInsightOutcome::skipped(CooInsightOutcome::BUSINESS_UNAVAILABLE);
        }

        $origin = $trigger->origin();
        $actorUserId = $envelope->actorUserId;

        if (! (bool) config('services.openai.active')) {
            return CooInsightOutcome::skipped(CooInsightOutcome::AI_DISABLED);
        }

        if (! $this->entitlements->decide($workspace, $business, PlatformFeature::AiCooBasic->value, $actorUserId ?? 0)->allowed) {
            return CooInsightOutcome::skipped(CooInsightOutcome::NOT_ENTITLED);
        }

        if ($trigger->isDormancyGated() && $this->activity->isDormant($business)) {
            return CooInsightOutcome::skipped(CooInsightOutcome::DORMANT);
        }

        // Contract 19 §5.9 — the envelope and the trigger must agree about who
        // caused this row and which Business it is about. A human-initiated
        // trigger with no actor, a background trigger carrying one, or an
        // envelope describing different tenancy is a wiring bug, not a row to
        // write. Checked after the free refusals above so the "cheapest
        // refusal first" order this class documents still holds.
        if ($origin->requiresActor() !== ($actorUserId !== null)
            || (int) $business->id !== $envelope->businessId
            || (int) $workspace->id !== $envelope->workspaceId) {
            return CooInsightOutcome::skipped(CooInsightOutcome::CONTEXT_MISMATCH);
        }

        $kind = CooInsightKind::PerformanceDiagnosis;
        $promptVersion = (int) config('coo.insight.prompt_version');
        $policyVersion = (int) config('coo.insight.policy_version');

        $facts = $this->factsReader->read($business, $range, $envelope);
        $fingerprint = $facts->fingerprint($promptVersion, $policyVersion);

        // §9.3 — the next signal read is what invalidates. Free, and done
        // whether or not anything is generated below.
        $this->invalidator->invalidateChangedSignals((int) $business->id, $kind, $facts->periodKey, $fingerprint, $promptVersion, $policyVersion);

        if (! $this->conditionHolds($trigger, $business, $facts, $fingerprint, $promptVersion, $policyVersion, $envelope)) {
            return CooInsightOutcome::skipped(CooInsightOutcome::CONDITION_NOT_MET);
        }

        // Contract 19 §8 — the cache identity is the authorization scope as
        // well as the facts. Two actors whose authorization differs get two
        // rows and two separate payments; neither can ever read the other's.
        $identity = [
            'scope' => $envelope->scope->value,
            'authorization_scope_fingerprint' => $envelope->authorizationScopeFingerprint,
            'origin' => $origin->value,
            'actor_user_id' => $actorUserId,
            'kind' => $kind->value,
            'subject_type' => CooInsight::SUBJECT_BUSINESS,
            'subject_id' => (int) $business->id,
            'signal_fingerprint' => $fingerprint,
            'prompt_version' => $promptVersion,
            'policy_version' => $policyVersion,
        ];

        if ($this->readableAnswerExists($identity, $origin)) {
            return CooInsightOutcome::skipped(CooInsightOutcome::ALREADY_CACHED);
        }

        $keyPrefix = 'coo_insight:' . hash('sha256', CooInsightFacts::canonicalJson($identity)) . ':';
        $family = $this->ledger->idempotencyFamily($keyPrefix);

        if ($family['paid'] || $family['in_flight']) {
            return CooInsightOutcome::skipped(CooInsightOutcome::ALREADY_PAID);
        }

        $category = $trigger->category();

        try {
            $result = $this->gateway->complete(new AiRequest(
                workspace: $workspace,
                business: $business,
                category: $category,
                lane: $trigger->lane(),
                route: $this->route($trigger, $facts),
                messages: $this->prompts->messages($facts),
                maxOutputTokens: max(1, (int) config('coo.insight.max_output_tokens')),
                idempotencyKey: $keyPrefix . ($family['attempts'] + 1),
                actorUserId: $actorUserId,
                jsonMode: true,
            ));
        } catch (UniqueConstraintViolationException) {
            // Another worker took this attempt's key first: that worker owns it.
            return CooInsightOutcome::skipped(CooInsightOutcome::ALREADY_PAID);
        }

        if (! $result->ok) {
            if ($result->refusalReason !== null) {
                Log::info('COO insight refused by the AI gateway', ['business_id' => (int) $business->id, 'trigger' => $trigger->value, 'reason' => $result->refusalReason->value]);

                return CooInsightOutcome::refused($result->refusalReason);
            }

            Log::warning('COO insight provider call failed', ['business_id' => (int) $business->id, 'trigger' => $trigger->value]);

            return CooInsightOutcome::skipped(CooInsightOutcome::PROVIDER_FAILED);
        }

        $statements = $this->validator->validate($result->content, $facts);

        if ($statements === null) {
            // Paid for and discarded: nothing a customer can see. The output
            // itself is never logged (§15.6).
            Log::warning('COO insight output rejected by the validator', ['business_id' => (int) $business->id, 'trigger' => $trigger->value]);

            return CooInsightOutcome::skipped(CooInsightOutcome::OUTPUT_REJECTED);
        }

        $entry = $result->ledgerEntry;
        $now = Carbon::now();

        try {
            $insight = CooInsight::query()->create($identity + $envelope->insightColumns() + [
                'period_key' => $facts->periodKey,
                'facts_snapshot' => $facts->forPrompt(),
                'output' => ['statements' => $statements],
                'policy_version' => $policyVersion,
                'model_route' => ($entry?->model_route ?? $this->route($trigger, $facts))->value,
                'provider_model' => $entry?->provider_model,
                'ai_usage_ledger_entry_id' => $entry?->id,
                'generated_at' => $now,
                'expires_at' => $now->copy()->addDays(max(1, (int) config('coo.insight_ttl_days', 7))),
            ]);
        } catch (UniqueConstraintViolationException) {
            return CooInsightOutcome::skipped(CooInsightOutcome::ALREADY_CACHED);
        }

        return CooInsightOutcome::generated($insight);
    }

    /**
     * §9 T-INS-1, preserved under Contract 19's scoped identity — identical
     * facts are never paid for twice.
     *
     * The exact identity is always checked. For a human's own ask there is a
     * second, equally valid answer: a background row with the SAME
     * authorization fingerprint and the SAME facts, which the display rules
     * already let this actor read (§5.9 — a `system` row belongs to nobody and
     * is readable by any exactly-matching scope). Paying again for a sentence
     * the customer can already see would be a regression, so that row counts.
     *
     * The reverse is deliberately NOT true: a background generation never
     * reuses somebody's `on_demand` row, because that row is theirs alone
     * (R-26) and a system row must be readable by every matching scope.
     *
     * @param  array<string, mixed>  $identity
     */
    private function readableAnswerExists(array $identity, CooInsightOrigin $origin): bool
    {
        if ($this->identityExists($identity)) {
            return true;
        }

        if (! $origin->requiresActor()) {
            return false;
        }

        return $this->identityExists(array_merge($identity, [
            'origin' => CooInsightOrigin::System->value,
            'actor_user_id' => null,
        ]));
    }

    /**
     * The identity carries `actor_user_id`, which is NULL for every background
     * row — and `where('actor_user_id', null)` compiles to `= NULL`, which is
     * never true. Null-valued components are therefore matched with whereNull,
     * exactly as the database's own generated surrogate does in the UNIQUE key.
     *
     * @param  array<string, mixed>  $identity
     */
    private function identityExists(array $identity): bool
    {
        $query = CooInsight::query();

        foreach ($identity as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query->exists();
    }

    private function conditionHolds(CooInsightTrigger $trigger, Business $business, CooInsightFacts $facts, string $fingerprint, int $promptVersion, int $policyVersion, CooContextEnvelope $envelope): bool
    {
        return match ($trigger) {
            // E-1, and E-2's "E-1 still holds afterwards".
            CooInsightTrigger::MultiSignalChange, CooInsightTrigger::WorkFinished => count($facts->materialMetricKeys()) >= 2
                && ! $facts->hasDeterministicExplanation(),

            // E-3 — only when the fingerprint differs from the last insight's.
            //
            // Contract 19 §5.8 — "the last insight's" has to mean the last one
            // written for THIS authorization scope. Before 19.A a Business had
            // at most one scope, so filtering by Business alone was
            // unambiguous; now several scopes can hold rows for the same
            // window, and reading another scope's fingerprint here would let
            // one audience's unchanged signals suppress another audience's
            // review, or the reverse. The comparison is therefore made inside
            // the same scope, exactly as the display read is.
            CooInsightTrigger::MonthlyReview => CooInsight::query()
                ->where('scope', $envelope->scope->value)
                ->where('authorization_scope_fingerprint', $envelope->authorizationScopeFingerprint)
                ->where('business_id', (int) $business->id)
                ->where('kind', CooInsightKind::PerformanceDiagnosis->value)
                ->where('period_key', $facts->periodKey)
                ->where('prompt_version', $promptVersion)
                ->where('policy_version', $policyVersion)
                ->orderByDesc('generated_at')
                ->orderByDesc('id')
                ->value('signal_fingerprint') !== $fingerprint,

            // E-4 — the customer asked; the once-per-window limit is enforced
            // where they asked, before this job was ever queued.
            CooInsightTrigger::ExplainThisChange => true,
        };
    }

    /**
     * §13 — `routine` for every COO insight, except an E-3 review with enough
     * material signals, which asks for `reasoning`; the gateway still
     * downgrades that when the budget's headroom is short (§11.2).
     */
    private function route(CooInsightTrigger $trigger, CooInsightFacts $facts): AiModelRoute
    {
        if ($trigger === CooInsightTrigger::MonthlyReview
            && count($facts->materialMetricKeys()) >= max(1, (int) config('coo.insight.reasoning_min_material_signals'))) {
            return AiModelRoute::Reasoning;
        }

        return $this->router->defaultRouteFor($trigger->category());
    }
}
