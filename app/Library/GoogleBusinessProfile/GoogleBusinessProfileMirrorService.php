<?php

namespace App\Library\GoogleBusinessProfile;

use App\DTO\GoogleBusinessProfile\GoogleLocationProfile;
use App\DTO\GoogleBusinessProfile\GoogleMirrorRefreshResult;
use App\DTO\GoogleBusinessProfile\GoogleVoiceOfMerchantState;
use App\Enums\GoogleBusinessProfile\GoogleLocationHealth;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileMirrorRefreshed;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleLocation;
use Illuminate\Support\Facades\DB;

/**
 * GBP Slice A contract §21 / §13 — fetches, bounds and persists the
 * read-only Google profile mirror, and computes mirror_expires_at under
 * the 30-calendar-day ceiling.
 *
 * THE MIRROR IS A PERFORMANCE CACHE IN THE EXACT SENSE GOOGLE'S POLICY
 * PERMITS, and nothing more: bounded key set (§21.2), never manipulated or
 * aggregated, never retained past its TTL (§13.1), and never the source of
 * a persisted derived value (§13.3).
 *
 * Sync must leave `businesses` and `business_locations` BYTE-IDENTICAL
 * (contract §21.4). There is deliberately no write path to either table
 * anywhere in this class or this slice.
 */
final class GoogleBusinessProfileMirrorService
{
    public function __construct(
        private readonly GoogleBusinessProfileReadClient $client,
        private readonly GoogleBusinessProfileConnectionManager $connections,
        private readonly GoogleBusinessProfileReadMask $readMask,
        private readonly GoogleBusinessProfileRetention $retention,
        private readonly GoogleBusinessProfileOperationLedger $ledger,
        private readonly GoogleBusinessProfileCallBudget $budget,
    ) {
    }

    /**
     * Refreshes one binding. Every provider call happens OUTSIDE any
     * transaction (contract §24.9); the transaction opens only to persist
     * the already-fetched result.
     *
     * @throws GoogleBusinessProfileProviderException
     */
    public function refresh(BusinessGoogleLocation $binding, BusinessGoogleConnection $connection, ?int $actorUserId = null): GoogleMirrorRefreshResult
    {
        $location = $binding->businessLocation;

        if ($location === null) {
            // C-4 makes this impossible in a consistent database; fail
            // closed rather than reading Google without knowing whether
            // the address is permitted.
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        $mask = $this->readMask->forLocation($location);
        $addressPermitted = $this->readMask->addressPermittedForLocation($location);

        $operation = $this->ledger->open(
            businessId: (int) $binding->business_id,
            type: GoogleOperationType::MirrorRefreshed,
            actorUserId: $actorUserId,
            googleLocationId: (int) $binding->id,
            summary: 'Refreshing ' . $binding->provider_location_resource_name,
            fingerprintParts: array_merge(['getLocation', (string) $binding->provider_location_resource_name], $mask),
        );

        try {
            // Correction pass item 6 — the token exchange and both reads
            // are charged to this operation and to the Business budget.
            [$profile, $state] = $this->budget->withinOperation($connection, $operation, function () use ($connection, $binding, $mask, $addressPermitted): array {
                $accessToken = $this->connections->accessTokenFor($connection);

                return [
                    $this->client->getLocation($accessToken, (string) $binding->provider_location_resource_name, $mask, $addressPermitted),
                    $this->client->getVoiceOfMerchantState($accessToken, (string) $binding->provider_location_resource_name),
                ];
            });
        } catch (GoogleBusinessProfileProviderException $exception) {
            // Contract §24.5 — a rate-limited call leaves last_synced_at
            // unchanged so the next sweep naturally retries. The ledger
            // records `deferred`, not `failed`; §24.6 records a timeout as
            // `unknown`. Neither is replayed here.
            $this->ledger->fail($operation, $exception, 'Refreshing ' . $binding->provider_location_resource_name);

            throw $exception;
        }

        // Correction pass item 8 — with an effective TTL of zero, nothing
        // reusable may be written. Only non-Content operational state is
        // persisted, and the fetched profile travels back to the caller
        // for ephemeral rendering inside this same request.
        $persisted = $this->retention->mirrorRetentionDays() > 0;

        $this->store($binding, $profile, $state);

        // The ledger summary names the operation only — never a Google
        // Content value (contract §11.3.4).
        $this->ledger->succeed($operation, 'Mirror refreshed');

        GoogleBusinessProfileMirrorRefreshed::dispatch((int) $binding->business_id, (int) $binding->id);

        $binding->refresh();

        return new GoogleMirrorRefreshResult($profile, $state, $persisted);
    }

    /**
     * Contract §13.1 / §21.2 — persists the BOUNDED mirror and stamps the
     * TTL. mirror_expires_at is always fetchedAt + min(configured, 30)
     * calendar days; no code path may produce a longer window.
     */
    public function store(BusinessGoogleLocation $binding, GoogleLocationProfile $profile, GoogleVoiceOfMerchantState $state): void
    {
        // Correction pass item 8 — with an effective TTL of zero, nothing
        // reusable may be written AT ALL. Branching here rather than in
        // refresh() means every caller (refresh AND bind) obeys the rule,
        // so a zero-TTL deployment cannot leak Content through the bind
        // path.
        if ($this->retention->mirrorRetentionDays() < 1) {
            $this->storeWithoutContent($binding, $profile, $state);

            return;
        }

        $fetchedAt = now();

        DB::transaction(function () use ($binding, $profile, $state, $fetchedAt) {
            $binding->forceFill([
                // Only the contract's closed key set — toMirrorArray()
                // emits exactly those keys and no others.
                'profile_mirror' => $profile->toMirrorArray(),
                'bound_title_snapshot' => $profile->title,
                'bound_locality_snapshot' => $profile->locality,
                'bound_region_code_snapshot' => $profile->regionCode,
                'verification_state' => $this->deriveHealth($profile, $state),
                'has_voice_of_merchant' => $state->hasVoiceOfMerchant ?? $profile->hasVoiceOfMerchant,
                'has_pending_edits' => $profile->hasPendingEdits,
                'open_status' => $profile->openStatus,
                'duplicate_of_resource_name' => $profile->duplicateOfResourceName,
                'mirror_fetched_at' => $fetchedAt,
                'mirror_expires_at' => $this->retention->mirrorExpiresAt($fetchedAt),
                'last_synced_at' => $fetchedAt,
            ])->save();
        });
    }

    /**
     * Correction pass item 8 — the zero-TTL write. Persists ONLY the
     * non-Content operational state that §13.2's purge itself preserves
     * (verification/location state and last_synced_at), and explicitly
     * clears every Content column, so nothing reusable survives the
     * request. The next read correctly reports "refresh required".
     */
    private function storeWithoutContent(BusinessGoogleLocation $binding, GoogleLocationProfile $profile, GoogleVoiceOfMerchantState $state): void
    {
        DB::transaction(function () use ($binding, $profile, $state) {
            $binding->forceFill([
                'profile_mirror' => null,
                'bound_title_snapshot' => null,
                'bound_locality_snapshot' => null,
                'bound_region_code_snapshot' => null,
                'duplicate_of_resource_name' => null,
                'mirror_fetched_at' => null,
                'mirror_expires_at' => null,
                'verification_state' => $this->deriveHealth($profile, $state),
                'has_voice_of_merchant' => $state->hasVoiceOfMerchant ?? $profile->hasVoiceOfMerchant,
                'has_pending_edits' => $profile->hasPendingEdits,
                'open_status' => $profile->openStatus,
                'last_synced_at' => now(),
            ])->save();
        });
    }

    /**
     * Contract §13.2 — nulls the mirror and the three bind-time snapshot
     * fields for one expired binding, and writes a mirror_purged ledger
     * row.
     *
     * It deliberately does NOT null provider_account_resource_name,
     * provider_location_resource_name, business_location_id or the
     * verification/state booleans: those are operational binding metadata,
     * not a Google-content archive (contract §13.7).
     */
    public function purge(BusinessGoogleLocation $binding): void
    {
        $operation = $this->ledger->open(
            businessId: (int) $binding->business_id,
            type: GoogleOperationType::MirrorPurged,
            googleLocationId: (int) $binding->id,
            summary: 'Purging expired mirror',
        );

        $binding->forceFill([
            'profile_mirror' => null,
            'bound_title_snapshot' => null,
            'bound_locality_snapshot' => null,
            'bound_region_code_snapshot' => null,
            'duplicate_of_resource_name' => null,
            'mirror_fetched_at' => null,
            'mirror_expires_at' => null,
        ])->save();

        $this->ledger->succeed($operation, 'Expired mirror purged');
    }

    /**
     * Contract §21.3 — the derived PLATFORM vocabulary, first match wins.
     * The underlying Google signals stay individually visible on the row;
     * nothing is auto-remediated.
     */
    private function deriveHealth(GoogleLocationProfile $profile, GoogleVoiceOfMerchantState $state): GoogleLocationHealth
    {
        if ($state->hasOwnershipConflict) {
            return GoogleLocationHealth::OwnershipConflict;
        }

        if ($state->complianceReason === 'BUSINESS_LOCATION_SUSPENDED') {
            return GoogleLocationHealth::Suspended;
        }

        if ($state->complianceReason === 'BUSINESS_LOCATION_DISABLED') {
            return GoogleLocationHealth::Disabled;
        }

        if ($profile->duplicateOfResourceName !== null) {
            return GoogleLocationHealth::Duplicate;
        }

        if ($state->hasPendingVerification) {
            return GoogleLocationHealth::VerificationPending;
        }

        if ($state->isWaitingForVoiceOfMerchant) {
            return GoogleLocationHealth::AwaitingReview;
        }

        $hasVoiceOfMerchant = $state->hasVoiceOfMerchant ?? $profile->hasVoiceOfMerchant;

        if ($hasVoiceOfMerchant === true) {
            return GoogleLocationHealth::Verified;
        }

        if ($hasVoiceOfMerchant === false) {
            return GoogleLocationHealth::Unverified;
        }

        return GoogleLocationHealth::Unknown;
    }
}
