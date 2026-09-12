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
     * Correction 9 — the deterministic upper bound on a request's input
     * tokens, for the exact shape being sent.
     *
     * Content bytes alone under-count: a chat request is not a string, it
     * is a sequence of messages, and the provider tokenises each message's
     * role and its structural delimiters too. Twenty one-word messages cost
     * far more than one twenty-word message, and an estimator that only
     * divides total characters by three would reserve a fraction of what
     * such a request is really billed — which, under concurrent
     * hard-enforced calls, is exactly how committed + reserved creeps past
     * a cap the customer was promised.
     *
     * So each message costs its own content plus a fixed framing
     * allowance, and the whole request carries one more for the reply
     * priming. The numbers are config, and deliberately generous: an
     * over-reservation is released the moment the provider reports what it
     * really used, while an under-reservation is a cap breach that cannot
     * be taken back.
     */
    public function estimateInputTokens(array $messages): int
    {
        $charsPerToken = max(1, (int) config('ai.estimator.chars_per_token', 3));
        $perMessageTokens = max(0, (int) config('ai.estimator.per_message_framing_tokens', 8));
        $perRequestTokens = max(0, (int) config('ai.estimator.per_request_framing_tokens', 8));

        $tokens = $perRequestTokens;

        foreach ($messages as $message) {
            $content = (string) ($message['content'] ?? '');
            $role = (string) ($message['role'] ?? '');

            $tokens += (int) ceil((strlen($content) + strlen($role)) / $charsPerToken) + $perMessageTokens;
        }

        return $tokens;
    }

    /**
     * The estimated input tokens at the route's input price, plus
     * maxOutputTokens at its output price (§10.1 step 3).
     */
    public function estimateCostMicrousd(AiModelRoute $route, array $messages, int $maxOutputTokens): int
    {
        $config = $this->config($route);
        $estimatedInputTokens = $this->estimateInputTokens($messages);

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
