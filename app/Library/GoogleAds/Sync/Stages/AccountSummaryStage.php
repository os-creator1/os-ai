<?php

namespace App\Library\GoogleAds\Sync\Stages;

use App\DTO\GoogleAds\GoogleAdsCustomerDetails;
use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\Sync\GoogleAdsStageResult;
use App\Library\GoogleAds\Sync\GoogleAdsSyncAbortException;
use App\Library\GoogleAds\Sync\GoogleAdsSyncContext;
use App\Library\GoogleAds\Sync\GoogleAdsSyncFailureCode;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes name / time zone / test flag from `customer`, and is the
 * CURRENCY GUARD: the stored currency_code is the one every fact row is
 * denominated in, so a different currency from Google stops the run before
 * any fact is written (contract §4 "never mix currencies").
 *
 * The single customer is wrapped in a one-row report so the stage fits the
 * same fetch/persist seam as the others.
 */
final class AccountSummaryStage extends AbstractGoogleAdsStage
{
    public function key(): string
    {
        return 'account';
    }

    public function fetch(GoogleAdsSyncContext $context): GoogleAdsReportResult
    {
        return new GoogleAdsReportResult([$this->client->customerDetails($context->access)]);
    }

    public function persist(GoogleAdsSyncContext $context, GoogleAdsReportResult $report): GoogleAdsStageResult
    {
        /** @var GoogleAdsCustomerDetails|null $details */
        $details = $report->rows[0] ?? null;

        if (! $details instanceof GoogleAdsCustomerDetails
            || $details->customerId !== $context->account->customer_id
            || $details->currencyCode === null) {
            // We cannot verify we are looking at the same, same-currency account.
            throw new GoogleAdsSyncAbortException(GoogleAdsProviderException::UNEXPECTED_RESPONSE);
        }

        if (strtoupper($details->currencyCode) !== strtoupper((string) $context->account->currency_code)) {
            throw new GoogleAdsSyncAbortException(GoogleAdsSyncFailureCode::CURRENCY_CHANGED);
        }

        $changes = ['is_test_account' => $details->isTest, 'updated_at' => $context->stamp()];

        if ($details->name !== null) {
            $changes['descriptive_name'] = $details->name;
        }

        if ($details->timeZone !== null) {
            $changes['time_zone'] = $details->timeZone;
        }

        DB::table('google_ads_accounts')
            ->where('id', $context->accountId())
            ->where('business_id', $context->businessId())
            ->update($changes);

        $context->account->refresh();

        return new GoogleAdsStageResult(1);
    }
}
