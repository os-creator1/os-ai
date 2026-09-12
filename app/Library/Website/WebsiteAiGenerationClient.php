<?php

namespace App\Library\Website;

use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Enums\AiLane;
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
 * Deliberately not `final` (unlike its sibling Library\Website services)
 * so tests can bind a Mockery mock in place of a real provider call.
 */
class WebsiteAiGenerationClient
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AiModelRouter $router,
    ) {
    }

    public function complete(array $messages, Business $business, ?int $actorUserId = null): ?string
    {
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

        return $result->ok ? $result->content : null;
    }
}
