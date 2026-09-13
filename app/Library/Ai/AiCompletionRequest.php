<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiModelRoute;

/**
 * Contract §13 — what the gateway hands to a provider adapter. Carries
 * the resolved route's config (model, token caps) so an adapter never
 * reads `config('ai.*')` itself; only `AiModelRouter` does.
 */
final readonly class AiCompletionRequest
{
    public function __construct(
        public AiModelRoute $route,
        public string $provider,
        public string $model,
        public array $messages,
        public int $maxOutputTokens,
        public bool $jsonMode = false,
    ) {
    }
}
