<?php

namespace App\Library\Coo\Insight;

use App\Enums\Coo\CooInsightKind;
use App\Enums\Coo\CooInsightTrigger;
use App\Enums\Entitlement\PlatformFeature;
use App\Library\Ai\AiBusinessActivityGate;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\AiUsageLedgerManager;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Analytics\AnalyticsDateRange;
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
 *  4. read the facts; invalidate same-window rows whose fingerprint moved (free)
 *  5. the trigger's own condition (E-1/E-2 multi-signal, E-3 fingerprint moved)
 *  6. an insight with this exact identity already exists   → reuse, never pay twice
 *  7. the ledger already paid for, or is holding, this identity → skip
 *  8. ask the gateway — which re-checks entitlement, dormancy and the budget
 *  9. validate the output; cache it only if every statement passes
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

    public function generate(Business $business, CooInsightTrigger $trigger, AnalyticsDateRange $range, ?int $actorUserId = null): CooInsightOutcome
    {
        $business->loadMissing('workspace');
        $workspace = $business->workspace;

        if ($workspace === null) {
            return CooInsightOutcome::skipped(CooInsightOutcome::BUSINESS_UNAVAILABLE);
        }

        if (! (bool) config('services.openai.active')) {
            return CooInsightOutcome::skipped(CooInsightOutcome::AI_DISABLED);
        }

        if (! $this->entitlements->decide($workspace, $business, PlatformFeature::AiCooBasic->value, $actorUserId ?? 0)->allowed) {
            return CooInsightOutcome::skipped(CooInsightOutcome::NOT_ENTITLED);
        }

        if ($trigger->isDormancyGated() && $this->activity->isDormant($business)) {
            return CooInsightOutcome::skipped(CooInsightOutcome::DORMANT);
        }

        $kind = CooInsightKind::PerformanceDiagnosis;
        $promptVersion = (int) config('coo.insight.prompt_version');
        $policyVersion = (int) config('coo.insight.policy_version');

        $facts = $this->factsReader->read($business, $range);
        $fingerprint = $facts->fingerprint($promptVersion, $policyVersion);

        // §9.3 — the next signal read is what invalidates. Free, and done
        // whether or not anything is generated below.
        $this->invalidator->invalidateChangedSignals((int) $business->id, $kind, $facts->periodKey, $fingerprint, $promptVersion, $policyVersion);

        if (! $this->conditionHolds($trigger, $business, $facts, $fingerprint, $promptVersion, $policyVersion)) {
            return CooInsightOutcome::skipped(CooInsightOutcome::CONDITION_NOT_MET);
        }

        $identity = [
            'business_id' => (int) $business->id,
            'kind' => $kind->value,
            'subject_type' => CooInsight::SUBJECT_BUSINESS,
            'subject_id' => (int) $business->id,
            'signal_fingerprint' => $fingerprint,
            'prompt_version' => $promptVersion,
        ];

        if (CooInsight::query()->where($identity)->exists()) {
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
            $insight = CooInsight::query()->create($identity + [
                'workspace_id' => (int) $workspace->id,
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

    private function conditionHolds(CooInsightTrigger $trigger, Business $business, CooInsightFacts $facts, string $fingerprint, int $promptVersion, int $policyVersion): bool
    {
        return match ($trigger) {
            // E-1, and E-2's "E-1 still holds afterwards".
            CooInsightTrigger::MultiSignalChange, CooInsightTrigger::WorkFinished => count($facts->materialMetricKeys()) >= 2
                && ! $facts->hasDeterministicExplanation(),

            // E-3 — only when the fingerprint differs from the last insight's.
            CooInsightTrigger::MonthlyReview => CooInsight::query()
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
