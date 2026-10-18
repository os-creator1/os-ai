<?php

namespace App\Library\Automation\Workflow\Runtime;

/**
 * The domain rows the fact behind one journey points at — identifiers only, all
 * inside the journey's Business, re-derived from the enrollment's own occurrence key
 * (see TriggerFactResolver). A journey that did not start from such a fact has none
 * of these.
 *
 * `opportunityId` is the deal the FACT itself names (the deal that moved, the deal
 * a form submission or document or appointment was linked to) — never a guess among
 * the contact's deals.
 */
final readonly class TriggerFact
{
    public function __construct(
        public ?int $documentId = null,
        public ?int $paymentId = null,
        public ?int $opportunityId = null,
        public ?int $appointmentId = null,
        public ?int $formSubmissionId = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }
}
