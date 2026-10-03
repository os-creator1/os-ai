<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Automation\Workflow\Runtime\AutomationLinkDelivery;
use App\Library\Automation\Workflow\Runtime\ContactMergeFields;
use App\Library\Automation\Workflow\Runtime\PinnedRunLocation;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Contacts;

/**
 * The shared shape of the three "send a link" actions — Send booking link, Send form
 * and Send questionnaire.
 *
 * Each subclass owns the one thing that differs: how its resource (a booking type, a
 * form) is proved to belong to this journey and what its public URL is. Everything
 * else is identical and lives here, once:
 *
 *   - the Contact must still be at a Location-bound journey's Location;
 *   - the words around the link come from the node's `message` (and `subject` for
 *     email), with the contact's merge tags rendered — never template evaluation;
 *   - delivery goes through AutomationLinkDelivery to the channels the node chose.
 *
 * The URL is resolved from the resource AT RUN TIME and never stored in the node, so
 * a renamed or re-deployed resource is never linked by a stale address, and a
 * resource that has since been archived, disabled or moved fails closed with a
 * bounded reason.
 *
 * Side-effect class External: an interrupted send is never re-run.
 */
abstract class LinkActionNodeExecutor implements NodeExecutor
{
    public function __construct(protected readonly AutomationLinkDelivery $delivery)
    {
    }

    /**
     * Prove the resource and return its public URL, or the bounded reason it cannot
     * be sent.
     *
     * @param array<string, mixed> $config
     *
     * @return array{url: string, subject: string, text: string}|NodeExecutionOutcome
     */
    abstract protected function resolveLink(
        array $config,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): array|NodeExecutionOutcome;

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $config = is_array($node->config) ? $node->config : [];
        $channels = array_values(array_filter((array) ($config['channels'] ?? []), 'is_string'));

        if ($channels === []) {
            return NodeExecutionOutcome::skipped('send_config_invalid');
        }

        $violation = PinnedRunLocation::violation($enrollment, $contact);

        if ($violation !== null) {
            return NodeExecutionOutcome::skipped($violation);
        }

        $link = $this->resolveLink($config, $enrollment, $business, $contact);

        if ($link instanceof NodeExecutionOutcome) {
            return $link;
        }

        $subject = is_string($config['subject'] ?? null) && trim((string) $config['subject']) !== ''
            ? trim((string) $config['subject'])
            : $link['subject'];
        $text = is_string($config['message'] ?? null) && trim((string) $config['message']) !== ''
            ? trim((string) $config['message'])
            : $link['text'];

        return $this->delivery->deliver(
            $channels,
            $node,
            $enrollment,
            $business,
            $contact,
            ContactMergeFields::render($subject, $contact),
            ContactMergeFields::render($text, $contact),
            $link['url'],
        );
    }
}
