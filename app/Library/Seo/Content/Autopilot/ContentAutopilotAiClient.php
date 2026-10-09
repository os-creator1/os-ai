<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Library\Ai\AiGateway;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Models\Business;

/**
 * Content Autopilot's one door to a model. Everything goes through AiGateway under the `content_autopilot` category,
 * so the Workspace budget, the per-Business hard ceiling (AiBusinessCategoryCeiling) and the ledger all apply.
 *
 * Two routes, deliberately:
 *  - write(): the stronger `content_writer` route — the article draft and meaningful rewrites ONLY;
 *  - assist(): the cheap `routine` route — outline/question help, classification, the soft-finding judge.
 *
 * Every call carries an explicit idempotency key derived from the Autopilot decision and step (never random), so a
 * retried job can never pay for the same step twice. A refusal returns null and records why; callers turn
 * budget refusals into a "deferred until the next period" outcome instead of retrying.
 */
class ContentAutopilotAiClient
{
    private ?AiRefusalReason $lastRefusalReason = null;

    public function __construct(private readonly AiGateway $gateway)
    {
    }

    public function write(array $messages, Business $business, string $idempotencyKey, int $maxOutputTokens, ?int $actorUserId = null): ?string
    {
        return $this->call(AiModelRoute::ContentWriter, $messages, $business, $idempotencyKey, $maxOutputTokens, $actorUserId);
    }

    public function assist(array $messages, Business $business, string $idempotencyKey, int $maxOutputTokens, ?int $actorUserId = null): ?string
    {
        return $this->call(AiModelRoute::Routine, $messages, $business, $idempotencyKey, $maxOutputTokens, $actorUserId);
    }

    public function lastRefusalReason(): ?AiRefusalReason
    {
        return $this->lastRefusalReason;
    }

    /**
     * True when the call was refused because money ran out — the Workspace budget, its interactive share, or the
     * Business's Content Autopilot ceiling. These are "wait for the next period", never "try again now".
     */
    public function lastCallWasBudgetRefusal(): bool
    {
        return in_array($this->lastRefusalReason, [
            AiRefusalReason::BudgetExhausted,
            AiRefusalReason::InteractiveShareExhausted,
            AiRefusalReason::CategoryCeilingReached,
        ], true);
    }

    private function call(AiModelRoute $route, array $messages, Business $business, string $idempotencyKey, int $maxOutputTokens, ?int $actorUserId): ?string
    {
        $this->lastRefusalReason = null;

        $result = $this->gateway->complete(AiRequest::forWorkspace(
            workspace: $business->workspace,
            business: $business,
            category: AiUsageCategory::ContentAutopilot,
            lane: AiLane::Product,
            route: $route,
            messages: $messages,
            maxOutputTokens: $maxOutputTokens,
            idempotencyKey: $idempotencyKey,
            actorUserId: $actorUserId,
            jsonMode: true,
        ));

        $this->lastRefusalReason = $result->refusalReason;

        return $result->ok ? $result->content : null;
    }
}
