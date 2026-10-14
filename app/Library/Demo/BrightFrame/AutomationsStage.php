<?php

namespace App\Library\Demo\BrightFrame;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\AutomationWorkflow;
use App\Models\CrmPipelineStage;
use App\Models\Tag;
use Illuminate\Support\Str;

/**
 * Automations: six workflows built through the real draft service and
 * published through the real compiler/publisher, so the builder opens them
 * exactly like customer-made ones.
 *
 * SAFE BY CONSTRUCTION: the demo Business has no messaging number, sender,
 * sending server or email account, so every text/email step fails closed
 * ("no sending path") instead of reaching anyone. The audit at the end of the
 * provisioner re-proves that.
 */
final class AutomationsStage implements DemoStage
{
    public function __construct(
        private readonly WorkflowDraftService $drafts,
        private readonly WorkflowPublisher $publisher,
    ) {
    }

    public function run(DemoContext $context): void
    {
        $business = $context->business;
        $pipeline = $context->refs['pipeline'];
        $stage = fn (string $name): int => (int) CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->where('name', $name)->value('id');
        $tag = fn (string $name): int => (int) Tag::query()->where('business_id', $business->id)->where('name', $name)->value('id');
        $quoteForm = $context->refs['forms']['Get a Quote'];
        $survey = $context->refs['forms']['Post-Event Feedback Survey'];

        $wait = fn (int $amount, string $unit): array => ['key' => (string) Str::uuid(), 'type' => 'wait', 'config' => ['mode' => 'duration', 'amount' => $amount, 'unit' => $unit]];
        $sms = fn (string $body): array => ['key' => (string) Str::uuid(), 'type' => 'send_sms', 'config' => ['body' => $body]];
        $addTag = fn (string $name): array => ['key' => (string) Str::uuid(), 'type' => 'add_tag', 'config' => ['tag_id' => $tag($name)]];
        $notify = fn (string $message): array => ['key' => (string) Str::uuid(), 'type' => 'internal_notification', 'config' => ['message' => $message]];

        $workflows = [
            ['New Quote Request: Instant Reply', WorkflowTriggerType::FormSubmitted, ['form_id' => $quoteForm->id], [
                $sms('Hi {first_name}, thanks for asking about a BrightFrame photo booth! A planner will text you a custom quote within one business day.'),
                $addTag('Hot lead'),
                $notify('New quote request from {first_name} {last_name}. Reply within the hour to win the booking.'),
            ], true],
            ['Proposal Follow-up', WorkflowTriggerType::OpportunityStageChanged, ['pipeline_id' => $pipeline->id, 'to_stage_id' => $stage('Proposal sent')], [
                $wait(2, 'days'),
                $sms('Hi {first_name}, just checking that you received your BrightFrame proposal. Happy to answer any questions or hold your date a bit longer.'),
            ], true],
            ['Booking Confirmation and Reminder', WorkflowTriggerType::AppointmentScheduled, [], [
                $sms('Hi {first_name}, you are booked with BrightFrame Photo Booths. We look forward to talking through your event!'),
                $wait(1, 'days'),
                $sms('Reminder: your BrightFrame appointment is tomorrow. Reply here if you need to reschedule.'),
            ], true],
            ['Post-Event Review Request', WorkflowTriggerType::OpportunityWon, ['pipeline_id' => $pipeline->id], [
                $wait(3, 'days'),
                $sms('Thank you for celebrating with BrightFrame, {first_name}! We would love your feedback on how the booth went.'),
                $addTag('Repeat client'),
            ], true],
            ['Alert Team to New Text Messages', WorkflowTriggerType::MessageReceived, [], [
                $notify('{first_name} {last_name} just texted BrightFrame. Open Conversations to reply.'),
            ], true],
            ['Cold Lead Nurture (draft)', WorkflowTriggerType::OpportunityLost, ['pipeline_id' => $pipeline->id], [
                $wait(30, 'days'),
                $sms('Hi {first_name}, BrightFrame here. Still planning your event? We have a few dates open and a spring discount for returning inquiries.'),
            ], false],
        ];

        foreach ($workflows as [$name, $trigger, $triggerConfig, $steps, $publish]) {
            $workflow = AutomationWorkflow::query()->where('business_id', $business->id)->where('name', $name)->first();

            if ($workflow === null) {
                $workflow = $this->drafts->createWorkflowWithDraft($business, $name, $trigger, $context->user->id);
                $draft = $workflow->draftVersion();
                $definition = $this->drafts->starterDefinition($trigger);
                $definition['root']['config'] = array_merge($definition['root']['config'], $triggerConfig);
                $definition['root']['next'] = $steps;
                $this->drafts->autosave($draft, $definition, $draft->definition_revision);

                if ($publish) {
                    $this->publisher->publish($workflow->fresh(), $context->user->id);
                }
            }

            $context->refs['workflows'][$name] = $workflow->fresh();
        }

        $context->note('automations', AutomationWorkflow::query()->where('business_id', $business->id)->count());
    }
}
