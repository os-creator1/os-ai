<?php

namespace App\Library\MetaAds\Sync\Stages;

use App\DTO\MetaAds\MetaAdsAccountCandidate;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Sync\MetaAdsStageReport;
use App\Library\MetaAds\Sync\MetaAdsStageResult;
use App\Library\MetaAds\Sync\MetaAdsSyncAbortException;
use App\Library\MetaAds\Sync\MetaAdsSyncContext;
use App\Library\MetaAds\Sync\MetaAdsSyncFailureCode;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes name / status / time zone from `GET /act_{id}`, and is the
 * ACCOUNT + CURRENCY GUARD: the stored ad_account_id and currency_code are
 * the ones every fact row is denominated in, so a different account id or
 * currency from Meta stops the run (`account_changed`) before anything is
 * written (contract 24 §3 / §4 "never mix currencies").
 *
 * The single account is wrapped in a one-row report so the stage fits the
 * same fetch/persist seam as the others.
 */
final class AccountSummaryStage extends AbstractMetaAdsStage
{
    public function key(): string
    {
        return 'account';
    }

    public function fetch(MetaAdsSyncContext $context): MetaAdsStageReport
    {
        return new MetaAdsStageReport([
            $this->client->accountDetails($context->accessToken(), $context->adAccountId()),
        ]);
    }

    public function persist(MetaAdsSyncContext $context, MetaAdsStageReport $report): MetaAdsStageResult
    {
        $details = $report->rows[0] ?? null;

        if (! $details instanceof MetaAdsAccountCandidate) {
            // We cannot verify we are looking at the same, same-currency account.
            throw new MetaAdsSyncAbortException(MetaProviderException::UNEXPECTED_RESPONSE);
        }

        if ($details->adAccountId !== $context->adAccountId()
            || strtoupper($details->currencyCode) !== strtoupper((string) $context->account->currency_code)) {
            throw new MetaAdsSyncAbortException(MetaAdsSyncFailureCode::ACCOUNT_CHANGED);
        }

        $changes = ['account_status' => $details->accountStatus, 'updated_at' => $context->stamp()];

        if ($details->name !== null && trim($details->name) !== '') {
            $changes['name'] = mb_substr(trim($details->name), 0, 191);
        }

        if (in_array($details->timeZone, timezone_identifiers_list(), true)) {
            $changes['time_zone'] = $details->timeZone;
        }

        DB::table('meta_ads_accounts')
            ->where('id', $context->accountId())
            ->where('business_id', $context->businessId())
            ->update($changes);

        $context->account->refresh();

        return new MetaAdsStageResult(1);
    }
}
