<?php

namespace Tests\Feature\Growth;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Growth\Concerns\CreatesGrowthFixtures;
use Tests\TestCase;

/**
 * Growth work history: one record, one vocabulary, built only from the Opportunity and its latest action execution. A technical success is
 * never presented as a business result, and the page has the same tenancy / capability / Location scope as the recommendations it lists.
 */
class GrowthWorkHistoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGrowthFixtures;

    private Customer $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['opportunity.enabled' => true]);
        [$this->owner, $this->business, $this->workspace] = $this->crmTenant();
        $this->primaryLocation = $this->growthLocation();
    }

    private function growth(string $name, array $extra = []): string
    {
        return route('customer.workspaces.businesses.growth.' . $name, array_merge([$this->workspace->uid, $this->business->uid], $extra));
    }

    private function opportunity(): Opportunity
    {
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $opportunity = $this->growthOpportunities()->first();
        $this->assertNotNull($opportunity, 'Fixture precondition: the engine produced a recommendation.');

        return $opportunity;
    }

    private function execution(Opportunity $opportunity, string $status, array $overrides = []): OpportunityActionExecution
    {
        $execution = new OpportunityActionExecution();
        $execution->forceFill(array_merge([
            'opportunity_id' => $opportunity->id,
            'action_key' => 'add_phone',
            'recommended_action_hash' => hash('sha256', 'hash-' . uniqid('', true)),
            'action_schema_version' => 1,
            'occurrence_number' => 1,
            'attempt_number' => 1,
            'idempotency_key' => hash('sha256', 'key-' . uniqid('', true)),
            'status' => $status,
            'initiated_by_user_id' => $this->owner->user_id,
            'initiated_by_type' => 'customer',
            'confirmed_by_user_id' => $this->owner->user_id,
            'confirmed_by_type' => 'customer',
            'approval_expires_at' => now()->addMinutes(30),
            'completion_policy' => 'system_verified',
            'safe_result_summary' => null,
            'safe_error_summary' => null,
            'started_at' => null,
            'completed_at' => null,
        ], $overrides))->save();

        return $execution;
    }

    private function setStatus(Opportunity $opportunity, string $status): void
    {
        DB::table('opportunities')->where('id', $opportunity->id)->update(['status' => $status]);
    }

    private function work(): string
    {
        $this->authenticateAs($this->owner, ['business_advisor', 'view_contact']);

        return $this->get($this->growth('work'))->assertOk()->getContent();
    }

    public function test_a_plain_recommendation_is_not_work_yet_and_the_empty_state_is_honest(): void
    {
        $this->opportunity();

        $html = $this->work();

        $this->assertStringContainsString('data-role="work-empty"', $html);
        $this->assertStringNotContainsString('data-role="work-row"', $html);
    }

    public function test_each_state_is_shown_in_the_one_vocabulary_from_the_records_that_prove_it(): void
    {
        $opportunity = $this->opportunity();

        $this->setStatus($opportunity, 'awaiting_approval');
        $this->assertStringContainsString('Awaiting your approval', $this->work());

        $this->setStatus($opportunity, 'in_progress');
        $execution = $this->execution($opportunity, 'pending');
        $this->assertStringContainsString('data-stage="approved"', $this->work());

        $execution->forceFill(['status' => 'running', 'started_at' => now()])->save();
        $this->assertStringContainsString('data-stage="executing"', $this->work());

        $execution->forceFill(['status' => 'succeeded', 'safe_result_summary' => 'Business phone updated and verified.', 'completed_at' => now()])->save();
        $this->setStatus($opportunity, 'completed');
        $verified = $this->work();
        $this->assertStringContainsString('data-stage="verified"', $verified);
        $this->assertStringContainsString('Business phone updated and verified.', $verified);
        $this->assertStringContainsString('does not show any change in enquiries, bookings or revenue', $verified, 'A technical success is not a business result.');
        $this->assertDoesNotMatchRegularExpression('/more (leads|bookings|customers)|revenue (grew|increased)|rankings improved/i', strip_tags($verified));
    }

    public function test_a_failed_execution_is_shown_as_failed_with_its_safe_reason_and_never_as_completed(): void
    {
        $opportunity = $this->opportunity();
        $this->execution($opportunity, 'failed', ['safe_error_summary' => 'Opportunity action state no longer matched the approved action.', 'completed_at' => now()]);

        $html = $this->work();

        $this->assertStringContainsString('data-stage="failed"', $html);
        $this->assertStringContainsString('no longer matched the approved action', $html);
        $this->assertStringNotContainsString('data-stage="verified"', $html);
        $this->assertStringNotContainsString('data-stage="completed"', $html);
    }

    public function test_an_attested_completion_is_completed_not_verified_and_a_dismissed_item_is_not_listed(): void
    {
        $opportunity = $this->opportunity();
        $this->execution($opportunity, 'succeeded', ['completion_policy' => 'customer_attested', 'completed_at' => now()]);
        $this->setStatus($opportunity, 'completed');
        $this->assertStringContainsString('data-stage="completed"', $this->work());

        $this->setStatus($opportunity, 'dismissed');
        $this->assertStringContainsString('data-role="work-empty"', $this->work(), 'Dismissed work is not part of the record.');
    }

    public function test_the_recommendation_page_shows_the_status_and_what_happened(): void
    {
        $opportunity = $this->opportunity();
        $this->setStatus($opportunity, 'in_progress');
        $this->execution($opportunity, 'failed', ['safe_error_summary' => 'The action did not complete safely.', 'completed_at' => now()]);
        $this->authenticateAs($this->owner, ['business_advisor', 'view_contact']);

        $detail = $this->get($this->growth('opportunities.show', ['opportunityUid' => $opportunity->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="work-status"', $detail);
        $this->assertStringContainsString('data-stage="failed"', $detail);
        $this->assertStringContainsString('The action did not complete safely.', $detail);
    }

    public function test_the_work_page_has_the_same_access_rules_as_the_rest_of_the_growth_center(): void
    {
        $this->opportunity();

        $this->authenticateAs($this->owner, ['view_contact']);
        $this->get($this->growth('work'))->assertStatus(401);

        [$stranger] = $this->crmTenant('Other Co', 'Other WS');
        $this->authenticateAs($stranger, ['business_advisor']);
        $this->get($this->growth('work'))->assertNotFound();
    }

    public function test_listing_the_work_never_changes_anything(): void
    {
        $opportunity = $this->opportunity();
        $this->setStatus($opportunity, 'awaiting_approval');
        $before = [DB::table('opportunities')->count(), DB::table('opportunity_action_executions')->count(), DB::table('opportunity_transitions')->count(), $opportunity->fresh()->status->value];

        $this->work();

        $this->assertSame($before, [DB::table('opportunities')->count(), DB::table('opportunity_action_executions')->count(), DB::table('opportunity_transitions')->count(), $opportunity->fresh()->status->value]);
    }

    // ------------------------------------------------------------------ Connected records (replies, booking, SEO)

    private function workAs(array $permissions): string
    {
        $this->authenticateAs($this->owner, $permissions);

        return $this->get($this->growth('work'))->assertOk()->getContent();
    }

    public function test_customer_replies_are_reported_from_recorded_send_states_and_never_as_a_booking(): void
    {
        $this->conversation([['incoming', 10], ['outgoing', 9, 'sent']]);
        $this->conversation([['incoming', 12], ['outgoing', 11, 'delivered']]);
        $this->conversation([['incoming', 8], ['outgoing', 7, 'failed']]); // a failed reply is not an answer: still waiting
        $this->conversation([['incoming', 5]]);

        $html = $this->workAs(['business_advisor', 'chat_box']);

        $this->assertStringContainsString('2 customers are waiting for a reply.', $html);
        $this->assertSame(2, substr_count($html, 'data-role="reply-row"'));
        $this->assertStringContainsString('Reply submitted to your messaging provider', $html);
        $this->assertStringContainsString('Reply delivered', $html);
        $text = str_replace('A reply is not a booking: MotionGrove does not know whether a customer booked.', '', strip_tags($html));
        $this->assertDoesNotMatchRegularExpression('/booked|revenue|recovered|converted/i', $text);
    }

    public function test_a_location_restricted_staff_member_sees_only_their_locations_replies_and_another_business_never_counts(): void
    {
        $second = $this->secondLocation('Second site');
        $this->conversation([['incoming', 5]], $this->primaryLocation->id);
        $this->conversation([['incoming', 6]], $second->id);

        [, $otherBusiness] = $this->crmTenant('Other Co', 'Other WS');
        DB::table('chat_boxes')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $otherBusiness->customer_id, 'business_id' => $otherBusiness->id,
            'from' => '15559990000', 'to' => '15559990001', 'notification' => 0, 'created_at' => now()->subDays(2), 'updated_at' => now(),
        ]);
        $boxId = (int) DB::table('chat_boxes')->where('business_id', $otherBusiness->id)->value('id');
        DB::table('chat_box_messages')->insert(['box_id' => $boxId, 'message' => 'x', 'sms_type' => 'sms', 'direction' => 'incoming', 'created_at' => now()->subHours(9), 'updated_at' => now()->subHours(9)]);

        $this->assertStringContainsString('2 customers are waiting for a reply.', $this->workAs(['business_advisor', 'chat_box']), 'Owner: this Business only, both Locations.');

        $staff = $this->createCustomer();
        $membership = $this->member($this->workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $this->primaryLocation);
        $this->authenticateAs($staff, ['business_advisor', 'chat_box']);

        $html = $this->get($this->growth('work'))->assertOk()->getContent();
        $this->assertStringContainsString('1 customer is waiting for a reply.', $html, 'Only the staff member\'s Location.');
    }

    public function test_a_booking_type_that_cannot_take_bookings_is_shown_with_a_fix_link_and_the_recheck_reads_live_state(): void
    {
        $typeId = $this->bookingType();
        $typeUid = (string) DB::table('booking_types')->where('id', $typeId)->value('uid');

        $first = $this->workAs(['business_advisor']);
        $this->assertStringContainsString('data-role="work-booking"', $first);
        $this->assertStringContainsString('data-state="needs_setup"', $first);
        $this->assertSame(1, preg_match('#data-role="booking-problem">(.*?)<a#s', $first, $before), 'The blocking reason is shown.');
        $this->assertStringContainsString('/booking-types/' . $typeUid, html_entity_decode($first), 'A fix link opens exactly that booking type.');

        // After the correction, simply loading the page again re-reads readiness: the same blocker is no longer reported.
        $this->assignStaff($typeId, null, 10);
        $second = $this->workAs(['business_advisor']);
        $stillBlocked = preg_match('#data-role="booking-problem">(.*?)<a#s', $second, $after) === 1 && trim(strip_tags($after[1])) === trim(strip_tags($before[1]));
        $this->assertFalse($stillBlocked, 'The recheck reflects the fix.');
        $this->assertStringContainsString('Ready does not mean customers have booked', $second);
    }

    public function test_website_and_seo_activity_is_recorded_activity_with_a_link_to_the_audit(): void
    {
        DB::table('seo_audit_runs')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'business_id' => $this->business->id, 'website_id' => 1, 'website_revision_id' => 1,
            'rule_set_version' => 1, 'status' => 'completed', 'critical_count' => 0, 'warning_count' => 2, 'info_count' => 0, 'created_at' => now()->subHours(3),
        ]);

        $html = $this->workAs(['business_advisor', 'view_seo']);

        $this->assertStringContainsString('data-role="work-seo"', $html);
        $this->assertStringContainsString('Website SEO audit completed: 2 issues found', $html);
        $this->assertStringContainsString('Recorded', $html);
        $this->assertStringContainsString('data-role="seo-audit-link"', $html);
        $this->assertDoesNotMatchRegularExpression('/ranking(s)? (improved|increase)|more traffic/i', strip_tags($html));
    }

    public function test_sections_a_user_may_not_open_are_not_shown(): void
    {
        $this->conversation([['incoming', 5]]);

        $html = $this->workAs(['business_advisor']); // no inbox and no SEO permission

        $this->assertStringNotContainsString('data-role="work-replies"', $html);
        $this->assertStringNotContainsString('data-role="work-seo"', $html);
    }
}
