<?php

namespace App\Library\Automation\Workflow\Executors;

use App\DTO\BusinessEmail\BusinessEmailSendRequest;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus;
use App\Enums\BusinessEmail\BusinessEmailSource;
use App\Exceptions\BusinessEmail\BusinessEmailSendRefusedException;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Automation\Workflow\Runtime\ClaimedStepRun;
use App\Library\Automation\Workflow\Runtime\ContactMergeFields;
use App\Library\BusinessEmail\BusinessEmailSender;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use Throwable;

/**
 * Automations V2 — the Send email action.
 *
 * THE SEND PATH IS NOT NEW. This executor reaches a mailbox through exactly one
 * door, the Business Email foundation's seam:
 *
 *     BusinessEmailSender::send(BusinessEmailSendRequest)
 *
 * There is no Gmail or Microsoft call, no second ledger and no retry schedule of
 * its own here. The sender re-derives the Contact inside the Business, resolves
 * the Business's own connected sender, attributes a Location, enforces its caps
 * and records one `business_email_messages` row per operation key.
 *
 * WHAT THE WORKFLOW STORES is a subject and a body, and nothing about who sends:
 * no account id, provider, address or Location is ever read from the node config
 * (a definition carrying such a key has it ignored, not honoured).
 *
 * THE OPERATION KEY is `automation:{workflow id}:{step run id}:email`, from the
 * claimed step run alone. The step run is unique per (enrollment, node), so
 * replaying the same node of the same journey composes the same key and the
 * sender answers from the recorded row — never a second provider call. It is
 * never built from a timestamp, a random id or the rendered text. With no claimed
 * step run there is no deterministic identity, so nothing is sent.
 *
 * OUTCOMES, preserving the foundation's semantics:
 *   accepted                       → succeeded
 *   refused (no row was written)   → failed, with the category
 *   failed (retryable or not)      → failed, with the category. The foundation
 *                                    allows a later retry under the same key, but
 *                                    an External step is never re-executed by this
 *                                    engine, so no retry is invented here.
 *   unconfirmed                    → failed, NEVER re-sent: the provider may
 *                                    already have delivered it, so it is surfaced
 *                                    for a person, not retried.
 *   sending (another worker's)     → failed as in progress; nothing is re-sent.
 *
 * Side-effect class External: an interrupted send is never re-run
 * (WorkflowRecoveryService).
 *
 * NOT HERE: promotional unsubscribe / suppression (a declared dependency of the
 * Business Email foundation) and inbound replies.
 */
class SendEmailNodeExecutor implements NodeExecutor
{
    public function __construct(private readonly BusinessEmailSender $emails)
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

        $stepRunId = ClaimedStepRun::idFor($node, $enrollment);

        if ($stepRunId === null) {
            // No claimed step, no deterministic identity: refuse rather than send
            // under a key a replay could not recompose.
            return NodeExecutionOutcome::failed('email_no_step_identity');
        }

        // THE RUN'S LOCATION. When the journey is pinned to one, that — and not
        // wherever the Contact lives today — is the Location the email is sent from
        // and attributed to, handed to the foundation's own explicit-Location seam
        // (which still requires it to be an active Location of this Business). A
        // pinned Location that no longer resolves fails closed; it is never swapped
        // for the Contact's. An unpinned journey (a fact with no Location) leaves
        // the foundation's own resolution — Contact, then the single active
        // Location, else refusal — exactly as before.
        $location = null;

        if ($enrollment->business_location_id !== null) {
            $location = BusinessLocation::query()
                ->where('business_id', (int) $business->id)
                ->find((int) $enrollment->business_location_id);

            if ($location === null) {
                return NodeExecutionOutcome::failed('email_location_unavailable');
            }
        }

        try {
            $message = $this->emails->send(new BusinessEmailSendRequest(
                business: $business,
                contact: $contact,
                subject: ContactMergeFields::render($subject, $contact),
                bodyText: ContactMergeFields::render($body, $contact),
                operationKey: sprintf('automation:%d:%d:email', (int) $enrollment->workflow_id, $stepRunId),
                source: BusinessEmailSource::Automation,
                location: $location,
                automationStepRunId: $stepRunId,
            ));
        } catch (BusinessEmailSendRefusedException $exception) {
            return NodeExecutionOutcome::failed('email_refused: ' . $exception->category->value);
        } catch (Throwable $exception) {
            // The outcome is unknown: a failure, never a retry. The step run is
            // already claimed, and External steps are never re-executed.
            return NodeExecutionOutcome::failed('email_exception: ' . class_basename($exception));
        }

        return match ($message->status) {
            BusinessEmailMessageStatus::Accepted => NodeExecutionOutcome::succeeded(
                'Email sent to ' . $this->maskedAddress((string) $message->to_email),
            ),
            BusinessEmailMessageStatus::Unconfirmed => NodeExecutionOutcome::failed('email_unconfirmed'),
            BusinessEmailMessageStatus::Failed => NodeExecutionOutcome::failed(
                'email_failed: ' . ($message->failure_category?->value ?? 'unknown'),
            ),
            BusinessEmailMessageStatus::Queued,
            BusinessEmailMessageStatus::Sending => NodeExecutionOutcome::failed('email_in_progress'),
        };
    }

    /** `j***@example.com` — enough to recognise, never the whole address. */
    private function maskedAddress(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($local === '' || $domain === '') {
            return '****';
        }

        return mb_substr($local, 0, 1) . '***@' . $domain;
    }
}
