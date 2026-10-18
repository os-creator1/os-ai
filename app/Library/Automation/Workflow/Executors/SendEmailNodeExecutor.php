<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Automation\Workflow\Runtime\AutomationEmailDispatcher;
use App\Library\Automation\Workflow\Runtime\ContactMergeFields;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Contacts;

/**
 * Automations V2 — the Send email action.
 *
 * A v2 `send_email` node stores a subject and a body, and nothing about who sends.
 * HOW it is sent — the Business Email foundation's one door, the deterministic
 * operation key from the claimed step run, the pinned Location, the outcome
 * semantics — lives in AutomationEmailDispatcher, shared with every other action
 * that emails a link.
 *
 * Side-effect class External: an interrupted send is never re-run
 * (WorkflowRecoveryService).
 */
class SendEmailNodeExecutor implements NodeExecutor
{
    public function __construct(private readonly AutomationEmailDispatcher $email)
    {
    }

    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::SendEmail;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $config = is_array($node->config) ? $node->config : [];
        $subject = is_string($config['subject'] ?? null) ? trim((string) $config['subject']) : '';
        $body = is_string($config['body'] ?? null) ? trim((string) $config['body']) : '';

        if ($subject === '' || $body === '') {
            return NodeExecutionOutcome::skipped('send_config_invalid');
        }

        $subject = ContactMergeFields::renderForEnrollment($subject, $contact, $enrollment, $business);
        $body = ContactMergeFields::renderForEnrollment($body, $contact, $enrollment, $business);

        if (trim($subject) === '' || trim($body) === '') {
            return NodeExecutionOutcome::skipped('rendered_content_empty');
        }

        return $this->email->send(
            $node,
            $enrollment,
            $business,
            $contact,
            $subject,
            $body,
        );
    }
}
