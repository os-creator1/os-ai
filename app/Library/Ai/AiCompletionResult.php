<?php

namespace App\Library\Ai;

/**
 * Contract §13 — what a provider adapter hands back to the gateway.
 * `success` is false for any provider-side failure (missing/inactive
 * credentials, HTTP/API error, malformed response) — an adapter never
 * throws for a provider-side failure, mirroring the existing three call
 * sites' own fail-closed shape.
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

    public static function failure(): self
    {
        return new self(false, null, null, 0, 0, 0, null);
    }
}
