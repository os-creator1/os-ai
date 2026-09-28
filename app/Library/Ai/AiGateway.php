<?php

namespace App\Library\Ai;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiScope;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AiUsagePeriod;

/**
 * Contract §10.1 — `AiGateway::complete(AiRequest): AiResult` is the
 * ONLY way the application reaches a model (§19.3, D-6, T-AI-GATE-1).
 * Every call is gated, priced, reserved, executed and settled through
 * this one path, whatever the caller — a COO job, a controller, or a
 * queued reply worker. Contract §5.7a, §6.7 — Platform scope goes
 * through this SAME path: one gateway, one router, one reservation
 * protocol, one ledger, one settle path, no second anything.
 */
final class AiGateway
{
    public function __construct(
        private readonly AiBudgetPolicyResolver $policyResolver,
        private readonly AiModelRouter $router,
        private readonly AiUsageLedgerManager $ledger,
        private readonly AiCompletionClient $completionClient,
        private readonly EntitlementManager $entitlements,
        private readonly AiBusinessActivityGate $activityGate,
        private readonly PlatformAiAuthority $platformAuthority,
    ) {
    }

    public function complete(AiRequest $request): AiResult
    {
        $isPlatform = $request->scope === AiScope::Platform;

        // Gate 1 (§10.1 step 1): the platform kill switch. No policy is
        // even resolved, and nothing is recorded — this is the one
        // circumstance where AI is entirely off, not merely out of
        // budget.
        if (! config('services.openai.active')) {
            return AiResult::refused(AiRefusalReason::AiDisabled);
        }

        // Gate 2 (§10.1 step 2): resolve the budget policy. Platform scope
        // (§5.7a D) resolves its own finite, config-derived cap — no
        // customer plan lookup occurs, and EntitlementManager is not
        // consulted at all. An unassigned, inactive or suspended plan (or
        // an absent/non-positive Platform cap) resolves to a zero cap
        // (T-BUD-6). This is refused unconditionally, even while §19.3's
        // observation-mode bypass is on for the three pre-existing
        // categories: that bypass exists to preserve EXISTING behaviour for
        // a Workspace that legitimately has a budget it is merely
        // exceeding, never to let a Workspace with no valid plan (or a
        // misconfigured Platform cap) through "for observation".
        $policy = $isPlatform
            ? $this->policyResolver->resolveForPlatform()
            : $this->policyResolver->resolveFor($request->workspace);

        if ($policy->workspaceCapMicrousd === 0) {
            return AiResult::refused(AiRefusalReason::BudgetExhausted);
        }

        // Gate 2a (§8.1, §10.1 step 1) — Correction 1. The COO categories
        // are a product the Business must actually be entitled to, and the
        // canonical entitlement system is the only thing that may say so.
        // Refused here, before any reservation and before any provider
        // call. The three categories that pre-date the gateway are
        // deliberately NOT put behind this feature: they shipped without
        // it, and moving them behind it would remove working product.
        // Never consulted at Platform scope (§5.7a D): there is no
        // Business to entitle, and no fabricated one is created (R-19).
        if (! $isPlatform && $request->category->requiresCooEntitlement()) {
            if ($request->business === null) {
                // `ai_coo_basic` is a Business-scoped feature; COO work with
                // no Business has nothing to entitle.
                return AiResult::refused(AiRefusalReason::EntitlementMissing);
            }

            $decision = $this->entitlements->decide(
                $request->workspace,
                $request->business,
                PlatformFeature::AiCooBasic->value,
                $request->actorUserId ?? 0,
            );

            if (! $decision->allowed) {
                return AiResult::refused(AiRefusalReason::EntitlementMissing);
            }
        }

        // Gate 2b (§8.1, C-8) — Correction 2. Scheduled, Business-scoped
        // product work is never spent on a dormant Business. A customer's
        // own explicit request (the interactive lane) is exempt from
        // dormancy alone, and Workspace-level work has no Business whose
        // dormancy could be asked about.
        if ($request->business !== null
            && $request->lane === AiLane::Product
            && $request->category->isDormancyGated()
            && $this->activityGate->isDormant($request->business)) {
            return AiResult::refused(AiRefusalReason::Dormant);
        }

        // §11.2 downgrade heuristic — an unlocked peek, not the
        // authoritative check.
        //
        // Correction 10: the headroom is measured against the cap this
        // period is actually being enforced against. An open period keeps
        // the cap it snapshotted when it opened, so a config change must
        // not make routing believe there is room that reserve() will then
        // refuse — or, worse, withhold reasoning that the period could
        // still afford. A period that does not exist yet has no snapshot,
        // and the newly configured cap is the right answer for it.
        $primaryScopeType = $isPlatform ? AiUsagePeriod::SCOPE_PLATFORM : AiUsagePeriod::SCOPE_WORKSPACE;
        $primaryScopeId = $isPlatform ? AiUsageLedgerManager::platformScopeId() : $request->workspace->id;
        $enforcedCap = $this->ledger->enforcedCapMicrousd($primaryScopeType, $primaryScopeId, $policy);
        $peeked = $this->ledger->peekCommittedAndReserved($primaryScopeType, $primaryScopeId, $policy->periodKey);
        $remaining = max(0, $enforcedCap - $peeked);
        $route = $this->router->resolveAffordableRoute($request->route, $remaining);

        $routeConfig = $this->router->config($route);
        $maxOutputTokens = min($request->maxOutputTokens, (int) $routeConfig['max_output_tokens']);

        // Gate 3a (§11.2) — Correction 4. The route's own input ceiling is
        // enforced before anything is reserved or sent. Oversized input is
        // refused rather than silently truncated: these callers' prompts
        // are structured (a website schema, a classification instruction,
        // a campaign brief), and quietly cutting one produces a confidently
        // wrong answer instead of an honest refusal. Trimming belongs to a
        // caller whose own contract defines what may be dropped.
        $estimatedInputTokens = $this->router->estimateInputTokens($request->messages);

        if ($estimatedInputTokens > (int) $routeConfig['max_input_tokens']) {
            return AiResult::refused(AiRefusalReason::InputTooLarge);
        }

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

        // Gate 3b (§5.7a C, §6.7, R-28) — the SECOND authority boundary, run
        // fresh, immediately before the reservation and therefore before any
        // provider call. Boundary 1 (no client-selectable actor id; the
        // actor is derived only from the authenticated server principal)
        // lives at the HTTP layer that constructs a Platform AiRequest, not
        // here. This is independent of that boundary and does not replace
        // it: it re-reads the named User row's `is_admin` right now, so a
        // miswired route, an authenticated non-admin, or an admin demoted or
        // deleted between enqueue and this very call is refused before
        // anything is reserved and before any provider call — no authority
        // is ever trusted from the request itself.
        if ($isPlatform && ! $this->platformAuthority->authorize((int) $request->actorUserId)) {
            return AiResult::refused(AiRefusalReason::PlatformAuthorityDenied);
        }

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
            // Correction 3 — usage the provider reported before failing was
            // really billed, so it is committed exactly once and only the
            // remainder of the reservation is released. A failure that
            // reported nothing releases the whole hold.
            $failedCostMicrousd = $completionResult->billableUsage()
                ? $this->router->actualCostMicrousd(
                    $route,
                    $completionResult->inputTokens,
                    $completionResult->cachedInputTokens,
                    $completionResult->outputTokens,
                )
                : 0;

            $settled = $this->ledger->releaseAsFailed(
                $entry,
                $failedCostMicrousd,
                $completionResult->providerModel,
                $completionResult->inputTokens,
                $completionResult->cachedInputTokens,
                $completionResult->outputTokens,
            );

            return AiResult::providerFailure($settled);
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
