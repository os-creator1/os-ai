<?php

namespace App\Library\Website;

use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Models\Business;
use Illuminate\Support\Str;

/**
 * Unified Business Home and COO Decision Engine Contract §10.1, §13
 * (slice AI-1). Website Generation + Hosting Slice A contract §14
 * originally called OpenAI directly here; this class now only
 * translates that same messages-in/JSON-out shape into one
 * AiGateway::complete() call — it never constructs a provider client
 * itself (that lives solely in App\Library\Ai\Providers\**).
 *
 * Fails closed, never throws: a disabled/inactive provider, a refused
 * budget, or any provider failure all return null — the caller
 * (WebsiteAiDraftGenerator) already treats null as "generation
 * unavailable", unchanged.
 *
 * Correction 6: null is not the whole story. "The included AI is used up
 * until the period resets" and "the provider is having a bad day" ask the
 * customer to do different things, and collapsing both into one generic
 * outage sentence tells one of them something false. The typed refusal is
 * therefore kept alongside the string: `lastRefusalReason()` reports what
 * the gateway actually said, so the generator and the controller can say
 * the right sentence and skip the retry that cannot help.
 *
 * Deliberately not `final` (unlike its sibling Library\Website services)
 * so tests can bind a Mockery mock in place of a real provider call.
 */
class WebsiteAiGenerationClient
{
    private ?AiRefusalReason $lastRefusalReason = null;

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AiModelRouter $router,
    ) {
    }

    /**
     * The gateway's own refusal reason for the most recent complete(), or
     * null when the last call was not refused (it succeeded, or the
     * provider itself failed).
     */
    public function lastRefusalReason(): ?AiRefusalReason
    {
        return $this->lastRefusalReason;
    }

    /** §11.4 — was the last call refused because the budget is used up? */
    public function lastCallWasBudgetExhausted(): bool
    {
        return in_array(
            $this->lastRefusalReason,
            [AiRefusalReason::BudgetExhausted, AiRefusalReason::InteractiveShareExhausted],
            true,
        );
    }

    public function complete(array $messages, Business $business, ?int $actorUserId = null): ?string
    {
        $this->lastRefusalReason = null;
        $category = AiUsageCategory::WebsiteGeneration;
        $route = $this->router->defaultRouteFor($category);
        $routeConfig = $this->router->config($route);

        $request = new AiRequest(
            workspace: $business->workspace,
            business: $business,
            category: $category,
            lane: AiLane::Product,
            route: $route,
            messages: $messages,
            maxOutputTokens: (int) $routeConfig['max_output_tokens'],
            idempotencyKey: (string) Str::uuid(),
            actorUserId: $actorUserId,
            jsonMode: true,
        );

        $result = $this->gateway->complete($request);
        $this->lastRefusalReason = $result->refusalReason;

        return $result->ok ? $result->content : null;
    }
}
