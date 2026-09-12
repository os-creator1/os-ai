<?php

namespace App\Library\Ai;

/**
 * Contract §13 — what a provider adapter hands back to the gateway.
 * `success` is false for any provider-side failure (missing/inactive
 * credentials, HTTP/API error, malformed response) — an adapter never
 * throws for a provider-side failure, mirroring the existing three call
 * sites' own fail-closed shape.
 *
 * Correction 3: a failure carries whatever usage the provider reported.
 * A model that read the whole prompt and then returned content we could
 * not use was still billed for it, and recording that call as free would
 * make the ledger quietly wrong in the provider's favour — and would let
 * a Workspace exceed its cap by repeating calls that "cost nothing".
 * `failure()` therefore takes the same usage fields as `success()`, and
 * `billableUsage()` says whether anything was actually charged.
 */
final readonly class AiCompletionResult
{
    private function __construct(
        public bool $success,
        public ?string $content,
        public ?string $providerModel,
        public int $inputTokens,
        public int $cachedInputTokens,
        public int $outputTokens,
        public ?string $finishReason,
    ) {
    }

    public static function success(
        string $content,
        string $providerModel,
        int $inputTokens,
        int $outputTokens,
        int $cachedInputTokens = 0,
        ?string $finishReason = null,
    ): self {
        return new self(true, $content, $providerModel, $inputTokens, $cachedInputTokens, $outputTokens, $finishReason);
    }

    public static function failure(
        ?string $providerModel = null,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cachedInputTokens = 0,
        ?string $finishReason = null,
    ): self {
        return new self(false, null, $providerModel, $inputTokens, $cachedInputTokens, $outputTokens, $finishReason);
    }

    /**
     * Did the provider report usage it will charge for? Only then does a
     * failed call commit anything; otherwise the whole reservation goes
     * back.
     */
    public function billableUsage(): bool
    {
        return $this->inputTokens > 0 || $this->cachedInputTokens > 0 || $this->outputTokens > 0;
    }
}
