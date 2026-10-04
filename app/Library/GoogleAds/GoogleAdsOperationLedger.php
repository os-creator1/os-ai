<?php

namespace App\Library\GoogleAds;

use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOperationLedger;
use App\Models\BusinessGoogleOperation;

/**
 * Google Ads Module V1 contract §15 / D8 — the Ads face of the product-neutral
 * `business_google_operations` ledger.
 *
 * COMPOSITION, NOT A COPY. open() and succeed() delegate to the shared
 * GoogleBusinessProfileOperationLedger (which holds the local-operation-key,
 * fingerprint and summary-bounding logic and is product-neutral despite its
 * name), so that state logic exists once. The two failure writers below are
 * the only new code, and they exist because the shared ledger's failure
 * methods are typed to GoogleBusinessProfileProviderException and clamp to the
 * GBP failure vocabulary; Ads needs `not_found` / `validation` and its own
 * exception. They map status exactly as the shared ledger does:
 *
 *   deferrable (rate limited / budget)  -> deferred (not a failure)
 *   ambiguous (mutate sent, no answer)  -> unknown  (never replayed)
 *   anything else                       -> failed
 *
 * Nothing that reaches this class may carry a token, authorization code,
 * provider payload or provider error text: `summary` is a bounded
 * own-vocabulary string and the classification is a closed set.
 */
final class GoogleAdsOperationLedger
{
    public function __construct(private readonly GoogleBusinessProfileOperationLedger $shared)
    {
    }

    /**
     * @param  array<int, string>  $fingerprintParts  method + resource name only — never anything containing provider content.
     */
    public function open(
        int $businessId,
        GoogleOperationType $type,
        ?int $actorUserId = null,
        ?string $summary = null,
        array $fingerprintParts = [],
    ): BusinessGoogleOperation {
        return $this->shared->open(
            businessId: $businessId,
            type: $type,
            actorUserId: $actorUserId,
            summary: $summary,
            fingerprintParts: $fingerprintParts,
        );
    }

    public function succeed(BusinessGoogleOperation $operation, ?string $summary = null, ?string $providerReference = null): BusinessGoogleOperation
    {
        return $this->shared->succeed($operation, $summary, $providerReference);
    }

    public function fail(BusinessGoogleOperation $operation, GoogleAdsProviderException $exception, ?string $summary = null): BusinessGoogleOperation
    {
        $status = match (true) {
            $exception->isDeferrable() => GoogleOperationStatus::Deferred,
            $exception->isAmbiguous() => GoogleOperationStatus::Unknown,
            default => GoogleOperationStatus::Failed,
        };

        return $this->close($operation, $status, $exception->classification, $summary);
    }

    /**
     * A local (non-provider) failure, e.g. the connection returned no
     * refresh token. Never carries a database driver message.
     */
    public function failLocally(BusinessGoogleOperation $operation, string $classification, ?string $summary = null): BusinessGoogleOperation
    {
        return $this->close(
            $operation,
            GoogleOperationStatus::Failed,
            in_array($classification, GoogleAdsProviderException::CLASSIFICATIONS, true)
                ? $classification
                : GoogleAdsProviderException::UNEXPECTED_RESPONSE,
            $summary,
        );
    }

    private function close(BusinessGoogleOperation $operation, GoogleOperationStatus $status, string $classification, ?string $summary): BusinessGoogleOperation
    {
        $attributes = [
            'status' => $status,
            'failure_classification' => $classification,
            'completed_at' => now(),
        ];

        if ($summary !== null && trim($summary) !== '') {
            $attributes['summary'] = mb_substr(trim($summary), 0, 255);
        }

        $operation->forceFill($attributes)->save();

        return $operation;
    }
}
