<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Migration\AutomationEnrollmentLocationBackfillV1;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ChatBox;
use App\Models\Contacts;
use App\Models\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Location run-scope foundation — independent review correction (pre-merge):
 * AutomationEnrollmentLocationBackfillV1 must resolve only from immutable,
 * trigger-appropriate evidence, never from the enrollment's Contact today.
 *
 * Every test here builds a historical `automation_enrollments` row directly
 * (raw insert, business_location_id NULL, exactly as a pre-lane row would
 * look), then proves what the backfill does and does not conclude.
 */
class AutomationEnrollmentLocationBackfillV1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;

    private function backfill(): array
    {
        return (new AutomationEnrollmentLocationBackfillV1())->run();
    }

    private function standardPipeline(Business $business): \App\Models\CrmPipeline
    {
        return app(CrmPipelineService::class)->setUpStandardPipeline($business);
    }

    /** A historical enrollment row, business_location_id NULL, as a pre-lane row would be. */
    private function historicalEnrollment(
        Business $business,
        Contacts $contact,
        WorkflowTriggerType $triggerType,
        string $occurrenceKey,
        int $workflowId,
        int $versionId,
    ): int {
        return (int) DB::table('automation_enrollments')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'workflow_id' => $workflowId,
            'version_id' => $versionId,
            'contact_id' => $contact->id,
            'business_location_id' => null,
            'status' => 'completed',
            'current_node_id' => null,
            'trigger_type' => $triggerType->value,
            'trigger_occurrence_key' => $occurrenceKey,
            'enrollment_key' => 'wf:' . $workflowId . ':c:' . $contact->id . ':o:' . $occurrenceKey,
            'causation_depth' => 0,
            'step_count' => 1,
            'enrolled_at' => now()->subDays(30),
            'completed_at' => now()->subDays(30),
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ]);
    }

    private function locationIdOf(int $enrollmentId): ?int
    {
        $value = DB::table('automation_enrollments')->where('id', $enrollmentId)->value('business_location_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * A real, immutable legacy inbound `reports` row — the durable evidence
     * `report:{id}` occurrence keys resolve from. `to` is the sender's phone
     * AT THE MOMENT the message arrived, exactly as `DLRController
     * ::inboundDLR()` writes it for an incoming report.
     */
    private function historicalReport(Business $business, string $to, string $from = '14155550199'): Reports
    {
        return Reports::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => $from,
            'to' => $to,
            'message' => 'Historical inbound message.',
            'sms_type' => 'plain',
            'status' => 'Delivered',
            'customer_status' => 'Delivered',
            'direction' => Reports::DIRECTION_INCOMING,
            'cost' => 1,
            'sms_count' => 1,
        ]);
    }

    // =================================================================
    // contact_created / contact_date_reached / manual_enrollment: NEVER
    // resolved, even when the Contact has a Location today (§7 correction).
    // =================================================================

    public function test_a_transferred_contacts_historical_enrollment_is_never_backfilled(): void
    {
        [, $business] = $this->entitledTenant();
        $locationB = $this->businessLocation($business);
        $contact = $this->contactFor($business);

        // The Contact has a real Location TODAY — this is exactly the "today's
        // relationship" the corrected backfill must not use, because nothing
        // proves it was the Contact's Location when this run actually happened
        // (the Contact could have been transferred since — a real Location B
        // exists here specifically so a bug that "just uses whatever's there"
        // would silently pass).
        $contact->forceFill(['location_id' => $locationB->id])->save();

        [$workflow, $version] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ContactCreated);
        $enrollmentId = $this->historicalEnrollment(
            $business, $contact, WorkflowTriggerType::ContactCreated, (string) $contact->id,
            (int) $workflow->id, (int) $version->id,
        );

        $result = $this->backfill();

        $this->assertNull($this->locationIdOf($enrollmentId), 'A contact_created historical run must never be inferred from the Contact\'s current Location.');
        $this->assertSame(0, $result['total']);
    }

    public function test_manual_enrollment_and_date_reached_historical_rows_are_never_backfilled(): void
    {
        [, $business] = $this->entitledTenant();
        $contact = $this->contactFor($business); // has a real, current Location

        foreach ([WorkflowTriggerType::ManualEnrollment, WorkflowTriggerType::ContactDateReached] as $type) {
            [$workflow, $version] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ContactCreated);
            $id = $this->historicalEnrollment($business, $contact, $type, 'occurrence-' . $type->value, (int) $workflow->id, (int) $version->id);

            $this->backfill();

            $this->assertNull($this->locationIdOf($id), $type->value . ' must never be backfilled from the Contact.');
        }
    }

    // =================================================================
    // message_received: the ORIGINAL inbound report's own phone, which may
    // DIFFER from the Contact's phone TODAY — proving the historical
    // message's own immutable evidence, never the Contact, is used.
    // =================================================================

    public function test_message_received_resolves_from_the_conversation_even_when_the_contact_disagrees(): void
    {
        [, $business] = $this->entitledTenant();
        $contactLocation = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $conversationLocation = $this->businessLocation($business);

        $contact = $this->contactFor($business);
        $contact->forceFill(['location_id' => $contactLocation->id])->save();

        $report = $this->historicalReport($business, $contact->phone);

        ChatBox::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'uid' => (string) Str::uuid(),
            'from' => '14155550100',
            'to' => $contact->phone,
            'location_id' => $conversationLocation->id,
        ]);

        [$workflow, $version] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::MessageReceived);
        $enrollmentId = $this->historicalEnrollment(
            $business, $contact, WorkflowTriggerType::MessageReceived, 'report:' . $report->id,
            (int) $workflow->id, (int) $version->id,
        );

        $result = $this->backfill();

        $this->assertSame((int) $conversationLocation->id, $this->locationIdOf($enrollmentId), 'message_received must resolve from the conversation\'s own Location, not the Contact\'s.');
        $this->assertNotSame((int) $contactLocation->id, (int) $conversationLocation->id, 'Sanity: the two Locations must actually differ for this proof to mean anything.');
        $this->assertSame(1, $result['message_received']);
    }

    /**
     * THE CENTRAL ONE (correction round 2). A Contact whose phone changed
     * since the historical run must never borrow a NEWER conversation's
     * Location just because that newer conversation happens to match the
     * Contact's CURRENT phone. The original report's own `to` — phone A —
     * is the only evidence that may ever resolve this row, never phone B.
     */
    public function test_a_transferred_phone_never_borrows_a_newer_conversations_location(): void
    {
        [, $business] = $this->entitledTenant();
        $locationA = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $locationB = $this->businessLocation($business);

        $phoneA = '12025550101';
        $phoneB = '12025550102';

        $contact = $this->contactFor($business);
        $contact->forceFill(['phone' => $phoneA, 'location_id' => $locationA->id])->save();

        // The ORIGINAL inbound message, and the conversation it opened, both
        // for phone A at Location A.
        $report = $this->historicalReport($business, $phoneA);
        ChatBox::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'uid' => (string) Str::uuid(),
            'from' => '14155550199',
            'to' => $phoneA,
            'location_id' => $locationA->id,
        ]);

        [$workflow, $version] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::MessageReceived);
        $enrollmentId = $this->historicalEnrollment(
            $business, $contact, WorkflowTriggerType::MessageReceived, 'report:' . $report->id,
            (int) $workflow->id, (int) $version->id,
        );

        // The Contact's phone changes AFTER the run — the exact scenario a
        // Contact-phone-keyed lookup would get wrong.
        $contact->forceFill(['phone' => $phoneB, 'location_id' => $locationB->id])->save();

        // A brand-new, perfectly UNAMBIGUOUS conversation opens for phone B,
        // at a DIFFERENT Location — present specifically so a bug that
        // "just matches the Contact's current phone" would silently find
        // exactly one thread and confidently assign the wrong Location.
        ChatBox::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'uid' => (string) Str::uuid(),
            'from' => '14155550199',
            'to' => $phoneB,
            'location_id' => $locationB->id,
        ]);

        $result = $this->backfill();

        $this->assertSame(
            (int) $locationA->id,
            $this->locationIdOf($enrollmentId),
            'The historical run must resolve from its OWN report\'s phone (A), never the Contact\'s current phone (B).',
        );
        $this->assertNotSame((int) $locationB->id, $this->locationIdOf($enrollmentId));
        $this->assertSame(1, $result['message_received']);
    }

    public function test_message_received_with_an_ambiguous_conversation_stays_null(): void
    {
        [, $business] = $this->entitledTenant();
        $contact = $this->contactFor($business);
        $report = $this->historicalReport($business, $contact->phone);

        // Two threads for the same (business, phone) pair — cannot disambiguate.
        foreach ([$this->businessLocation($business), $this->businessLocation($business)] as $i => $location) {
            ChatBox::create([
                'user_id' => $business->customer_id,
                'business_id' => $business->id,
                'uid' => (string) Str::uuid(),
                'from' => '1415555010' . $i,
                'to' => $contact->phone,
                'location_id' => $location->id,
            ]);
        }

        [$workflow, $version] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::MessageReceived);
        $enrollmentId = $this->historicalEnrollment(
            $business, $contact, WorkflowTriggerType::MessageReceived, 'report:' . $report->id,
            (int) $workflow->id, (int) $version->id,
        );

        $this->backfill();

        $this->assertNull($this->locationIdOf($enrollmentId), 'An ambiguous conversation must never be guessed between.');
    }

    /**
     * `operation:{id}` — the managed inbound path — has NO durable
     * per-message phone evidence anywhere (§ the method's own docblock).
     * Even a perfectly matching, unambiguous conversation for the
     * Contact's phone must never be used, because nothing proves it is
     * the conversation this run's own occurrence actually belongs to.
     */
    public function test_a_managed_operation_occurrence_is_never_backfilled(): void
    {
        [, $business] = $this->entitledTenant();
        $location = $this->businessLocation($business);
        $contact = $this->contactFor($business);

        ChatBox::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'uid' => (string) Str::uuid(),
            'from' => '14155550199',
            'to' => $contact->phone,
            'location_id' => $location->id,
        ]);

        [$workflow, $version] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::MessageReceived);
        $enrollmentId = $this->historicalEnrollment(
            $business, $contact, WorkflowTriggerType::MessageReceived, 'operation:123456',
            (int) $workflow->id, (int) $version->id,
        );

        $result = $this->backfill();

        $this->assertNull($this->locationIdOf($enrollmentId), 'A managed-operation occurrence has no durable phone evidence and must never be backfilled.');
        $this->assertSame(0, $result['message_received']);
    }

    // =================================================================
    // The four CRM triggers: the deal's own Location, which may DIFFER
    // from the Contact's own.
    // =================================================================

    public function test_crm_triggers_resolve_from_the_deal_even_when_the_contact_disagrees(): void
    {
        [, $business] = $this->entitledTenant();
        $contactLocation = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $dealLocation = $this->businessLocation($business);

        $pipeline = $this->standardPipeline($business);
        $contact = $this->contactFor($business);
        $contact->forceFill(['location_id' => $contactLocation->id])->save();

        $deal = app(CrmOpportunityService::class)->create($business, $pipeline, $contact, 'Historical deal');
        $deal->forceFill(['location_id' => $dealLocation->id])->save();

        $historyId = DB::table('crm_opportunity_history')
            ->where('opportunity_id', $deal->id)->where('event', 'created')->value('id');
        $this->assertNotNull($historyId, 'Sanity: creating a deal must leave a history row.');

        [$workflow, $version] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::OpportunityCreated);
        $enrollmentId = $this->historicalEnrollment(
            $business, $contact, WorkflowTriggerType::OpportunityCreated, 'crm_opportunity_history:' . $historyId,
            (int) $workflow->id, (int) $version->id,
        );

        $result = $this->backfill();

        $this->assertSame((int) $dealLocation->id, $this->locationIdOf($enrollmentId), 'A CRM-triggered historical run must resolve from the deal\'s own Location, not the Contact\'s.');
        $this->assertNotSame((int) $contactLocation->id, (int) $dealLocation->id, 'Sanity: the two Locations must actually differ for this proof to mean anything.');
        $this->assertSame(1, $result['crm']);
    }

    public function test_a_crm_trigger_whose_deal_has_no_location_stays_null(): void
    {
        [, $business] = $this->entitledTenant();
        $pipeline = $this->standardPipeline($business);
        $contact = $this->contactFor($business);

        $deal = app(CrmOpportunityService::class)->create($business, $pipeline, $contact, 'No-location deal');
        $deal->forceFill(['location_id' => null])->save();

        $historyId = DB::table('crm_opportunity_history')
            ->where('opportunity_id', $deal->id)->where('event', 'created')->value('id');

        [$workflow, $version] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::OpportunityCreated);
        $enrollmentId = $this->historicalEnrollment(
            $business, $contact, WorkflowTriggerType::OpportunityCreated, 'crm_opportunity_history:' . $historyId,
            (int) $workflow->id, (int) $version->id,
        );

        $this->backfill();

        $this->assertNull($this->locationIdOf($enrollmentId));
    }

    public function test_the_backfill_is_idempotent_and_never_overwrites_an_already_set_row(): void
    {
        [, $business] = $this->entitledTenant();
        $realLocation = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $otherLocation = $this->businessLocation($business);
        $contact = $this->contactFor($business);

        [$workflow, $version] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ContactCreated);
        $enrollmentId = $this->historicalEnrollment(
            $business, $contact, WorkflowTriggerType::ContactCreated, (string) $contact->id,
            (int) $workflow->id, (int) $version->id,
        );

        // Already resolved by a live write (or a prior, correct backfill) —
        // must never be touched again, even to a DIFFERENT, also-plausible
        // value.
        DB::table('automation_enrollments')->where('id', $enrollmentId)->update(['business_location_id' => $realLocation->id]);

        $this->backfill();

        $this->assertSame((int) $realLocation->id, $this->locationIdOf($enrollmentId));
        $this->assertNotSame((int) $otherLocation->id, $this->locationIdOf($enrollmentId));
    }
}
