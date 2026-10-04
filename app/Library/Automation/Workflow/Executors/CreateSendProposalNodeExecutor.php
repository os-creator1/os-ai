<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Automation\Workflow\Runtime\AutomationDocumentFactory;
use App\Library\Automation\Workflow\Runtime\ClaimedStepRun;
use App\Library\Automation\Workflow\Runtime\ContactMergeFields;
use App\Library\Automation\Workflow\Runtime\PinnedRunLocation;
use App\Library\Automation\Workflow\WorkflowCapabilities;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\Contacts;

/**
 * Automations V2 — the Create & send proposal action ("proposal or contract").
 *
 * A contract IS a proposal that requires a signature: the Documents domain has two
 * kinds, `proposal` and `invoice`, and a proposal carries e-signature. So there is
 * one action, and it creates a `proposal` document — what an owner calls a proposal
 * or a contract is the same signed agreement.
 *
 * The step stores a title, one catalog item (a product or package) with a quantity,
 * and a payment schedule (in full, or a deposit then the balance). Everything it
 * does is AutomationDocumentFactory's: DocumentManager creates the document, takes
 * the catalog's frozen price snapshot as its line, sets the schedule and sends it,
 * which freezes the issued version and emails the secure link to the contact. There
 * is no document-template model in the Documents domain today, so none is invented
 * here.
 *
 * Side-effect class External: it creates a record a person receives. Never re-run.
 */
class CreateSendProposalNodeExecutor implements NodeExecutor
{
    public function __construct(
        private readonly AutomationDocumentFactory $documents,
        private readonly WorkflowCapabilities $capabilities,
    ) {
    }

    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::CreateSendProposal;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $config = is_array($node->config) ? $node->config : [];
        $title = is_string($config['title'] ?? null) ? trim((string) $config['title']) : '';

        if ($title === '' || (int) ($config['catalog_item_id'] ?? 0) <= 0) {
            return NodeExecutionOutcome::skipped('send_config_invalid');
        }

        $violation = PinnedRunLocation::violation($enrollment, $contact);

        if ($violation !== null) {
            return NodeExecutionOutcome::skipped($violation);
        }

        // The account may have lost the product since this version was published.
        if (! $this->capabilities->isAvailable($business, WorkflowCapabilities::DOCUMENTS)
            || ! $this->capabilities->isAvailable($business, WorkflowCapabilities::CATALOG)) {
            return NodeExecutionOutcome::failed('document_unavailable');
        }

        $result = $this->documents->createAndSend(
            'proposal',
            mb_substr(ContactMergeFields::render($title, $contact), 0, 200),
            ['catalog_item_id' => (int) $config['catalog_item_id'], 'quantity' => (int) ($config['quantity'] ?? 1)],
            (string) ($config['payment_schedule'] ?? 'full') === 'deposit' ? 'deposit' : 'full',
            isset($config['deposit_percent']) ? (int) $config['deposit_percent'] : null,
            $enrollment,
            $business,
            $contact,
            ClaimedStepRun::idFor($node, $enrollment),
        );

        if ($result instanceof NodeExecutionOutcome) {
            return $result;
        }

        return NodeExecutionOutcome::succeeded('Proposal sent to ' . $this->masked((string) $result->recipient_email_snapshot));
    }

    private function masked(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return $local === '' || $domain === '' ? '****' : mb_substr($local, 0, 1) . '***@' . $domain;
    }
}
