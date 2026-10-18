<?php

namespace App\Library\MetaAds;

use App\DTO\MetaAds\MetaAdsAccountCandidate;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaAdsAccountSelectionException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Models\Business;
use App\Models\BusinessMetaConnection;
use App\Models\MetaAdsAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Meta Ads Module V1 contract 24 §4 — the ONLY way a Business gets a selected
 * Meta ad account, and it FAILS CLOSED.
 *
 * select() takes an ad account id and NOTHING else from the caller: it
 * normalises it (digits; an `act_` prefix is stripped), requires the Business's
 * own ACTIVE connection, refuses while a sync is live, RE-DERIVES the candidate
 * set from Meta right now, refuses an id that is not in it (NOT_A_CANDIDATE) or
 * is not ACTIVE at Meta (NOT_SELECTABLE), and takes name, currency and time
 * zone from the FRESH candidate. Never auto-selects.
 *
 * Re-selecting the same account (same id and currency) only re-stamps
 * selected_at / selected_by / selected_meta_user_id. A DIFFERENT account purges
 * this Business's Meta facts (campaigns, ad sets, ads, daily insights, daily
 * results) and resets the account-currency targets, the result type and the
 * sync bookkeeping, so two accounts / currencies never mix. Sync-run and
 * mutation history and the ledger are retained.
 */
final class MetaAdsAccountSelector
{
    /** A sync claim / running run younger than this is live. */
    public const CLAIM_STALE_MINUTES = 15;

    /** A queued run older than this is treated as lost. */
    public const QUEUED_RUN_WINDOW_MINUTES = 180;

    public function __construct(
        private readonly MetaAdsAccountDirectory $directory,
        private readonly MetaAdsOperationLedger $ledger,
    ) {
    }

    /**
     * @throws MetaAdsAccountSelectionException invalid id, not connected, sync running, not a candidate, not selectable
     * @throws MetaProviderException when Meta cannot be asked
     */
    public function select(Business $business, BusinessMetaConnection $connection, string $rawAccountId, int $actorUserId): MetaAdsAccount
    {
        $accountId = self::normalize($rawAccountId)
            ?? throw MetaAdsAccountSelectionException::invalidAccountId();

        // Re-read: a stale model must not decide, and another Business's row is "not connected".
        $connection = BusinessMetaConnection::query()
            ->where('id', $connection->id)
            ->where('business_id', $business->id)
            ->first();

        if ($connection === null || ! $connection->isActive()) {
            throw MetaAdsAccountSelectionException::notConnected();
        }

        $current = MetaAdsAccount::query()->where('business_id', $business->id)->first();

        if ($current !== null && $this->hasLiveWork($current)) {
            throw MetaAdsAccountSelectionException::syncRunning();
        }

        $candidate = $this->findCandidate($this->directory->candidates($business, $connection, $actorUserId), $accountId);

        if ($candidate === null) {
            throw MetaAdsAccountSelectionException::notACandidate();
        }

        if (! $candidate->isSelectable()) {
            throw MetaAdsAccountSelectionException::notSelectable();
        }

        $operation = $this->ledger->open(
            businessId: (int) $business->id,
            type: MetaOperationType::AccountSelected,
            actorUserId: $actorUserId,
            summary: 'Meta ad account selected',
        );

        try {
            $account = $this->persist($business, $connection, $candidate, $actorUserId);
        } catch (Throwable $exception) {
            $this->ledger->failLocally(
                $operation,
                $exception instanceof MetaAdsAccountSelectionException ? MetaProviderException::VALIDATION : MetaProviderException::UNEXPECTED_RESPONSE,
                'Meta ad account selection failed',
            );

            throw $exception;
        }

        $this->ledger->succeed($operation);

        return $account;
    }

    /** Digits only; `act_` prefix tolerated and stripped; 1..20 digits. */
    public static function normalize(string $raw): ?string
    {
        $value = trim($raw);

        if (stripos($value, 'act_') === 0) {
            $value = substr($value, 4);
        }

        return preg_match('/\A\d{1,20}\z/', $value) === 1 ? $value : null;
    }

    /**
     * Is a sync claimed or in flight for this account? A claim younger than 15
     * minutes, a `running` run younger than 15 minutes, or a `queued` run whose
     * job is plausibly still waiting.
     */
    public function hasLiveWork(MetaAdsAccount $account): bool
    {
        $claim = DB::table('meta_ads_accounts')->where('id', $account->id)->value('sync_claimed_at');

        if ($claim !== null && strtotime((string) $claim) > now()->subMinutes(self::CLAIM_STALE_MINUTES)->getTimestamp()) {
            return true;
        }

        return DB::table('meta_ads_sync_runs')
            ->where('meta_ads_account_id', $account->id)
            ->where(function ($query): void {
                $query->where(function ($running): void {
                    $running->where('state', 'running')
                        ->whereRaw('COALESCE(started_at, created_at) > ?', [now()->subMinutes(self::CLAIM_STALE_MINUTES)]);
                })->orWhere(function ($queued): void {
                    $queued->where('state', 'queued')
                        ->where('created_at', '>', now()->subMinutes(self::QUEUED_RUN_WINDOW_MINUTES));
                });
            })
            ->exists();
    }

    /** @param  array<int, MetaAdsAccountCandidate>  $candidates */
    private function findCandidate(array $candidates, string $accountId): ?MetaAdsAccountCandidate
    {
        foreach ($candidates as $candidate) {
            if ($candidate->adAccountId === $accountId) {
                return $candidate;
            }
        }

        return null;
    }

    private function persist(Business $business, BusinessMetaConnection $connection, MetaAdsAccountCandidate $candidate, int $actorUserId): MetaAdsAccount
    {
        $attributes = [
            'business_id' => $business->id,
            'business_meta_connection_id' => $connection->id,
            'ad_account_id' => $candidate->adAccountId,
            'name' => $candidate->name !== null ? mb_substr($candidate->name, 0, 191) : null,
            'currency_code' => strtoupper($candidate->currencyCode),
            'time_zone' => $candidate->timeZone,
            'account_status' => $candidate->accountStatus,
            'selected_at' => now(),
            'selected_by_user_id' => $actorUserId,
            'selected_meta_user_id' => $connection->meta_user_id,
        ];

        return DB::transaction(function () use ($business, $attributes): MetaAdsAccount {
            $existing = $this->lockedAccount($business);

            if ($existing === null) {
                try {
                    return MetaAdsAccount::create($attributes);
                } catch (UniqueConstraintViolationException) {
                    // A concurrent first selection won; continue as an update of its row.
                    $existing = $this->lockedAccount($business);

                    if ($existing === null) {
                        throw new LogicException('Meta ads account row vanished during selection.');
                    }
                }
            }

            if ($this->hasLiveWork($existing)) {
                throw MetaAdsAccountSelectionException::syncRunning();
            }

            if ($existing->ad_account_id !== $attributes['ad_account_id'] || $existing->currency_code !== $attributes['currency_code']) {
                $this->purgeFacts($existing);
                $attributes += $this->resetForNewAccount();
            }

            $existing->forceFill($attributes)->save();

            return $existing;
        });
    }

    private function lockedAccount(Business $business): ?MetaAdsAccount
    {
        return MetaAdsAccount::query()->where('business_id', $business->id)->lockForUpdate()->first();
    }

    /** Deletes this account's normalised facts (children first). History is retained. */
    private function purgeFacts(MetaAdsAccount $account): void
    {
        foreach (['meta_ads_daily_results', 'meta_ads_daily_insights', 'meta_ads_ads', 'meta_ads_ad_sets', 'meta_ads_campaigns'] as $table) {
            DB::table($table)->where('meta_ads_account_id', $account->id)->delete();
        }
    }

    /**
     * Targets and the result type are tied to the OLD account / currency and the
     * sync bookkeeping describes the OLD account; none may carry over.
     *
     * @return array<string, null>
     */
    private function resetForNewAccount(): array
    {
        return [
            'monthly_budget_target_micros' => null,
            'target_cost_per_result_micros' => null,
            'result_action_type' => null,
            'last_sync_started_at' => null,
            'last_successful_sync_at' => null,
            'data_through_date' => null,
            'last_sync_failure_code' => null,
            'sync_claimed_at' => null,
            'manual_refresh_requested_at' => null,
        ];
    }
}
