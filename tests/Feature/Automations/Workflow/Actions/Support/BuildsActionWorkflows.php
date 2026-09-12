<?php

namespace Tests\Feature\Automations\Workflow\Actions\Support;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use App\Models\CustomerBasedSendingServer;
use App\Models\Senderid;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;

/**
 * Support for the V2-B action tests.
 *
 * The workflow-building itself is NOT redefined here: these tests reuse the
 * runtime lane's BuildsWorkflows, so an action is proven against exactly the
 * graphs the real compiler and publisher emit, not against a shape invented for
 * the convenience of a test.
 */
trait BuildsActionWorkflows
{
    /**
     * The B1 send core, doubled so that quickSend() is observable.
     *
     * This is the same seam B4's own at-most-once tests use
     * (CreatesAutomationFixtures::mockSendCore), extended to record what it was
     * handed: the payload is how the sender-resolution rules are proven, and the
     * transaction level is how "no provider call inside a transaction" is proven
     * rather than asserted.
     */
    protected function captureSendCore(?int $expectedSends = null, bool $succeed = true): object
    {
        $captured = new class()
        {
            /** @var list<array<string, mixed>> */
            public array $payloads = [];

            /** @var list<int> */
            public array $transactionLevels = [];

            /** @var list<int|null> */
            public array $campaignBusinessIds = [];

            public function count(): int
            {
                return count($this->payloads);
            }

            public function lastPayload(): array
            {
                return $this->payloads[count($this->payloads) - 1] ?? [];
            }
        };

        $mock = Mockery::mock(CampaignRepository::class);

        $mock->shouldReceive('checkQuickSendValidation')
            ->andReturnUsing(fn (array $input) => response()->json([
                'status' => 'success',
                'sender_id' => $input['sender_id'] ?? null,
                'sms_type' => $input['sms_type'] ?? 'plain',
                'user_id' => $input['user_id'] ?? null,
            ]));

        $expectation = $mock->shouldReceive('quickSend');

        if ($expectedSends === 0) {
            $expectation->never();
        } elseif ($expectedSends !== null) {
            $expectation->times($expectedSends);
        }

        $expectation->andReturnUsing(function ($campaign, array $input) use ($captured, $succeed) {
            $captured->payloads[] = $input;
            $captured->transactionLevels[] = DB::transactionLevel();
            $captured->campaignBusinessIds[] = $campaign->business_id ?? null;

            return response()->json($succeed
                ? ['status' => 'success', 'message' => 'sent']
                : ['status' => 'error', 'message' => 'provider rejected']);
        });

        $this->app->instance(CampaignRepository::class, $mock);

        // The registry is a singleton and its executors are constructed with
        // whatever CampaignRepository was bound at the time. Forget it so the
        // executors are rebuilt around the double rather than the real core.
        $this->app->forgetInstance(NodeExecutorRegistry::class);

        return $captured;
    }

    /** Give a Business an active managed sending identity with a primary number. */
    protected function giveManagedIdentity(Business $business, ?string $number = null): BusinessMessagingIdentity
    {
        $identity = new BusinessMessagingIdentity([
            'uid' => (string) Str::uuid(),
            'business_id' => (int) $business->id,
            'provider' => MessagingProvider::Telnyx->value,
            'status' => BusinessMessagingIdentityStatus::Active->value,
            'messaging_profile_id' => 'mp_' . Str::random(14),
            'activated_at' => now(),
        ]);
        $identity->save();

        $primary = new BusinessMessagingNumber([
            'business_messaging_identity_id' => (int) $identity->id,
            'phone_number' => $number ?? sprintf('+1415555%04d', random_int(1000, 9999)),
            'status' => BusinessMessagingNumberStatus::Active->value,
            'is_primary' => true,
            'activated_at' => now(),
        ]);
        $primary->save();

        return $identity;
    }

    /** Remove every BYO channel assignment, leaving no legacy gateway at all. */
    protected function removeByoChannels(Business $business): void
    {
        CustomerBasedSendingServer::query()->where('business_id', $business->id)->delete();
    }

    /** Remove every originator this Business owns. */
    protected function removeOriginators(Business $business): void
    {
        Senderid::query()->where('business_id', $business->id)->delete();
    }

    /**
     * Publish a workflow whose trigger watches ONE specific contact group.
     *
     * WorkflowCompiler requires this for `update_contact_field`: a custom field
     * belongs to exactly one group, so a workflow whose field cannot match its
     * audience is refused at publish. BuildsWorkflows' group-less starter cannot
     * express that, so this is the one publishing helper the action lane adds.
     *
     * @param list<array<string, mixed>> $steps
     *
     * @return array{0: \App\Models\AutomationWorkflow, 1: \App\Models\AutomationWorkflowVersion}
     */
    protected function publishGroupScopedWorkflow(
        Business $business,
        \App\Models\ContactGroups $group,
        array $steps,
        string $name = 'Action workflow',
    ): array {
        $drafts = app(\App\Library\Automation\Workflow\WorkflowDraftService::class);
        $trigger = \App\Enums\Automation\Workflow\WorkflowTriggerType::ContactCreated;

        $workflow = $drafts->createWorkflowWithDraft($business, $name, $trigger);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition($trigger);
        $definition['root']['config'] += ['contact_group_id' => $group->id];
        $definition['root']['next'] = $steps;

        $drafts->autosave($draft, $definition, $draft->definition_revision);

        $version = app(\App\Library\Automation\Workflow\WorkflowPublisher::class)->publish($workflow->fresh());

        return [$workflow->fresh(), $version];
    }

    /** A real Send SMS step. */
    protected function smsStep(string $body = 'Hello there', array $extraConfig = []): array
    {
        return [
            'key' => (string) Str::uuid(),
            'type' => 'send_sms',
            'config' => ['body' => $body] + $extraConfig,
        ];
    }

    protected function updateFieldStep(int $fieldId, string $value): array
    {
        return [
            'key' => (string) Str::uuid(),
            'type' => 'update_contact_field',
            'config' => ['field_id' => $fieldId, 'value' => $value],
        ];
    }

    protected function notificationStep(string $message = 'A contact reached this step.'): array
    {
        return [
            'key' => (string) Str::uuid(),
            'type' => 'internal_notification',
            'config' => ['message' => $message],
        ];
    }
}
