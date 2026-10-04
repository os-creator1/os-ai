<?php

namespace App\Library\GoogleAds\Mutations;

use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;

/**
 * Contract §6 step 7 — the exact facts a confirmation must show before a
 * negative keyword is sent. Produced without any provider call.
 */
final readonly class NegativeKeywordPreview
{
    public function __construct(
        public string $term,
        public GoogleAdsKeywordLevel $scope,
        public string $scopeLabel,
        public string $parentName,
        public GoogleAdsMatchType $matchType,
        public bool $alreadyExcluded,
    ) {
    }

    /** @return array{term: string, scope: string, scope_label: string, parent_name: string, match_type: string, already_excluded: bool} */
    public function toArray(): array
    {
        return [
            'term' => $this->term,
            'scope' => $this->scope->value,
            'scope_label' => $this->scopeLabel,
            'parent_name' => $this->parentName,
            'match_type' => $this->matchType->value,
            'already_excluded' => $this->alreadyExcluded,
        ];
    }
}
