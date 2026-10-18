<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Library\Growth\Readers\Concerns\BucketsByLocation;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * CRM pipeline facts. TWO queries, however many deals the Business has:
 * one for the open deals (each deal's last recorded activity is folded in by
 * a correlated sub-select, not a per-deal lookup) and one for the new-lead
 * period counts.
 *
 * Fact shape (domain `crm`):
 *   open_count   int   open deals considered
 *   truncated    bool  more than ROW_CAP open deals exist (facts are partial)
 *   by_location  array<int, array{unanswered, stale, high_value_stale}>
 *                  keyed by Location id (0 = no Location); each value is a
 *                  bucket {count, value_minor, currency, mixed_currency, uids}
 *   new_leads    array{current: int, previous: int}  7-day windows
 *
 * Definitions (all from canonical CRM columns, never inferred):
 *   unanswered        open, contact_status = no_contact, created_at older
 *                     than unanswered_lead_hours
 *   stale             open, last activity older than stale_deal_days, value
 *                     below high_value_deal_minor (or no value)
 *   high_value_stale  as stale, value >= high_value_deal_minor
 * Last activity = latest of created_at, stage_entered_at and the newest
 * crm_opportunity_history row. A deal is counted in AT MOST ONE bucket: a
 * deal that is both unanswered and stale is "unanswered" only, because
 * "reply to this lead" is the more actionable statement of the same problem.
 */
final class GrowthCrmFactReader implements GrowthFactReader
{
    use BucketsByLocation;

    private const ROW_CAP = 5000;

    public function domain(): string
    {
        return 'crm';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::Crm;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $history = DB::table('crm_opportunity_history as h')
            ->whereColumn('h.opportunity_id', 'o.id')
            ->selectRaw('MAX(h.created_at)');

        $rows = DB::table('crm_opportunities as o')
            ->where('o.business_id', $business->id)
            ->where('o.status', 'open')
            ->select(['o.uid', 'o.location_id', 'o.value_minor', 'o.currency_code', 'o.contact_status', 'o.created_at', 'o.stage_entered_at'])
            ->selectSub($history, 'last_history_at')
            ->orderBy('o.id')
            ->limit(self::ROW_CAP + 1)
            ->get();

        $truncated = $rows->count() > self::ROW_CAP;

        if ($truncated) {
            $rows = $rows->take(self::ROW_CAP);
        }

        $unansweredBefore = $now->subHours($thresholds->get('unanswered_lead_hours'));
        $staleBefore = $now->subDays($thresholds->get('stale_deal_days'));
        $highValue = $thresholds->get('high_value_deal_minor');

        $byLocation = [];

        foreach ($rows as $row) {
            $key = $this->locationKey($row->location_id);
            $byLocation[$key] ??= [
                'unanswered' => $this->emptyBucket(),
                'stale' => $this->emptyBucket(),
                'high_value_stale' => $this->emptyBucket(),
            ];

            $value = $row->value_minor === null ? null : (int) $row->value_minor;
            $created = CarbonImmutable::parse($row->created_at);

            if ($row->contact_status === 'no_contact' && $created->lte($unansweredBefore)) {
                $byLocation[$key]['unanswered'] = $this->addToBucket($byLocation[$key]['unanswered'], $row->uid, $value, $row->currency_code);

                continue;
            }

            $lastActivity = collect([$row->created_at, $row->stage_entered_at, $row->last_history_at])
                ->filter()
                ->map(fn ($v) => CarbonImmutable::parse($v))
                ->max();

            if ($lastActivity !== null && $lastActivity->lte($staleBefore)) {
                $bucket = ($value !== null && $value >= $highValue) ? 'high_value_stale' : 'stale';
                $byLocation[$key][$bucket] = $this->addToBucket($byLocation[$key][$bucket], $row->uid, $value, $row->currency_code);
            }
        }

        $windows = DB::table('crm_opportunities')
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $now->subDays(14))
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS cur', [$now->subDays(7)])
            ->selectRaw('SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) AS prev', [$now->subDays(7)])
            ->first();

        return GrowthFactSet::available($this->domain(), [
            'open_count' => $rows->count(),
            'truncated' => $truncated,
            'by_location' => $byLocation,
            'new_leads' => ['current' => (int) ($windows->cur ?? 0), 'previous' => (int) ($windows->prev ?? 0)],
        ]);
    }
}
