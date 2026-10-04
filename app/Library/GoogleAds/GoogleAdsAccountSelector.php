<?php

namespace App\Library\GoogleAds;

use App\DTO\GoogleAds\GoogleAdsAccountCandidate;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleAds\GoogleAdsAccountSelectionException;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\GoogleAdsAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Google Ads Module V1 contract §3 — the ONLY way a Business gets a selected
 * Ads account, and it FAILS CLOSED.
 *
 * select() takes a customer id and NOTHING else from the caller. It
 *   1. normalises the id (10 digits) — anything else is rejected;
 *   2. requires an ACTIVE google_ads connection for THIS Business (found by
 *      business id, never by a caller-supplied connection);
 *   3. RE-DERIVES the candidate set from Google right now
 *      (GoogleAdsAccountDirectory) — it never trusts a candidate list from an
 *      earlier render;
 *   4. refuses a customer id that is not in that fresh set (NOT_A_CANDIDATE,
 *      the 404 case) or is a manager (MANAGER_NOT_SELECTABLE);
 *   5. takes login-customer-id, currency, time zone, name and test flag from
 *      the CANDIDATE only;
 *   6. in one short transaction creates or updates the single account row for
 *      the Business, bound to that Business's own connection (the composite
 *      FK makes any other binding impossible).
 *
 * If the selected customer id OR currency changes from the previous
 * selection, this Business's normalised Ads facts are PURGED first and the
 * currency-denominated targets and sync bookkeeping are reset, so two
 * customers / currencies never mix. Sync-run and mutation history, and the
 * ledger, are retained.
 *
 * Two ledger operations record the story: `ads_accounts_listed` (from the
 * directory, carries the call count) and `ads_account_selected`.
 */
final class GoogleAdsAccountSelector
{
    public function __construct(
        private readonly GoogleAdsConnectionManager $connections,
        private readonly GoogleAdsAccountDirectory $directory,
        private readonly GoogleAdsOperationLedger $ledger,
    ) {
    }

    /**
     * @throws GoogleAdsAccountSelectionException when the id is invalid, not connected, not a candidate, or a manager
     * @throws GoogleAdsProviderException when Google cannot be asked
     */
    public function select(Business $business, int $actorUserId, string $customerId): GoogleAdsAccount
    {
        $normalized = GoogleAdsCustomerId::normalize($customerId)
            ?? throw GoogleAdsAccountSelectionException::invalidCustomerId();

        $connection = $this->connections->findForBusiness($business);

        if ($connection === null || ! $connection->isActive()) {
            throw GoogleAdsAccountSelectionException::notConnected();
        }

        $candidate = $this->findCandidate(
            $this->directory->candidates($business, $connection, $actorUserId),
            $normalized,
        );

        if ($candidate === null) {
            throw GoogleAdsAccountSelectionException::notACandidate();
        }

        if (! $candidate->isSelectable()) {
            throw GoogleAdsAccountSelectionException::managerNotSelectable();
        }

        $operation = $this->ledger->open(
            businessId: (int) $business->id,
            type: GoogleOperationType::AdsAccountSelected,
            actorUserId: $actorUserId,
            summary: 'Google Ads account selected',
        );

        try {
            $account = $this->persist($business, $connection, $candidate, $actorUserId);
        } catch (Throwable $exception) {
            $this->ledger->failLocally($operation, GoogleAdsProviderException::UNEXPECTED_RESPONSE, 'Google Ads account selection failed');

            throw $exception;
        }

        $this->ledger->succeed($operation);

        return $account;
    }

    /**
     * @param  array<int, GoogleAdsAccountCandidate>  $candidates
     */
    private function findCandidate(array $candidates, string $customerId): ?GoogleAdsAccountCandidate
    {
        foreach ($candidates as $candidate) {
            if ($candidate->customerId === $customerId) {
                return $candidate;
            }
        }

        return null;
    }

    private function persist(Business $business, BusinessGoogleConnection $connection, GoogleAdsAccountCandidate $candidate, int $actorUserId): GoogleAdsAccount
    {
        $attributes = [
            'business_id' => $business->id,
            'business_google_connection_id' => $connection->id,
            'customer_id' => $candidate->customerId,
            'login_customer_id' => $candidate->loginCustomerId,
            'descriptive_name' => $candidate->name !== null ? mb_substr($candidate->name, 0, 191) : null,
            'currency_code' => strtoupper($candidate->currencyCode),
            'time_zone' => $candidate->timeZone,
            'is_test_account' => $candidate->isTest,
            'selected_at' => now(),
            'selected_by_user_id' => $actorUserId,
        ];

        return DB::transaction(function () use ($business, $attributes): GoogleAdsAccount {
            $existing = $this->lockedAccount($business);

            if ($existing === null) {
                try {
                    return GoogleAdsAccount::create($attributes);
                } catch (UniqueConstraintViolationException) {
                    // A concurrent first selection won; continue as an update of its row.
                    $existing = $this->lockedAccount($business);

                    if ($existing === null) {
                        throw new \LogicException('Google Ads account row vanished during selection.');
                    }
                }
            }

            if ($existing->customer_id !== $attributes['customer_id'] || $existing->currency_code !== $attributes['currency_code']) {
                $this->purgeFacts($existing);
                $attributes += $this->resetForNewCustomer();
            }

            $existing->forceFill($attributes)->save();

            return $existing;
        });
    }

    private function lockedAccount(Business $business): ?GoogleAdsAccount
    {
        return GoogleAdsAccount::query()
            ->where('business_id', $business->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Deletes this account's normalised facts (children first). History that
     * is not a fact — sync runs, mutation detail, the ledger — is retained.
     */
    private function purgeFacts(GoogleAdsAccount $account): void
    {
        foreach (['google_ads_search_terms', 'google_ads_daily_metrics', 'google_ads_keywords', 'google_ads_ad_groups', 'google_ads_campaigns'] as $table) {
            DB::table($table)->where('google_ads_account_id', $account->id)->delete();
        }
    }

    /**
     * Targets are in the OLD currency and the sync bookkeeping describes the
     * OLD customer; neither may carry over.
     *
     * @return array<string, null>
     */
    private function resetForNewCustomer(): array
    {
        return [
            'monthly_budget_target_micros' => null,
            'target_cpl_micros' => null,
            'last_sync_started_at' => null,
            'last_successful_sync_at' => null,
            'data_through_date' => null,
            'last_sync_failure_code' => null,
            'sync_claimed_at' => null,
            'manual_refresh_requested_at' => null,
        ];
    }
}
