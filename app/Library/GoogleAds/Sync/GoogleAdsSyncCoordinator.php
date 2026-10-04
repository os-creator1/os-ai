<?php

namespace App\Library\GoogleAds\Sync;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\Enums\GoogleAds\GoogleAdsSyncRunState;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\GoogleAdsCallBudget;
use App\Library\GoogleAds\GoogleAdsConfig;
use App\Library\GoogleAds\GoogleAdsConnectionManager;
use App\Library\GoogleAds\GoogleAdsOperationLedger;
use App\Library\GoogleAds\Sync\Contracts\GoogleAdsSyncObserver;
use App\Library\GoogleAds\Sync\Contracts\GoogleAdsSyncStage;
use App\Library\GoogleAds\Sync\Stages\AccountSummaryStage;
use App\Library\GoogleAds\Sync\Stages\AdGroupsStage;
use App\Library\GoogleAds\Sync\Stages\CampaignMetricsStage;
use App\Library\GoogleAds\Sync\Stages\CampaignsStage;
use App\Library\GoogleAds\Sync\Stages\KeywordMetricsStage;
use App\Library\GoogleAds\Sync\Stages\KeywordsStage;
use App\Library\GoogleAds\Sync\Stages\SearchTermsStage;
use App\Models\BusinessGoogleConnection;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Google Ads Module V1 contract §5 — runs ONE sync of ONE account.
 *
 * PRECONDITIONS (owned by SyncGoogleAdsAccount): the account was just
 * re-validated by GoogleAdsSyncEligibility and the caller holds the account
 * claim. This class does the run lifecycle, the ledger operation and the
 * stages, in the contract's order:
 *
 *   account summary (+ currency guard) -> campaigns (+budgets) -> ad groups
 *   -> keywords (+negatives) -> campaign daily metrics -> keyword daily
 *   metrics -> search terms
 *
 * Rules enforced here:
 *  - ONE ledger operation (`ads_sync`) per run; the access-token exchange and
 *    every stage fetch run inside GoogleAdsCallBudget::withinOperation().
 *  - fetch is complete BEFORE any write, and persist() opens only short
 *    chunk transactions, so no provider call ever happens inside one.
 *  - A provider failure stops the run: stages that already persisted stay
 *    (they were idempotent upserts), `last_successful_sync_at` does not move,
 *    and the failure is a safe code. A deferred outcome (429 / budget) is
 *    `skipped` + `rate_limited` and is never retried here.
 *  - A truncated report finishes the run as `partial` / `row_cap`.
 *  - A programming error finalises the run as `failed` / `internal_error`
 *    and is RE-THROWN: it is never swallowed.
 *
 * SEAM FOR LATER PHASES: services tagged GoogleAdsSyncCoordinator::OBSERVER_TAG
 * (GoogleAdsSyncObserver) are told after each persisted stage, e.g. the
 * mutation reconciler after `keywords` and `campaigns`.
 */
final class GoogleAdsSyncCoordinator
{
    public const OBSERVER_TAG = 'google_ads.sync_observers';

    /** @var array<int, GoogleAdsSyncStage> */
    private readonly array $stages;

    public function __construct(
        private readonly GoogleAdsConnectionManager $connections,
        private readonly GoogleAdsOperationLedger $ledger,
        private readonly GoogleAdsCallBudget $budget,
        private readonly GoogleAdsConfig $config,
        private readonly Container $container,
        AccountSummaryStage $accountSummary,
        CampaignsStage $campaigns,
        AdGroupsStage $adGroups,
        KeywordsStage $keywords,
        CampaignMetricsStage $campaignMetrics,
        KeywordMetricsStage $keywordMetrics,
        SearchTermsStage $searchTerms,
    ) {
        $this->stages = [$accountSummary, $campaigns, $adGroups, $keywords, $campaignMetrics, $keywordMetrics, $searchTerms];
    }

    public function execute(
        GoogleAdsAccount $account,
        BusinessGoogleConnection $connection,
        GoogleAdsSyncRun $run,
        ?int $actorUserId = null,
    ): GoogleAdsSyncRun {
        $this->begin($account, $run);

        $operation = $this->ledger->open(
            businessId: (int) $account->business_id,
            type: GoogleOperationType::AdsSync,
            actorUserId: $actorUserId,
            summary: 'Google Ads sync',
        );
        $run->forceFill(['business_google_operation_id' => $operation->id])->save();

        $rows = 0;
        $truncated = false;
        $through = null;

        try {
            $token = $this->budget->withinOperation(
                $connection,
                $operation,
                fn (): string => $this->connections->accessTokenFor($connection),
            );
            $context = $this->contextFor($account, $token);

            foreach ($this->stages as $stage) {
                $report = $this->budget->withinOperation($connection, $operation, fn () => $stage->fetch($context));
                $result = $stage->persist($context, $report);

                $rows += $result->rows;
                $truncated = $truncated || $result->truncated;
                $through = $result->dataThroughDate ?? $through;

                $this->notifyObservers($account, $stage->key());
            }
        } catch (GoogleAdsProviderException $exception) {
            $this->ledger->fail($operation, $exception, 'Google Ads sync stopped');
            $code = GoogleAdsSyncFailureCode::forProviderException($exception);

            return $this->finish(
                $account,
                $run,
                $exception->isDeferrable() ? GoogleAdsSyncRunState::Skipped : GoogleAdsSyncRunState::Failed,
                $code,
                $through,
                $rows,
            );
        } catch (GoogleAdsSyncAbortException $exception) {
            $this->ledger->failLocally($operation, GoogleAdsProviderException::UNEXPECTED_RESPONSE, 'Google Ads sync stopped: ' . $exception->failureCode);

            return $this->finish($account, $run, GoogleAdsSyncRunState::Failed, $exception->failureCode, $through, $rows);
        } catch (Throwable $exception) {
            $this->ledger->failLocally($operation, GoogleAdsProviderException::UNEXPECTED_RESPONSE, 'Google Ads sync failed locally');
            $this->finish($account, $run, GoogleAdsSyncRunState::Failed, GoogleAdsSyncFailureCode::INTERNAL_ERROR, $through, $rows);

            throw $exception;
        }

        $this->ledger->succeed($operation, $truncated ? 'Google Ads sync partial: row cap reached' : 'Google Ads sync complete');

        return $this->finish(
            $account,
            $run,
            $truncated ? GoogleAdsSyncRunState::Partial : GoogleAdsSyncRunState::Succeeded,
            $truncated ? GoogleAdsSyncFailureCode::ROW_CAP : null,
            $through,
            $rows,
        );
    }

    /** Ends a queued run that will not execute (claim lost, no longer eligible). No provider access. */
    public function skip(GoogleAdsSyncRun $run, string $failureCode): GoogleAdsSyncRun
    {
        $run->forceFill([
            'state' => GoogleAdsSyncRunState::Skipped,
            'failure_code' => $failureCode,
            'completed_at' => now(),
        ])->save();

        return $run;
    }

    private function begin(GoogleAdsAccount $account, GoogleAdsSyncRun $run): void
    {
        $run->forceFill(['state' => GoogleAdsSyncRunState::Running, 'started_at' => now(), 'failure_code' => null])->save();

        DB::table('google_ads_accounts')
            ->where('id', $account->id)
            ->update(['last_sync_started_at' => now()]);
    }

    private function contextFor(GoogleAdsAccount $account, string $accessToken): GoogleAdsSyncContext
    {
        $zone = in_array($account->time_zone, timezone_identifiers_list(), true) ? $account->time_zone : 'UTC';
        $today = CarbonImmutable::now($zone)->startOfDay();

        return new GoogleAdsSyncContext(
            account: $account,
            access: new GoogleAdsAccessContext($accessToken, (string) $account->customer_id, $account->login_customer_id),
            syncedAt: CarbonImmutable::now(),
            metricsStart: $today->subDays($this->config->metricsLookbackDays())->toDateString(),
            searchTermsStart: $today->subDays($this->config->searchTermLookbackDays())->toDateString(),
            endDate: $today->toDateString(),
        );
    }

    /**
     * Run row and account bookkeeping in one short transaction. Only a
     * succeeded / partial run advances last_successful_sync_at and
     * data_through_date; a failed or skipped one only records the safe code,
     * so prior facts and their freshness stamp are untouched.
     */
    private function finish(
        GoogleAdsAccount $account,
        GoogleAdsSyncRun $run,
        GoogleAdsSyncRunState $state,
        ?string $failureCode,
        ?string $dataThroughDate,
        int $rows,
    ): GoogleAdsSyncRun {
        DB::transaction(function () use ($account, $run, $state, $failureCode, $dataThroughDate, $rows): void {
            $run->forceFill([
                'state' => $state,
                'failure_code' => $failureCode,
                'completed_at' => now(),
                'data_through_date' => $dataThroughDate,
                'rows_counted' => $rows,
            ])->save();

            $query = DB::table('google_ads_accounts')->where('id', $account->id);
            $changes = ['last_sync_failure_code' => $failureCode];

            if ($state === GoogleAdsSyncRunState::Succeeded || $state === GoogleAdsSyncRunState::Partial) {
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

    private function notifyObservers(GoogleAdsAccount $account, string $stageKey): void
    {
        foreach ($this->container->tagged(self::OBSERVER_TAG) as $observer) {
            if (! $observer instanceof GoogleAdsSyncObserver) {
                continue;
            }

            try {
                $observer->afterStage($account, $stageKey);
            } catch (Throwable $exception) {
                // An observer defect must not abort the sync, but it must be seen.
                report($exception);
            }
        }
    }
}
