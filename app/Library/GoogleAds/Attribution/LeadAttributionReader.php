<?php

namespace App\Library\GoogleAds\Attribution;

use App\Library\Contacts\ContactDirectory;
use App\Models\Business;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Google Ads Module V1 contract §10/§11 — read-only view of a Business's
 * leads and where they came from, for the Leads & conversions page.
 *
 * A LEAD is a distinct Contact created or matched by one of the three public
 * conversion events (public form submission, non-spam website form
 * submission, public booking) inside the period. Business OS records a touch
 * only when the visitor arrived with a click id or UTM, so a lead with no
 * touch is `not_captured` — and nothing here ever claims which Google
 * campaign or keyword produced a lead.
 *
 * Every query is scoped to the Business and bounded by the page size; the
 * number of queries does not depend on the number of rows.
 */
class LeadAttributionReader
{
    public const PER_PAGE = 25;

    private const TAG_COLUMNS = [
        'gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_page',
    ];

    /**
     * One page of leads, newest conversion first.
     *
     * Row: contact{id,uid,name,phone}, level, first_touch, last_touch (null unless a
     * different last touch exists), entry_surface, landing_page, captured_at,
     * opportunity{stage,status,value_minor,currency}|null, booked,
     * subject{type,id} (the contact's latest conversion in the period).
     */
    public function page(Business $business, CarbonInterface $from, CarbonInterface $to, int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        $leads = $this->leadIds($business, $from, $to);
        $total = (int) DB::query()->fromSub($leads, 'l')->count();

        $ids = $total === 0 ? [] : DB::query()
            ->fromSub($this->events($business, $from, $to), 'e')
            ->groupBy('contact_id')
            ->orderByRaw('MAX(occurred_at) DESC')
            ->orderByDesc('contact_id')
            ->forPage($page, $perPage)
            ->pluck('contact_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return new LengthAwarePaginator($this->rows($business, $from, $to, $ids), $total, $perPage, $page);
    }

    /**
     * Distinct-contact lead count for the period and how many sit at each level.
     *
     * @return array{leads: int, google_click: int, campaign_tags: int, not_captured: int}
     */
    public function summary(Business $business, CarbonInterface $from, CarbonInterface $to): array
    {
        $flags = DB::table('lead_attribution_touches')
            ->where('business_id', $business->id)
            ->whereNotNull('contact_id')
            ->groupBy('contact_id')
            ->selectRaw(
                'contact_id,
                 MAX(CASE WHEN gclid IS NOT NULL OR gbraid IS NOT NULL OR wbraid IS NOT NULL THEN 1 ELSE 0 END) AS has_click,
                 MAX(CASE WHEN utm_source IS NOT NULL OR utm_medium IS NOT NULL OR utm_campaign IS NOT NULL
                          OR utm_term IS NOT NULL OR utm_content IS NOT NULL THEN 1 ELSE 0 END) AS has_utm'
            );

        $row = DB::query()
            ->fromSub($this->leadIds($business, $from, $to), 'l')
            ->leftJoinSub($flags, 't', 't.contact_id', '=', 'l.contact_id')
            ->selectRaw(
                'COUNT(*) AS leads,
                 COALESCE(SUM(CASE WHEN t.has_click = 1 THEN 1 ELSE 0 END), 0) AS google_click,
                 COALESCE(SUM(CASE WHEN COALESCE(t.has_click, 0) = 0 AND t.has_utm = 1 THEN 1 ELSE 0 END), 0) AS campaign_tags'
            )
            ->first();

        $leads = (int) ($row->leads ?? 0);
        $click = (int) ($row->google_click ?? 0);
        $tags = (int) ($row->campaign_tags ?? 0);

        return ['leads' => $leads, 'google_click' => $click, 'campaign_tags' => $tags, 'not_captured' => $leads - $click - $tags];
    }

    /**
     * The contact's FIRST (oldest) click id, for a later offline conversion
     * upload (deferred, contract D6). Business-scoped; no upload logic here.
     *
     * @return array{type: string, value: string, captured_at: string}|null
     */
    public function firstClickIdFor(Business|int $business, int $contactId): ?array
    {
        $businessId = $business instanceof Business ? (int) $business->id : $business;

        $row = DB::table('lead_attribution_touches')
            ->where('business_id', $businessId)
            ->where('contact_id', $contactId)
            ->where(fn ($q) => $q->whereNotNull('gclid')->orWhereNotNull('gbraid')->orWhereNotNull('wbraid'))
            ->orderBy('captured_at')
            ->orderBy('id')
            ->first(['gclid', 'gbraid', 'wbraid', 'captured_at']);

        if ($row === null) {
            return null;
        }

        foreach (AttributionParameters::CLICK_IDS as $type) {
            if ($row->{$type} !== null) {
                return ['type' => $type, 'value' => (string) $row->{$type}, 'captured_at' => (string) $row->captured_at];
            }
        }

        return null;
    }

    private function leadIds(Business $business, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return DB::query()->fromSub($this->events($business, $from, $to), 'e')->select('contact_id')->distinct();
    }

    /**
     * The conversion events of the period that resolved a contact, from the
     * three public surfaces (staff-created appointments and spam excluded).
     */
    private function events(Business $business, CarbonInterface $from, CarbonInterface $to, ?array $contactIds = null): Builder
    {
        $scope = function (Builder $query, string $table) use ($from, $to, $contactIds): Builder {
            $query->whereNotNull("{$table}.contact_id")->whereBetween("{$table}.created_at", [$from, $to]);

            return $contactIds === null ? $query : $query->whereIn("{$table}.contact_id", $contactIds);
        };

        $forms = $scope(
            DB::table('form_submissions as s')->where('s.business_id', $business->id),
            's'
        )->selectRaw("s.contact_id, s.created_at AS occurred_at, 'form_submission' AS subject_type, s.id AS subject_id, 'public_form' AS surface");

        $website = $scope(
            DB::table('website_form_submissions as s')
                ->join('website_forms as wf', 'wf.id', '=', 's.website_form_id')
                ->join('websites as w', 'w.id', '=', 'wf.website_id')
                ->where('w.business_id', $business->id)
                ->where('s.is_spam', false),
            's'
        )->selectRaw("s.contact_id, s.created_at AS occurred_at, 'website_form_submission' AS subject_type, s.id AS subject_id, 'website_form' AS surface");

        $bookings = $scope(
            DB::table('appointments as s')
                ->join('business_locations as bl', 'bl.id', '=', 's.business_location_id')
                ->where('bl.business_id', $business->id)
                ->whereNull('s.created_by_user_id'),
            's'
        )->selectRaw("s.contact_id, s.created_at AS occurred_at, 'appointment' AS subject_type, s.id AS subject_id, 'booking' AS surface");

        return $forms->unionAll($website)->unionAll($bookings);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function rows(Business $business, CarbonInterface $from, CarbonInterface $to, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $events = DB::query()->fromSub($this->events($business, $from, $to, $ids), 'e')
            ->orderByDesc('occurred_at')->orderByDesc('subject_id')
            ->get(['contact_id', 'subject_type', 'subject_id', 'surface'])
            ->unique('contact_id')->keyBy('contact_id');

        $touches = DB::table('lead_attribution_touches')
            ->where('business_id', $business->id)
            ->whereIn('contact_id', $ids)
            ->orderBy('captured_at')->orderBy('id')
            ->get()->groupBy('contact_id');

        $contacts = (new ContactDirectory)->summaries($business, $ids);

        $opportunities = DB::table('crm_opportunities as o')
            ->join('crm_pipeline_stages as st', 'st.id', '=', 'o.stage_id')
            ->where('o.business_id', $business->id)
            ->whereIn('o.id', fn ($q) => $q->from('crm_opportunities')->selectRaw('MAX(id)')
                ->where('business_id', $business->id)->whereIn('contact_id', $ids)->groupBy('contact_id'))
            ->get(['o.contact_id', 'o.status', 'o.value_minor', 'o.currency_code', 'st.name as stage_name'])
            ->keyBy('contact_id');

        $booked = DB::table('appointments as a')
            ->join('business_locations as bl', 'bl.id', '=', 'a.business_location_id')
            ->where('bl.business_id', $business->id)
            ->whereIn('a.contact_id', $ids)
            ->distinct()->pluck('a.contact_id')->flip();

        $rows = [];
        foreach ($ids as $id) {
            $mine = $touches->get($id, collect());
            $first = $mine->firstWhere('touch_role', 'first');
            $last = $mine->where('touch_role', 'last')->last();
            $event = $events->get($id);
            $opportunity = $opportunities->get($id);

            $rows[] = [
                'contact' => ['id' => $id, 'uid' => $contacts[$id]['uid'] ?? null, 'name' => $contacts[$id]['name'] ?? null, 'phone' => $contacts[$id]['phone'] ?? null],
                'level' => $this->level($mine),
                'first_touch' => $first === null ? null : $this->touch($first),
                'last_touch' => $last === null ? null : $this->touch($last),
                'entry_surface' => $first->entry_surface ?? $event->surface ?? null,
                'landing_page' => $first->landing_page ?? null,
                'captured_at' => $first->captured_at ?? null,
                'opportunity' => $opportunity === null ? null : [
                    'stage' => (string) $opportunity->stage_name,
                    'status' => (string) $opportunity->status,
                    'value_minor' => $opportunity->value_minor === null ? null : (int) $opportunity->value_minor,
                    'currency' => (string) ($opportunity->currency_code ?: $business->currency_code),
                ],
                'booked' => $booked->has($id),
                'subject' => $event === null ? null : ['type' => (string) $event->subject_type, 'id' => (int) $event->subject_id],
            ];
        }

        return $rows;
    }

    /** Any click id wins over UTM-only; no touch at all is not captured. */
    private function level(iterable $touches): LeadAttributionLevel
    {
        $level = LeadAttributionLevel::NotCaptured;

        foreach ($touches as $touch) {
            if ($touch->gclid !== null || $touch->gbraid !== null || $touch->wbraid !== null) {
                return LeadAttributionLevel::GoogleClick;
            }

            if ($touch->utm_source !== null || $touch->utm_medium !== null || $touch->utm_campaign !== null
                || $touch->utm_term !== null || $touch->utm_content !== null) {
                $level = LeadAttributionLevel::CampaignTags;
            }
        }

        return $level;
    }

    /** @return array<string, ?string> */
    private function touch(object $row): array
    {
        $touch = [];
        foreach (self::TAG_COLUMNS as $column) {
            $touch[$column] = $row->{$column};
        }

        return $touch;
    }
}
