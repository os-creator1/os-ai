<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown when a Business's own currency_code resolves to zero
 * ("not_found" — covers missing/malformed/unknown/inactive-only matches)
 * or more than one ("ambiguous") active currencies row (RFC-005 M1
 * contract §5.5, corrected in Correction Round 1). Never substitutes a
 * fallback currency — the caller must leave no wallet/ledger state behind
 * and the operator must correct the Business's currency data before retry.
 */
class BusinessCurrencyUnresolvableException extends RuntimeException
{
    public const CLASSIFICATION_NOT_FOUND = 'not_found';
    public const CLASSIFICATION_AMBIGUOUS = 'ambiguous';

    public function __construct(
        public readonly int $businessId,
        public readonly string $classification,
    ) {
        parent::__construct(
            "Business #{$businessId}'s currency could not be resolved to exactly one active currency ({$classification})."
        );
    }
}
