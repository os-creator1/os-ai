<?php

namespace App\Library\AgencyProspecting;

use App\Library\AgencyProspecting\Contracts\AgencyProspectingAiClient;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Unified Business Home and COO Decision Engine Contract §10.1, §10.3a
 * (slice AI-1). Now the ONLY thing this class does is translate the raw
 * messages-in/text-out shape AgencyProspectingRespondJob already speaks
 * into one AiGateway::complete() call — it never constructs a provider
 * client itself (that lives solely in App\Library\Ai\Providers\**).
 *
 * Agency prospecting has no single Business, so this is checked against
 * the Workspace cap only (§10.3a), exactly as the contract specifies.
 *
 * Fails closed, never throws: a disabled/inactive provider, a refused
 * budget, or any provider failure all return null — the caller's
 * existing fallback (no automatic reply, flagged for a human) is
 * unchanged.
 */
final class OpenAiAgencyProspectingClient implements AgencyProspectingAiClient
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AiModelRouter $router,
    ) {
    }

    public function complete(array $messages, Workspace $workspace, ?int $actorUserId = null): ?string
    {
        $category = AiUsageCategory::AgencyProspectReply;
        $route = $this->router->defaultRouteFor($category);
        $routeConfig = $this->router->config($route);

        $request = new AiRequest(
            workspace: $workspace,
            business: null,
            category: $category,
            lane: AiLane::Product,
            route: $route,
            messages: $messages,
            maxOutputTokens: (int) $routeConfig['max_output_tokens'],
            idempotencyKey: (string) Str::uuid(),
            actorUserId: $actorUserId,
        );

        $result = $this->gateway->complete($request);

        return $result->ok ? $result->content : null;
    }
}
