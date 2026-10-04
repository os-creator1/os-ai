<?php

namespace App\Library\MetaAds;

use App\Enums\MetaAds\MetaOperationStatus;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Models\BusinessMetaOperation;
use Illuminate\Support\Str;

/**
 * Meta Ads Module V1 contract 24 §3 / §7 — the ONE writer of
 * business_meta_operations. Nothing else may insert or update that table.
 *
 * Meta has its own ledger (contract 24 M2) rather than reusing the Google one,
 * because the Google ledger is Google-typed and its per-Business call counter
 * would otherwise mix Meta and Google calls. The behaviour is deliberately the
 * same as GoogleAdsOperationLedger: a local operation key exists BEFORE the
 * provider is called, and failures map to status from the exception, not from
 * the caller:
 *
 *   deferrable (rate limited / our budget) -> deferred (not a failure)
 *   ambiguous (mutate sent, no answer)     -> unknown  (never replayed)
 *   anything else                          -> failed
 *
 * Nothing that reaches this class may carry a token, authorization code,
 * provider payload or provider error text: `summary` is a bounded
 * own-vocabulary string and the classification is a closed set.
 */
final class MetaAdsOperationLedger
{
    public const NOT_APPLIED = 'not_applied';

    /**
     * @param  array<int, string>  $fingerprintParts  method + object kind only — never anything containing provider content.
     */
    public function open(
        int $businessId,
        MetaOperationType $type,
        ?int $actorUserId = null,
        ?string $summary = null,
        array $fingerprintParts = [],
    ): BusinessMetaOperation {
        return BusinessMetaOperation::create([
            'business_id' => $businessId,
            'operation_type' => $type,
            'local_operation_key' => $type->value . ':' . $businessId . ':' . Str::uuid(),
            'request_fingerprint' => $fingerprintParts === []
                ? null
                : hash('sha256', implode('|', $fingerprintParts)),
            'status' => MetaOperationStatus::Pending,
            'actor_user_id' => $actorUserId,
            'summary' => $this->bounded($summary),
            'started_at' => now(),
        ]);
    }

    public function succeed(BusinessMetaOperation $operation, ?string $summary = null, ?string $providerReference = null): BusinessMetaOperation
    {
        $attributes = [
            'status' => MetaOperationStatus::Succeeded,
            'completed_at' => now(),
        ];

        if ($summary !== null) {
            $attributes['summary'] = $this->bounded($summary);
        }

        // Written ONLY when the provider actually supplied one; never invented.
        if ($providerReference !== null && $providerReference !== '') {
            $attributes['provider_operation_reference'] = mb_substr($providerReference, 0, 191);
        }

        $operation->forceFill($attributes)->save();

        return $operation;
    }

    public function fail(BusinessMetaOperation $operation, MetaProviderException $exception, ?string $summary = null): BusinessMetaOperation
    {
        $status = match (true) {
            $exception->isDeferrable() => MetaOperationStatus::Deferred,
            $exception->isAmbiguous() => MetaOperationStatus::Unknown,
            default => MetaOperationStatus::Failed,
        };

        return $this->close($operation, $status, $exception->classification, $summary);
    }

    /** A local (non-provider) failure. Never carries a database driver message. */
    public function failLocally(BusinessMetaOperation $operation, string $classification, ?string $summary = null): BusinessMetaOperation
    {
        return $this->close(
            $operation,
            MetaOperationStatus::Failed,
            in_array($classification, MetaProviderException::CLASSIFICATIONS, true)
                ? $classification
                : MetaProviderException::UNEXPECTED_RESPONSE,
            $summary,
        );
    }

    /**
     * Mutation reconciliation only: a previously `unknown` status change whose
     * freshly synced Meta state shows it was NOT applied.
     */
    public function failNotApplied(BusinessMetaOperation $operation, ?string $summary = null): BusinessMetaOperation
    {
        return $this->close($operation, MetaOperationStatus::Failed, self::NOT_APPLIED, $summary);
    }

    private function close(BusinessMetaOperation $operation, MetaOperationStatus $status, string $classification, ?string $summary): BusinessMetaOperation
    {
        $attributes = [
            'status' => $status,
            'failure_classification' => $classification,
            'completed_at' => now(),
        ];

        if ($summary !== null && trim($summary) !== '') {
            $attributes['summary'] = $this->bounded($summary);
        }

        $operation->forceFill($attributes)->save();

        return $operation;
    }

    private function bounded(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        $trimmed = trim($summary);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 255);
    }
}
