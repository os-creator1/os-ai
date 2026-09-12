<?php

namespace Tests\Feature\Automations\Workflow\Actions;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Executors\SendSmsNodeExecutor;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Models\AutomationWorkflowNode;
use App\Models\Contacts;
use App\Models\SendingServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-B §10.1 — the Send SMS action.
 *
 * The two properties worth the most here are negative ones: that this executor
 * did not open a second road to a provider, and that it did not persist a sender
 * it could later send from by mistake. Both are proven mechanically — one by
 * watching the send core and the network, the other by reading what the
 * publisher actually stored.
 */
class SendSmsExecutorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsActionWorkflows;

    /** @return array{0: \App\Models\Business, 1: Contacts, 2: \App\Models\AutomationWorkflow} */
    private function sendableWorkflow(string $body = 'Hello there'): array
    {
        [, $business] = $this->entitledTenant();
        $this->sendableChannel($business);
        [$workflow] = $this->publishWorkflow($business, [$this->smsStep($body), $this->endStep()]);
        $contact = $this->contactFor($business);

        return [$business, $contact, $workflow];
    }

    private function runToCompletion(\App\Models\AutomationWorkflow $workflow, Contacts $contact): \App\Models\AutomationEnrollment
    {
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        return $enrollment->fresh();
    }

    /** 1. The canonical send path is used, once. */
    public function test_the_executor_sends_through_quick_send_exactly_once(): void
    {
        [$business, $contact, $workflow] = $this->sendableWorkflow('Welcome!');

        $core = $this->captureSendCore(1);

        $enrollment = $this->runToCompletion($workflow, $contact);

        $this->assertSame(1, $core->count(), 'quickSend() must be called exactly once.');
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);

        $payload = $core->lastPayload();
        $this->assertSame((int) $business->id, (int) $payload['business_id']);
        $this->assertSame((int) $business->customer_id, (int) $payload['user_id']);
        $this->assertSame('Welcome!', $payload['message']);

        // Every Reports/TrackingLog row the core writes inherits the Business
        // from the transient campaign, exactly as Outreach does.
        $this->assertSame((int) $business->id, (int) $core->campaignBusinessIds[0]);

        $stepRun = DB::table('automation_step_runs')->where('node_type', 'send_sms')->first();
        $this->assertSame(StepRunStatus::Succeeded->value, $stepRun->status);
    }

    /**
     * 2. No second provider road. The executor reaches the network through the
     * send core or not at all — so with the core doubled, nothing may leave.
     */
    public function test_no_direct_provider_path_is_introduced(): void
    {
        [, $contact, $workflow] = $this->sendableWorkflow();

        Http::preventStrayRequests();
        Http::fake();

        $this->captureSendCore(1);

        $this->runToCompletion($workflow, $contact);

        Http::assertNothingSent();
    }

    /**
     * 2b. And the executable source names no provider, adapter, HTTP client or
     * billing path.
     *
     * Comments are stripped before the scan: a docblock that says "no Telnyx
     * call happens here" is documentation, not a provider path, and a check that
     * cannot tell those apart would only teach people to stop writing the
     * docblock. What is scanned is the code the engine actually runs.
     */
    public function test_the_action_executors_reference_no_provider_directly(): void
    {
        $forbidden = ['Telnyx', 'Twilio', 'MessagingAdapter', 'Http::', 'curl_', 'UsageWalletManager', 'sms_unit'];

        foreach (['SendSms', 'UpdateContactField', 'InternalNotification'] as $name) {
            $code = $this->executableSource(base_path("app/Library/Automation/Workflow/Executors/{$name}NodeExecutor.php"));

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    "{$name}NodeExecutor must not reach a provider or a billing path of its own.",
                );
            }
        }
    }

    /** The file's PHP with every comment and docblock removed. */
    private function executableSource(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** 3. A managed Business sends with no BYO sending server at all. */
    public function test_a_managed_business_sends_without_a_byo_server(): void
    {
        [, $business] = $this->entitledTenant();
        $this->sendableChannel($business);

        // Managed identity, and NO legacy channel whatsoever.
        $this->giveManagedIdentity($business);
        $this->removeByoChannels($business);

        [$workflow] = $this->publishWorkflow($business, [$this->smsStep(), $this->endStep()]);
        $contact = $this->contactFor($business);

        $core = $this->captureSendCore(1);

        $enrollment = $this->runToCompletion($workflow, $contact);

        $this->assertSame(1, $core->count(), 'A managed Business must be able to send.');
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $this->assertArrayNotHasKey(
            'sending_server',
            $core->lastPayload(),
            'A managed send must not be handed a legacy gateway, or quickSend() takes its BYO branch.',
        );
    }

    /** 4. Without a managed identity, the Business's assigned BYO channel is used. */
    public function test_a_byo_business_sends_through_its_assigned_channel(): void
    {
        [$business, $contact, $workflow] = $this->sendableWorkflow();

        $expectedServerId = (int) DB::table('customer_based_sending_servers')
            ->where('business_id', $business->id)->value('sending_server');

        $core = $this->captureSendCore(1);

        $this->runToCompletion($workflow, $contact);

        $this->assertSame(
            $expectedServerId,
            (int) $core->lastPayload()['sending_server'],
            'A BYO send must carry the Business\'s own assigned channel.',
        );
    }

    /** 4b. A channel whose underlying server is switched off is not usable. */
    public function test_a_byo_channel_with_an_inactive_server_is_refused(): void
    {
        [$business, $contact, $workflow] = $this->sendableWorkflow();

        SendingServer::query()->update(['status' => false]);

        $core = $this->captureSendCore(0);

        $enrollment = $this->runToCompletion($workflow, $contact);

        $this->assertSame(0, $core->count());
        $this->assertSame(EnrollmentStatus::Failed, $enrollment->status);
        $this->assertSame('no_business_sending_path', $enrollment->exit_reason);
    }

    /**
     * 5. THE ONE THAT MATTERS MOST for staleness: publishing a send step stores
     * the body and nothing else. There is no channel id in the definition to go
     * stale, and none in the compiled graph either.
     */
    public function test_no_sender_or_channel_is_persisted_into_the_definition(): void
    {
        [, $business] = $this->entitledTenant();
        $this->sendableChannel($business);
        [$workflow, $version] = $this->publishWorkflow($business, [$this->smsStep('Body only'), $this->endStep()]);

        $node = AutomationWorkflowNode::query()
            ->where('version_id', $version->id)->where('node_type', 'send_sms')->firstOrFail();

        $this->assertSame(['body' => 'Body only'], $node->config);

        foreach (['sender_id', 'sending_server', 'channel_id', 'phone_number', 'from'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $node->config);
        }

        // And the draft definition the customer edits carries none either.
        $definition = json_encode($workflow->draftVersion()?->definition ?? []);
        $this->assertStringNotContainsString('sending_server', (string) $definition);
        $this->assertStringNotContainsString('sender_id', (string) $definition);
    }

    /**
     * 5b. Even a tampered definition cannot choose the sender: a hand-written
     * `sending_server` in the config is ignored, and the Business's own channel
     * is used instead.
     */
    public function test_a_sender_smuggled_into_the_config_is_ignored(): void
    {
        [, $business] = $this->entitledTenant();
        $this->sendableChannel($business);

        [, $otherBusiness] = $this->entitledTenant();
        $foreign = $this->sendableChannel($otherBusiness);

        [$workflow, $version] = $this->publishWorkflow($business, [$this->smsStep(), $this->endStep()]);

        // Post-publish tampering, straight into the compiled graph.
        DB::table('automation_workflow_nodes')
            ->where('version_id', $version->id)->where('node_type', 'send_sms')
            ->update(['config' => json_encode([
                'body' => 'Hello there',
                'sending_server' => $foreign['server']->id,
                'sender_id' => 'FOREIGNSENDER',
            ])]);

        $ownServerId = (int) DB::table('customer_based_sending_servers')
            ->where('business_id', $business->id)->value('sending_server');

        $contact = $this->contactFor($business);
        $core = $this->captureSendCore(1);

        $this->runToCompletion($workflow->fresh(), $contact);

        $payload = $core->lastPayload();
        $this->assertSame($ownServerId, (int) $payload['sending_server'], 'The smuggled channel must be ignored.');
        $this->assertNotSame('FOREIGNSENDER', $payload['sender_id'], 'The smuggled sender must be ignored.');
        $this->assertSame('AUTOSENDER', $payload['sender_id']);
    }

    /** 6. No usable sending path at all: fail closed, with no provider call. */
    public function test_a_business_with_no_sending_path_fails_closed(): void
    {
        [, $business] = $this->entitledTenant();
        $this->sendableChannel($business);
        $this->removeByoChannels($business);
        $this->removeOriginators($business);

        [$workflow] = $this->publishWorkflow($business, [$this->smsStep(), $this->endStep()]);
        $contact = $this->contactFor($business);

        $core = $this->captureSendCore(0);

        $enrollment = $this->runToCompletion($workflow, $contact);

        $this->assertSame(0, $core->count(), 'Nothing may be sent without a sending path.');
        $this->assertSame(EnrollmentStatus::Failed, $enrollment->status);
        $this->assertSame('no_business_sending_path', $enrollment->exit_reason);
    }

    /** 6b. An originator the Business does not own is never invented. */
    public function test_a_business_with_no_originator_fails_closed_even_when_managed(): void
    {
        [, $business] = $this->entitledTenant();
        $this->sendableChannel($business);
        $this->giveManagedIdentity($business);
        $this->removeOriginators($business);

        [$workflow] = $this->publishWorkflow($business, [$this->smsStep(), $this->endStep()]);
        $contact = $this->contactFor($business);

        $core = $this->captureSendCore(0);

        $this->assertSame(
            EnrollmentStatus::Failed,
            $this->runToCompletion($workflow, $contact)->status,
        );
        $this->assertSame(0, $core->count());
    }

    /** 7. Consent is re-checked at the action boundary, not at claim time. */
    public function test_an_unsubscribed_contact_is_never_texted(): void
    {
        [, $contact, $workflow] = $this->sendableWorkflow();

        $core = $this->captureSendCore(0);

        // The contact replies STOP after enrolling but before the step runs.
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        DB::table('contacts')->where('id', $contact->id)
            ->update(['status' => Contacts::STATUS_UNSUBSCRIBE]);

        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertSame(0, $core->count(), 'An unsubscribed contact must never be texted.');

        $stepRun = DB::table('automation_step_runs')->where('node_type', 'send_sms')->first();
        $this->assertSame(StepRunStatus::Skipped->value, $stepRun->status);
        $this->assertSame('contact_unsubscribed', $stepRun->safe_error_summary);
    }

    /** 7b. A failed final eligibility check prevents the send entirely. */
    public function test_a_contact_that_left_the_business_is_never_texted(): void
    {
        [, $contact, $workflow] = $this->sendableWorkflow();

        $core = $this->captureSendCore(0);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        DB::table('contacts')->where('id', $contact->id)->update(['business_id' => null]);

        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertSame(0, $core->count());
        $this->assertSame(EnrollmentStatus::Exited, $enrollment->fresh()->status);
        $this->assertSame('contact_not_in_business', $enrollment->fresh()->exit_reason);
    }

    /** 8. However many times the job is delivered, one message. */
    public function test_duplicate_advancement_cannot_duplicate_the_sms(): void
    {
        [, $contact, $workflow] = $this->sendableWorkflow();

        $core = $this->captureSendCore(1);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        $advancer = app(WorkflowAdvancer::class);

        for ($i = 0; $i < 6; $i++) {
            $advancer->advance($enrollment->fresh() ?? $enrollment);
        }

        $this->assertSame(1, $core->count(), 'Six deliveries of the same work, one message.');
        $this->assertSame(
            1,
            DB::table('automation_step_runs')->where('node_type', 'send_sms')->count(),
            'One step run per node, and no more.',
        );
    }

    /**
     * 9. The provider call opens no transaction of its own and is not wrapped in
     * one.
     *
     * Measured against the AMBIENT level rather than against zero, because
     * RefreshDatabase already holds a transaction open around every test: an
     * assertion of `=== 0` here would fail for a reason that has nothing to do
     * with the executor, and passing it would have meant disabling the very
     * isolation the rest of the suite relies on. Equality with the ambient level
     * is the real invariant — it fails the moment either this executor or the
     * advancer wraps the send in a transaction.
     */
    public function test_the_provider_call_happens_outside_a_transaction(): void
    {
        [, $contact, $workflow] = $this->sendableWorkflow();

        $core = $this->captureSendCore(1);
        $ambient = DB::transactionLevel();

        $this->runToCompletion($workflow, $contact);

        $this->assertSame(
            [$ambient],
            $core->transactionLevels,
            'quickSend() must never be called with an extra transaction open.',
        );
    }

    /** 10. A contact from another Business can never be the recipient. */
    public function test_a_cross_business_contact_is_never_used(): void
    {
        [, $business] = $this->entitledTenant();
        $this->sendableChannel($business);
        [$workflow] = $this->publishWorkflow($business, [$this->smsStep(), $this->endStep()]);

        [, $otherBusiness] = $this->entitledTenant();
        $foreignContact = $this->contactFor($otherBusiness, 'Theirs');

        $core = $this->captureSendCore(0);

        $this->assertNull(
            app(EnrollmentService::class)->enroll($workflow, $foreignContact, (string) $foreignContact->id),
            'Another Business\'s contact must never enter this workflow.',
        );
        $this->assertSame(0, $core->count());
    }

    /** A provider rejection is a durable failure, never an automatic resend. */
    public function test_a_rejected_send_fails_and_is_never_retried(): void
    {
        [, $contact, $workflow] = $this->sendableWorkflow();

        $core = $this->captureSendCore(1, succeed: false);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        $advancer = app(WorkflowAdvancer::class);

        $advancer->advance($enrollment);
        $advancer->advance($enrollment->fresh() ?? $enrollment);

        $this->assertSame(1, $core->count(), 'A failed send is never automatically repeated.');
        $this->assertSame(EnrollmentStatus::Failed, $enrollment->fresh()->status);
        $this->assertSame('send_failed', $enrollment->fresh()->exit_reason);
    }

    /** The executor is what the registry hands the advancer for this type. */
    public function test_the_registry_resolves_this_executor_for_send_sms(): void
    {
        $this->assertInstanceOf(
            SendSmsNodeExecutor::class,
            app(\App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry::class)
                ->for(\App\Enums\Automation\Workflow\WorkflowNodeType::SendSms),
        );
    }
}
