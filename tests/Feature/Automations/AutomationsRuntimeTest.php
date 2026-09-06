<?php

namespace Tests\Feature\Automations;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationExecutionStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\Business\BusinessStatus;
use App\Jobs\AutomationJob;
use App\Jobs\SendAutomationMessage;
use App\Library\Automation\AutomationExecutionClaimService;
use App\Models\Automation;
use App\Models\AutomationExecution;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\CustomerBasedSendingServer;
use App\Models\SendingServer;
use App\Repositories\Eloquent\EloquentContactsRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\TestCase;

/**
 * B4 — trigger evaluation, claim idempotency, at-most-once provider
 * discipline, runtime revalidation, both actions, Agency separation.
 *
 * QUEUE_CONNECTION=sync: every dispatch runs inline, so a trigger job
 * drives the claim AND the action job to completion deterministically.
 * No sleeps anywhere.
 */
class AutomationsRuntimeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function freezeBusinessLocalTime(string $localDateTime, string $tz = 'America/New_York'): void
    {
        $now = CarbonImmutable::parse($localDateTime, $tz);

        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function runDateSweep(Automation $automation): void
    {
        $this->app->call([AutomationJob::forDateSweep($automation->id), 'handle']);
    }

    private function runContactCreated(Contacts $contact): void
    {
        $this->app->call([AutomationJob::forContactCreated($contact->id), 'handle']);
    }

    private function runAction(AutomationExecution $execution): void
    {
        $this->app->call([new SendAutomationMessage($execution->id), 'handle']);
    }

    private function pendingExecution(Automation $automation, Contacts $contact, string $key): AutomationExecution
    {
        return AutomationExecution::create([
            'business_id' => $automation->business_id,
            'automation_id' => $automation->id,
            'contact_id' => $contact->id,
            'trigger_type' => $automation->trigger_type->value,
            'idempotency_key' => $key,
            'status' => AutomationExecutionStatus::Pending->value,
            'action_claimed_at' => now(),
        ]);
    }

    private function storeContactThroughSeam(ContactGroups $group, string $phone): Contacts
    {
        $response = app(EloquentContactsRepository::class)->storeContact($group, [
            'phone' => $phone,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);

        $this->assertSame('success', $response->getData()->status, 'storeContact seam must succeed for the fixture phone.');

        return Contacts::where('group_id', $group->id)->where('phone', $phone)->firstOrFail();
    }

    // ---------------------------------------------------------------
    // CONTACT_CREATED
    // ---------------------------------------------------------------

    public function test_contact_created_through_store_seam_sends_exactly_once(): void
    {
        [, $business] = $this->entitledTenant();
        $channel = $this->sendableChannel($business);
        $automation = $this->sendMessageAutomation($business, $channel['server'], $channel['sender']);
        $group = $this->contactGroup($business);
        $this->mockSendCore(1);

        $contact = $this->storeContactThroughSeam($group, '12025551001');

        $execution = AutomationExecution::where('automation_id', $automation->id)->where('contact_id', $contact->id)->firstOrFail();

        $this->assertSame(AutomationExecutionStatus::Succeeded, $execution->status);
        $this->assertSame(AutomationExecutionClaimService::contactCreatedKey($automation->id, $contact->id), $execution->idempotency_key);
        $this->assertSame((int) $business->id, (int) $execution->business_id);
        $this->assertNotNull($execution->completed_at);
    }

    public function test_contact_created_through_request_seam_fires_only_for_newly_created(): void
    {
        [, $business] = $this->entitledTenant();
        $channel = $this->sendableChannel($business);
        $automation = $this->sendMessageAutomation($business, $channel['server'], $channel['sender']);
        $group = $this->contactGroup($business);
        $this->mockSendCore(1);

        $repository = app(EloquentContactsRepository::class);
        $input = ['PHONE' => '12025551002', 'FIRST_NAME' => 'Grace', 'LAST_NAME' => 'Hopper'];

        [, $created] = $repository->createContactFromRequest($group, $input);

        // (Validator::fails() re-runs the unique rule, so the persisted row —
        // not the validator — is the evidence the seam accepted the input.)
        $this->assertTrue(Contacts::where('group_id', $group->id)->where('phone', '12025551002')->exists());
        $this->assertSame(1, AutomationExecution::where('automation_id', $automation->id)->count());

        // A second pass for the same phone matches the EXISTING subscriber
        // (firstOrNew) — no trigger, no second send.
        $repository->createContactFromRequest($group, $input);

        $this->assertSame(1, AutomationExecution::where('automation_id', $automation->id)->count());
    }

    public function test_contact_created_dispatches_only_after_commit(): void
    {
        [, $business] = $this->entitledTenant();
        $channel = $this->sendableChannel($business);
        $automation = $this->sendMessageAutomation($business, $channel['server'], $channel['sender']);
        $group = $this->contactGroup($business);
        $this->mockSendCore(1);

        DB::transaction(function () use ($group, $automation): void {
            $this->storeContactThroughSeam($group, '12025551003');

            $this->assertSame(0, AutomationExecution::where('automation_id', $automation->id)->count(), 'Nothing may run before commit.');
        });

        $this->assertSame(1, AutomationExecution::where('automation_id', $automation->id)->count());
    }

    public function test_contact_created_never_fires_when_transaction_rolls_back(): void
    {
        [, $business] = $this->entitledTenant();
        $channel = $this->sendableChannel($business);
        $automation = $this->sendMessageAutomation($business, $channel['server'], $channel['sender']);
        $group = $this->contactGroup($business);
        $this->mockSendCore(0);

        try {
            DB::transaction(function () use ($group): void {
                $this->storeContactThroughSeam($group, '12025551004');

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, AutomationExecution::where('automation_id', $automation->id)->count());
        $this->assertSame(0, Contacts::where('phone', '12025551004')->count());
    }

    public function test_null_business_contact_never_triggers(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $channel = $this->sendableChannel($business);
        $this->sendMessageAutomation($business, $channel['server'], $channel['sender']);
        $this->mockSendCore(0);

        $legacyGroup = ContactGroups::create(['customer_id' => $customer->user_id, 'business_id' => null, 'name' => 'Legacy list', 'status' => true]);

        $contact = $this->storeContactThroughSeam($legacyGroup, '12025551005');

        $this->assertNull($contact->business_id);
        $this->assertSame(0, AutomationExecution::count());
    }

    public function test_contact_created_respects_group_filter_and_business_boundary(): void
    {
        [, $business] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $channel = $this->sendableChannel($business);
        $targetGroup = $this->contactGroup($business, 'Target');
        $otherGroup = $this->contactGroup($business, 'Other');
        $automation = $this->sendMessageAutomation($business, $channel['server'], $channel['sender'], [
            'trigger_config' => ['contact_group_id' => $targetGroup->id],
        ]);
        $this->mockSendCore(1);

        $this->storeContactThroughSeam($otherGroup, '12025551006');
        $this->assertSame(0, AutomationExecution::where('automation_id', $automation->id)->count());

        $foreignGroup = $this->contactGroup($otherBusiness, 'Foreign');
        $this->storeContactThroughSeam($foreignGroup, '12025551007');
        $this->assertSame(0, AutomationExecution::where('automation_id', $automation->id)->count());

        $this->storeContactThroughSeam($targetGroup, '12025551008');
        $this->assertSame(1, AutomationExecution::where('automation_id', $automation->id)->count());
    }

    // ---------------------------------------------------------------
    // CONTACT_DATE_REACHED
    // ---------------------------------------------------------------

    /**
     * @return array{0: \App\Models\Business, 1: Automation, 2: Contacts}
     */
    private function dueBirthdayScenario(array $automationOverrides = []): array
    {
        [, $business] = $this->entitledTenant();
        $channel = $this->sendableChannel($business);
        $group = $this->contactGroup($business);
        $field = $this->dateField($group);
        $automation = $this->dateReachedAutomation($business, $group, $field, $channel['server'], $channel['sender'], $automationOverrides);

        // offset "2 days": local 2026-03-10 + 2 days = 03-12 → matches a 03-12 birthday.
        $contact = $this->contact($business, $group, '12025552001', '1990-03-12', $field);
        $this->contact($business, $group, '12025552002', '1990-07-01', $field);

        $this->freezeBusinessLocalTime('2026-03-10 15:00:00');

        return [$business, $automation, $contact];
    }

    public function test_date_reached_sends_once_for_due_contact_with_year_scoped_key(): void
    {
        [$business, $automation, $contact] = $this->dueBirthdayScenario();
        $this->mockSendCore(1);

        $this->runDateSweep($automation);

        $executions = AutomationExecution::where('automation_id', $automation->id)->get();
        $this->assertCount(1, $executions);
        $this->assertSame((int) $contact->id, (int) $executions[0]->contact_id);
        $this->assertSame(AutomationExecutionClaimService::dateReachedKey($automation->id, $contact->id, 2026), $executions[0]->idempotency_key);
        $this->assertSame(AutomationExecutionStatus::Succeeded, $executions[0]->status);
        $this->assertSame((int) $business->id, (int) $executions[0]->business_id);
    }

    public function test_date_reached_is_idempotent_across_repeated_sweeps(): void
    {
        [, $automation] = $this->dueBirthdayScenario();
        $this->mockSendCore(1);

        $this->runDateSweep($automation);
        $this->runDateSweep($automation);
        $this->runDateSweep($automation);

        $this->assertSame(1, AutomationExecution::where('automation_id', $automation->id)->count());
    }

    public function test_date_reached_waits_for_send_at_in_business_local_time(): void
    {
        [, $automation] = $this->dueBirthdayScenario();
        $this->mockSendCore(0);

        $this->freezeBusinessLocalTime('2026-03-10 08:59:00');
        $this->runDateSweep($automation);

        $this->assertSame(0, AutomationExecution::count());
    }

    public function test_date_reached_does_not_fire_for_non_matching_offset(): void
    {
        [, $automation] = $this->dueBirthdayScenario();
        $automation->update(['trigger_config' => array_merge($automation->trigger_config, ['offset' => '1 day'])]);
        $this->mockSendCore(0);

        $this->runDateSweep($automation);

        $this->assertSame(0, AutomationExecution::count());
    }

    public function test_existing_execution_in_any_status_blocks_second_action(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $this->mockSendCore(0);

        $key = AutomationExecutionClaimService::dateReachedKey($automation->id, $contact->id, 2026);

        foreach ([AutomationExecutionStatus::Failed, AutomationExecutionStatus::Skipped, AutomationExecutionStatus::Succeeded, AutomationExecutionStatus::Pending] as $status) {
            AutomationExecution::where('idempotency_key', $key)->delete();
            $this->pendingExecution($automation, $contact, $key)->update(['status' => $status->value]);

            $this->runDateSweep($automation);

            $this->assertSame(1, AutomationExecution::where('idempotency_key', $key)->count(), "Status {$status->value} must block a second claim.");
            $this->assertSame($status, AutomationExecution::where('idempotency_key', $key)->first()->status, 'The existing row must remain untouched.');
        }
    }

    public function test_idempotency_key_is_unique_at_the_database(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $key = AutomationExecutionClaimService::dateReachedKey($automation->id, $contact->id, 2026);

        $this->pendingExecution($automation, $contact, $key);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->pendingExecution($automation, $contact, $key);
    }

    public function test_claim_service_returns_null_instead_of_a_second_row(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $key = AutomationExecutionClaimService::dateReachedKey($automation->id, $contact->id, 2026);
        $claims = app(AutomationExecutionClaimService::class);

        $first = $claims->claim($automation->id, $contact, AutomationTriggerType::ContactDateReached, $key);
        $second = $claims->claim($automation->id, $contact, AutomationTriggerType::ContactDateReached, $key);

        $this->assertInstanceOf(AutomationExecution::class, $first);
        $this->assertNull($second);
        $this->assertSame(1, AutomationExecution::where('idempotency_key', $key)->count());
    }

    public function test_claim_refuses_contact_from_another_business(): void
    {
        [, $automation] = $this->dueBirthdayScenario();
        [, $otherBusiness] = $this->entitledTenant();
        $foreign = $this->contact($otherBusiness, $this->contactGroup($otherBusiness), '12025553001');

        $result = app(AutomationExecutionClaimService::class)->claim(
            $automation->id,
            $foreign,
            AutomationTriggerType::ContactDateReached,
            AutomationExecutionClaimService::dateReachedKey($automation->id, $foreign->id, 2026)
        );

        $this->assertNull($result);
        $this->assertSame(0, AutomationExecution::count());
    }

    public function test_automation_run_command_only_sweeps_business_scoped_active_date_automations(): void
    {
        [$business, $automation] = $this->dueBirthdayScenario();
        $this->mockSendCore(1);

        Automation::create([
            'user_id' => $business->customer_id,
            'business_id' => null,
            'name' => 'Legacy NULL-business',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => AutomationTriggerType::ContactDateReached->value,
            'trigger_config' => $automation->trigger_config,
            'action_type' => AutomationActionType::SendMessage->value,
            'action_config' => $automation->action_config,
        ]);

        $this->artisan('automation:run')->assertSuccessful();

        $this->assertSame(1, AutomationExecution::count());
        $this->assertSame((int) $automation->id, (int) AutomationExecution::first()->automation_id);
    }

    // ---------------------------------------------------------------
    // Pause / disable / revalidation before the provider call
    // ---------------------------------------------------------------

    public function test_disabled_before_claim_never_executes(): void
    {
        [, $automation] = $this->dueBirthdayScenario();
        $automation->update(['status' => Automation::STATUS_INACTIVE]);
        $this->mockSendCore(0);

        $this->runDateSweep($automation);

        $this->assertSame(0, AutomationExecution::count());
    }

    public function test_disabled_after_claim_is_skipped_before_provider(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $execution = $this->pendingExecution($automation, $contact, 'k:disabled');
        $automation->update(['status' => Automation::STATUS_INACTIVE]);
        $this->mockSendCore(0);

        $this->runAction($execution);

        $this->assertSame(AutomationExecutionStatus::Skipped, $execution->fresh()->status);
        $this->assertNotNull($execution->fresh()->safe_error_summary);
    }

    public function test_business_deactivated_after_claim_is_skipped_before_provider(): void
    {
        [$business, $automation, $contact] = $this->dueBirthdayScenario();
        $execution = $this->pendingExecution($automation, $contact, 'k:biz');
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Inactive->value]);
        $this->mockSendCore(0);

        $this->runAction($execution);

        $this->assertSame(AutomationExecutionStatus::Skipped, $execution->fresh()->status);
    }

    public function test_workspace_deactivated_after_claim_is_skipped_before_provider(): void
    {
        [$business, $automation, $contact] = $this->dueBirthdayScenario();
        $execution = $this->pendingExecution($automation, $contact, 'k:ws');
        DB::table('workspaces')->where('id', $business->workspace_id)->update(['is_active' => false]);
        $this->mockSendCore(0);

        $this->runAction($execution);

        $this->assertSame(AutomationExecutionStatus::Skipped, $execution->fresh()->status);
    }

    /**
     * "No attempt was made" outcomes are recorded as `skipped` with a safe
     * reason; the provider is never reached and the row is terminal.
     */
    private function assertSkippedWithoutSend(AutomationExecution $execution, string $reason): void
    {
        $fresh = $execution->fresh();

        $this->assertSame(AutomationExecutionStatus::Skipped, $fresh->status);
        $this->assertStringContainsString($reason, (string) $fresh->safe_error_summary);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_channel_assignment_disabled_before_provider_skips_without_send(): void
    {
        [$business, $automation, $contact] = $this->dueBirthdayScenario();
        $execution = $this->pendingExecution($automation, $contact, 'k:chan');
        CustomerBasedSendingServer::where('business_id', $business->id)->update(['status' => 0]);
        $this->mockSendCore(0);

        $this->runAction($execution);

        $this->assertSkippedWithoutSend($execution, 'channel_unavailable');
    }

    public function test_sending_server_disabled_before_provider_skips_without_send(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $execution = $this->pendingExecution($automation, $contact, 'k:server');
        SendingServer::where('id', $automation->action_config['sending_server'])->update(['status' => false]);
        $this->mockSendCore(0);

        $this->runAction($execution);

        $this->assertSkippedWithoutSend($execution, 'channel_unavailable');
    }

    public function test_foreign_channel_in_action_config_is_rejected_at_execution(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        [, $otherBusiness] = $this->entitledTenant();
        $foreign = $this->sendableChannel($otherBusiness);
        $automation->update(['action_config' => array_merge($automation->action_config, ['sending_server' => $foreign['server']->id])]);
        $execution = $this->pendingExecution($automation, $contact, 'k:foreignchan');
        $this->mockSendCore(0);

        $this->runAction($execution);

        $this->assertSkippedWithoutSend($execution, 'channel_unavailable');
    }

    public function test_foreign_sender_in_action_config_is_rejected_by_the_send_core(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $automation->update(['action_config' => array_merge($automation->action_config, ['sender_id' => 'SOMEONEELSE'])]);
        $execution = $this->pendingExecution($automation, $contact, 'k:foreignsender');

        // The REAL B1 validation seam (not a double): the sender must be
        // owned by THIS Business, so a foreign sender is rejected before
        // quickSend() is ever reached.
        $mock = \Mockery::mock(\App\Repositories\Contracts\CampaignRepository::class)->makePartial();
        $real = app(\App\Repositories\Eloquent\EloquentCampaignRepository::class);
        $mock->shouldReceive('checkQuickSendValidation')->once()->andReturnUsing(fn (array $input) => $real->checkQuickSendValidation($input));
        $mock->shouldReceive('quickSend')->never();
        $this->app->instance(\App\Repositories\Contracts\CampaignRepository::class, $mock);

        $this->runAction($execution);

        $this->assertSkippedWithoutSend($execution, 'sender_rejected');
    }

    public function test_contact_moved_to_another_business_after_claim_is_skipped(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        [, $otherBusiness] = $this->entitledTenant();
        $execution = $this->pendingExecution($automation, $contact, 'k:moved');
        DB::table('contacts')->where('id', $contact->id)->update(['business_id' => $otherBusiness->id]);
        $this->mockSendCore(0);

        $this->runAction($execution);

        $this->assertSame(AutomationExecutionStatus::Skipped, $execution->fresh()->status);
    }

    public function test_unsubscribed_contact_is_not_messaged(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $execution = $this->pendingExecution($automation, $contact, 'k:unsub');
        DB::table('contacts')->where('id', $contact->id)->update(['status' => 'unsubscribe']);
        $this->mockSendCore(0);

        $this->runAction($execution);

        $this->assertNotSame(AutomationExecutionStatus::Succeeded, $execution->fresh()->status);
        $this->assertNotSame(AutomationExecutionStatus::Pending, $execution->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Provider outcome discipline
    // ---------------------------------------------------------------

    public function test_provider_failure_is_recorded_and_never_retried(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $this->mockSendCore(1, false);

        $this->runDateSweep($automation);
        $this->runDateSweep($automation);

        $execution = AutomationExecution::where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame(AutomationExecutionStatus::Failed, $execution->status);
        $this->assertNotNull($execution->safe_error_summary);
        $this->assertSame(1, AutomationExecution::count());
    }

    // ---------------------------------------------------------------
    // Execution-start claim (§5.4): the SAME row acts at most once
    // ---------------------------------------------------------------

    public function test_execution_already_started_never_calls_provider(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $execution = $this->pendingExecution($automation, $contact, 'k:started');
        $startedAt = now()->subMinute()->startOfSecond();
        $execution->update(['started_at' => $startedAt]);
        $this->mockSendCore(0);

        $this->runAction($execution);
        $this->runAction($execution);

        $fresh = $execution->fresh();
        $this->assertSame(AutomationExecutionStatus::Pending, $fresh->status, 'A started-but-unfinished row stays honestly pending.');
        $this->assertTrue($startedAt->equalTo($fresh->started_at), 'started_at is never reset.');
        $this->assertNull($fresh->completed_at);
    }

    public function test_execution_already_started_never_updates_contact_field(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business);
        $field = $this->textField($group);
        $automation = Automation::create([
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => 'Tag',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'trigger_config' => ['contact_group_id' => $group->id],
            'action_type' => AutomationActionType::UpdateContactField->value,
            'action_config' => ['field_id' => $field->id, 'value' => 'lead'],
        ]);
        $contact = $this->contact($business, $group, '12025556001');
        $execution = $this->pendingExecution($automation, $contact, 'k:started-field');
        $execution->update(['started_at' => now()]);

        $this->runAction($execution);

        $this->assertSame(AutomationExecutionStatus::Pending, $execution->fresh()->status);
        $this->assertSame(0, ContactsCustomField::where('contact_id', $contact->id)->where('field_id', $field->id)->count());
    }

    /**
     * Deterministic duplicate delivery: while worker A is inside the
     * provider call (row still Pending), worker B processes the SAME
     * executionId re-entrantly. B must lose the execution-start claim and
     * produce no side effect; exactly one provider call happens.
     */
    public function test_duplicate_processing_of_the_same_execution_yields_exactly_one_side_effect(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $execution = $this->pendingExecution($automation, $contact, 'k:dup');
        $nestedSawStarted = null;

        $mock = \Mockery::mock(\App\Repositories\Contracts\CampaignRepository::class);
        $mock->shouldReceive('checkQuickSendValidation')->andReturnUsing(fn (array $input) => response()->json([
            'status' => 'success',
            'sender_id' => $input['sender_id'] ?? null,
            'sms_type' => 'plain',
            'user_id' => $input['user_id'] ?? null,
        ]));
        $mock->shouldReceive('quickSend')->once()->andReturnUsing(function () use ($execution, &$nestedSawStarted) {
            // Worker B, mid-flight of worker A.
            $nestedSawStarted = $execution->fresh()->started_at !== null;
            $this->runAction($execution);

            return response()->json(['status' => 'success', 'message' => 'sent']);
        });
        $this->app->instance(\App\Repositories\Contracts\CampaignRepository::class, $mock);

        $this->runAction($execution);

        $fresh = $execution->fresh();
        $this->assertTrue($nestedSawStarted, 'The start claim must be durable before the provider call.');
        $this->assertSame(AutomationExecutionStatus::Succeeded, $fresh->status);
        $this->assertNotNull($fresh->started_at);
        $this->assertSame(1, AutomationExecution::count());
    }

    public function test_start_claim_is_granted_exactly_once(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $execution = $this->pendingExecution($automation, $contact, 'k:once');
        $claims = app(AutomationExecutionClaimService::class);

        $first = $claims->claimStart($execution->id);
        $second = $claims->claimStart($execution->id);

        $this->assertInstanceOf(AutomationExecution::class, $first);
        $this->assertNull($second);
        $this->assertNotNull($execution->fresh()->started_at);

        $execution->update(['status' => AutomationExecutionStatus::Failed->value]);
        $this->assertNull($claims->claimStart($execution->id), 'A terminal row can never be started.');
    }

    // ---------------------------------------------------------------
    // Stale-definition guard at claim time (§5.2)
    // ---------------------------------------------------------------

    public function test_claim_refuses_a_stale_trigger_type(): void
    {
        [, $business] = $this->entitledTenant();
        $channel = $this->sendableChannel($business);
        $group = $this->contactGroup($business);
        $field = $this->dateField($group);
        $automation = $this->sendMessageAutomation($business, $channel['server'], $channel['sender']);
        $contact = $this->contact($business, $group, '12025557001');
        $this->mockSendCore(0);

        // The evaluator observed CONTACT_CREATED; the definition is edited
        // to the other trigger before the claim runs.
        $automation->update([
            'trigger_type' => AutomationTriggerType::ContactDateReached->value,
            'trigger_config' => ['contact_group_id' => $group->id, 'date_field_id' => $field->id, 'offset' => '0 day', 'send_at' => '09:00'],
        ]);

        $result = app(AutomationExecutionClaimService::class)->claim(
            $automation->id,
            $contact,
            AutomationTriggerType::ContactCreated,
            AutomationExecutionClaimService::contactCreatedKey($automation->id, $contact->id)
        );

        $this->assertNull($result);
        $this->assertSame(0, AutomationExecution::count());
    }

    public function test_claim_refuses_a_contact_outside_the_current_audience_group(): void
    {
        [, $business] = $this->entitledTenant();
        $channel = $this->sendableChannel($business);
        $groupA = $this->contactGroup($business, 'A');
        $groupB = $this->contactGroup($business, 'B');
        $automation = $this->sendMessageAutomation($business, $channel['server'], $channel['sender'], [
            'trigger_config' => ['contact_group_id' => null],
        ]);
        $contact = $this->contact($business, $groupA, '12025557002');
        $this->mockSendCore(0);

        // Audience narrowed to group B after the candidate was prepared.
        $automation->update(['trigger_config' => ['contact_group_id' => $groupB->id]]);

        $result = app(AutomationExecutionClaimService::class)->claim(
            $automation->id,
            $contact,
            AutomationTriggerType::ContactCreated,
            AutomationExecutionClaimService::contactCreatedKey($automation->id, $contact->id)
        );

        $this->assertNull($result);
        $this->assertSame(0, AutomationExecution::count());
    }

    public function test_action_job_never_reruns_a_terminal_execution(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $execution = $this->pendingExecution($automation, $contact, 'k:terminal');
        $execution->update(['status' => AutomationExecutionStatus::Failed->value]);
        $this->mockSendCore(0);

        $this->runAction($execution);
        $this->runAction($execution);

        $this->assertSame(AutomationExecutionStatus::Failed, $execution->fresh()->status);
    }

    public function test_history_summaries_never_contain_message_body_or_phone(): void
    {
        [, $automation, $contact] = $this->dueBirthdayScenario();
        $this->mockSendCore(1);

        $this->runDateSweep($automation);

        $execution = AutomationExecution::where('contact_id', $contact->id)->firstOrFail();
        $blob = (string) $execution->safe_result_summary . (string) $execution->safe_error_summary;

        $this->assertStringNotContainsString('Happy birthday', $blob);
        $this->assertStringNotContainsString($contact->phone, $blob);
        $this->assertFalse(Schema::hasColumn('automation_executions', 'provider_response'));
        $this->assertFalse(Schema::hasColumn('automation_executions', 'message'));
    }

    public function test_send_message_does_not_bill_through_a_legacy_unit_path(): void
    {
        [$business, $automation] = $this->dueBirthdayScenario();
        $this->mockSendCore(1);
        $unitsBefore = DB::table('users')->where('id', $business->customer_id)->value('sms_unit');

        $this->runDateSweep($automation);

        $this->assertSame(1, AutomationExecution::count());
        $this->assertEquals($unitsBefore, DB::table('users')->where('id', $business->customer_id)->value('sms_unit'));

        // Comments stripped: the assertion is about executable code paths.
        $source = php_strip_whitespace(app_path('Library/Automation/Actions/SendMessageAction.php'));
        $this->assertStringNotContainsString('sms_unit', $source);
        $this->assertStringNotContainsString('countSMSUnit', $source);
        $this->assertStringNotContainsString('AgencyProspect', $source);
        $this->assertStringNotContainsString('Prospecting', $source);
    }

    public function test_send_message_never_calls_provider_inside_a_transaction(): void
    {
        [, $automation] = $this->dueBirthdayScenario();

        // RefreshDatabase holds the outer test transaction; any application
        // transaction would raise the level above this baseline.
        $baseline = DB::transactionLevel();
        $observed = null;

        $mock = \Mockery::mock(\App\Repositories\Contracts\CampaignRepository::class);
        $mock->shouldReceive('checkQuickSendValidation')->once()->andReturnUsing(fn (array $input) => response()->json([
            'status' => 'success',
            'sender_id' => $input['sender_id'] ?? null,
            'sms_type' => 'plain',
            'user_id' => $input['user_id'] ?? null,
        ]));
        $mock->shouldReceive('quickSend')->once()->andReturnUsing(function () use (&$observed) {
            $observed = DB::transactionLevel();

            return response()->json(['status' => 'success', 'message' => 'sent']);
        });
        $this->app->instance(\App\Repositories\Contracts\CampaignRepository::class, $mock);

        $this->runDateSweep($automation);

        $this->assertSame($baseline, $observed, 'quickSend must run outside any application transaction.');
        $this->assertSame(AutomationExecutionStatus::Succeeded, AutomationExecution::firstOrFail()->status);
    }

    // ---------------------------------------------------------------
    // UPDATE_CONTACT_FIELD
    // ---------------------------------------------------------------

    public function test_update_contact_field_writes_only_business_scoped_field(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business);
        $field = $this->textField($group);
        $automation = Automation::create([
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => 'Tag new',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'trigger_config' => ['contact_group_id' => $group->id],
            'action_type' => AutomationActionType::UpdateContactField->value,
            'action_config' => ['field_id' => $field->id, 'value' => 'lead'],
        ]);
        $this->mockSendCore(0);

        $contact = $this->storeContactThroughSeam($group, '12025554001');

        $execution = AutomationExecution::where('automation_id', $automation->id)->firstOrFail();
        $this->assertSame(AutomationExecutionStatus::Succeeded, $execution->status);
        $this->assertSame('lead', ContactsCustomField::where('contact_id', $contact->id)->where('field_id', $field->id)->value('value'));
    }

    public function test_update_contact_field_refuses_foreign_field(): void
    {
        [, $business] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $group = $this->contactGroup($business);
        $foreignField = $this->textField($this->contactGroup($otherBusiness));
        $automation = Automation::create([
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => 'Tampered',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'trigger_config' => ['contact_group_id' => $group->id],
            'action_type' => AutomationActionType::UpdateContactField->value,
            'action_config' => ['field_id' => $foreignField->id, 'value' => 'pwned'],
        ]);
        $this->mockSendCore(0);

        $contact = $this->storeContactThroughSeam($group, '12025554002');

        $execution = AutomationExecution::where('automation_id', $automation->id)->firstOrFail();
        $this->assertSame(AutomationExecutionStatus::Skipped, $execution->status);
        $this->assertStringContainsString('field_not_in_contact_business', (string) $execution->safe_error_summary);
        $this->assertSame(0, ContactsCustomField::where('contact_id', $contact->id)->where('field_id', $foreignField->id)->count());
    }

    /**
     * Defense in depth (§7.B): even a definition that bypassed the
     * definition-time group rule cannot write a field of another group of
     * the same Business at runtime.
     */
    public function test_update_contact_field_refuses_same_business_field_from_another_group_at_runtime(): void
    {
        [, $business] = $this->entitledTenant();
        $audience = $this->contactGroup($business, 'Audience');
        $otherField = $this->textField($this->contactGroup($business, 'Other'), 'OTHER_NOTE');
        $automation = Automation::create([
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => 'Cross-group',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'trigger_config' => ['contact_group_id' => $audience->id],
            'action_type' => AutomationActionType::UpdateContactField->value,
            'action_config' => ['field_id' => $otherField->id, 'value' => 'x'],
        ]);
        $this->mockSendCore(0);

        $contact = $this->storeContactThroughSeam($audience, '12025554004');

        $execution = AutomationExecution::where('automation_id', $automation->id)->firstOrFail();
        $this->assertSame(AutomationExecutionStatus::Skipped, $execution->status);
        $this->assertStringContainsString('field_not_in_contact_business', (string) $execution->safe_error_summary);
        $this->assertSame(0, ContactsCustomField::where('contact_id', $contact->id)->where('field_id', $otherField->id)->count());
    }

    public function test_update_contact_field_refuses_phone_field(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business);
        $phoneField = $group->getFields()->firstWhere('is_phone', true);
        $automation = Automation::create([
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => 'Phone rewrite',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'trigger_config' => ['contact_group_id' => $group->id],
            'action_type' => AutomationActionType::UpdateContactField->value,
            'action_config' => ['field_id' => $phoneField->id, 'value' => '19999999999'],
        ]);
        $this->mockSendCore(0);

        $contact = $this->storeContactThroughSeam($group, '12025554003');

        $execution = AutomationExecution::where('automation_id', $automation->id)->firstOrFail();
        $this->assertSame(AutomationExecutionStatus::Skipped, $execution->status);
        $this->assertStringContainsString('phone_field_not_writable', (string) $execution->safe_error_summary);
        $this->assertSame('12025554003', (string) $contact->fresh()->phone);
    }

    // ---------------------------------------------------------------
    // Schema (§4.1): B4/B5 index coordination
    // ---------------------------------------------------------------

    public function test_executions_table_has_the_business_created_at_composite_index(): void
    {
        $indexes = collect(Schema::getIndexes('automation_executions'));

        $composite = $indexes->firstWhere('name', 'automation_executions_business_id_created_at_index');

        $this->assertNotNull($composite, 'Missing automation_executions_business_id_created_at_index. Present: ' . $indexes->pluck('name')->implode(', '));
        $this->assertSame(['business_id', 'created_at'], array_values($composite['columns']));
        $this->assertFalse($composite['unique']);

        // The contracted set, and nothing analytics-oriented beyond it.
        $this->assertTrue($indexes->contains(fn (array $index) => $index['unique'] && array_values($index['columns']) === ['idempotency_key']));
        $this->assertTrue($indexes->contains(fn (array $index) => array_values($index['columns']) === ['automation_id', 'created_at']));
    }

    // ---------------------------------------------------------------
    // Import policy (§6.B): only the two interactive seams dispatch
    // ---------------------------------------------------------------

    public function test_import_path_has_no_automation_dispatch_and_contacts_has_no_model_hook(): void
    {
        $import = php_strip_whitespace(app_path('Models/ContactGroups.php'));
        $contacts = php_strip_whitespace(app_path('Models/Contacts.php'));

        $this->assertStringNotContainsString('AutomationJob', $import);
        $this->assertStringNotContainsString('forContactCreated', $import);
        $this->assertStringNotContainsString('AutomationJob', $contacts);
        $this->assertStringNotContainsString('::created(', $contacts);
        $this->assertStringNotContainsString('::saved(', $contacts);

        // Exactly the two contracted interactive seams dispatch the trigger
        // (AutomationJob itself only DEFINES the factory).
        $callSites = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && str_contains(php_strip_whitespace($file->getPathname()), 'AutomationJob::forContactCreated(')) {
                $callSites[] = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
            }
        }

        sort($callSites);
        $this->assertSame(['Repositories/Eloquent/EloquentContactsRepository.php'], $callSites, 'Unexpected CONTACT_CREATED dispatch sites: ' . json_encode($callSites));
        $this->assertSame(2, substr_count(php_strip_whitespace(app_path('Repositories/Eloquent/EloquentContactsRepository.php')), 'AutomationJob::forContactCreated('));
    }

    // ---------------------------------------------------------------
    // Separation from Agency Prospecting
    // ---------------------------------------------------------------

    public function test_automations_never_touch_agency_prospecting_tables(): void
    {
        [, $automation] = $this->dueBirthdayScenario();
        $this->mockSendCore(1);

        $agencyTables = array_values(array_filter(
            ['agency_prospects', 'agency_prospect_messages', 'agency_prospecting_runs', 'agency_prospect_conversations'],
            fn (string $table) => Schema::hasTable($table)
        ));

        $before = [];
        foreach ($agencyTables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $this->runDateSweep($automation);

        $this->assertSame(1, AutomationExecution::count());
        foreach ($agencyTables as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "{$table} must not change.");
        }

        foreach (array_merge(glob(app_path('Library/Automation/*.php')), glob(app_path('Library/Automation/Actions/*.php'))) as $file) {
            $this->assertStringNotContainsString('Agency', php_strip_whitespace($file), basename($file) . ' must not reference Agency Prospecting code.');
        }
    }
}
