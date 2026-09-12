<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiUsageCategory;

/**
 * Contract §13 — the ONLY reader of `config('ai.routes')` and
 * `config('ai.category_routes')`. No provider or model name, and no
 * price, may appear anywhere else (T-ROUTE-1's architecture test).
 */
final class AiModelRouter
{
    /**
     * The category's default route, per the §13 selection-policy table.
     */
    public function defaultRouteFor(AiUsageCategory $category): AiModelRoute
    {
        $key = config('ai.category_routes.' . $category->value);

        if ($key === null) {
            // A category with no configured route is a configuration bug,
            // never a silent free call — fail loudly rather than guessing.
            throw new \RuntimeException("No route configured for AI category [{$category->value}].");
        }

        return AiModelRoute::from($key);
    }

    public function config(AiModelRoute $route): array
    {
        $config = config('ai.routes.' . $route->value);

        if ($config === null) {
            throw new \RuntimeException("No configuration for AI route [{$route->value}].");
        }

        return $config;
    }

    /**
     * §11.2 — `reasoning` is used only when the remaining Workspace
     * budget is at least `min_headroom_multiple` x its own request cap;
     * otherwise the request is downgraded to `routine`. `routine` and
     * `compaction` have no further downgrade: if either is unaffordable,
     * the gateway refuses outright (§13 "If routine is unaffordable,
     * refuse").
     */
    public function resolveAffordableRoute(AiModelRoute $requested, int $remainingWorkspaceMicrousd): AiModelRoute
    {
        if ($requested !== AiModelRoute::Reasoning) {
            return $requested;
        }

        $reasoningConfig = $this->config(AiModelRoute::Reasoning);
        $requiredHeadroom = $reasoningConfig['max_request_cost_microusd'] * $reasoningConfig['min_headroom_multiple'];

        if ($remainingWorkspaceMicrousd >= $requiredHeadroom) {
            return AiModelRoute::Reasoning;
        }

        return AiModelRoute::Routine;
    }

    /**
     * ceil(input_chars / 3) tokens at the route's input price, plus
     * maxOutputTokens at the route's output price (§10.1 step 3).
     */
    public function estimateCostMicrousd(AiModelRoute $route, array $messages, int $maxOutputTokens): int
    {
        $config = $this->config($route);
        $inputChars = array_sum(array_map(static fn (array $m): int => strlen((string) ($m['content'] ?? '')), $messages));
        $estimatedInputTokens = (int) ceil($inputChars / 3);

        $inputCost = (int) ceil($estimatedInputTokens * $config['input_price_microusd_per_mtok'] / 1_000_000);
        $outputCost = (int) ceil($maxOutputTokens * $config['output_price_microusd_per_mtok'] / 1_000_000);

        return $inputCost + $outputCost;
    }

    public function actualCostMicrousd(AiModelRoute $route, int $inputTokens, int $cachedInputTokens, int $outputTokens): int
    {
        $config = $this->config($route);
        $uncachedInputTokens = max(0, $inputTokens - $cachedInputTokens);

        $inputCost = (int) ceil($uncachedInputTokens * $config['input_price_microusd_per_mtok'] / 1_000_000);
        $cachedCost = (int) ceil($cachedInputTokens * $config['cached_input_price_microusd_per_mtok'] / 1_000_000);
        $outputCost = (int) ceil($outputTokens * $config['output_price_microusd_per_mtok'] / 1_000_000);

        return $inputCost + $cachedCost + $outputCost;
    }
}
