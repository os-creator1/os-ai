<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Enums\Automation\Workflow\StepRunStatus;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Contacts;

/**
 * Delivers one public link — a booking page, a form, a questionnaire — to the
 * journey's Contact through the channels the workflow chose.
 *
 * It owns NO sending. Email goes through AutomationEmailDispatcher and a text
 * through AutomationSmsDispatcher, each the single canonical door for its channel
 * (Business Email's sender; the quick-send core with its billing, opt-out and
 * Location rules). This class only composes the words and combines the outcomes.
 *
 * The links it carries are plain public URLs with no secret in them, which is why a
 * text is possible at all. (A document's secure link is NOT such a URL — its token
 * exists only inside DocumentManager's own email — so documents are email-only.)
 *
 * COMBINING OUTCOMES. A step that delivers by several channels succeeds when at
 * least one channel delivered; it says which, and names (by bounded reason) any that
 * did not. When nothing delivered it fails with the reasons — or is skipped when every
 * channel was a skip (an unsubscribed contact, a contact who left the Location), which
 * the engine never retries. Each channel keeps its own idempotency, so a replay of the
 * step repeats neither.
 */
class AutomationLinkDelivery
{
    public function __construct(
        private readonly AutomationEmailDispatcher $email,
        private readonly AutomationSmsDispatcher $sms,
    ) {
    }

    /**
     * @param list<string> $channels `email` and/or `sms`
     * @param string $subject the email subject
     * @param string $text    the sentence(s) that precede the link
     * @param string $url     the link itself
     */
    public function deliver(
        array $channels,
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
        string $subject,
        string $text,
        string $url,
    ): NodeExecutionOutcome {
        $body = rtrim($text) . "\n\n" . $url;

        $delivered = [];
        $problems = [];
        $skippedOnly = true;

        foreach (array_values(array_unique($channels)) as $channel) {
            $outcome = match ($channel) {
                'email' => $this->email->send($node, $enrollment, $business, $contact, $subject, $body),
                'sms' => $this->sms->send($node, $enrollment, $business, $contact, $body),
                default => NodeExecutionOutcome::failed('channel_unknown'),
            };

            if ($outcome->status === StepRunStatus::Succeeded) {
                $delivered[] = $channel === 'email' ? 'email' : 'text';

                continue;
            }

            if ($outcome->status !== StepRunStatus::Skipped) {
                $skippedOnly = false;
            }

            $problems[] = ($channel === 'email' ? 'email' : 'text') . ': ' . ($outcome->safeErrorSummary ?? 'not_sent');
        }

        if ($delivered !== []) {
            $summary = 'Link sent by ' . implode(' and ', $delivered);

            return NodeExecutionOutcome::succeeded(mb_substr($problems === [] ? $summary : $summary . ' (' . implode('; ', $problems) . ')', 0, 255));
        }

        $reason = mb_substr(implode('; ', $problems), 0, 255);

        return $skippedOnly && $problems !== []
            ? NodeExecutionOutcome::skipped($reason)
            : NodeExecutionOutcome::failed($reason === '' ? 'link_not_sent' : $reason);
    }
}
