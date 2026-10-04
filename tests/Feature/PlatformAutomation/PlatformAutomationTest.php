<?php

namespace Tests\Feature\PlatformAutomation;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformAutomation\PlatformRunState;
use App\Enums\PlatformAutomation\PlatformStepState;
use App\Events\Entitlement\WorkspaceEnteredGracePeriod;
use App\Jobs\PlatformAutomation\ExecutePlatformAutomationRun;
use App\Library\PlatformAutomation\PlatformAutomationCatalog;
use App\Library\PlatformAutomation\PlatformAutomationManager;
use App\Library\PlatformAutomation\PlatformRunExecutor;
use App\Library\PlatformAutomation\PlatformScheduledTriggers;
use App\Mail\PlatformAutomationMail;
use App\Models\PlatformAutomation;
use App\Models\PlatformAutomationRun;
use App\Models\PlatformDatabaseNotification;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Platform Automations: definitions, triggers (event + scheduled), actions, safety
 * classes, retries, and tenant isolation. Jobs are faked and runs are advanced by
 * hand so every assertion is deterministic.
 */
class PlatformAutomationTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function manager(): PlatformAutomationManager
    {
        return app(PlatformAutomationManager::class);
    }

    /** @param list<array<string, mixed>> $steps */
    private function automation(string $trigger, array $steps, array $params = [], array $conditions = [], bool $enable = true): PlatformAutomation
    {
        $automation = $this->manager()->create([
            'name' => 'Test ' . $trigger,
            'trigger_type' => $trigger,
            'definition' => ['params' => $params, 'conditions' => $conditions, 'steps' => $steps],
        ], $this->platformAdminId());

        return $enable ? $this->manager()->enable($automation, $this->platformAdminId()) : $automation;
    }

    private function email(string $recipient = 'workspace_owner'): array
    {
        return ['action' => 'send_email', 'params' => ['recipient' => $recipient, 'subject' => 'Hello {{user.first_name}}', 'body' => 'About {{workspace.name}}', 'purpose' => 'transactional']];
    }

    private function advance(PlatformAutomationRun $run): PlatformAutomationRun
    {
        app(PlatformRunExecutor::class)->advance($run->id);

        return $run->fresh();
    }

    private function trialSubscription(Workspace $workspace, string $endsIn): int
    {
        return DB::table('platform_subscriptions')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'workspace_id' => $workspace->id,
            'workspace_plan_catalog_id' => DB::table('workspace_plan_catalog')->value('id'),
            'local_idempotency_key' => 'k-' . $workspace->id . '-' . uniqid(),
            'billing_cycle_snapshot' => 'monthly',
            'status' => 'trialing',
            'trial_ends_at' => Carbon::parse($endsIn),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // -- catalog & definitions -----------------------------------------------------

    public function test_every_recipe_points_at_a_real_available_trigger_and_validates(): void
    {
        foreach (PlatformAutomationCatalog::recipes() as $key => $recipe) {
            if (! $recipe['available']) {
                $this->assertArrayHasKey('unavailable_reason', $recipe, $key);
                continue;
            }

            $this->assertTrue(PlatformAutomationCatalog::triggers()[$recipe['trigger_type']]['available'] ?? false, "{$key} points at a trigger that does not exist");
            $automation = $this->manager()->createFromRecipe($key, $this->platformAdminId());
            $this->assertSame('draft', $automation->status->value, 'a recipe never arrives switched on');
        }
    }

    public function test_the_invitation_recipe_is_honestly_unavailable(): void
    {
        $this->expectException(ValidationException::class);
        $this->manager()->createFromRecipe('invitation_reminder', $this->platformAdminId());
    }

    public function test_the_validator_refuses_what_cannot_work(): void
    {
        $bad = [
            'unknown trigger' => ['user.invited', [['action' => 'send_email', 'params' => []]]],
            'unknown merge field' => ['workspace.created', [['action' => 'send_in_app_notification', 'params' => ['recipient' => 'workspace_owner', 'title' => 'Hi {{user.password}}', 'message' => 'x']]]],
            'http webhook' => ['workspace.created', [['action' => 'webhook', 'params' => ['url' => 'http://example.com/hook']]]],
            'internal webhook' => ['workspace.created', [['action' => 'webhook', 'params' => ['url' => 'https://localhost/hook']]]],
            'recipient the trigger cannot have' => ['user.registered', [['action' => 'send_in_app_notification', 'params' => ['recipient' => 'business_owner', 'title' => 'a', 'message' => 'b']]]],
            'suspend on a user trigger' => ['user.registered', [['action' => 'suspend_business', 'params' => ['reason' => 'x']]]],
            'no steps' => ['workspace.created', []],
            'marketing purpose' => ['workspace.created', [['action' => 'send_email', 'params' => ['recipient' => 'workspace_owner', 'subject' => 's', 'body' => 'b', 'purpose' => 'marketing']]]],
        ];

        foreach ($bad as $label => [$trigger, $steps]) {
            try {
                $this->manager()->create(['name' => $label, 'trigger_type' => $trigger, 'definition' => ['steps' => $steps]], $this->platformAdminId());
                $this->fail("{$label} should have been refused");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, PlatformAutomation::query()->count());
    }

    public function test_versions_are_immutable_snapshots_and_duplicates_are_always_drafts(): void
    {
        $a = $this->automation('workspace.created', [$this->email()]);
        $this->assertSame(1, $a->version);

        $same = $this->manager()->update($a, ['name' => 'Renamed', 'trigger_type' => 'workspace.created', 'definition' => $a->definition], $this->platformAdminId());
        $this->assertSame(1, $same->version, 'a rename alone is not a new version');

        $steps = $a->definition['steps'];
        $steps[0]['params']['subject'] = 'Changed';
        $v2 = $this->manager()->update($a, ['name' => 'Renamed', 'trigger_type' => 'workspace.created', 'definition' => ['steps' => $steps]], $this->platformAdminId());
        $this->assertSame(2, $v2->version);
        $this->assertSame(2, $a->versions()->count());
        $this->assertSame('Hello {{user.first_name}}', $a->versions()->where('version_number', 1)->first()->definition['steps'][0]['params']['subject']);

        $copy = $this->manager()->duplicate($v2, $this->platformAdminId());
        $this->assertSame('draft', $copy->status->value);
        $this->assertNotSame($v2->uid, $copy->uid);
    }

    // -- event triggers, idempotency, isolation ---------------------------------------

    public function test_an_event_creates_exactly_one_run_and_emails_only_that_workspaces_owner(): void
    {
        Mail::fake();
        [$ownerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Alpha Biz', 'Alpha WS');
        [$ownerB, , $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Beta Biz', 'Beta WS');
        $this->automation('subscription.payment_failed', [$this->email()]);

        event(new WorkspaceEnteredGracePeriod($workspaceA->id, null, 'card declined'));

        $run = PlatformAutomationRun::query()->sole();
        $this->assertSame($workspaceA->id, (int) $run->workspace_id);
        $this->assertSame('workspace', $run->target_type->value);
        Queue::assertPushed(ExecutePlatformAutomationRun::class);

        $run = $this->advance($run);

        $this->assertSame(PlatformRunState::Succeeded, $run->state);
        Mail::assertSent(PlatformAutomationMail::class, fn ($m) => $m->hasTo($ownerA->user->email) && str_contains($m->mailBody, 'Alpha WS'));
        Mail::assertNotSent(PlatformAutomationMail::class, fn ($m) => $m->hasTo($ownerB->user->email));

        // The same occurrence again is the same run.
        $this->assertSame(0, app(\App\Library\PlatformAutomation\PlatformTriggerDispatcher::class)->fire(
            'subscription.payment_failed', \App\Library\PlatformAutomation\PlatformTarget::workspace($workspaceA->id), $run->occurrence_key));
        $this->assertSame(1, PlatformAutomationRun::query()->count());

        // And Workspace B has its own, separate run.
        event(new WorkspaceEnteredGracePeriod($workspaceB->id, null, 'card declined'));
        $this->assertSame(2, PlatformAutomationRun::query()->count());
        $this->assertSame([$workspaceA->id, $workspaceB->id], PlatformAutomationRun::query()->orderBy('id')->pluck('workspace_id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_conditions_and_the_enabled_switch_decide_whether_a_run_starts(): void
    {
        [, , $growth] = $this->tenant(WorkspacePlanTier::Growth, 'G Biz', 'G WS');
        $only = $this->automation('subscription.payment_failed', [$this->email()], [], [['fact' => 'plan_tier', 'op' => 'eq', 'value' => 'agency']]);

        event(new WorkspaceEnteredGracePeriod($growth->id, null, null));
        $this->assertSame(0, PlatformAutomationRun::query()->count(), 'a Growth workspace does not match an agency-only condition');

        $this->manager()->update($only, ['name' => $only->name, 'trigger_type' => 'subscription.payment_failed', 'definition' => ['conditions' => [['fact' => 'plan_tier', 'op' => 'in', 'value' => 'growth,core']], 'steps' => $only->definition['steps']]], $this->platformAdminId());
        event(new WorkspaceEnteredGracePeriod($growth->id, null, null));
        $this->assertSame(1, PlatformAutomationRun::query()->count());

        $this->manager()->disable($only->fresh(), $this->platformAdminId());
        event(new WorkspaceEnteredGracePeriod($growth->id, null, 'again'));
        $this->assertSame(1, PlatformAutomationRun::query()->count(), 'a disabled automation never fires');
    }

    public function test_platform_runs_never_touch_the_business_automation_engine_and_vice_versa(): void
    {
        Mail::fake();
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Iso Biz', 'Iso WS');
        $before = [
            DB::table('automation_workflows')->count(), DB::table('automation_enrollments')->count(),
            DB::table('automation_step_runs')->count(), DB::table('automation_executions')->count(),
        ];
        $this->automation('subscription.payment_failed', [$this->email()]);

        event(new WorkspaceEnteredGracePeriod($workspace->id, null, null));
        $this->advance(PlatformAutomationRun::query()->sole());

        $after = [
            DB::table('automation_workflows')->count(), DB::table('automation_enrollments')->count(),
            DB::table('automation_step_runs')->count(), DB::table('automation_executions')->count(),
        ];
        $this->assertSame($before, $after, 'no Business-automation row was created by a Platform run');

        // A Business-engine trigger with no Platform automation for it starts nothing here.
        $this->assertSame(1, PlatformAutomationRun::query()->count());
    }

    // -- scheduled triggers -------------------------------------------------------------

    public function test_trial_ending_fires_once_per_trial_and_only_inside_the_window(): void
    {
        Mail::fake();
        [$soonOwner, , $soon] = $this->tenant(WorkspacePlanTier::Growth, 'Soon Biz', 'Soon WS');
        [, , $later] = $this->tenant(WorkspacePlanTier::Growth, 'Later Biz', 'Later WS');
        $this->trialSubscription($soon, '+2 days');
        $this->trialSubscription($later, '+10 days');
        $this->automation('subscription.trial_ending', [$this->email()], ['days_before' => 3]);

        $sweep = app(PlatformScheduledTriggers::class);
        $this->assertSame(['subscription.trial_ending' => 1], array_filter($sweep->sweep()));
        $this->assertSame([], array_filter($sweep->sweep()), 'the next sweep starts nothing new');

        $run = PlatformAutomationRun::query()->sole();
        $this->assertSame('subscription', $run->target_type->value);
        $this->assertSame($soon->id, (int) $run->workspace_id);

        $this->advance($run);
        Mail::assertSent(PlatformAutomationMail::class, 1);
        Mail::assertSent(PlatformAutomationMail::class, fn ($m) => $m->hasTo($soonOwner->user->email));
    }

    public function test_onboarding_incomplete_fires_for_an_unfinished_business_only(): void
    {
        [$unfinished, $unfinishedBusiness] = $this->tenant(WorkspacePlanTier::Growth, 'Open Biz', 'Open WS');
        [$done, $doneBusiness] = $this->tenant(WorkspacePlanTier::Growth, 'Done Biz', 'Done WS');
        DB::table('businesses')->whereIn('id', [$unfinishedBusiness->id, $doneBusiness->id])->update(['created_at' => now()->subHours(30)]);
        DB::table('customer_onboardings')->insert([
            ['customer_id' => $unfinished->user_id, 'business_id' => $unfinishedBusiness->id, 'is_required' => true, 'status' => 'started', 'current_step' => 'goals', 'created_at' => now(), 'updated_at' => now()],
            ['customer_id' => $done->user_id, 'business_id' => $doneBusiness->id, 'is_required' => true, 'status' => 'completed', 'current_step' => 'goals', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->automation('business.onboarding_incomplete', [['action' => 'create_internal_task', 'params' => ['body' => 'Nudge {{business.name}}']]], ['hours' => 24]);

        app(PlatformScheduledTriggers::class)->sweep();
        $run = PlatformAutomationRun::query()->sole();
        $this->assertSame($unfinishedBusiness->id, (int) $run->business_id);

        $this->advance($run);
        $note = \App\Models\PlatformAccountNote::query()->sole();
        $this->assertSame('task', $note->kind);
        $this->assertSame('Nudge Open Biz', $note->body);
        $this->assertSame($unfinishedBusiness->id, (int) $note->business_id);
    }

    // -- actions ----------------------------------------------------------------------

    public function test_in_app_notices_reach_exactly_the_resolved_owner(): void
    {
        [$ownerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'A Biz', 'A WS');
        [$ownerB] = $this->tenant(WorkspacePlanTier::Growth, 'B Biz', 'B WS');
        $this->automation('workspace.created', [['action' => 'send_in_app_notification', 'params' => ['recipient' => 'workspace_owner', 'title' => 'Welcome to {{workspace.name}}', 'message' => 'Hi {{user.first_name}}']]]);

        \App\Events\Workspace\WorkspaceCreated::dispatch($workspaceA->id, $ownerA->user_id);
        $this->advance(PlatformAutomationRun::query()->sole());

        $rows = PlatformDatabaseNotification::query()->get();
        $this->assertCount(1, $rows);
        $this->assertSame($ownerA->user_id, (int) $rows->first()->notifiable_id);
        $this->assertSame('Welcome to A WS', $rows->first()->data['title']);
        $this->assertNotSame($ownerB->user_id, (int) $rows->first()->notifiable_id);
    }

    public function test_resend_verification_and_reset_link_send_links_and_never_a_password(): void
    {
        Notification::fake();
        $user = User::create(['first_name' => 'Una', 'last_name' => 'Verified', 'email' => 'una@example.test', 'status' => true, 'is_customer' => true, 'password' => bcrypt('x-very-long-secret-1')]);
        $this->automation('user.registered', [
            ['action' => 'resend_email_verification', 'params' => []],
            ['action' => 'send_password_reset_link', 'params' => []],
        ]);

        event(new \Illuminate\Auth\Events\Registered($user));
        $run = $this->advance(PlatformAutomationRun::query()->sole());

        $this->assertSame(PlatformRunState::Succeeded, $run->state);
        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertStringNotContainsString('secret', json_encode($run->steps->map->result->all()));
    }

    public function test_a_step_with_nobody_to_act_on_is_skipped_not_failed(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'S Biz', 'S WS');
        // A Workspace-level trigger has no "business_owner"; the validator refuses that, so
        // force the situation by pointing a user-trigger at a user that is already verified.
        $this->automation('user.registered', [['action' => 'resend_email_verification', 'params' => []]]);
        $verified = User::create(['first_name' => 'V', 'last_name' => 'V', 'email' => 'v@example.test', 'status' => true, 'is_customer' => true, 'email_verified_at' => now()]);

        event(new \Illuminate\Auth\Events\Registered($verified));
        $run = $this->advance(PlatformAutomationRun::query()->sole());

        $this->assertSame(PlatformRunState::Succeeded, $run->state);
        $this->assertSame(PlatformStepState::Skipped, $run->steps->first()->state);
    }

    // -- failure, retry, wait -----------------------------------------------------------

    public function test_a_failing_step_retries_then_fails_with_a_safe_error_and_can_be_retried(): void
    {
        $status = 500;
        Http::fake(function () use (&$status) {
            return Http::response($status === 500 ? 'boom: secret-internal-detail' : 'ok', $status);
        });
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'F Biz', 'F WS');
        $this->automation('workspace.created', [['action' => 'webhook', 'params' => ['url' => 'https://hooks.example.com/in']]]);
        \App\Events\Workspace\WorkspaceCreated::dispatch($workspace->id, 1);
        $run = PlatformAutomationRun::query()->sole();

        $run = $this->advance($run);
        $this->assertSame(PlatformRunState::Waiting, $run->state, 'first failure schedules a retry');
        $this->assertSame(1, $run->steps->first()->attempts);

        Carbon::setTestNow(now()->addMinutes(3));
        $run = $this->advance($run);
        $this->assertSame(PlatformRunState::Waiting, $run->state);

        Carbon::setTestNow(now()->addMinutes(11));
        $run = $this->advance($run);
        $this->assertSame(PlatformRunState::Failed, $run->state);
        $step = $run->steps->first();
        $this->assertSame(PlatformStepState::Failed, $step->state);
        $this->assertSame(3, $step->attempts);
        $this->assertStringNotContainsString('secret-internal-detail', (string) $run->safe_error . (string) $step->safe_error);

        $status = 200;
        $this->assertTrue(app(PlatformRunExecutor::class)->retry($run));
        $run = $this->advance($run->fresh());
        $this->assertSame(PlatformRunState::Succeeded, $run->state);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Platform-Signature') && $request->url() === 'https://hooks.example.com/in');
    }

    public function test_wait_parks_the_run_until_it_is_due(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'W Biz', 'W WS');
        $this->automation('workspace.created', [
            ['action' => 'wait', 'params' => ['amount' => 2, 'unit' => 'hours']],
            ['action' => 'add_internal_note', 'params' => ['body' => 'after the wait']],
        ]);
        \App\Events\Workspace\WorkspaceCreated::dispatch($workspace->id, 1);

        $run = $this->advance(PlatformAutomationRun::query()->sole());
        $this->assertSame(PlatformRunState::Waiting, $run->state);
        $this->assertSame(0, \App\Models\PlatformAccountNote::query()->count());

        Carbon::setTestNow(now()->addHours(3));
        $run = $this->advance($run);
        $this->assertSame(PlatformRunState::Succeeded, $run->state);
        $this->assertSame(1, \App\Models\PlatformAccountNote::query()->count());
    }

    // -- safety classes ---------------------------------------------------------------

    public function test_account_state_steps_wait_for_a_platform_owner_and_then_run_as_that_owner(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Risk Biz', 'Risk WS');

        $a =$this->manager()->create(['name' => 'Suspend', 'trigger_type' => 'usage.funding_failed', 'definition' => ['steps' => [
            ['action' => 'suspend_business', 'params' => ['reason' => 'Repeated funding failures']],
        ]]], $this->platformAdminId());
        $this->manager()->enable($a, $this->platformAdminId());

        \App\Events\Usage\BusinessFundingAttemptFailed::dispatch(991, $business->id, 'auto_recharge');
        $run = $this->advance(PlatformAutomationRun::query()->sole());

        $this->assertSame(PlatformRunState::AwaitingApproval, $run->state);
        $this->assertSame(BusinessStatus::Active, $business->fresh()->status, 'nothing changed before approval');

        $owner = $this->actingAsPlatformOwner();
        app(PlatformRunExecutor::class)->approve($run->steps->first(), $owner->id);
        $run = $this->advance($run->fresh());

        $this->assertSame(PlatformRunState::Succeeded, $run->state);
        $this->assertSame(BusinessStatus::Inactive, $business->fresh()->status);
        $step = $run->steps->first();
        $this->assertSame($owner->id, (int) $step->decided_by_user_id);
        $this->assertSame('business:' . $business->id, $step->operation_ref);
    }

    public function test_a_rejected_step_cancels_the_run_and_changes_nothing(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Keep Biz', 'Keep WS');
        $a = $this->manager()->create(['name' => 'Suspend', 'trigger_type' => 'usage.funding_failed', 'definition' => ['steps' => [
            ['action' => 'suspend_business', 'params' => ['reason' => 'Because']],
        ]]], $this->platformAdminId());
        $this->manager()->enable($a, $this->platformAdminId());

        \App\Events\Usage\BusinessFundingAttemptFailed::dispatch(992, $business->id, 'auto_recharge');
        $run = $this->advance(PlatformAutomationRun::query()->sole());
        $owner = $this->actingAsPlatformOwner();
        app(PlatformRunExecutor::class)->reject($run->steps->first(), $owner->id);
        $run = $this->advance($run->fresh());

        $this->assertSame(PlatformRunState::Cancelled, $run->state);
        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
    }

    public function test_an_unapproved_billing_step_can_never_run_by_itself(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Plan Biz', 'Plan WS');
        $a = $this->manager()->create(['name' => 'Downgrade', 'trigger_type' => 'subscription.payment_failed', 'definition' => ['steps' => [
            ['action' => 'change_plan', 'params' => ['tier' => 'core', 'reason' => 'Non payment']],
        ]]], $this->platformAdminId());
        $this->manager()->enable($a, $this->platformAdminId());

        event(new WorkspaceEnteredGracePeriod($workspace->id, null, null));
        $run = $this->advance(PlatformAutomationRun::query()->sole());
        $run = $this->advance($run);   // advancing again must not run it either

        $this->assertSame(PlatformRunState::AwaitingApproval, $run->state);
        $tier = DB::table('workspace_plan_assignments as a')->join('workspace_plan_catalog as c', 'c.id', '=', 'a.workspace_plan_catalog_id')->where('a.workspace_id', $workspace->id)->value('c.tier');
        $this->assertSame('growth', $tier);
    }
}
