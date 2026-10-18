<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Automation\Workflow\Runtime\AutomationSmsDispatcher;
use App\Library\Automation\Workflow\Runtime\ContactMergeFields;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Contacts;

/**
 * Automations V2 §10.1 — the Send SMS action.
 *
 * A v2 `send_sms` node stores one thing — the message body
 * (NodeTypeRegistry::validateSendSms) — and everything about HOW it is sent lives in
 * AutomationSmsDispatcher, shared with every other action that texts a link: the one
 * quick-send door, sender resolution from the Business itself (never from node
 * config), the pinned Location rules, consent at the action boundary, and the
 * at-most-once claim. Billing, opt-out and provider calls are inherited from the
 * core, not built here.
 *
 * Side-effect class External: an interrupted send is never re-run
 * (WorkflowRecoveryService), and the advancer's claim means duplicate delivery of
 * the same job cannot produce a second message.
 */
class SendSmsNodeExecutor implements NodeExecutor
{
    public function __construct(private readonly AutomationSmsDispatcher $sms)
    {
    }

    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::SendSms;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $config = is_array($node->config) ? $node->config : [];
        $body = is_string($config['body'] ?? null) ? trim((string) $config['body']) : '';

        if ($body === '') {
            return NodeExecutionOutcome::skipped('send_config_invalid');
        }

        $message = ContactMergeFields::renderForEnrollment($body, $contact, $enrollment, $business);

        if (trim($message) === '') {
            // Every merge value was missing: nothing meaningful to send.
            return NodeExecutionOutcome::skipped('rendered_content_empty');
        }

        return $this->sms->send($node, $enrollment, $business, $contact, $message);
    }
}
