<?php

namespace App\Library\Automation\Migration;

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
 *                           Business and the enrolled Contact's phone.
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
     * Business and the enrolled Contact's own phone number.
     */
    private function backfillMessageReceived(): int
    {
        $updated = 0;

        DB::table('automation_enrollments as e')
            ->join('contacts as c', function ($join): void {
                $join->on('c.id', '=', 'e.contact_id')->on('c.business_id', '=', 'e.business_id');
            })
            ->where('e.trigger_type', 'message_received')
            ->whereNull('e.business_location_id')
            ->orderBy('e.id')
            ->select(['e.id as enrollment_id', 'e.business_id', 'c.phone'])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (&$updated): void {
                foreach ($rows as $row) {
                    if ($row->phone === null || $row->phone === '') {
                        continue;
                    }

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

                    $affected = DB::table('automation_enrollments')
                        ->where('id', $row->enrollment_id)
                        ->whereNull('business_location_id')
                        ->update(['business_location_id' => (int) $matches->first()]);

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
