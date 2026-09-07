<?php

namespace App\Library\Business;

/**
 * Website Guided Generation contract §4.3 -- the read-only DTO returned
 * by BusinessKnowledgeProfileManager::completenessCheck(). Never
 * persisted. Every BusinessKnowledgeProfileFieldKey value (§5.1) appears
 * in exactly one of the three sets below.
 */
final readonly class BusinessKnowledgeProfileCompleteness
{
    /**
     * @param  array<int, string>  $missingFieldKeys  no value present at all
     * @param  array<int, string>  $staleFieldKeys  a value is present but unconfirmed, or confirmed and past its field-specific reconfirmAfterDays()
     * @param  array<int, string>  $presentFieldKeys  a value is present, customer_confirmed, and within its reconfirmAfterDays() window
     */
    public function __construct(
        public array $missingFieldKeys,
        public array $staleFieldKeys,
        public array $presentFieldKeys,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->missingFieldKeys === [] && $this->staleFieldKeys === [];
    }
}
