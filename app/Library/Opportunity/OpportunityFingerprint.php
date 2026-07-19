<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Enums\Opportunity\OpportunityWorkerKey;

/**
 * Computes the trusted SHA-256 identity of an Opportunity (RFC-002 §26).
 * Performs no type-specific context normalization itself — $context must
 * already be trusted and pre-normalized by the caller (a fingerprint
 * context_validator) before it reaches this class. For every RFC-002
 * business_advisor type, $context is exactly null.
 */
final class OpportunityFingerprint
{
    public function compute(
        int $businessId,
        OpportunityWorkerKey $workerKey,
        string $type,
        mixed $context,
        int $fingerprintVersion,
    ): string {
        $canonicalObject = [
            'fingerprint_version' => $fingerprintVersion,
            'business_id' => $businessId,
            'worker_key' => $workerKey->value,
            'type' => $type,
            'context' => $context,
        ];

        return hash('sha256', CanonicalJson::encode($canonicalObject));
    }
}
