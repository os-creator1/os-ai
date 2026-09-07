<?php

namespace App\Library\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Models\BusinessGoogleOperation;
use Illuminate\Support\Str;

/**
 * GBP Slice A contract §11.3 / §24.4 / §27 — the ONE writer of
 * business_google_operations. Nothing else in the codebase may insert or
 * update that table.
 *
 * The ledger is the whole idempotency mechanism: Google publishes no
 * idempotency key for these APIs, so a local key is generated and the row
 * INSERTED **before** the provider call, exactly like
 * business_funding_attempts.local_idempotency_key.
 *
 * Nothing that reaches this class may carry a token, an authorization
 * code, raw state, a provider payload, provider error text, a Google
 * Content value, a street address or reviewer PII. `summary` is a bounded,
 * own-vocabulary string and `failure_classification` is a closed set
 * (contract §11.3.4, security criterion G-10).
 */
final class GoogleBusinessProfileOperationLedger
{
    /**
     * Opens an operation. The unique local_operation_key exists BEFORE any
     * provider call, so a duplicate attempt collides rather than
     * double-calling Google.
     *
     * @param  array<int, string>  $fingerprintParts  method + resource name + read-mask fields ONLY — never anything containing Content.
     */
    public function open(
        int $businessId,
        GoogleOperationType $type,
        ?int $actorUserId = null,
        ?int $googleLocationId = null,
        ?string $summary = null,
        array $fingerprintParts = [],
    ): BusinessGoogleOperation {
        return BusinessGoogleOperation::create([
            'business_id' => $businessId,
            'business_google_location_id' => $googleLocationId,
            'operation_type' => $type,
            'local_operation_key' => $type->value . ':' . $businessId . ':' . Str::uuid(),
            'request_fingerprint' => $fingerprintParts === []
                ? null
                : hash('sha256', implode('|', $fingerprintParts)),
            'status' => GoogleOperationStatus::Pending,
            'actor_user_id' => $actorUserId,
            'summary' => $this->boundedSummary($summary),
            'started_at' => now(),
        ]);
    }

    public function succeed(BusinessGoogleOperation $operation, ?string $summary = null, ?string $providerReference = null): BusinessGoogleOperation
    {
        $attributes = [
            'status' => GoogleOperationStatus::Succeeded,
            'completed_at' => now(),
        ];

        if ($summary !== null) {
            $attributes['summary'] = $this->boundedSummary($summary);
        }

        // Contract §24.4 — written ONLY when the provider actually
        // supplied one. No synthetic value is ever invented.
        if ($providerReference !== null && $providerReference !== '') {
            $attributes['provider_operation_reference'] = mb_substr($providerReference, 0, 191);
        }

        $operation->forceFill($attributes)->save();

        return $operation;
    }

    /**
     * Records a normalized provider failure. Contract §24.5/§24.6 decide
     * the STATUS from the classification, not the caller:
     *   - rate limited  -> deferred (not a failure)
     *   - timeout       -> unknown  (ambiguous; never blindly replayed)
     *   - anything else -> failed
     */
    public function fail(BusinessGoogleOperation $operation, GoogleBusinessProfileProviderException $exception, ?string $summary = null): BusinessGoogleOperation
    {
        $status = match (true) {
            $exception->isDeferrable() => GoogleOperationStatus::Deferred,
            $exception->isAmbiguous() => GoogleOperationStatus::Unknown,
            default => GoogleOperationStatus::Failed,
        };

        $attributes = [
            'status' => $status,
            'failure_classification' => $exception->classification,
            'completed_at' => now(),
        ];

        if ($summary !== null) {
            $attributes['summary'] = $this->boundedSummary($summary);
        }

        $operation->forceFill($attributes)->save();

        return $operation;
    }

    /**
     * Records a local (non-provider) failure — e.g. a unique-constraint
     * collision on binding. Never carries a database driver message.
     */
    public function failLocally(BusinessGoogleOperation $operation, string $classification, ?string $summary = null): BusinessGoogleOperation
    {
        $operation->forceFill([
            'status' => GoogleOperationStatus::Failed,
            'failure_classification' => in_array($classification, BusinessGoogleOperation::FAILURE_CLASSIFICATIONS, true)
                ? $classification
                : BusinessGoogleOperation::FAILURE_UNEXPECTED_RESPONSE,
            'summary' => $this->boundedSummary($summary),
            'completed_at' => now(),
        ])->save();

        return $operation;
    }

    /**
     * Contract §11.3.4 — hard length bound. Callers are responsible for
     * passing only own-vocabulary text; this is the belt to that braces.
     */
    private function boundedSummary(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        $trimmed = trim($summary);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 255);
    }
}
