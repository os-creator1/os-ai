<?php

namespace App\Library\Automation\Migration;

use App\Models\Reports;
use Illuminate\Support\Facades\DB;

/**
 * Automations V1 completion — Location run-scope foundation, §7 of the
 * lane's own contract: attribute a historical `automation_enrollments` row
 * to a Location only where IMMUTABLE, TRIGGER-APPROPRIATE stored evidence
 * proves it — never from the enrollment's Contact today.
 *
 * CORRECTED (independent review, pre-merge). The first revision copied the
 * enrollment's Contact's CURRENT `location_id` for every trigger shape. That
 * is wrong twice over:
 *
 *   1. A Contact's `location_id` is not immutable — nothing in this
 *      repository prevents it being edited after the enrollment happened,
 *      so "current" is not "at enrollment time." Copying it fabricates a
 *      Location for a Contact that was transferred after the run started —
 *      exactly the historical-fact invention §7 forbids.
 *   2. For `message_received` and the four CRM triggers, the Contact was
 *      never the authoritative Location source even at RUN time — the live
 *      paths read the conversation's (`chat_boxes.location_id`) or the
 *      deal's (`crm_opportunities.location_id`) own Location
 *      (`MessageReceivedTriggerSource`, `CrmOpportunityTriggerSource`).
 *      Backfilling from the Contact for those trigger types answers a
 *      different, wrong question.
 *
 * THE CORRECTED EVIDENCE, PER TRIGGER TYPE — every one of them a value nothing
 * in this codebase ever reassigns once set, so "current" and "at enrollment
 * time" are provably the same fact:
 *
 *   message_received       The ONE unambiguous ChatBox thread for this
 *                           Business and the phone that ACTUALLY sent
 *                           the ORIGINAL inbound message — never the
 *                           enrolled Contact's phone today (CORRECTED,
 *                           correction round 2; see the method's own
 *                           docblock for why "the Contact's phone" was
 *                           itself a mutable-evidence defect of exactly
 *                           the kind this class exists to avoid).
 *                           `chat_boxes.location_id` is decided only once,
 *                           when the thread first opens, and is never
 *                           reassigned after (`ChatBox`'s own docblock,
 *                           Contract 06 §5) — the same reason the LIVE
 *                           trigger already treats it as authoritative.
 *                           Zero or several matching threads is the same
 *                           "cannot disambiguate" case the live trigger
 *                           itself refuses (`theOneSubscribedContact()`'s
 *                           own precedent) — left NULL, never guessed
 *                           between candidates.
 *   opportunity_created,    `crm_opportunities.location_id`, joined from the
 *   opportunity_stage_changed, occurrence key's own `crm_opportunity_history`
 *   opportunity_won,        row to its deal. No code path in this
 *   opportunity_lost        repository ever reassigns a deal's
 *                           `location_id` after `CrmOpportunityService`
 *                           sets it at creation (confirmed: no transfer
 *                           method exists), so it is exactly the same fact
 *                           at the history row's moment and today.
 *   contact_created,        NO immutable evidence exists. The Contact's
 *   contact_date_reached,   `location_id` is an ordinary, editable column
 *   manual_enrollment       with no change history recorded anywhere, so
 *                           there is no way to prove what it was worth at
 *                           enrollment time versus now. Left NULL, always —
 *                           this is the one deliberate, permanent gap §7
 *                           accepts ("do not infer them from today's
 *                           Contact relationship").
 *
 * Idempotent: every UPDATE re-checks `business_location_id IS NULL`, so a
 * row a concurrent process already set is never overwritten. Chunked so a
 * large table is never locked in one statement.
 */
class AutomationEnrollmentLocationBackfillV1
{
    private const CHUNK_SIZE = 500;

    /** @return array{message_received: int, crm: int, total: int} */
    public function run(): array
    {
        $messageReceived = $this->backfillMessageReceived();
        $crm = $this->backfillCrmTriggered();

        return [
            'message_received' => $messageReceived,
            'crm' => $crm,
            'total' => $messageReceived + $crm,
        ];
    }

    /**
     * message_received: the one unambiguous ChatBox thread for this
     * Business and the phone that actually sent the ORIGINAL inbound
     * message — read from that message's own immutable durable record,
     * never from the enrolled Contact's phone today.
     *
     * CORRECTED (independent review, correction round 2). The previous
     * revision matched `chat_boxes` using the enrolled Contact's CURRENT
     * `phone`. A Contact's phone is not immutable — nothing in this
     * repository prevents editing it after a run started — so "exactly
     * one conversation found for that phone TODAY" does not prove that
     * conversation (or its Location) is the one this historical run's
     * trigger actually fired for. A Contact phone change between the
     * original message and now can make today's lookup match an entirely
     * different, unrelated thread, at a different Location, than the
     * historical one — the exact class of defect this method exists to
     * avoid, just relocated one join further out.
     *
     * `trigger_occurrence_key` is namespaced by inbound path
     * (`InboundMessageReceived`'s own docblock): `report:{id}` for the
     * legacy path, `operation:{id}` for managed. The two paths have
     * different durable evidence:
     *
     *   report:{id}     The legacy `reports` row's own `to` column — the
     *                    sender's phone AT THE MOMENT the message arrived
     *                    (`DLRController::inboundDLR()`'s own comment:
     *                    "`from` is the Business's own receiving number
     *                    and `to` the external sender"). Written once at
     *                    insert and never mutated after — only `status`
     *                    and `customer_status` are ever updated on a
     *                    Report row (`InboundWebhookAttributionResolver
     *                    ::syncCorrelatedReport()`, the only writer that
     *                    touches an existing Report). This is provably
     *                    the exact phone `MessageReceivedTriggerSource`
     *                    itself passed to
     *                    `InboundMessageReceived::fromLegacyReport()`
     *                    when this run's enrollment was created.
     *   operation:{id}  NO durable, per-message evidence exists at all.
     *                    `InboundWebhookAttributionResolver::persistInbound()`
     *                    never stores a phone on
     *                    `business_messaging_operations` — the sender is
     *                    carried only as an EPHEMERAL event value, used
     *                    once to open/update the `chat_boxes` thread and
     *                    then gone (`InboundMessageReceived`'s own
     *                    docblock: "the managed path persists no
     *                    per-message sender anywhere"). Left NULL,
     *                    always — the same deliberate, permanent gap as
     *                    contact_created/contact_date_reached/
     *                    manual_enrollment: there is nothing here that
     *                    could ever be resolved later, by a smarter query
     *                    or otherwise.
     *
     * Once the immutable phone is known, the rest is unchanged: the ONE
     * unambiguous `chat_boxes` thread for (business_id, that phone).
     *
     * CORRECTED (independent review, correction round 3) — two tenant/
     * parsing defects in the report join itself:
     *
     *   1. TENANT BOUNDARY. `trigger_occurrence_key` is a plain string with
     *      no foreign key to `reports`, so nothing stopped a malformed or
     *      cross-tenant historical row from naming a Report that belongs to
     *      a DIFFERENT Business. The join now requires
     *      `r.business_id = e.business_id` explicitly — the exact
     *      tenant-bound shape `backfillCrmTriggered()`'s own
     *      `crm_opportunity_history` join already uses below. Without it, a
     *      Business A enrollment referencing a Business B Report would
     *      resolve Business B's phone, and if Business A happens to also
     *      have a conversation with that same phone, would wrongly assign
     *      Business A's Location for that (foreign, coincidental) thread.
     *   2. MALFORMED-SUFFIX PARSING. `CAST(SUBSTRING(...) AS UNSIGNED)` in
     *      non-strict SQL mode does not fail on a non-numeric suffix — it
     *      silently takes the leading numeric PREFIX (`CAST('123-junk' AS
     *      UNSIGNED)` = `123`), so `report:123-junk` would previously match
     *      Report 123 exactly as if the key had been clean. The
     *      `REGEXP` guard below accepts only the canonical
     *      `report:{positive integer}` shape — no junk suffix, no leading
     *      zero, no sign — before the CAST ever runs, so a malformed key
     *      joins nothing and the row stays NULL.
     *
     * The resolved `chat_boxes.location_id` is additionally verified to
     * belong to the SAME Business (`business_locations.business_id =
     * e.business_id`) before it is ever used, rather than trusted merely
     * because it sits on a conversation row already selected by
     * `business_id`. The live writer's own invariant
     * (`ChatBox::singleActiveLocationIdFor()` only ever selects a Location
     * already scoped to the conversation's own Business) means this should
     * never actually diverge, but the join makes it a verified fact for
     * this backfill rather than an assumption borrowed from a writer this
     * class does not control.
     */
    private function backfillMessageReceived(): int
    {
        $updated = 0;

        DB::table('automation_enrollments as e')
            ->join('reports as r', function ($join): void {
                $join->on('r.id', '=', DB::raw(
                    "CAST(SUBSTRING(e.trigger_occurrence_key, LENGTH('report:') + 1) AS UNSIGNED)"
                ))->on('r.business_id', '=', 'e.business_id');
            })
            ->where('e.trigger_type', 'message_received')
            ->whereNull('e.business_location_id')
            // Canonical `report:{positive integer}` only — a malformed
            // suffix (`report:123-junk`, `report:`, `report:01`) must never
            // reach the CAST/SUBSTRING above, which would otherwise accept
            // it via MySQL's non-strict leading-digit truncation.
            ->where('e.trigger_occurrence_key', 'regexp', '^report:[1-9][0-9]*$')
            ->where('r.direction', Reports::DIRECTION_INCOMING)
            ->whereNotNull('r.to')
            ->where('r.to', '!=', '')
            ->orderBy('e.id')
            ->select(['e.id as enrollment_id', 'e.business_id', 'r.to as phone'])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (&$updated): void {
                foreach ($rows as $row) {
                    // Every matching thread is counted here, regardless of
                    // whether it carries a Location — ambiguity is about
                    // how many conversations exist for this (business,
                    // phone), not how many of them happen to have a
                    // Location. Folding a Business-ownership join into this
                    // query would silently drop a NULL-location thread from
                    // the count and could turn a genuinely ambiguous pair
                    // into a false single match.
                    $matches = DB::table('chat_boxes')
                        ->where('business_id', $row->business_id)
                        ->where('to', $row->phone)
                        ->limit(2)
                        ->pluck('location_id');

                    // Zero or several candidate threads: cannot disambiguate,
                    // exactly like the live trigger's own refusal — never a
                    // guess between them.
                    if ($matches->count() !== 1 || $matches->first() === null) {
                        continue;
                    }

                    $locationId = (int) $matches->first();

                    // Verified, not assumed: the resolved Location must
                    // itself belong to this same Business before it is ever
                    // used, even though the live writer's own invariant
                    // (ChatBox::singleActiveLocationIdFor()) should already
                    // guarantee it.
                    $locationBelongsToBusiness = DB::table('business_locations')
                        ->where('id', $locationId)
                        ->where('business_id', $row->business_id)
                        ->exists();

                    if (! $locationBelongsToBusiness) {
                        continue;
                    }

                    $affected = DB::table('automation_enrollments')
                        ->where('id', $row->enrollment_id)
                        ->whereNull('business_location_id')
                        ->update(['business_location_id' => $locationId]);

                    $updated += $affected;
                }
            }, 'e.id', 'enrollment_id');

        return $updated;
    }

    /**
     * The four CRM triggers: the deal's own, never-reassigned
     * `crm_opportunities.location_id`, joined from the occurrence key's own
     * `crm_opportunity_history` row.
     */
    private function backfillCrmTriggered(): int
    {
        $updated = 0;
        $crmTriggerTypes = [
            'opportunity_created',
            'opportunity_stage_changed',
            'opportunity_won',
            'opportunity_lost',
        ];

        DB::table('automation_enrollments as e')
            ->join('crm_opportunity_history as h', function ($join): void {
                $join->on('h.id', '=', DB::raw(
                    "CAST(SUBSTRING(e.trigger_occurrence_key, LENGTH('crm_opportunity_history:') + 1) AS UNSIGNED)"
                ))->on('h.business_id', '=', 'e.business_id');
            })
            ->join('crm_opportunities as o', function ($join): void {
                $join->on('o.id', '=', 'h.opportunity_id')->on('o.business_id', '=', 'h.business_id');
            })
            ->whereIn('e.trigger_type', $crmTriggerTypes)
            ->whereNull('e.business_location_id')
            ->where('e.trigger_occurrence_key', 'like', 'crm_opportunity_history:%')
            ->whereNotNull('o.location_id')
            ->orderBy('e.id')
            ->select(['e.id as enrollment_id', 'o.location_id'])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (&$updated): void {
                foreach ($rows as $row) {
                    $affected = DB::table('automation_enrollments')
                        ->where('id', $row->enrollment_id)
                        ->whereNull('business_location_id')
                        ->update(['business_location_id' => (int) $row->location_id]);

                    $updated += $affected;
                }
            }, 'e.id', 'enrollment_id');

        return $updated;
    }

    public function unresolvedCount(): int
    {
        return DB::table('automation_enrollments')->whereNull('business_location_id')->count();
    }
}
