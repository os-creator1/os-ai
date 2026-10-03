<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\DocumentPaymentFailed;
use App\Events\DocumentPaymentSucceeded;
use App\Events\DocumentSent;
use App\Events\DocumentSigned;
use Illuminate\Support\Facades\DB;

/**
 * Automations V2 — the document and payment triggers: "A proposal or document is
 * sent", "…is signed", "A payment succeeds", "A payment fails".
 *
 * THE DOCUMENTS AND PAYMENTS DOMAINS DO NOT CALL AUTOMATIONS. DocumentManager and
 * PaymentFinalizer emit their durable after-commit events — one per issued version,
 * per signature row, per transition of a payment row into succeeded or failed —
 * and a queued listener hands each here. There is no trigger off a browser
 * redirect: the only inputs are those server-side events.
 *
 * WHAT IS TRUSTED. The events are ids only, so every fact is re-read from the
 * domain's own rows, scoped to the event's Business: the document, and the version,
 * signature or payment the event names — which must belong to THAT document. A
 * document of another Business, a version/signature/payment that does not belong to
 * the document, or one that no longer exists is no fact at all. The Contact is the
 * document's OWN (frozen for its life) and must still be a contact of that Business;
 * the Location is the document's own `business_location_id`, never inferred from the
 * Contact.
 *
 * THE OCCURRENCE KEY is a function of the persisted row alone, so replay composes
 * the same key and EnrollmentService refuses the duplicate:
 *
 *   document_version_sent:{version id}        one issued version
 *   document_signature:{signature id}         the document's one signature row
 *   document_payment_succeeded:{payment id}   the one transition into succeeded
 *   document_payment_failed:{payment id}      the one transition into failed
 *
 * (A failed payment row can later succeed, which is a different occurrence under a
 * different key.)
 *
 * LOOP PREVENTION. A document an automation sent (Create & send proposal) carries
 * `origin = automation_step_run:{id}` on its DocumentSent; the producing workflow
 * never re-triggers off its own output, and any other workflow enrolls at depth + 1.
 *
 * ONE KIND FILTER. Every trigger may narrow to `proposal` or `invoice` by the
 * document's own kind ("any" when absent). A contract is a proposal that requires a
 * signature, so there is no separate contract value to filter on.
 */
class DocumentTriggerSource extends FoundationTriggerSource
{
    protected function assertServes(WorkflowTriggerType $triggerType): void
    {
        if (! $triggerType->hasDocumentFact()) {
            throw new \InvalidArgumentException('The document trigger source serves only the document and payment triggers.');
        }
    }

    /** @return array{enrolled: int, skipped: array<string, int>} */
    public function handle(DocumentSent|DocumentSigned|DocumentPaymentSucceeded|DocumentPaymentFailed $event): array
    {
        $result = $this->emptyResult();

        $expected = match (true) {
            $event instanceof DocumentSent => WorkflowTriggerType::DocumentSent,
            $event instanceof DocumentSigned => WorkflowTriggerType::DocumentSigned,
            $event instanceof DocumentPaymentSucceeded => WorkflowTriggerType::PaymentSucceeded,
            default => WorkflowTriggerType::PaymentFailed,
        };

        if ($expected !== $this->triggerType || $event->businessId === null) {
            return $this->skip($result, self::SKIPPED_NO_FACT);
        }

        $key = $this->verifiedOccurrenceKey($event);

        if ($key === null) {
            return $this->skip($result, self::SKIPPED_NO_FACT);
        }

        $document = DB::table('business_documents')
            ->where('id', $event->documentId)
            ->where('business_id', $event->businessId)
            ->first(['id', 'business_id', 'business_location_id', 'contact_id', 'kind']);

        if ($document === null) {
            return $this->skip($result, self::SKIPPED_NO_FACT);
        }

        $kind = (string) $document->kind;

        return $this->enrollListening(
            $result,
            (int) $document->business_id,
            (int) $document->contact_id,
            $key,
            fn (array $config): bool => ($wanted = $config['document_kind'] ?? null) === null || $wanted === '' || $wanted === $kind,
            $event instanceof DocumentSent ? TriggerCause::resolve($event->origin, (int) $document->business_id) : null,
            // The document's own Location, fixed for its life.
            (int) $document->business_location_id,
        );
    }

    /**
     * The occurrence key, built from the persisted row the event names — or null
     * when that row does not exist, does not belong to the event's document and
     * Business, or the event disagrees with it.
     */
    private function verifiedOccurrenceKey(DocumentSent|DocumentSigned|DocumentPaymentSucceeded|DocumentPaymentFailed $event): ?string
    {
        if ($event instanceof DocumentSent) {
            $version = DB::table('business_document_versions')
                ->where('id', $event->versionId)
                ->where('business_document_id', $event->documentId)
                ->whereIn('state', ['issued', 'superseded'])
                ->exists();

            return $version && $event->occurrenceKey() === 'document_version_sent:' . $event->versionId
                ? $event->occurrenceKey()
                : null;
        }

        if ($event instanceof DocumentSigned) {
            $signature = DB::table('business_document_signatures')
                ->where('id', $event->signatureId)
                ->where('business_document_id', $event->documentId)
                ->exists();

            return $signature && $event->occurrenceKey() === 'document_signature:' . $event->signatureId
                ? $event->occurrenceKey()
                : null;
        }

        $payment = DB::table('business_document_payments')
            ->where('id', $event->paymentId)
            ->where('business_document_id', $event->documentId)
            ->where('business_id', $event->businessId)
            ->first(['id', 'status']);

        if ($payment === null) {
            return null;
        }

        if ($event instanceof DocumentPaymentSucceeded) {
            // The transition into `succeeded` is final: a row that is not succeeded
            // now cannot have emitted this.
            return (string) $payment->status === 'succeeded' ? 'document_payment_succeeded:' . $event->paymentId : null;
        }

        return 'document_payment_failed:' . $event->paymentId;
    }
}
