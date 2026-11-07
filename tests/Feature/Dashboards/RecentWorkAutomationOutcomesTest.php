<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Dashboard\RecentWorkReader;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Automation;
use App\Models\Business;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/** Verified CRM automation outcomes in "MotionGrove at work": succeeded sends and completed workflows, never pending/failed ones. */
class RecentWorkAutomationOutcomesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    private int $n = 0;

    private function sender(Business $business, string $name = 'Welcome text', string $action = 'send_message'): Automation
    {
        return Automation::create([
            'business_id' => $business->id, 'user_id' => $business->customer_id, 'name' => $name, 'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => 'contact_created', 'trigger_config' => ['contact_group_id' => null],
            'action_type' => $action, 'action_config' => ['body' => 'SECRET message body'],
        ]);
    }

    private function contact(Business $business): Contacts
    {
        $group = $this->contactGroup($business);

        return Contacts::create([
            'customer_id' => $group->customer_id, 'business_id' => $business->id, 'group_id' => $group->id,
            'phone' => '1404666' . str_pad((string) (++$this->n), 4, '0', STR_PAD_LEFT), 'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
    }

    private function execution(Business $business, Automation $automation, string $status, $completedAt = null): void
    {
        DB::table('automation_executions')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'automation_id' => $automation->id,
            'contact_id' => $this->contact($business)->id, 'trigger_type' => 'contact_created', 'idempotency_key' => 'k:' . Str::uuid(),
            'status' => $status, 'completed_at' => $completedAt, 'safe_result_summary' => $status === 'succeeded' ? 'Sent to Jane Doe 15551234567' : null,
            'created_at' => now()->subHours(8), 'updated_at' => now()->subHours(8),
        ]);
    }

    private function items(Business $business, ?int $actor = null): array
    {
        return app(RecentWorkReader::class)->recent($business->fresh(), 10, $actor);
    }

    private function keyed(array $items, string $key): array
    {
        return array_values(array_filter($items, fn ($i) => $i->key === $key));
    }

    public function test_only_succeeded_sends_are_counted_grouped_per_automation_and_day(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Follow Venue', 'Follow Account');
        $auto = $this->sender($business);
        foreach ([2, 3, 4] as $h) {
            $this->execution($business, $auto, 'succeeded', now()->subHours($h));
        }
        $this->execution($business, $auto, 'pending');
        $this->execution($business, $auto, 'failed', now()->subHour());
        $this->execution($business, $auto, 'skipped', now()->subHour());
        $this->execution($business, $this->sender($business, 'Field setter', 'update_contact_field'), 'succeeded', now()->subHours(2));

        $items = $this->items($business);
        $sent = $this->keyed($items, 'automation_follow_ups_sent');

        $this->assertCount(1, $sent, 'One group per automation per local day; internal field updates are not follow-ups.');
        $this->assertSame('Sent 3 automated follow-ups (Welcome text)', $sent[0]->text);
        $this->assertSame(now()->subHours(2)->getTimestamp(), $sent[0]->at->getTimestamp(), 'The newest completion time.');
        $this->assertCount(1, $this->keyed($items, 'automation_failed'), 'The failure is still reported, once.');
        $this->assertSame(['automations'], $sent[0]->permissions);
        $this->assertSame('automations', $sent[0]->featureKey);
    }

    public function test_no_customer_data_or_message_content_reaches_the_feed(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Private Venue', 'Private Account');
        $this->execution($business, $this->sender($business), 'succeeded', now()->subHour());

        $text = implode(' ', array_map(fn ($i) => $i->text, $this->items($business)));

        foreach (['SECRET', 'Jane', '1555', '1404666'] as $leak) {
            $this->assertStringNotContainsString($leak, $text);
        }
    }

    public function test_another_business_never_contributes(): void
    {
        [, $mine] = $this->tenant(WorkspacePlanTier::Growth, 'Mine Venue', 'Mine Account');
        [, $theirs] = $this->tenant(WorkspacePlanTier::Growth, 'Theirs Venue', 'Theirs Account');
        $this->execution($theirs, $this->sender($theirs, 'Their text'), 'succeeded', now()->subHour());

        $this->assertSame([], $this->keyed($this->items($mine), 'automation_follow_ups_sent'));
        $this->assertCount(1, $this->keyed($this->items($theirs), 'automation_follow_ups_sent'));
    }

    private function workflow(Business $business, string $name, int $completed, int $steps = 2, ?int $locationId = null): void
    {
        $workflowId = DB::table('automation_workflows')->insertGetId(['uid' => (string) Str::uuid(), 'business_id' => $business->id, 'name' => $name, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $versionId = DB::table('automation_workflow_versions')->insertGetId(['uid' => (string) Str::uuid(), 'workflow_id' => $workflowId, 'business_id' => $business->id, 'version_number' => 1, 'definition' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        foreach (range(1, $completed) as $i) {
            DB::table('automation_enrollments')->insert([
                'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'workflow_id' => $workflowId, 'version_id' => $versionId,
                'contact_id' => $this->contact($business)->id, 'business_location_id' => $locationId, 'status' => 'completed', 'trigger_type' => 'contact_created',
                'trigger_occurrence_key' => 'o:' . Str::uuid(), 'enrollment_key' => 'e:' . Str::uuid(), 'step_count' => $steps,
                'enrolled_at' => now()->subHours(9), 'completed_at' => now()->subHours(3), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_completed_workflows_are_counted_without_claiming_a_booking_or_reply(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Flow Venue', 'Flow Account');
        $this->workflow($business, 'Review request', 2);
        $this->workflow($business, 'Idle flow', 3, 0);

        $done = $this->keyed($this->items($business), 'workflow_completed');

        $this->assertCount(1, $done, 'A zero-step enrollment is internal noise.');
        $this->assertSame('Review request completed for 2 contacts', $done[0]->text);
        $this->assertDoesNotMatchRegularExpression('/book|repl|respond|won/i', $done[0]->text);
    }

    public function test_location_bound_workflow_completions_follow_the_actors_reach(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Site Flow Venue', 'Site Flow Account');
        $location = (int) DB::table('business_locations')->where('business_id', $business->id)->value('id');
        $this->workflow($business, 'North flow', 1, 2, $location);

        $this->mock(LocationAccessGuard::class, fn ($m) => $m->shouldReceive('accessibleLocationIdsForBusiness')->andReturn([]));
        $this->assertSame([], $this->keyed($this->items($business, (int) $customer->user_id), 'workflow_completed'));

        $this->mock(LocationAccessGuard::class, fn ($m) => $m->shouldReceive('accessibleLocationIdsForBusiness')->andReturn([$location]));
        $this->assertCount(1, $this->keyed($this->items($business, (int) $customer->user_id), 'workflow_completed'));
    }

    public function test_reading_the_feed_sends_nothing_and_writes_nothing(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Quiet Venue', 'Quiet Account');
        $this->execution($business, $this->sender($business), 'succeeded', now()->subHour());
        $this->execution($business, $this->sender($business, 'Pending one'), 'pending');
        Queue::fake();
        $before = [DB::table('automation_executions')->count(), DB::table('automation_executions')->where('status', 'pending')->count()];

        $this->items($business);
        $this->items($business);

        Queue::assertNothingPushed();
        $this->assertSame($before, [DB::table('automation_executions')->count(), DB::table('automation_executions')->where('status', 'pending')->count()], 'The pending execution stays pending.');
    }
}
