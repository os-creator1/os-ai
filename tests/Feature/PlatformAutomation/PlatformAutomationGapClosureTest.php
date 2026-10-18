<?php

namespace Tests\Feature\PlatformAutomation;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformAutomation\PlatformRunState;
use App\Events\Account\UserAccountStatusChanged;
use App\Events\Provider\ProviderReconnectionRequired;
use App\Events\Usage\BusinessFundingAttemptFailed;
use App\Events\Usage\BusinessSpendingLimitReached;
use App\Events\Usage\BusinessWalletLowBalance;
use App\Events\Workspace\WorkspaceMembershipDeactivated;
use App\Events\Workspace\WorkspaceMembershipReactivated;
use App\Events\Workspace\WorkspaceMembershipRoleChanged;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\PlatformAutomation\PlatformAutomationCatalog;
use App\Library\PlatformAutomation\PlatformAutomationManager;
use App\Library\PlatformAutomation\PlatformRunExecutor;
use App\Library\PlatformAutomation\PlatformScheduledTriggers;
use App\Library\PlatformOwner\PlatformOwnerAccountActions;
use App\Mail\PlatformAutomationMail;
use App\Models\PlatformAutomation;
use App\Models\PlatformAutomationRun;
use App\Models\WorkspaceEntitlementOverride;
use App\Models\WorkspaceEntitlementTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * The narrow Platform Automations gap closure: the entitlement override action, the triggers
 * that now have a canonical source (and the write-side events added for them), and the
 * triggers that must stay unavailable.
 */
class PlatformAutomationGapClosureTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
        Queue::fake();
    }

    private function manager(): PlatformAutomationManager
    {
        return app(PlatformAutomationManager::class);
    }

    /** @param list<array<string, mixed>> $steps */
    private function enabled(string $trigger, array $steps, array $params = [], array $conditions = []): PlatformAutomation
    {
        $a = $this->manager()->create([
            'name' => 'T ' . $trigger, 'trigger_type' => $trigger,
            'definition' => ['params' => $params, 'conditions' => $conditions, 'steps' => $steps],
        ], $this->platformAdminId());

        return $this->manager()->enable($a, $this->platformAdminId());
    }

    private function note(): array
    {
        return [['action' => 'add_internal_note', 'params' => ['body' => 'seen {{business.name}}']]];
    }

    private function advance(PlatformAutomationRun $run): PlatformAutomationRun
    {
        app(PlatformRunExecutor::class)->advance($run->id);

        return $run->fresh();
    }

    private function override(string $workspaceFeatureState = 'allow', ?string $feature = null): array
    {
        return ['action' => 'set_feature_override', 'params' => [
            'feature' => $feature ?? PlatformFeature::Automations->value, 'state' => $workspaceFeatureState, 'reason' => 'Goodwill after an outage',
        ]];
    }

    // -- 1. entitlement action --------------------------------------------------------------

    public function test_a_feature_override_waits_for_approval_then_goes_through_the_canonical_authority(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Grant Biz', 'Grant WS');
        $feature = PlatformFeature::GoogleAdsModule; // Growth+, so Core does not have it
        $this->enabled('subscription.payment_failed', [$this->override('allow', $feature->value)]);
        $planBefore = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->value('workspace_plan_catalog_id');

        event(new \App\Events\Entitlement\WorkspaceEnteredGracePeriod($workspace->id, null, null));
        $run = $this->advance(PlatformAutomationRun::query()->sole());

        $this->assertSame(PlatformRunState::AwaitingApproval, $run->state);
        $this->assertSame(0, WorkspaceEntitlementOverride::query()->count(), 'nothing is overridden before approval');

        $owner = $this->actingAsPlatformOwner();
        app(PlatformRunExecutor::class)->approve($run->steps->first(), $owner->id);
        $run = $this->advance($run->fresh());

        $this->assertSame(PlatformRunState::Succeeded, $run->state);
        $override = WorkspaceEntitlementOverride::query()->sole();
        $this->assertSame($feature, $override->feature_key);
        $this->assertSame(WorkspaceEntitlementOverrideState::Allow, $override->state);
        $this->assertSame($owner->id, (int) $override->created_by_user_id, 'the approving owner is the actor');

        $transition = WorkspaceEntitlementTransition::query()->where('workspace_id', $workspace->id)->latest('id')->first();
        $this->assertSame('Goodwill after an outage', $transition->reason);
        $this->assertSame($owner->id, (int) $transition->actor_user_id);
        $this->assertSame('override:' . $workspace->id . ':' . $feature->value . ':allow', $run->steps->first()->operation_ref);
        $this->assertSame($planBefore, DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->value('workspace_plan_catalog_id'), 'the plan is never touched');
    }

    public function test_a_deny_override_and_a_repeat_are_idempotent(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Deny Biz', 'Deny WS');
        $this->enabled('subscription.payment_failed', [$this->override('deny')]);
        $owner = $this->actingAsPlatformOwner();

        $afterFirst = null;

        foreach ([1, 2] as $round) {
            event(new \App\Events\Entitlement\WorkspaceEnteredGracePeriod($workspace->id, null, 'round ' . $round));
            $run = PlatformAutomationRun::query()->latest('id')->first();
            $run = $this->advance($run);
            app(PlatformRunExecutor::class)->approve($run->steps->first(), $owner->id);
            $this->advance($run->fresh());
            $afterFirst ??= WorkspaceEntitlementTransition::query()->where('workspace_id', $workspace->id)->count();
            \Illuminate\Support\Carbon::setTestNow(now()->addSecond());
        }
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertSame(2, PlatformAutomationRun::query()->count());
        $this->assertSame(1, WorkspaceEntitlementOverride::query()->count());
        $this->assertSame(WorkspaceEntitlementOverrideState::Deny, WorkspaceEntitlementOverride::query()->sole()->state);
        $this->assertSame($afterFirst, WorkspaceEntitlementTransition::query()->where('workspace_id', $workspace->id)->count(), 'the second approval wrote no second audit row');
    }

    public function test_an_unknown_feature_and_an_unavailable_allow_are_refused_at_save_time(): void
    {
        $planned = collect(PlatformFeature::cases())->first(fn ($f) => ! PlatformFeatureRegistry::isAvailable($f->value));
        $this->assertNotNull($planned, 'the registry has at least one not-yet-available feature to test with');

        foreach ([
            'unknown key' => $this->override('allow', 'definitely_not_a_feature'),
            'allow of an unavailable feature' => $this->override('allow', $planned->value),
        ] as $label => $step) {
            try {
                $this->enabled('subscription.payment_failed', [$step]);
                $this->fail($label . ' should be refused');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        // A DENY of the same not-yet-available feature is allowed: withholding is always safe.
        $this->assertSame('enabled', $this->enabled('subscription.payment_failed', [$this->override('deny', $planned->value)])->status->value);
    }

    public function test_an_override_cannot_hang_off_a_trigger_that_has_no_workspace(): void
    {
        $this->expectException(ValidationException::class);
        $this->enabled('user.registered', [$this->override()]);
    }

    // -- 2. triggers with a canonical source -------------------------------------------------

    public function test_wallet_low_and_spend_limit_triggers_fire_for_that_business_with_the_reason_condition(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Low Biz', 'Low WS');
        [, $other] = $this->tenant(WorkspacePlanTier::Growth, 'Other Biz', 'Other WS');
        $this->enabled('usage.wallet_low', $this->note());
        $this->enabled('usage.spend_limit_reached', $this->note(), [], [['fact' => 'reason', 'op' => 'eq', 'value' => 'business_spend_cap']]);

        BusinessWalletLowBalance::dispatch($business->id);
        BusinessSpendingLimitReached::dispatch($other->id, 'insufficient_balance');   // wrong reason: no run
        BusinessSpendingLimitReached::dispatch($business->id, 'business_spend_cap');

        $runs = PlatformAutomationRun::query()->orderBy('id')->get();
        $this->assertSame(['usage.wallet_low', 'usage.spend_limit_reached'], $runs->pluck('trigger_type')->all());
        $this->assertSame([$business->id, $business->id], $runs->pluck('business_id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_only_an_auto_recharge_failure_fires_the_auto_recharge_trigger(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Auto Biz', 'Auto WS');
        $this->enabled('usage.auto_recharge_failed', $this->note());

        BusinessFundingAttemptFailed::dispatch(5001, $business->id, 'manual_top_up');
        $this->assertSame(0, PlatformAutomationRun::query()->count());

        BusinessFundingAttemptFailed::dispatch(5002, $business->id, 'auto_recharge');
        $this->assertSame(1, PlatformAutomationRun::query()->count());
    }

    public function test_membership_triggers_target_the_member_and_carry_their_workspace(): void
    {
        Mail::fake();
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Team Biz', 'Team WS');
        $member = \App\Models\User::create(['first_name' => 'Mia', 'last_name' => 'Member', 'email' => 'mia@example.test', 'status' => true, 'is_customer' => true]);
        $mail = fn () => [['action' => 'send_email', 'params' => ['recipient' => 'workspace_owner', 'subject' => 'Role change', 'body' => 'In {{workspace.name}}', 'purpose' => 'transactional']]];
        $this->enabled('membership.role_changed', $mail(), [], [['fact' => 'new_role', 'op' => 'eq', 'value' => 'admin']]);
        $this->enabled('membership.deactivated', $this->note());
        $this->enabled('membership.reactivated', $this->note());

        WorkspaceMembershipRoleChanged::dispatch(41, $workspace->id, $member->id, $owner->user_id, 'staff', 'staff');   // not admin: no run
        WorkspaceMembershipRoleChanged::dispatch(41, $workspace->id, $member->id, $owner->user_id, 'staff', 'admin');
        WorkspaceMembershipDeactivated::dispatch(41, $workspace->id, $member->id, $owner->user_id);
        WorkspaceMembershipReactivated::dispatch(41, $workspace->id, $member->id, $owner->user_id);

        $runs = PlatformAutomationRun::query()->orderBy('id')->get();
        $this->assertSame(['membership.role_changed', 'membership.deactivated', 'membership.reactivated'], $runs->pluck('trigger_type')->all());
        foreach ($runs as $run) {
            $this->assertSame('user', $run->target_type->value);
            $this->assertSame($member->id, (int) $run->user_id);
            $this->assertSame($workspace->id, (int) $run->workspace_id);
        }

        $this->advance($runs->first());
        Mail::assertSent(PlatformAutomationMail::class, fn ($m) => $m->hasTo($owner->user->email) && str_contains($m->mailBody, 'Team WS'));
    }

    public function test_business_status_writes_raise_one_event_and_start_suspended_and_reactivated_runs(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Status Biz', 'Status WS');
        $this->enabled('business.suspended', $this->note());
        $this->enabled('business.reactivated', $this->note());
        $owner = $this->actingAsPlatformOwner();
        $actions = app(PlatformOwnerAccountActions::class);

        $actions->changeBusinessStatus($business->id, BusinessStatus::Inactive, $owner->id, 'Chargeback');
        $actions->changeBusinessStatus($business->id, BusinessStatus::Inactive, $owner->id, 'Chargeback again');   // no change: no event
        $this->assertSame(['business.suspended'], PlatformAutomationRun::query()->pluck('trigger_type')->all());

        \Illuminate\Support\Carbon::setTestNow(now()->addSecond());
        $actions->changeBusinessStatus($business->id, BusinessStatus::Active, $owner->id, null);
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertSame(['business.suspended', 'business.reactivated'], PlatformAutomationRun::query()->orderBy('id')->pluck('trigger_type')->all());
    }

    public function test_enabling_and_disabling_an_account_through_the_customer_repository_starts_account_runs(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Acct Biz', 'Acct WS');
        $this->enabled('user.account_disabled', $this->note());
        $this->enabled('user.account_enabled', $this->note());
        $repository = app(\App\Repositories\Contracts\CustomerRepository::class);
        $uid = $customer->user->uid ?? $customer->uid;

        $repository->batchDisable([$uid]);
        $this->assertSame(['user.account_disabled'], PlatformAutomationRun::query()->pluck('trigger_type')->all());

        \Illuminate\Support\Carbon::setTestNow(now()->addSecond());
        $repository->batchEnable([$uid]);
        \Illuminate\Support\Carbon::setTestNow();
        $this->assertSame(['user.account_disabled', 'user.account_enabled'], PlatformAutomationRun::query()->orderBy('id')->pluck('trigger_type')->all());
        $this->assertSame($customer->user_id, (int) PlatformAutomationRun::query()->first()->user_id);
    }

    public function test_an_invitation_raises_created_and_an_expired_one_is_found_once(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Biz', 'Agency WS');
        $this->enabled('invitation.created', $this->note());
        $this->enabled('invitation.expired', $this->note());

        \App\Events\Workspace\ClientInvitationCreated::dispatch(1, $workspace->id);
        $this->assertSame(['invitation.created'], PlatformAutomationRun::query()->pluck('trigger_type')->all());

        DB::table('client_workspace_invitations')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'agency_workspace_id' => $workspace->id, 'invited_by_user_id' => $owner->user_id, 'email' => 'late@example.test',
            'token_hash' => 'x', 'status' => 'pending', 'expires_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('client_workspace_invitations')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'agency_workspace_id' => $workspace->id, 'invited_by_user_id' => $owner->user_id, 'email' => 'fresh@example.test',
            'token_hash' => 'y', 'status' => 'pending', 'expires_at' => now()->addDays(3), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $sweep = app(PlatformScheduledTriggers::class);
        $this->assertSame(1, $sweep->sweep()['invitation.expired'], 'only the lapsed invitation');
        $this->assertSame(0, $sweep->sweep()['invitation.expired'], 'and only once');
    }

    public function test_the_first_lead_fires_for_the_first_opportunity_only(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Lead Biz', 'Lead WS');
        $this->enabled('product.first_lead', $this->note());
        $pipeline = app(\App\Library\Crm\CrmPipelineService::class);
        $pipeline->setUpStandardPipeline($business);
        $pipelineRow = \App\Models\CrmPipeline::query()->where('business_id', $business->id)->firstOrFail();
        $service = app(\App\Library\Crm\CrmOpportunityService::class);
        $contact = fn (string $phone) => \App\Models\Contacts::create([
            'uid' => uniqid(), 'business_id' => $business->id, 'customer_id' => $customer->user_id, 'phone' => $phone, 'status' => 'subscribe',
        ]);

        $service->create($business, $pipelineRow, $contact('15550000001'), 'First deal');
        $this->assertSame(1, PlatformAutomationRun::query()->count());
        $this->assertSame($business->id, (int) PlatformAutomationRun::query()->sole()->business_id);

        $service->create($business, $pipelineRow, $contact('15550000002'), 'Second deal');
        $this->assertSame(1, PlatformAutomationRun::query()->count(), 'a second opportunity is not a first lead');
    }

    public function test_an_ads_connection_going_bad_starts_a_reconnection_run_for_that_business(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Ads Biz', 'Ads WS');
        $this->enabled('provider.reconnection_required', $this->note());

        ProviderReconnectionRequired::dispatch($business->id, 'meta_ads', 'expired');

        $run = PlatformAutomationRun::query()->sole();
        $this->assertSame('provider', $run->target_type->value);
        $this->assertSame($business->id, (int) $run->business_id);
    }

    // -- honesty ---------------------------------------------------------------------------

    public function test_the_triggers_with_no_canonical_source_stay_unavailable_with_their_reasons(): void
    {
        $unavailable = PlatformAutomationCatalog::unavailableTriggers();

        foreach (['Usage warning threshold', 'First Website generated', 'Password reset requested', 'Subscription cancelled'] as $label) {
            $this->assertArrayHasKey($label, $unavailable);
            $this->assertNotSame('', trim($unavailable[$label]));
        }

        // ...and none of them is also offered as a live trigger.
        $labels = array_column(PlatformAutomationCatalog::triggers(), 'label');
        foreach (['Usage warning threshold', 'First Website generated'] as $label) {
            $this->assertNotContains($label, $labels);
        }
        $this->assertArrayNotHasKey('Enable / disable a feature for an account', PlatformAutomationCatalog::unavailableActions());
    }
}
