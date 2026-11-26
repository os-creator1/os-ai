<?php

namespace App\Library\AgencyOutreach\Analytics;

use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectingChannel;
use App\Models\CustomFieldDefinition;
use App\Models\AgencyProspectImportBatch;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Workspace-scoped Outreach analytics read model. Everything is computed in SQL from the authoritative
 * rows (confirmed outbound messages, inbound timestamps, members, prospects); nothing is loaded
 * message-by-message into PHP and nothing is estimated.
 *
 * ATTRIBUTION (the one rule every number shares)
 *   - A message "reached" a step only if it was CONFIRMED SENT (status sent + sent_at). A queued, paused
 *     or failed row is never a send.
 *   - A step is the row's immutable `sequence_step_id`; the campaign opener (purpose initial, no step id)
 *     is the "opener" step. Rows with neither are conversation replies / hand-written messages: they are
 *     not steps and never counted as one ("unattributed").
 *   - An inbound belongs to the confirmed outbound message IMMEDIATELY before it in that conversation. A
 *     reply that arrives after a later step, an AI answer or a person's manual message belongs to THAT
 *     message, never to an earlier step. An inbound with no earlier confirmed outbound is unattributed.
 *   - Texts are the snapshot stored on the message at send time, so later script edits never rewrite
 *     history. The opener/steps keep the script_version they were sent under.
 *
 * COHORT: prospect-level figures cover prospects FIRST CONTACTED inside the selected period, followed
 * through every later reply, booking and win. Imported / Eligible are the current totals in scope.
 */
final class OutreachAnalytics
{
    public const SMALL_SAMPLE = 20;

    /** Custom field types that make sense as a filter value / comparison dimension. */
    public const SEGMENT_FIELD_TYPES = ['text', 'select', 'number', 'boolean', 'email', 'phone'];

    private int $ws;
    private ?int $campaignId = null;
    private ?int $batchId = null;
    private ?int $groupId = null;
    private ?CustomFieldDefinition $cf = null;
    /** @var array<string,mixed> */
    private array $bind = [];
    private int $n = 0;

    public function __construct(private readonly Workspace $workspace, private readonly AnalyticsFilters $f)
    {
        $this->ws = (int) $workspace->id;

        if ($f->campaignUid !== null) {
            $this->campaignId = AgencyProspectCampaign::query()->where('workspace_id', $this->ws)->where('uid', $f->campaignUid)->value('id');
            // An unknown / foreign campaign matches nothing, it never widens the scope.
            $this->campaignId ??= 0;
        }

        if ($f->groupUid !== null) {
            // Only a group that actually references this Workspace's prospects can match; a foreign uid matches nothing.
            $this->groupId = DB::table('contact_groups as cg')->join('agency_prospect_group_links as gl', 'gl.contact_group_id', '=', 'cg.id')
                ->where('gl.workspace_id', $this->ws)->where('cg.uid', $f->groupUid)->value('cg.id') ?? 0;
        }

        if ($f->cfKey !== null) {
            $business = \App\Library\AgencyOutreach\AgencyOutreachBusinessResolver::forWorkspace($workspace);
            $this->cf = $business === null ? null : CustomFieldDefinition::query()->where('business_id', $business->id)
                ->where('entity', CustomFieldDefinition::ENTITY_CONTACT)->where('key', $f->cfKey)->first();
            // An unknown field (or none) with a requested filter matches nothing.
            $this->cf ??= new CustomFieldDefinition(['id' => 0, 'type' => 'text']);
        }

        if ($f->batchUid !== null) {
            $this->batchId = AgencyProspectImportBatch::query()->where('workspace_id', $this->ws)->where('uid', $f->batchUid)->value('id') ?? 0;
        }
    }

    // ------------------------------------------------------------------ SQL building blocks

    private function b(mixed $v): string
    {
        $k = 'p' . (++$this->n);
        $this->bind[$k] = $v;

        return ':' . $k;
    }

    private function camp(string $alias = 'cm'): string
    {
        return $this->campaignId === null ? '' : " AND {$alias}.campaign_id = " . (int) $this->campaignId;
    }

    /** Conditions on the prospect row `p`. */
    private function prospectConds(): string
    {
        $c = " p.workspace_id = {$this->ws}";
        $f = $this->f;

        if ($f->businessType !== null) {
            $c .= ' AND p.business_type = ' . $this->b($f->businessType);
        }

        if ($f->geo !== null) {
            $c .= ' AND (p.state = ' . $this->b($f->geo) . ' OR p.city = ' . $this->b($f->geo) . ')';
        }

        if ($f->rating !== null) {
            [$lo, $hi] = AnalyticsFilters::RATING_BANDS[$f->rating];
            $c .= " AND p.google_rating >= {$lo} AND p.google_rating < {$hi}";
        }

        if ($f->reviews !== null) {
            [$lo, $hi] = AnalyticsFilters::REVIEW_BANDS[$f->reviews];
            $c .= " AND p.review_count >= {$lo}" . ($hi !== null ? " AND p.review_count < {$hi}" : '');
        }

        if ($f->website !== null) {
            $c .= match ($f->website) {
                'yes' => ' AND p.has_website = 1',
                'no' => ' AND p.has_website = 0',
                default => ' AND p.has_website IS NULL',
            };
        }

        if ($this->batchId !== null) {
            $c .= ' AND p.import_batch_id = ' . (int) $this->batchId;
        }

        if ($f->q !== null) {
            $like = '%' . addcslashes($f->q, '%_\\') . '%';
            $c .= ' AND (p.company_name LIKE ' . $this->b($like) . ' OR p.phone LIKE ' . $this->b($like) . ')';
        }

        if ($this->groupId !== null) {
            $c .= ' AND EXISTS (SELECT 1 FROM agency_prospect_group_links glf WHERE glf.prospect_id = p.id AND glf.workspace_id = ' . $this->ws . ' AND glf.contact_group_id = ' . (int) $this->groupId . ')';
        }

        if ($this->cf !== null) {
            $c .= ' AND EXISTS (SELECT 1 FROM agency_prospect_group_links glc JOIN custom_field_values cfv ON cfv.contact_id = glc.contact_id AND cfv.definition_id = ' . (int) $this->cf->id
                . ' WHERE glc.prospect_id = p.id AND glc.workspace_id = ' . $this->ws . $this->cfValueCondition() . ')';
        }

        if ($f->stepKey !== null) {
            $c .= ' AND EXISTS (SELECT 1 FROM agency_prospect_messages ms JOIN agency_prospect_campaign_members cms ON cms.id = ms.campaign_member_id
                WHERE cms.prospect_id = p.id AND cms.workspace_id = ' . $this->ws . " AND ms.direction = 'outbound' AND ms.status = 'sent' AND ms.sent_at IS NOT NULL
                AND COALESCE(ms.sequence_step_id, CASE WHEN ms.purpose = 'initial' THEN 'opener' END) = " . $this->b($f->stepKey) . ')';
        }

        if ($this->campaignId !== null) {
            $c .= ' AND EXISTS (SELECT 1 FROM agency_prospect_campaign_members cmx WHERE cmx.prospect_id = p.id AND cmx.workspace_id = ' . $this->ws . ' AND cmx.campaign_id = ' . (int) $this->campaignId . ')';
        }

        return $c;
    }

    /** The value test for the selected custom field (type-aware); no value given = "has any value". */
    private function cfValueCondition(): string
    {
        $v = $this->f->cfValue;

        if ($v === null || $this->cf === null) {
            return '';
        }

        return match ($this->cf->type) {
            'number', 'currency' => is_numeric($v) ? ' AND cfv.value_number = ' . $this->b((string) $v) : ' AND 1 = 0',
            'boolean' => ' AND cfv.value_bool = ' . (in_array(mb_strtolower($v), ['yes', 'true', '1'], true) ? 1 : 0),
            default => ' AND cfv.value_text = ' . $this->b($v),
        };
    }

    /** Every confirmed send of the Workspace, with NO display filter: the complete timeline successors are read from. */
    private function sentBase(): string
    {
        return " m.workspace_id = {$this->ws} AND m.direction = 'outbound' AND m.status = 'sent' AND m.sent_at IS NOT NULL";
    }

    /**
     * Display filters (period, sending number, script version) for journey rows `j`. They decide which steps are SHOWN;
     * they are applied after each step's successor/predecessor has been read from the complete timeline, so a filter can
     * never make a later step vanish and hand its advancement, booking or win to an earlier one.
     */
    private function displayConds(): string
    {
        $c = '';

        if ($this->f->channelId !== null) {
            $c .= ' AND j.channel_id = ' . (int) $this->f->channelId;
        }

        if ($this->f->scriptVersion !== null) {
            $c .= ' AND j.script_version = ' . (int) $this->f->scriptVersion;
        }

        [$from, $to] = $this->f->window();
        $from !== null && $c .= ' AND j.sent_at >= ' . $this->b($from->toDateTimeString());
        $to !== null && $c .= ' AND j.sent_at < ' . $this->b($to->toDateTimeString());

        return $c;
    }

    /** Message-level conditions on an outbound row `m`. */
    private function outConds(bool $withWindow): string
    {
        $c = " m.workspace_id = {$this->ws} AND m.direction = 'outbound' AND m.status = 'sent' AND m.sent_at IS NOT NULL";

        if ($this->f->channelId !== null) {
            $c .= ' AND m.channel_id = ' . (int) $this->f->channelId;
        }

        if ($this->f->scriptVersion !== null) {
            $c .= ' AND m.script_version = ' . (int) $this->f->scriptVersion;
        }

        if ($withWindow) {
            [$from, $to] = $this->f->window();
            $from !== null && $c .= ' AND m.sent_at >= ' . $this->b($from->toDateTimeString());
            $to !== null && $c .= ' AND m.sent_at < ' . $this->b($to->toDateTimeString());
        }

        return $c;
    }

    /**
     * One row per prospect in scope, with the flags every KPI / funnel / segment / explorer row shares.
     * $cohort = true applies the period to FIRST CONTACT; false looks at all time (used for journey rows).
     */
    private function prospectSql(bool $cohort): string
    {
        $camp = $this->camp('cm');
        $out = $this->outConds(false);

        $firstSent = "(SELECT MIN(m.sent_at) FROM agency_prospect_messages m JOIN agency_prospect_campaign_members cm ON cm.id = m.campaign_member_id WHERE cm.prospect_id = p.id AND cm.workspace_id = {$this->ws}{$camp} AND {$out})";

        $contactedCond = "first_sent IS NOT NULL";

        if ($cohort) {
            [$from, $to] = $this->f->window();
            $from !== null && $contactedCond .= ' AND first_sent >= ' . $this->b($from->toDateTimeString());
            $to !== null && $contactedCond .= ' AND first_sent < ' . $this->b($to->toDateTimeString());
        }

        $inner = "SELECT p.id, p.uid, p.company_name, p.phone, p.business_type, p.google_rating, p.review_count, p.has_website, p.state, p.city,
            p.import_batch_id, p.status, p.stop_reason, p.client_won_at, p.acquisition_member_id, p.booked_at, p.created_at,
            {$firstSent} AS first_sent,
            (SELECT MAX(cm.stage) FROM agency_prospect_campaign_members cm WHERE cm.prospect_id = p.id AND cm.workspace_id = {$this->ws}{$camp}) AS max_stage,
            (SELECT mb.campaign_member_id FROM agency_prospect_messages mb JOIN agency_prospect_campaign_members cmb ON cmb.id = mb.campaign_member_id
                WHERE cmb.prospect_id = p.id AND cmb.workspace_id = {$this->ws} AND mb.workspace_id = {$this->ws} AND mb.direction = 'outbound' AND mb.status = 'sent' AND mb.sent_at IS NOT NULL
                  AND (mb.sequence_step_id IS NOT NULL OR mb.purpose = 'initial') AND p.booked_at IS NOT NULL AND mb.sent_at <= p.booked_at
                ORDER BY mb.sent_at DESC, mb.id DESC LIMIT 1) AS booked_member_id,
            EXISTS (SELECT 1 FROM agency_prospect_messages i JOIN agency_prospect_campaign_members cm ON cm.id = i.campaign_member_id WHERE cm.prospect_id = p.id AND cm.workspace_id = {$this->ws}{$camp} AND i.workspace_id = {$this->ws} AND i.direction = 'inbound' AND i.received_at IS NOT NULL) AS has_reply,
            EXISTS (SELECT 1 FROM agency_prospect_exclusions e WHERE e.workspace_id = {$this->ws} AND e.active_phone = p.phone) AS is_excluded
            FROM agency_prospects p WHERE" . $this->prospectConds();

        $mid = "SELECT q.*, ({$contactedCond}) AS contacted FROM ({$inner}) q";

        // Raw flags first, then the exclusive outcome (priority: won > booked > opted out > rejected > interested > replied).
        return "SELECT z.*,
            CASE WHEN z.contacted = 0 THEN 'none'
                 WHEN z.is_won = 1 THEN 'won'
                 WHEN z.is_booked = 1 THEN 'booked'
                 WHEN z.status = 'stopped' AND z.stop_reason = 'opt_out' THEN 'opted_out'
                 WHEN z.status = 'stopped' AND z.stop_reason = 'rejected' THEN 'rejected'
                 WHEN z.has_reply = 1 AND z.max_stage BETWEEN 3 AND 5 THEN 'interested'
                 WHEN z.has_reply = 1 THEN 'replied'
                 ELSE 'no_response' END AS outcome
            FROM (SELECT y.*, (y.client_won_at IS NOT NULL) AS is_won, COALESCE(y.status = 'booked' OR y.max_stage = 6, 0) AS is_booked FROM ({$mid}) y) z";
    }

    /** Prospect-level rows after the optional outcome / "reached" filters. */
    private function scopedProspects(?string $reached = null): string
    {
        $sql = 'SELECT s.* FROM (' . $this->prospectSql(true) . ') s WHERE 1 = 1';

        if ($this->f->outcome !== null) {
            $sql .= ' AND s.outcome = ' . $this->b($this->f->outcome);
        }

        $sql .= match ($reached) {
            'contacted' => ' AND s.contacted = 1',
            'replied' => ' AND s.contacted = 1 AND s.has_reply = 1',
            'interested' => " AND s.contacted = 1 AND s.outcome IN ('interested','booked','won')",
            'rejected' => " AND s.contacted = 1 AND s.outcome = 'rejected'",
            'opted_out' => " AND s.contacted = 1 AND s.outcome = 'opted_out'",
            'booked' => ' AND s.contacted = 1 AND s.is_booked = 1',
            'won' => ' AND s.contacted = 1 AND s.is_won = 1',
            'eligible' => " AND s.status <> 'stopped' AND s.is_excluded = 0",
            default => '',
        };

        return $sql;
    }

    /** Every attributed reply: inbound id → the confirmed outbound immediately before it. */
    private function repliesSql(): string
    {
        return "SELECT x.out_id, MIN(x.received_at) AS first_reply_at, MIN(x.inbound_id) AS first_reply_id
            FROM (SELECT i.id AS inbound_id, i.received_at,
                (SELECT o2.id FROM agency_prospect_messages o2
                  WHERE o2.campaign_member_id = i.campaign_member_id AND o2.direction = 'outbound' AND o2.status = 'sent'
                    AND o2.sent_at IS NOT NULL AND o2.sent_at <= i.received_at
                  ORDER BY o2.sent_at DESC, o2.id DESC LIMIT 1) AS out_id
                FROM agency_prospect_messages i
                WHERE i.workspace_id = {$this->ws} AND i.direction = 'inbound' AND i.received_at IS NOT NULL) x
            WHERE x.out_id IS NOT NULL GROUP BY x.out_id";
    }

    /** One row per (conversation, step) that was confirmed sent, with its attributed reply and outcome. */
    private function journeySql(): string
    {
        $camp = $this->camp('cm');
        $stepKey = "COALESCE(m.sequence_step_id, CASE WHEN m.purpose = 'initial' THEN 'opener' END)";
        $lastIn = "(SELECT o3.id FROM agency_prospect_messages o3 WHERE o3.campaign_member_id = s.member_id AND o3.direction = 'outbound' AND o3.status = 'sent' AND o3.sent_at IS NOT NULL
                    AND o3.sent_at <= (SELECT MAX(i3.received_at) FROM agency_prospect_messages i3 WHERE i3.campaign_member_id = s.member_id AND i3.direction = 'inbound')
                    ORDER BY o3.sent_at DESC, o3.id DESC LIMIT 1)";

        $inner = "SELECT s.member_id, s.step_key, s.out_id, cm.campaign_id, cm.prospect_id, o.sent_at, o.script_version, o.channel_id, o.body,
                pr.outcome, pr.booked_at, pr.client_won_at, pr.acquisition_member_id, pr.booked_member_id, pr.uid AS prospect_uid, pr.company_name, pr.business_type, pr.google_rating, pr.review_count, pr.has_website, pr.city, pr.state,
                r.first_reply_at, r.first_reply_id,
                {$lastIn} AS last_in_out_id,
                LEAD(s.out_id) OVER (PARTITION BY s.member_id ORDER BY s.out_id) AS next_out_id,
                LEAD(o.sent_at) OVER (PARTITION BY s.member_id ORDER BY s.out_id) AS next_sent_at,
                LAG(o.sent_at) OVER (PARTITION BY s.member_id ORDER BY s.out_id) AS prev_sent_at
            FROM (SELECT x.member_id, x.step_key, MIN(x.id) AS out_id
                    FROM (SELECT m.campaign_member_id AS member_id, m.id, {$stepKey} AS step_key
                            FROM agency_prospect_messages m JOIN agency_prospect_campaign_members cm ON cm.id = m.campaign_member_id
                           WHERE cm.workspace_id = {$this->ws}{$camp} AND " . $this->sentBase() . ") x
                   WHERE x.step_key IS NOT NULL
                GROUP BY x.member_id, x.step_key) s
            JOIN agency_prospect_messages o ON o.id = s.out_id
            JOIN agency_prospect_campaign_members cm ON cm.id = s.member_id
            JOIN (" . $this->scopedProspectsForJourney() . ") pr ON pr.id = cm.prospect_id
            LEFT JOIN ({$this->repliesSql()}) r ON r.out_id = s.out_id";

        return "SELECT j.*,
            CASE WHEN j.first_reply_id IS NULL THEN 'no_response'
                 WHEN j.outcome = 'opted_out' AND j.out_id = j.last_in_out_id THEN 'opted_out'
                 WHEN j.outcome = 'rejected' AND j.out_id = j.last_in_out_id THEN 'rejected'
                 WHEN j.outcome IN ('interested','booked','won') THEN 'interested'
                 ELSE 'other' END AS step_outcome,
            (j.outcome IN ('booked','won') AND j.booked_at IS NOT NULL AND j.member_id = j.booked_member_id AND j.sent_at <= j.booked_at AND (j.next_sent_at IS NULL OR j.next_sent_at > j.booked_at)) AS booked_here,
            (j.client_won_at IS NOT NULL AND j.member_id = j.acquisition_member_id AND j.sent_at <= j.client_won_at AND (j.next_sent_at IS NULL OR j.next_sent_at > j.client_won_at)) AS won_here,
            (j.next_out_id IS NOT NULL) AS advanced,
            CASE WHEN j.first_reply_at IS NULL THEN NULL ELSE TIMESTAMPDIFF(SECOND, j.sent_at, j.first_reply_at) END AS latency_sec
            FROM ({$inner}) j WHERE 1 = 1" . $this->displayConds();
    }

    /** Prospect rows with their outcome measured over all time (a journey row's outcome is the prospect's current one). */
    private function scopedProspectsForJourney(): string
    {
        $sql = 'SELECT s.id, s.uid, s.company_name, s.business_type, s.google_rating, s.review_count, s.has_website, s.city, s.state, s.outcome, s.booked_at, s.client_won_at, s.acquisition_member_id, s.booked_member_id FROM (' . $this->prospectSql(false) . ') s';

        return $this->f->outcome !== null ? $sql . ' WHERE s.outcome = ' . $this->b($this->f->outcome) : $sql;
    }

    /** @return list<object> */
    private function select(string $sql): array
    {
        preg_match_all('/:(p\d+)\b/', $sql, $m);
        $used = array_intersect_key($this->bind, array_flip($m[1]));

        return DB::select($sql, $used);
    }

    // ------------------------------------------------------------------ public API

    /** @return array<string, mixed> */
    public function kpis(): array
    {
        $row = $this->select("SELECT COUNT(*) AS imported,
                COALESCE(SUM(s.status <> 'stopped' AND s.is_excluded = 0), 0) AS eligible,
                COALESCE(SUM(s.contacted), 0) AS contacted,
                COALESCE(SUM(s.contacted = 1 AND s.has_reply = 1), 0) AS replied,
                COALESCE(SUM(s.contacted = 1 AND s.outcome IN ('interested','booked','won')), 0) AS interested,
                COALESCE(SUM(s.contacted = 1 AND s.outcome = 'rejected'), 0) AS rejected,
                COALESCE(SUM(s.contacted = 1 AND s.outcome = 'opted_out'), 0) AS opted_out,
                COALESCE(SUM(s.contacted = 1 AND s.is_booked = 1), 0) AS booked,
                COALESCE(SUM(s.contacted = 1 AND s.is_won = 1), 0) AS won
            FROM (" . $this->scopedProspectsAllOutcomes() . ') s')[0];

        $k = array_map('intval', (array) $row);
        $lat = $this->latencyPercentiles(null);
        $k['median_reply_sec'] = $lat['p50'];
        $k['p75_reply_sec'] = $lat['p75'];
        $k['reply_samples'] = $lat['n'];

        return $k;
    }

    /** Same as scopedProspects(), without the outcome filter (a KPI card IS an outcome). */
    private function scopedProspectsAllOutcomes(): string
    {
        return 'SELECT s.* FROM (' . $this->prospectSql(true) . ') s';
    }

    /** @return array{p50: ?int, p75: ?int, n: int} */
    public function latencyPercentiles(?string $stepKey): array
    {
        $rows = $this->percentiles($stepKey === null ? 'all' : 'step', $stepKey);

        return $rows[$stepKey ?? '*'] ?? ['p50' => null, 'p75' => null, 'n' => 0];
    }

    /**
     * Median and 75th percentile of reply latency, computed in SQL with window functions.
     *
     * @return array<string, array{p50: ?int, p75: ?int, n: int}> keyed by step key, or '*' for every step together
     */
    private function percentiles(string $mode, ?string $onlyStep = null): array
    {
        $key = $mode === 'all' ? "'*'" : 'j.step_key';
        $where = $onlyStep !== null ? ' AND j.step_key = ' . $this->b($onlyStep) : '';

        $rows = $this->select("SELECT t.k, t.lat, t.rn, t.n FROM (
                SELECT u.k, u.lat, ROW_NUMBER() OVER (PARTITION BY u.k ORDER BY u.lat) AS rn, COUNT(*) OVER (PARTITION BY u.k) AS n
                FROM (SELECT {$key} AS k, j.latency_sec AS lat FROM (" . $this->journeySql() . ") j WHERE j.latency_sec IS NOT NULL{$where}) u) t
            WHERE t.rn = CEIL(t.n * 0.5) OR t.rn = CEIL(t.n * 0.75)");

        $out = [];

        foreach ($rows as $r) {
            $k = (string) $r->k;
            $out[$k] ??= ['p50' => null, 'p75' => null, 'n' => (int) $r->n];
            (int) $r->rn === (int) ceil($r->n * 0.5) && $out[$k]['p50'] = (int) $r->lat;
            (int) $r->rn === (int) ceil($r->n * 0.75) && $out[$k]['p75'] = (int) $r->lat;
        }

        return $out;
    }

    /**
     * The message journey, one entry per step that was actually sent (default four, but whatever the data
     * and the current script contain: custom, reordered, disabled and deleted steps all appear).
     *
     * @return list<array<string, mixed>>
     */
    public function steps(): array
    {
        $rows = $this->select("SELECT j.step_key, COUNT(*) AS reached, COUNT(DISTINCT j.prospect_id) AS prospects,
                COALESCE(SUM(j.first_reply_id IS NOT NULL), 0) AS replied,
                COALESCE(SUM(j.step_outcome = 'interested'), 0) AS interested,
                COALESCE(SUM(j.step_outcome = 'other'), 0) AS other_replies,
                COALESCE(SUM(j.step_outcome = 'rejected'), 0) AS rejected,
                COALESCE(SUM(j.step_outcome = 'opted_out'), 0) AS opted_out,
                COALESCE(SUM(j.step_outcome = 'no_response'), 0) AS no_response,
                COALESCE(SUM(j.advanced), 0) AS advanced,
                COALESCE(SUM(j.booked_here), 0) AS booked,
                COALESCE(SUM(j.won_here), 0) AS won,
                MIN(j.out_id) AS first_out_id, MAX(j.out_id) AS last_out_id
            FROM (" . $this->journeySql() . ') j GROUP BY j.step_key');

        $pct = $this->percentiles('step');
        $steps = [];

        foreach ($rows as $r) {
            if ($this->f->stepKey !== null && $r->step_key !== $this->f->stepKey) {
                continue;
            }

            $p = $pct[$r->step_key] ?? ['p50' => null, 'p75' => null, 'n' => 0];
            $steps[] = array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, (array) $r) + ['median_sec' => $p['p50'], 'p75_sec' => $p['p75']];
        }

        return $steps;
    }

    /** Messages sent in scope that belong to no step (conversation replies, manual texts): the honest "unattributed". */
    public function unattributedSends(): int
    {
        $camp = $this->camp('cm');
        $r = $this->select("SELECT COUNT(*) AS c FROM agency_prospect_messages m JOIN agency_prospect_campaign_members cm ON cm.id = m.campaign_member_id
            WHERE cm.workspace_id = {$this->ws}{$camp} AND " . $this->outConds(true) . " AND m.sequence_step_id IS NULL AND m.purpose <> 'initial'");

        return (int) $r[0]->c;
    }

    /** @return list<array<string, mixed>> distinct (script version, exact text) pairs sent for one step, newest first. */
    public function stepTexts(string $stepKey): array
    {
        $rows = $this->select("SELECT j.script_version, j.body, COUNT(*) AS sent, MAX(j.sent_at) AS last_sent
            FROM (" . $this->journeySql() . ') j WHERE j.step_key = ' . $this->b($stepKey) . ' GROUP BY j.script_version, j.body ORDER BY last_sent DESC LIMIT 5');

        return array_map(fn ($r) => (array) $r, $rows);
    }

    /**
     * The funnel. Each stage is a prospect counted ONCE however many campaigns reached them, and stages are
     * counted independently (a prospect can be booked without a recorded "interested" reply), so each
     * percentage names its own denominator instead of implying a straight line.
     *
     * @return list<array{key: string, label: string, count: int, of: ?int, of_label: ?string, pct: ?float}>
     */
    public function funnel(array $k): array
    {
        $stages = [
            ['imported', 'Imported', null, null],
            ['eligible', 'Eligible', 'imported', 'imported'],
            ['contacted', 'Contacted', 'eligible', 'eligible'],
            ['replied', 'Replied', 'contacted', 'contacted'],
            ['interested', 'Interested', 'contacted', 'contacted'],
            ['booked', 'Call booked', 'contacted', 'contacted'],
            ['won', 'Client won', 'contacted', 'contacted'],
        ];

        return array_map(function ($s) use ($k) {
            [$key, $label, $ofKey, $ofLabel] = $s;
            $of = $ofKey === null ? null : (int) $k[$ofKey];

            return ['key' => $key, 'label' => $label, 'count' => (int) $k[$key], 'of' => $of, 'of_label' => $ofLabel,
                'pct' => $of !== null && $of > 0 ? round($k[$key] / $of * 100, 1) : null];
        }, $stages);
    }

    /**
     * Segment comparison for one dimension (type | rating | reviews | website | geo | source | campaign).
     *
     * @return list<array{segment: string, n: int, replied: int, interested: int, booked: int, rejected: int, won: int, small: bool}>
     */
    public function segments(string $dimension): array
    {
        if ($dimension === 'campaign') {
            return $this->campaignSegments();
        }

        if ($dimension === 'group') {
            // A prospect referenced by two groups appears in BOTH group rows (that is the comparison); global totals elsewhere stay unique.
            return $this->segmentRows('cg.name', 's', 'JOIN agency_prospect_group_links gl ON gl.prospect_id = s.id AND gl.workspace_id = ' . $this->ws
                . ' JOIN contact_groups cg ON cg.id = gl.contact_group_id');
        }

        if (str_starts_with($dimension, 'cf:')) {
            return $this->customFieldSegments(substr($dimension, 3));
        }

        $expr = match ($dimension) {
            'rating' => "CASE WHEN s.google_rating IS NULL THEN 'No rating' WHEN s.google_rating >= 4.5 THEN '4.5+' WHEN s.google_rating >= 4 THEN '4–4.4' WHEN s.google_rating >= 3 THEN '3–3.9' ELSE 'Under 3' END",
            'reviews' => "CASE WHEN s.review_count IS NULL THEN 'No review data' WHEN s.review_count >= 50 THEN '50+ reviews' WHEN s.review_count >= 10 THEN '10–49 reviews' ELSE 'Under 10 reviews' END",
            'website' => "CASE WHEN s.has_website = 1 THEN 'Has website' WHEN s.has_website = 0 THEN 'No website' ELSE 'Unknown' END",
            'geo' => "COALESCE(NULLIF(s.state, ''), NULLIF(s.city, ''), 'Unknown')",
            'source' => "COALESCE((SELECT b.filename FROM agency_prospect_import_batches b WHERE b.id = s.import_batch_id), 'Added manually')",
            default => "COALESCE(NULLIF(s.business_type, ''), 'Unknown')",
        };

        return $this->segmentRows($expr, 's');
    }

    /** @return list<array<string, mixed>> */
    private function segmentRows(string $expr, string $alias, string $join = ''): array
    {
        $rows = $this->select("SELECT {$expr} AS segment, COUNT(*) AS n,
                SUM(s.has_reply = 1) AS replied,
                SUM(s.outcome IN ('interested','booked','won')) AS interested,
                SUM(s.is_booked = 1) AS booked,
                SUM(s.outcome = 'rejected') AS rejected,
                SUM(s.is_won = 1) AS won
            FROM (" . $this->scopedProspects('contacted') . ") s {$join} GROUP BY segment ORDER BY n DESC, segment LIMIT 30");

        return array_map(fn ($r) => [
            'segment' => (string) $r->segment, 'n' => (int) $r->n, 'replied' => (int) $r->replied, 'interested' => (int) $r->interested,
            'booked' => (int) $r->booked, 'rejected' => (int) $r->rejected, 'won' => (int) $r->won, 'small' => (int) $r->n < self::SMALL_SAMPLE,
        ], $rows);
    }

    /** @return list<array<string, mixed>> real stored values of one Business Custom Field, one row per value. */
    private function customFieldSegments(string $key): array
    {
        $business = \App\Library\AgencyOutreach\AgencyOutreachBusinessResolver::forWorkspace($this->workspace);
        $def = $business === null ? null : CustomFieldDefinition::query()->where('business_id', $business->id)
            ->where('entity', CustomFieldDefinition::ENTITY_CONTACT)->where('key', $key)->whereNull('archived_at')->first();

        if ($def === null || ! in_array($def->type, self::SEGMENT_FIELD_TYPES, true)) {
            return [];
        }

        $value = match ($def->type) {
            'number', 'currency' => 'CAST(cfv.value_number AS CHAR)',
            'boolean' => "IF(cfv.value_bool = 1, 'Yes', 'No')",
            // A select stores the option's stable id; the comparison shows the label (an id with no option left shows as stored).
            'select' => $this->optionLabelCase($def),
            default => 'cfv.value_text',
        };

        // One row per (logical prospect, value): two Contact copies of the same person with the same value count once.
        return $this->segmentRows('x.seg', 's', 'JOIN (SELECT DISTINCT glx.prospect_id, ' . $value . ' AS seg FROM agency_prospect_group_links glx'
            . ' JOIN custom_field_values cfv ON cfv.contact_id = glx.contact_id AND cfv.definition_id = ' . (int) $def->id
            . ' WHERE glx.workspace_id = ' . $this->ws . ' AND ' . $this->storedColumn($def) . ' IS NOT NULL) x ON x.prospect_id = s.id');
    }

    /** The column a field type stores its value in (used for the plain presence test, which must not repeat bound labels). */
    private function storedColumn(CustomFieldDefinition $def): string
    {
        return match ($def->type) {
            'number', 'currency' => 'cfv.value_number',
            'boolean' => 'cfv.value_bool',
            default => 'cfv.value_text',
        };
    }

    /** SQL mapping a select field's stored option ids to their labels. */
    private function optionLabelCase(CustomFieldDefinition $def): string
    {
        $options = array_filter($def->optionList(), fn ($o) => is_array($o) && isset($o['id'], $o['label']));

        if ($options === []) {
            return 'cfv.value_text';
        }

        $when = '';
        foreach ($options as $o) {
            $when .= ' WHEN ' . $this->b((string) $o['id']) . ' THEN ' . $this->b((string) $o['label']);
        }

        return 'CASE cfv.value_text' . $when . ' ELSE cfv.value_text END';
    }

    /** @return list<array<string, mixed>> */
    /**
     * Per sending number (the agency's own Telnyx numbers): prospects pinned to it, messages it sent
     * and failed (the ledger's own channel_id), and replies from the prospects pinned to it. Campaign
     * filter and period apply; read from the existing ledger, nothing new is tracked.
     *
     * @return list<array{number: string, assigned: int, sent: int, failed: int, replies: int}>
     */
    public function numbers(): array
    {
        [$from, $to] = $this->f->window();
        $campaign = fn ($q, string $col) => $this->campaignId === null ? $q : $q->where($col, (int) $this->campaignId);
        $window = function ($q, string $col) use ($from, $to) {
            $from !== null && $q->where($col, '>=', $from);
            $to !== null && $q->where($col, '<', $to);

            return $q;
        };

        $assigned = $campaign(DB::table('agency_prospect_campaign_members')->where('workspace_id', $this->ws)->whereNotNull('sender_channel_id'), 'campaign_id')
            ->selectRaw('sender_channel_id as c, COUNT(*) as n')->groupBy('sender_channel_id')->pluck('n', 'c');

        $outbound = fn (string $status, string $at) => $window($campaign(DB::table('agency_prospect_messages as m')
            ->join('agency_prospect_campaign_members as cm', 'cm.id', '=', 'm.campaign_member_id')
            ->where('m.workspace_id', $this->ws)->where('m.direction', 'outbound')->where('m.status', $status)
            ->whereNotNull('m.channel_id'), 'cm.campaign_id'), $at)
            ->selectRaw('m.channel_id as c, COUNT(*) as n')->groupBy('m.channel_id')->pluck('n', 'c');

        $sent = $outbound('sent', 'm.sent_at');
        $failed = $outbound('failed', 'm.created_at');

        $replies = $window($campaign(DB::table('agency_prospect_messages as m')
            ->join('agency_prospect_campaign_members as cm', 'cm.id', '=', 'm.campaign_member_id')
            ->where('m.workspace_id', $this->ws)->where('m.direction', 'inbound')
            ->whereNotNull('cm.sender_channel_id'), 'cm.campaign_id'), 'm.created_at')
            ->selectRaw('cm.sender_channel_id as c, COUNT(*) as n')->groupBy('cm.sender_channel_id')->pluck('n', 'c');

        $ids = collect([$assigned, $sent, $failed, $replies])->flatMap(fn ($c) => $c->keys())->unique()->all();

        if ($ids === []) {
            return [];
        }

        return AgencyProspectingChannel::query()
            ->where('workspace_id', $this->ws)->whereIn('id', $ids)->orderBy('id')->get(['id', 'sender_number'])
            ->map(fn ($ch) => [
                'number' => (string) $ch->sender_number,
                'assigned' => (int) ($assigned[$ch->id] ?? 0),
                'sent' => (int) ($sent[$ch->id] ?? 0),
                'failed' => (int) ($failed[$ch->id] ?? 0),
                'replies' => (int) ($replies[$ch->id] ?? 0),
            ])->values()->all();
    }

    private function campaignSegments(): array
    {
        $out = [];
        $campaigns = AgencyProspectCampaign::query()->where('workspace_id', $this->ws)->orderByDesc('id')->limit(25)->get(['id', 'uid', 'name']);

        foreach ($campaigns as $c) {
            $f = new AnalyticsFilters(campaignUid: $c->uid, period: $this->f->period, from: $this->f->from, to: $this->f->to,
                businessType: $this->f->businessType, geo: $this->f->geo, rating: $this->f->rating, reviews: $this->f->reviews,
                website: $this->f->website, batchUid: $this->f->batchUid, channelId: $this->f->channelId, scriptVersion: $this->f->scriptVersion,
                groupUid: $this->f->groupUid, cfKey: $this->f->cfKey, cfValue: $this->f->cfValue);
            $k = (new self($this->workspace, $f))->kpis();

            if ($k['contacted'] > 0) {
                $out[] = ['segment' => $c->name, 'n' => $k['contacted'], 'replied' => $k['replied'], 'interested' => $k['interested'],
                    'booked' => $k['booked'], 'rejected' => $k['rejected'], 'won' => $k['won'], 'small' => $k['contacted'] < self::SMALL_SAMPLE];
            }
        }

        return $out;
    }

    /**
     * Timing: reply-latency distribution, send hour / weekday, and the delay evidence stored on AI replies.
     *
     * @return array<string, mixed>
     */
    public function timing(): array
    {
        $buckets = $this->select("SELECT CASE WHEN j.latency_sec < 300 THEN 'Under 5 min' WHEN j.latency_sec < 1800 THEN '5–30 min' WHEN j.latency_sec < 7200 THEN '30 min – 2 h'
                    WHEN j.latency_sec < 21600 THEN '2–6 h' WHEN j.latency_sec < 86400 THEN '6–24 h' ELSE 'Over 24 h' END AS bucket,
                    MIN(j.latency_sec) AS lo, COUNT(*) AS c
            FROM (" . $this->journeySql() . ') j WHERE j.latency_sec IS NOT NULL GROUP BY bucket ORDER BY lo');

        $hours = $this->select("SELECT HOUR(j.sent_at) AS h, COUNT(*) AS sent, COALESCE(SUM(j.first_reply_id IS NOT NULL), 0) AS replied
            FROM (" . $this->journeySql() . ') j GROUP BY h ORDER BY h');

        $days = $this->select("SELECT DAYOFWEEK(j.sent_at) AS d, COUNT(*) AS sent, COALESCE(SUM(j.first_reply_id IS NOT NULL), 0) AS replied
            FROM (" . $this->journeySql() . ') j GROUP BY d ORDER BY d');

        $gap = $this->select("SELECT j.step_key, COUNT(*) AS c, AVG(TIMESTAMPDIFF(SECOND, j.prev_sent_at, j.sent_at)) AS avg_gap
            FROM (" . $this->journeySql() . ') j WHERE j.prev_sent_at IS NOT NULL GROUP BY j.step_key');

        $camp = $this->camp('cm');
        // The pacing evidence lives on the INBOUND the answer responds to (its due moment is chosen when it arrives and
        // stored there); the AI answer is tied to it by operation_key "outreach:reply:<inbound id>".
        $ai = $this->select("SELECT COUNT(*) AS replies,
                COALESCE(SUM(i.reply_due_at IS NOT NULL), 0) AS paced,
                AVG(CASE WHEN i.reply_due_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, COALESCE(i.received_at, i.created_at), i.reply_due_at) END) AS avg_planned_wait,
                AVG(CASE WHEN i.reply_due_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, i.reply_due_at, m.sent_at) END) AS avg_send_lag,
                COALESCE(SUM(m.deferred_until IS NOT NULL OR m.failure_reason IN ('rate_limited','opener_daily_cap')), 0) AS deferred
            FROM agency_prospect_messages m JOIN agency_prospect_campaign_members cm ON cm.id = m.campaign_member_id
            LEFT JOIN agency_prospect_messages i ON i.id = CASE WHEN m.operation_key LIKE 'outreach:reply:%' THEN CAST(SUBSTRING(m.operation_key, 16) AS UNSIGNED) END
                AND i.campaign_member_id = m.campaign_member_id AND i.direction = 'inbound'
            WHERE cm.workspace_id = {$this->ws}{$camp} AND " . $this->outConds(true) . " AND m.purpose = 'ai_reply'")[0];

        $manual = $this->select("SELECT COUNT(*) AS c,
                AVG(TIMESTAMPDIFF(SECOND, (SELECT MAX(i.received_at) FROM agency_prospect_messages i WHERE i.campaign_member_id = m.campaign_member_id AND i.direction = 'inbound' AND i.received_at <= m.sent_at), m.sent_at)) AS avg_latency
            FROM agency_prospect_messages m JOIN agency_prospect_campaign_members cm ON cm.id = m.campaign_member_id
            WHERE cm.workspace_id = {$this->ws}{$camp} AND " . $this->outConds(true) . " AND m.source = 'manual'")[0];

        return [
            'distribution' => array_map(fn ($r) => ['bucket' => $r->bucket, 'count' => (int) $r->c], $buckets),
            'hours' => array_map(fn ($r) => ['hour' => (int) $r->h, 'sent' => (int) $r->sent, 'replied' => (int) $r->replied], $hours),
            'weekdays' => array_map(fn ($r) => ['day' => (int) $r->d, 'sent' => (int) $r->sent, 'replied' => (int) $r->replied], $days),
            'gaps' => array_map(fn ($r) => ['step_key' => $r->step_key, 'count' => (int) $r->c, 'avg_sec' => $r->avg_gap === null ? null : (int) round((float) $r->avg_gap)], $gap),
            'ai' => ['replies' => (int) $ai->replies, 'paced' => (int) $ai->paced,
                'avg_planned_wait' => $ai->avg_planned_wait === null ? null : (int) round((float) $ai->avg_planned_wait),
                'avg_send_lag' => $ai->avg_send_lag === null ? null : (int) round((float) $ai->avg_send_lag), 'deferred' => (int) $ai->deferred],
            'manual' => ['count' => (int) $manual->c, 'avg_latency' => $manual->avg_latency === null ? null : (int) round((float) $manual->avg_latency)],
            'overall' => $this->latencyPercentiles(null),
        ];
    }

    /**
     * Prospect explorer / drill-down rows (paginated in SQL).
     *
     * @return array{rows: list<object>, total: int}
     */
    public function explorer(int $page, int $perPage = 25, ?string $reached = null): array
    {
        $base = $this->scopedProspects($reached);
        $total = (int) $this->select("SELECT COUNT(*) AS c FROM ({$base}) t")[0]->c;
        $offset = max(0, ($page - 1) * $perPage);

        $camp = $this->camp('cm2');
        $rows = $this->select("SELECT t.*,
                (SELECT c.name FROM agency_prospect_campaign_members cm2 JOIN agency_prospect_campaigns c ON c.id = cm2.campaign_id WHERE cm2.prospect_id = t.id AND cm2.workspace_id = {$this->ws}{$camp} ORDER BY cm2.id DESC LIMIT 1) AS campaign_name,
                (SELECT cm2.id FROM agency_prospect_campaign_members cm2 WHERE cm2.prospect_id = t.id AND cm2.workspace_id = {$this->ws}{$camp} ORDER BY cm2.id DESC LIMIT 1) AS member_id,
                (SELECT COUNT(*) FROM agency_prospect_messages m JOIN agency_prospect_campaign_members cm2 ON cm2.id = m.campaign_member_id
                   WHERE cm2.prospect_id = t.id AND cm2.workspace_id = {$this->ws}{$camp} AND m.direction = 'outbound' AND m.status = 'sent' AND (m.sequence_step_id IS NOT NULL OR m.purpose = 'initial')) AS steps_sent,
                (SELECT m.body FROM agency_prospect_messages m JOIN agency_prospect_campaign_members cm2 ON cm2.id = m.campaign_member_id
                   WHERE cm2.prospect_id = t.id AND cm2.workspace_id = {$this->ws}{$camp} AND m.direction = 'outbound' AND m.status = 'sent' ORDER BY m.sent_at DESC, m.id DESC LIMIT 1) AS last_out,
                (SELECT m.body FROM agency_prospect_messages m JOIN agency_prospect_campaign_members cm2 ON cm2.id = m.campaign_member_id
                   WHERE cm2.prospect_id = t.id AND cm2.workspace_id = {$this->ws}{$camp} AND m.direction = 'inbound' ORDER BY m.received_at DESC, m.id DESC LIMIT 1) AS last_in,
                (SELECT MIN(TIMESTAMPDIFF(SECOND, o.sent_at, i.received_at)) FROM agency_prospect_messages i JOIN agency_prospect_campaign_members cm2 ON cm2.id = i.campaign_member_id
                   JOIN agency_prospect_messages o ON o.campaign_member_id = i.campaign_member_id AND o.direction = 'outbound' AND o.status = 'sent' AND o.sent_at <= i.received_at
                   WHERE cm2.prospect_id = t.id AND cm2.workspace_id = {$this->ws}{$camp} AND i.direction = 'inbound' AND i.received_at IS NOT NULL
                     AND o.id = (SELECT o2.id FROM agency_prospect_messages o2 WHERE o2.campaign_member_id = i.campaign_member_id AND o2.direction = 'outbound' AND o2.status = 'sent' AND o2.sent_at IS NOT NULL AND o2.sent_at <= i.received_at ORDER BY o2.sent_at DESC, o2.id DESC LIMIT 1)) AS reply_sec,
                (SELECT GROUP_CONCAT(DISTINCT cgn.name ORDER BY cgn.name SEPARATOR '; ') FROM agency_prospect_group_links gln JOIN contact_groups cgn ON cgn.id = gln.contact_group_id
                   WHERE gln.prospect_id = t.id AND gln.workspace_id = {$this->ws}) AS group_names,
                (SELECT b.filename FROM agency_prospect_import_batches b WHERE b.id = t.import_batch_id) AS source_name
            FROM ({$base}) t ORDER BY t.id DESC LIMIT {$perPage} OFFSET {$offset}");

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * One step's businesses (drill-down), paginated in SQL, filtered by the step outcome tab.
     *
     * @return array{rows: list<object>, total: int, counts: array<string,int>}
     */
    public function stepProspects(string $stepKey, string $tab, int $page, int $perPage = 25): array
    {
        $journey = $this->journeySql();
        $key = $this->b($stepKey);

        $counts = (array) $this->select("SELECT COUNT(*) AS `all`, COALESCE(SUM(j.first_reply_id IS NOT NULL), 0) AS replied,
                COALESCE(SUM(j.step_outcome = 'rejected'), 0) AS rejected, COALESCE(SUM(j.step_outcome = 'opted_out'), 0) AS opted_out,
                COALESCE(SUM(j.step_outcome = 'no_response'), 0) AS no_response
            FROM ({$journey}) j WHERE j.step_key = {$key}")[0];
        $counts = array_map('intval', $counts);

        $tabSql = match ($tab) {
            'replied' => ' AND j.first_reply_id IS NOT NULL',
            'rejected' => " AND j.step_outcome = 'rejected'",
            'opted_out' => " AND j.step_outcome = 'opted_out'",
            'no_response' => " AND j.step_outcome = 'no_response'",
            default => '',
        };

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->select("SELECT j.*, (SELECT i.body FROM agency_prospect_messages i WHERE i.id = j.first_reply_id) AS reply_body
            FROM ({$journey}) j WHERE j.step_key = {$key}{$tabSql} ORDER BY j.sent_at DESC, j.out_id DESC LIMIT {$perPage} OFFSET {$offset}");

        $total = match ($tab) {
            'replied' => $counts['replied'], 'rejected' => $counts['rejected'], 'opted_out' => $counts['opted_out'],
            'no_response' => $counts['no_response'], default => $counts['all'],
        };

        return ['rows' => $rows, 'total' => $total, 'counts' => $counts];
    }

    /** Filter dropdown values from this Workspace's real data. @return array<string, list<mixed>> */
    public function options(): array
    {
        $ws = $this->ws;

        return [
            'types' => DB::table('agency_prospects')->where('workspace_id', $ws)->whereNotNull('business_type')->where('business_type', '<>', '')->distinct()->orderBy('business_type')->limit(100)->pluck('business_type')->all(),
            'geos' => DB::table('agency_prospects')->where('workspace_id', $ws)->whereNotNull('state')->where('state', '<>', '')->distinct()->orderBy('state')->limit(100)->pluck('state')->all(),
            'campaigns' => AgencyProspectCampaign::query()->where('workspace_id', $ws)->orderByDesc('id')->limit(100)->get(['uid', 'name'])->all(),
            'batches' => AgencyProspectImportBatch::query()->where('workspace_id', $ws)->where('status', 'imported')->orderByDesc('id')->limit(50)->get(['uid', 'filename'])->all(),
            'channels' => DB::table('agency_prospecting_channels')->where('workspace_id', $ws)->orderBy('id')->limit(20)->get(['id', 'sender_number'])->all(),
            'steps' => DB::select("SELECT x.step_key, MIN(x.id) AS first_out_id FROM (
                    SELECT id, COALESCE(sequence_step_id, CASE WHEN purpose = 'initial' THEN 'opener' END) AS step_key
                    FROM agency_prospect_messages WHERE workspace_id = {$ws} AND direction = 'outbound' AND status = 'sent' AND sent_at IS NOT NULL) x
                WHERE x.step_key IS NOT NULL GROUP BY x.step_key LIMIT 30"),
            'groups' => DB::table('agency_prospect_group_links as gl')->join('contact_groups as cg', 'cg.id', '=', 'gl.contact_group_id')
                ->where('gl.workspace_id', $ws)->distinct()->orderBy('cg.name')->limit(100)->get(['cg.uid', 'cg.name'])->all(),
            'fields' => $this->customFieldOptions(),
            'versions' => DB::table('agency_prospect_messages')->where('workspace_id', $ws)->whereNotNull('script_version')->distinct()->orderBy('script_version')->limit(50)->pluck('script_version')->all(),
        ];
    }

    /** @return list<array{key: string, label: string, type: string, options: list<array{id: string, label: string}>}> active contact custom fields usable as a filter/dimension. */
    public function customFieldOptions(): array
    {
        $business = \App\Library\AgencyOutreach\AgencyOutreachBusinessResolver::forWorkspace($this->workspace);

        if ($business === null) {
            return [];
        }

        return CustomFieldDefinition::query()->where('business_id', $business->id)->where('entity', CustomFieldDefinition::ENTITY_CONTACT)
            ->whereNull('archived_at')->whereIn('type', self::SEGMENT_FIELD_TYPES)->orderBy('position')->limit(60)->get(['key', 'label', 'type', 'options'])
            ->map(fn ($d) => ['key' => $d->key, 'label' => $d->label, 'type' => $d->type,
                // A select's filter value is the option's stable id; the picker shows the label.
                'options' => $d->type === 'select' ? array_map(fn ($o) => ['id' => (string) $o['id'], 'label' => (string) $o['label']], array_filter($d->optionList(), fn ($o) => is_array($o) && isset($o['id'], $o['label']))) : []])->all();
    }
}
