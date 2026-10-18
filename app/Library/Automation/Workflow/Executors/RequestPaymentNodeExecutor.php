<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Documents\DocumentStatus;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Automation\Workflow\Runtime\AutomationDocumentFactory;
use App\Library\Automation\Workflow\Runtime\ClaimedStepRun;
use App\Library\Automation\Workflow\Runtime\ContactMergeFields;
use App\Library\Automation\Workflow\Runtime\PinnedRunLocation;
use App\Library\Automation\Workflow\Runtime\TriggerFactResolver;
use App\Library\Automation\Workflow\WorkflowCapabilities;
use App\Library\Documents\DocumentManager;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\Contacts;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Automations V2 — the Request payment action.
 *
 * THIS IS NOT CHARGING A CARD. Nothing here charges a stored card, refunds, or talks
 * to Stripe: a payment is requested the way the product already requests one — a
 * document with a payment schedule and a secure link the customer opens to pay
 * (PaymentManager serves that page). The step produces that link through the
 * Documents domain, in one of two ways:
 *
 *   source `document`  the journey is about a document (a proposal that was sent or
 *                      signed, a payment that failed): its secure link is re-sent —
 *                      DocumentManager::resendLink(), which rotates the link, changes
 *                      no content and no payment state, and is refused for a document
 *                      that is no longer awaiting the customer.
 *   source `invoice`   a NEW invoice is made from a catalog item or a fixed amount and
 *                      sent (AutomationDocumentFactory, i.e. DocumentManager).
 *
 * PRECONDITIONS, re-proved at run time because a pinned version outlives what was true
 * when it was published: the account still has Payments & contracts and a Stripe
 * account that can take a charge (`payment_request_unavailable` otherwise); a document
 * belongs to this Business, to this Contact, and to the journey's pinned Location when
 * it has one; and it is still payable — sent or signed, with an unpaid instalment.
 *
 * Delivery is the Documents domain's: an email carrying the secure link. The link's
 * token exists only inside that email, so there is no text of it.
 *
 * Side-effect class External. Never re-run: a duplicate delivery of the job finds the
 * step already recorded and requests nothing twice.
 */
class RequestPaymentNodeExecutor implements NodeExecutor
{
    public function __construct(
        private readonly DocumentManager $documents,
        private readonly AutomationDocumentFactory $factory,
        private readonly TriggerFactResolver $facts,
        private readonly WorkflowCapabilities $capabilities,
    ) {
    }

    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::RequestPayment;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $config = is_array($node->config) ? $node->config : [];
        $source = (string) ($config['source'] ?? '');

        if (! in_array($source, ['document', 'invoice'], true)) {
            return NodeExecutionOutcome::skipped('send_config_invalid');
        }

        $violation = PinnedRunLocation::violation($enrollment, $contact);

        if ($violation !== null) {
            return NodeExecutionOutcome::skipped($violation);
        }

        if (! $this->capabilities->isAvailable($business, WorkflowCapabilities::PAYMENTS)) {
            return NodeExecutionOutcome::failed('payment_request_unavailable');
        }

        return $source === 'document'
            ? $this->resendDocumentLink($enrollment, $business, $contact)
            : $this->sendInvoice($node, $config, $enrollment, $business, $contact);
    }

    private function resendDocumentLink(AutomationEnrollment $enrollment, Business $business, Contacts $contact): NodeExecutionOutcome
    {
        $documentId = $this->facts->forEnrollment($enrollment)->documentId;

        if ($documentId === null) {
            // The journey is not about a document (its trigger cannot make it one).
            return NodeExecutionOutcome::skipped('document_not_found');
        }

        $document = BusinessDocument::query()
            ->where('business_id', (int) $business->id)
            ->where('contact_id', (int) $contact->id)
            ->find($documentId);

        if ($document === null) {
            return NodeExecutionOutcome::skipped('document_not_found');
        }

        $outside = PinnedRunLocation::resourceViolation($enrollment, (int) $document->business_location_id);

        if ($outside !== null) {
            return NodeExecutionOutcome::skipped($outside);
        }

        if (! in_array($document->status, [DocumentStatus::Sent, DocumentStatus::Signed], true)) {
            return NodeExecutionOutcome::skipped('document_not_payable');
        }

        if (! $this->hasUnpaidInstalment($document)) {
            return NodeExecutionOutcome::skipped('nothing_to_pay');
        }

        try {
            $this->documents->resendLink($document);
        } catch (ValidationException $exception) {
            $message = (string) (collect($exception->errors())->flatten()->first() ?? 'refused');

            return NodeExecutionOutcome::failed('payment_request_refused: ' . mb_substr($message, 0, 200));
        } catch (Throwable $exception) {
            return NodeExecutionOutcome::failed('payment_request_exception: ' . class_basename($exception));
        }

        return NodeExecutionOutcome::succeeded('Payment link sent');
    }

    /** @param array<string, mixed> $config */
    private function sendInvoice(AutomationWorkflowNode $node, array $config, AutomationEnrollment $enrollment, Business $business, Contacts $contact): NodeExecutionOutcome
    {
        $title = is_string($config['title'] ?? null) ? trim((string) $config['title']) : '';
        $itemId = (int) ($config['catalog_item_id'] ?? 0);
        $amount = (int) ($config['amount_minor'] ?? 0);

        if ($title === '' || ($itemId > 0) === ($amount > 0)) {
            return NodeExecutionOutcome::skipped('send_config_invalid');
        }

        $result = $this->factory->createAndSend(
            'invoice',
            mb_substr(ContactMergeFields::render($title, $contact), 0, 200),
            $itemId > 0
                ? ['catalog_item_id' => $itemId, 'quantity' => (int) ($config['quantity'] ?? 1)]
                : ['amount_minor' => $amount],
            'full',
            null,
            $enrollment,
            $business,
            $contact,
            ClaimedStepRun::idFor($node, $enrollment),
        );

        return $result instanceof NodeExecutionOutcome ? $result : NodeExecutionOutcome::succeeded('Payment request sent');
    }

    /** Whether the issued version still has an instalment nobody has paid. */
    private function hasUnpaidInstalment(BusinessDocument $document): bool
    {
        return \Illuminate\Support\Facades\DB::table('business_document_payment_schedule_items')
            ->where('business_document_version_id', (int) $document->current_version_id)
            ->where('status', 'pending')
            ->exists();
    }
}
