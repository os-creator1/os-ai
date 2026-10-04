<?php

namespace App\Library\MetaAds\Sync\Stages;

use App\DTO\MetaAds\MetaFrequencyRow;
use App\Library\MetaAds\Sync\MetaAdsStageReport;
use App\Library\MetaAds\Sync\MetaAdsStageResult;
use App\Library\MetaAds\Sync\MetaAdsSyncContext;
use Illuminate\Support\Facades\DB;

/**
 * Each ad set's OWN trailing-7-day reach and frequency (contract 24 §5.3):
 * one non-daily insights call over the last 7 COMPLETE days ending yesterday
 * in the account time zone, written onto meta_ads_ad_sets (reach_7d,
 * frequency_7d, frequency_window_end).
 *
 * Reach and frequency are not additive, so they are never stored per day and
 * never summed. An ad set Meta did not report in this window has no 7-day
 * figure NOW: after a COMPLETE (non-truncated) report its figures are set to
 * NULL (unavailable — never 0, never the previous window's number dressed as
 * current). A truncated report makes no such inference. A row for an ad set
 * this account does not hold is skipped and counted.
 */
final class FrequencyStage extends AbstractMetaAdsStage
{
    public function key(): string
    {
        return 'frequency';
    }

    public function fetch(MetaAdsSyncContext $context): MetaAdsStageReport
    {
        return $this->paged(fn (?string $cursor) => $this->client->frequency7d(
            $context->accessToken(),
            $context->adAccountId(),
            $context->frequencyStart,
            $context->frequencyEnd,
            $cursor,
        ));
    }

    public function persist(MetaAdsSyncContext $context, MetaAdsStageReport $report): MetaAdsStageResult
    {
        $stamp = $context->stamp();
        $adSets = $this->idMap('meta_ads_ad_sets', 'external_ad_set_id', $context);

        $updates = [];
        $skipped = 0;

        /** @var MetaFrequencyRow $row */
        foreach ($report->rows as $row) {
            $id = $adSets[$row->externalAdSetId] ?? null;

            if ($id === null) {
                $skipped++;

                continue;
            }

            $updates[$id] = [
                'reach_7d' => $row->reach,
                'frequency_7d' => $row->frequency === null || $row->frequency < 0 || $row->frequency >= 1_000_000
                    ? null
                    : number_format($row->frequency, 4, '.', ''),
                'frequency_window_end' => $context->frequencyEnd,
            ];
        }

        foreach (array_chunk($updates, self::CHUNK, true) as $chunk) {
            DB::transaction(function () use ($chunk, $context, $stamp): void {
                foreach ($chunk as $id => $values) {
                    DB::table('meta_ads_ad_sets')
                        ->where('id', $id)
                        ->where('meta_ads_account_id', $context->accountId())
                        ->where('business_id', $context->businessId())
                        ->update($values + ['updated_at' => $stamp]);
                }
            });
        }

        if (! $report->truncated) {
            $query = DB::table('meta_ads_ad_sets')
                ->where('meta_ads_account_id', $context->accountId())
                ->where('business_id', $context->businessId());

            if ($updates !== []) {
                $query->whereNotIn('id', array_keys($updates));
            }

            $query->update(['reach_7d' => null, 'frequency_7d' => null, 'frequency_window_end' => null, 'updated_at' => $stamp]);
        }

        return new MetaAdsStageResult(count($updates), $report->truncated, null, $skipped);
    }
}
