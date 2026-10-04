<?php

namespace App\Library\GoogleAds\Mutations;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\DTO\GoogleAds\GoogleAdsMutationResult;
use App\Enums\GoogleAds\GoogleAdsMutationKind;
use App\Library\GoogleAds\Contracts\GoogleAdsMutationClient;
use Closure;

/**
 * Internal to the mutation service: one fully-resolved, validated mutation.
 * Everything in it was derived INSIDE the Business's account; `send` makes
 * the single provider call and `applyLocally` writes the confirmed result.
 */
final readonly class MutationPlan
{
    /**
     * @param  array<string, mixed>|null  $params
     * @param  Closure(GoogleAdsMutationClient, GoogleAdsAccessContext): GoogleAdsMutationResult  $send
     * @param  Closure(GoogleAdsMutationResult): void  $applyLocally
     */
    public function __construct(
        public GoogleAdsMutationKind $kind,
        public string $targetType,
        public int $targetLocalId,
        public string $targetResourceName,
        public ?string $requestedState,
        public ?array $params,
        public string $dedupeKey,
        public string $summary,
        public Closure $send,
        public Closure $applyLocally,
    ) {
    }
}
