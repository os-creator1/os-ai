<?php

namespace App\Library\MetaAds\Sync;

use App\Enums\MetaAds\MetaAdsSyncRunState;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaAdsConcurrencyException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\MetaAdsCallBudget;
use App\Library\MetaAds\MetaAdsConfig;
use App\Library\MetaAds\MetaAdsConnectionManager;
use App\Library\MetaAds\MetaAdsOperationLedger;
use App\Library\MetaAds\Sync\Contracts\MetaAdsSyncObserver;
use App\Library\MetaAds\Sync\Contracts\MetaAdsSyncStage;
use App\Library\MetaAds\Sync\Stages\AccountSummaryStage;
use App\Library\MetaAds\Sync\Stages\AdInsightsStage;
use App\Library\MetaAds\Sync\Stages\AdSetInsightsStage;
use App\Library\MetaAds\Sync\Stages\AdSetsStage;
use App\Library\MetaAds\Sync\Stages\AdsStage;
use App\Library\MetaAds\Sync\Stages\CampaignInsightsStage;
use App\Library\MetaAds\Sync\Stages\CampaignsStage;
use App\Library\MetaAds\Sync\Stages\FrequencyStage;
use App\Models\BusinessMetaConnection;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Meta Ads Module V1 contract 24 §6 — runs ONE sync of ONE account.
 *
 * PRECONDITIONS (owned by SyncMetaAdsAccount): the account was just
 * re-validated by MetaAdsSyncEligibility and the caller holds the account
 * claim. This class does the run lifecycle, the ledger operation and the
 * stages, in the contract's order:
 *
 *   account (+ account/currency guard) -> campaigns -> ad sets -> ads
 *   -> campaign insights -> ad-set insights -> ad insights -> 7-day frequency
 *
 * Rules enforced here:
 *  - ONE ledger operation (`meta_ads_sync`) per run; every stage fetch runs
 *    inside MetaAdsCallBudget::withinOperation(). The access token is read
 *    once per run (accessTokenFor) and lives only in the run's context.
 *  - fetch is complete BEFORE any write, and persist() opens only short chunk
 *    transactions, so no provider call ever happens inside one.
 *  - Before every persist the account, its selection and its connection are
 *    re-read: another ad account / currency / Meta user / connection, or an
 *    unselected account, stops the run (`account_changed` /
 *    `connection_mismatch`) with nothing further written.
 *  - A provider failure stops the run: stages that already persisted stay
 *    (idempotent upserts), `last_successful_sync_at` does not move, and the
 *    failure is a safe code. A dead token (190) additionally moves the
 *    connection to expired / revoked. A deferred outcome (throttle / budget)
 *    is `skipped` + `rate_limited` and is never retried here.
 *  - A truncated report (page / row cap) finishes the run as `partial` /
 *    `row_cap`; API usage at/above the stop threshold stops it cleanly as
 *    `partial` / `usage_high`. Only a COMPLETE run advances
 *    last_successful_sync_at and the account's data_through_date.
 *  - A programming error finalises the run as `failed` / `internal_error` and
 *    is RE-THROWN: it is never swallowed.
 *
 * SEAM FOR LATER PHASES: services tagged MetaAdsSyncCoordinator::OBSERVER_TAG
 * (MetaAdsSyncObserver) are told after each persisted stage, e.g. the mutation
 * reconciler after `campaigns`, `ad_sets` and `ads`.
 */
final class MetaAdsSyncCoordinator
{
    public const OBSERVER_TAG = 'meta_ads.sync_observers';

    /** @var array<int, MetaAdsSyncStage> */
    private readonly array $stages;

    public function __construct(
        private readonly MetaAdsConnectionManager $connections,
        private readonly MetaAdsOperationLedger $ledger,
        private readonly MetaAdsCallBudget $budget,
        private readonly MetaAdsConfig $config,
        private readonly MetaAdsSyncGuard $guard,
        private readonly Container $container,
        AccountSummaryStage $accountSummary,
        CampaignsStage $campaigns,
        AdSetsStage $adSets,
        AdsStage $ads,
        CampaignInsightsStage $campaignInsights,
        AdSetInsightsStage $adSetInsights,
        AdInsightsStage $adInsights,
        FrequencyStage $frequency,
    ) {
        $this->stages = [$accountSummary, $campaigns, $adSets, $ads, $campaignInsights, $adSetInsights, $adInsights, $frequency];
    }

    public function execute(
        MetaAdsAccount $account,
        BusinessMetaConnection $connection,
        MetaAdsSyncRun $run,
        ?int $actorUserId = null,
        ?MetaAdsSyncClaim $claim = null,
    ): MetaAdsSyncRun {
        $this->begin($account, $run);

        $operation = $this->ledger->open(
            businessId: (int) $account->business_id,
            type: MetaOperationType::MetaAdsSync,
            actorUserId: $actorUserId,
            summary: 'Meta Ads sync',
        );
        $run->forceFill(['business_meta_operation_id' => $operation->id])->save();

        // What the run was started for; compared again before every persist.
        $adAccountId = (string) $account->ad_account_id;
        $currency = strtoupper((string) $account->currency_code);
        $rows = 0;
        $truncated = false;
        $usageHigh = false;
        $through = null;

        try {
            $token = $this->budget->withinOperation(
                $connection,
                $operation,
                fn (): string => $this->connections->accessTokenFor($connection),
            );
            $context = $this->contextFor($account, $token);
            unset($token);

            $last = count($this->stages) - 1;

            foreach ($this->stages as $index => $stage) {
                if ($claim !== null && ! $this->guard->heartbeat($claim)) {
                    throw new MetaAdsSyncAbortException(MetaAdsSyncFailureCode::CLAIM_LOST);
                }

                $report = $this->budget->withinOperation($connection, $operation, fn () => $stage->fetch($context));
                $this->assertUnchanged($account, $connection, $adAccountId, $currency);
                $result = $stage->persist($context, $report);

                $rows += $result->rows;
                $truncated = $truncated || $result->truncated;
                $through = $result->dataThroughDate ?? $through;

                $this->notifyObservers($account, $stage->key(), $result->truncated);

                if ($report->usageHigh) {
                    // Stop before the next stage; on the final stage there is nothing left to skip.
                    if ($index < $last || $report->truncated) {
                        $usageHigh = true;
                    }

                    if ($index < $last) {
                        break;
                    }
                }
            }
        } catch (MetaProviderException $exception) {
            $this->ledger->fail($operation, $exception, 'Meta Ads sync stopped');

            if ($exception->isTokenFailure()) {
                $this->retireToken($connection, $exception);
            }

            return $this->finish(
                $account,
                $run,
                $exception->isDeferrable() ? MetaAdsSyncRunState::Skipped : MetaAdsSyncRunState::Failed,
                MetaAdsSyncFailureCode::forProviderException($exception),
                $through,
                $rows,
            );
        } catch (MetaAdsSyncAbortException $exception) {
            $this->ledger->failLocally($operation, MetaProviderException::UNEXPECTED_RESPONSE, 'Meta Ads sync stopped: ' . $exception->failureCode);

            return $this->finish($account, $run, MetaAdsSyncRunState::Failed, $exception->failureCode, $through, $rows);
        } catch (Throwable $exception) {
            $this->ledger->failLocally($operation, MetaProviderException::UNEXPECTED_RESPONSE, 'Meta Ads sync failed locally');
            $this->finish($account, $run, MetaAdsSyncRunState::Failed, MetaAdsSyncFailureCode::INTERNAL_ERROR, $through, $rows);

            throw $exception;
        }

        $partial = $truncated || $usageHigh;

        $this->ledger->succeed($operation, match (true) {
            $usageHigh => 'Meta Ads sync partial: API usage high',
            $truncated => 'Meta Ads sync partial: row cap reached',
            default => 'Meta Ads sync complete',
        });

        return $this->finish(
            $account,
            $run,
            $partial ? MetaAdsSyncRunState::Partial : MetaAdsSyncRunState::Succeeded,
            match (true) {
                $usageHigh => MetaAdsSyncFailureCode::USAGE_HIGH,
                $truncated => MetaAdsSyncFailureCode::ROW_CAP,
                default => null,
            },
            $through,
            $rows,
        );
    }

    /** Ends a queued run that will not execute (claim lost, no longer eligible). No provider access. */
    public function skip(MetaAdsSyncRun $run, string $failureCode): MetaAdsSyncRun
    {
        $run->forceFill([
            'state' => MetaAdsSyncRunState::Skipped,
            'failure_code' => $failureCode,
            'completed_at' => now(),
        ])->save();

        return $run;
    }

    /**
     * Nothing more is written once the account was re-selected (other ad
     * account / currency) or unselected (account_changed), or once the
     * connection is no longer the active one the selection was made under
     * (connection_mismatch). Both rows are re-read from the database.
     */
    private function assertUnchanged(MetaAdsAccount $account, BusinessMetaConnection $connection, string $adAccountId, string $currency): void
    {
        $row = DB::table('meta_ads_accounts')->where('id', $account->id)->first([
            'business_id', 'ad_account_id', 'currency_code', 'selected_at', 'selected_meta_user_id', 'business_meta_connection_id',
        ]);

        if ($row === null
            || $row->selected_at === null
            || (int) $row->business_id !== (int) $account->business_id
            || (string) $row->ad_account_id !== $adAccountId
            || strtoupper((string) $row->currency_code) !== $currency) {
            throw new MetaAdsSyncAbortException(MetaAdsSyncFailureCode::ACCOUNT_CHANGED);
        }

        $stored = DB::table('business_meta_connections')->where('id', $row->business_meta_connection_id)->first(['business_id', 'state', 'meta_user_id']);

        if ($stored === null
            || (int) $row->business_meta_connection_id !== (int) $connection->id
            || (int) $stored->business_id !== (int) $account->business_id
            || (string) $stored->state !== 'active'
            || $row->selected_meta_user_id === null
            || (string) $stored->meta_user_id !== (string) $row->selected_meta_user_id) {
            throw new MetaAdsSyncAbortException(MetaAdsSyncFailureCode::CONNECTION_MISMATCH);
        }
    }

    private function begin(MetaAdsAccount $account, MetaAdsSyncRun $run): void
    {
        $run->forceFill(['state' => MetaAdsSyncRunState::Running, 'started_at' => now(), 'failure_code' => null])->save();

        DB::table('meta_ads_accounts')
            ->where('id', $account->id)
            ->update(['last_sync_started_at' => now()]);
    }

    private function contextFor(MetaAdsAccount $account, string $accessToken): MetaAdsSyncContext
    {
        $zone = in_array($account->time_zone, timezone_identifiers_list(), true) ? $account->time_zone : 'UTC';
        $today = CarbonImmutable::now($zone)->startOfDay();
        $yesterday = $today->subDay();

        return new MetaAdsSyncContext(
            account: $account,
            accessToken: $accessToken,
            syncedAt: CarbonImmutable::now(),
            metricsStart: $today->subDays($this->config->metricsLookbackDays())->toDateString(),
            metricsEnd: $today->toDateString(),
            // The last 7 COMPLETE days: yesterday and the six days before it.
            frequencyStart: $yesterday->subDays(6)->toDateString(),
            frequencyEnd: $yesterday->toDateString(),
        );
    }

    /** A dead token moves the connection to expired / revoked; another writer having done so already is fine. */
    private function retireToken(BusinessMetaConnection $connection, MetaProviderException $exception): void
    {
        try {
            $this->connections->markTokenFailure($connection, $exception);
        } catch (MetaAdsConcurrencyException) {
            // Another writer already moved the row; the failure stands.
        }
    }

    /**
     * Run row and account bookkeeping in one short transaction. Only a
     * `succeeded` (complete) run advances last_successful_sync_at and
     * data_through_date; a partial, failed or skipped one only records the
     * safe code, so prior facts and their freshness stamp are untouched.
     */
    private function finish(
        MetaAdsAccount $account,
        MetaAdsSyncRun $run,
        MetaAdsSyncRunState $state,
        ?string $failureCode,
        ?string $dataThroughDate,
        int $rows,
    ): MetaAdsSyncRun {
        DB::transaction(function () use ($account, $run, $state, $failureCode, $dataThroughDate, $rows): void {
            $run->forceFill([
                'state' => $state,
                'failure_code' => $failureCode,
                'completed_at' => now(),
                'data_through_date' => $dataThroughDate,
                'rows_counted' => $rows,
            ])->save();

            $query = DB::table('meta_ads_accounts')->where('id', $account->id);
            $changes = ['last_sync_failure_code' => $failureCode];

            if ($state === MetaAdsSyncRunState::Succeeded) {
                $changes['last_successful_sync_at'] = now();

                $current = (clone $query)->value('data_through_date');

                if ($dataThroughDate !== null && ($current === null || $dataThroughDate > substr((string) $current, 0, 10))) {
                    $changes['data_through_date'] = $dataThroughDate;
                }
            }

            $query->update($changes);
        });

        return $run;
    }

    private function notifyObservers(MetaAdsAccount $account, string $stageKey, bool $truncated): void
    {
        foreach ($this->container->tagged(self::OBSERVER_TAG) as $observer) {
            if (! $observer instanceof MetaAdsSyncObserver) {
                continue;
            }

            try {
                $observer->afterStage($account, $stageKey, $truncated);
            } catch (Throwable $exception) {
                // An observer defect must not abort the sync, but it must be seen.
                report($exception);
            }
        }
    }
}
