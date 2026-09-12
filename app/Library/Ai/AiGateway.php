<?php

namespace App\Library\Ai;

use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Enums\AiRefusalReason;

/**
 * Contract §10.1 — `AiGateway::complete(AiRequest): AiResult` is the
 * ONLY way the application reaches a model (§19.3, D-6, T-AI-GATE-1).
 * Every call is gated, priced, reserved, executed and settled through
 * this one path, whatever the caller — a COO job, a controller, or a
 * queued reply worker.
 */
final class AiGateway
{
    public function __construct(
        private readonly AiBudgetPolicyResolver $policyResolver,
        private readonly AiModelRouter $router,
        private readonly AiUsageLedgerManager $ledger,
        private readonly AiCompletionClient $completionClient,
    ) {
    }

    public function complete(AiRequest $request): AiResult
    {
        // Gate 1 (§10.1 step 1): the platform kill switch. No policy is
        // even resolved, and nothing is recorded — this is the one
        // circumstance where AI is entirely off, not merely out of
        // budget.
        if (! config('services.openai.active')) {
            return AiResult::refused(AiRefusalReason::AiDisabled);
        }

        // Gate 2 (§10.1 step 2): resolve the Workspace's budget policy.
        // An unassigned, inactive or suspended plan resolves to a zero
        // cap (T-BUD-6). This is refused unconditionally, even while
        // §19.3's observation-mode bypass is on for the three
        // pre-existing categories: that bypass exists to preserve
        // EXISTING behaviour for a Workspace that legitimately has a
        // budget it is merely exceeding, never to let a Workspace with
        // no valid plan at all through "for observation".
        $policy = $this->policyResolver->resolveFor($request->workspace);

        if ($policy->workspaceCapMicrousd === 0) {
            return AiResult::refused(AiRefusalReason::BudgetExhausted);
        }

        // §11.2 downgrade heuristic — an unlocked peek, not the
        // authoritative check.
        $peeked = $this->ledger->peekWorkspaceCommittedAndReserved($request->workspace->id, $policy->periodKey);
        $remaining = max(0, $policy->workspaceCapMicrousd - $peeked);
        $route = $this->router->resolveAffordableRoute($request->route, $remaining);

        $routeConfig = $this->router->config($route);
        $maxOutputTokens = min($request->maxOutputTokens, (int) $routeConfig['max_output_tokens']);

        // Gate 3 (§10.1 step 3): the request itself, at this route's
        // price, must fit under the route's own per-request cap.
        $estimatedCostMicrousd = $this->router->estimateCostMicrousd($route, $request->messages, $maxOutputTokens);

        if ($estimatedCostMicrousd > (int) $routeConfig['max_request_cost_microusd']) {
            return AiResult::refused(AiRefusalReason::RequestTooExpensive);
        }

        $estimate = new AiUsageCostEstimate(
            route: $route,
            provider: (string) $routeConfig['provider'],
            model: (string) $routeConfig['model'],
            priceVersion: (int) $routeConfig['price_version'],
            costMicrousd: $estimatedCostMicrousd,
            maxRequestCostMicrousd: (int) $routeConfig['max_request_cost_microusd'],
        );

        // §19.3 rule 3 / T-BUD-8: the three pre-existing categories may
        // run over cap, unrefused, for one observation window. New COO
        // categories are never eligible for this bypass.
        $bypassCapEnforcement = ! $request->category->isAlwaysHardEnforced()
            && ! (bool) config('ai.enforce_budgets_for_existing_categories');

        // Gate 4 (§10.1 step 4): the authoritative, locked reserve.
        ['entry' => $entry, 'refused' => $refused] = $this->ledger->reserve($request, $policy, $estimate, $bypassCapEnforcement);

        if ($refused) {
            return AiResult::refused($entry->refusal_reason, $entry);
        }

        // §10.1 step 5: the provider call itself runs OUTSIDE any DB
        // transaction — reserve() above already committed its own
        // transaction before this line runs.
        $completionRequest = new AiCompletionRequest(
            route: $route,
            provider: $estimate->provider,
            model: $estimate->model,
            messages: $request->messages,
            maxOutputTokens: $maxOutputTokens,
            jsonMode: $request->jsonMode,
        );

        $completionResult = $this->completionClient->complete($completionRequest);

        if (! $completionResult->success) {
            $this->ledger->releaseAsFailed($entry);

            return AiResult::providerFailure($entry);
        }

        // §10.1 step 6: commit actual usage x this call's own price
        // version, release the difference.
        $actualCostMicrousd = $this->router->actualCostMicrousd(
            $route,
            $completionResult->inputTokens,
            $completionResult->cachedInputTokens,
            $completionResult->outputTokens,
        );

        $committedEntry = $this->ledger->commitActual(
            $entry,
            $completionResult->providerModel ?? $estimate->model,
            $completionResult->inputTokens,
            $completionResult->cachedInputTokens,
            $completionResult->outputTokens,
            $actualCostMicrousd,
        );

        return AiResult::success($completionResult->content, $committedEntry);
    }
}
