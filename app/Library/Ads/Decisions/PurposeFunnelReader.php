<?php

namespace App\Library\Ads\Decisions;

use App\Library\MetaAds\Attribution\MetaAdsLeadAttributionReader;
use App\Models\AcquisitionPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Acquisition Purpose + Ads Decisioning V1 — what happened to the people one
 * provider's ads brought, inside ONE purpose's CRM pipeline.
 *
 *   inquiries  Opportunities opened in the purpose's pipeline in the period whose
 *              contact's FIRST attribution touch is this provider's
 *   qualified  of those, the ones that are WON or have moved past the pipeline's
 *              first (new_inquiry) stage. An inquiry nobody has worked yet, or one
 *              lost at the very first stage, is not "qualified".
 *   outcomes   of those, the ones that are WON (the enrolled student, the hire)
 *
 * It is a COHORT view: the opportunities CREATED in the period, followed to
 * wherever they are now. Read-only, Business-scoped, bounded (aggregate
 * counts only). Attribution is deliberately honest about its strength: a first
 * touch tagged with the provider's source (or, for Google, carrying a click id)
 * — never a claim about which campaign, ad set or ad.
 */
final class PurposeFunnelReader
{
    /** utm_source values that mean "tagged as Google Ads" when no click id is present. */
    public const GOOGLE_SOURCE_TAGS = ['google', 'googleads', 'google_ads', 'adwords'];

    /**
     * @return array{inquiries: int, qualified: int, outcomes: int, milestone: ?int}
     */
    public function read(AcquisitionPurpose $purpose, string $provider, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($purpose->crm_pipeline_id === null) {
            return ['inquiries' => 0, 'qualified' => 0, 'outcomes' => 0, 'milestone' => null];
        }

        $firstStageId = $this->firstStageId((int) $purpose->crm_pipeline_id);
        $milestonePosition = $this->milestonePosition($purpose);

        $row = DB::table('crm_opportunities as o')
            ->join('crm_pipeline_stages as st', 'st.id', '=', 'o.stage_id')
            ->where('o.business_id', $purpose->business_id)
            ->where('o.pipeline_id', $purpose->crm_pipeline_id)
            ->whereBetween('o.created_at', [$this->storage($from->startOfDay()), $this->storage($to->endOfDay())])
            ->whereIn('o.contact_id', $this->providerContacts((int) $purpose->business_id, $provider))
            ->selectRaw(
                'COUNT(*) AS inquiries,
                 COALESCE(SUM(CASE WHEN o.status = ? OR o.stage_id <> ? THEN 1 ELSE 0 END), 0) AS qualified,
                 COALESCE(SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END), 0) AS outcomes,
                 COALESCE(SUM(CASE WHEN o.status = ? OR st.position >= ? THEN 1 ELSE 0 END), 0) AS milestone',
                ['won', $firstStageId ?? 0, 'won', 'won', $milestonePosition ?? PHP_INT_MAX],
            )
            ->first();

        return [
            'inquiries' => (int) ($row->inquiries ?? 0),
            'qualified' => (int) ($row->qualified ?? 0),
            'outcomes' => (int) ($row->outcomes ?? 0),
            'milestone' => $milestonePosition === null ? null : (int) ($row->milestone ?? 0),
        ];
    }

    /** The position of the stage the purpose names as an extra KPI (labels.milestone_stage = a stage semantic key), if the pipeline has it. */
    private function milestonePosition(AcquisitionPurpose $purpose): ?int
    {
        $key = $purpose->labels['milestone_stage'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        $position = DB::table('crm_pipeline_stages')
            ->where('pipeline_id', $purpose->crm_pipeline_id)
            ->where('semantic_key', $key)
            ->whereNull('archived_at')
            ->value('position');

        return $position === null ? null : (int) $position;
    }

    /**
     * The tracking probe: how many distinct contacts have a first touch from this
     * provider in the lookback, across ALL of the Business's goals. Zero together
     * with clicks is what "tracking missing" means.
     */
    public function attributedTouches(int $businessId, string $provider, CarbonImmutable $now): int
    {
        $since = $this->storage($now->subDays((int) config('ads_decisions.attribution.tracking_lookback_days', 30)));

        return (int) $this->providerTouches($businessId, $provider)
            ->where('captured_at', '>=', $since)
            ->distinct()
            ->count('contact_id');
    }

    private function firstStageId(int $pipelineId): ?int
    {
        $id = DB::table('crm_pipeline_stages')
            ->where('pipeline_id', $pipelineId)
            ->whereNull('archived_at')
            ->orderByRaw("CASE WHEN semantic_key = 'new_inquiry' THEN 0 ELSE 1 END")
            ->orderBy('position')
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function providerContacts(int $businessId, string $provider): Builder
    {
        return $this->providerTouches($businessId, $provider)->select('contact_id');
    }

    private function providerTouches(int $businessId, string $provider): Builder
    {
        $query = DB::table('lead_attribution_touches')
            ->where('business_id', $businessId)
            ->where('touch_role', 'first')
            ->whereNotNull('contact_id');

        if ($provider === 'google') {
            $tags = self::GOOGLE_SOURCE_TAGS;

            return $query->where(function ($q) use ($tags): void {
                $q->whereNotNull('gclid')->orWhereNotNull('gbraid')->orWhereNotNull('wbraid')
                    ->orWhereRaw('LOWER(utm_source) IN (' . implode(',', array_fill(0, count($tags), '?')) . ')', $tags);
            });
        }

        $tags = MetaAdsLeadAttributionReader::SOURCE_TAGS;

        return $query->whereRaw('LOWER(utm_source) IN (' . implode(',', array_fill(0, count($tags), '?')) . ')', $tags);
    }

    /** `created_at` is stored in the application timezone, not UTC. */
    private function storage(CarbonImmutable $moment): string
    {
        return $moment->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
