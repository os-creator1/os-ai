<?php

namespace App\Library\Automation\Workflow\Conditions;

use App\Library\Automation\Workflow\Runtime\TriggerFactResolver;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;

/**
 * What the fact behind a journey (and the Contact's own deals) currently say — the
 * read side of the CRM, document, payment and appointment conditions.
 *
 * READ-ONLY, AND LIVE. A condition asks "is it signed NOW", not "was it signed when
 * the journey began": a Wait followed by "if not signed" is exactly the follow-up
 * pattern, so every value is read from the owning domain's own row at evaluation, never
 * from anything captured at enrollment. Every read is scoped to the journey's Business
 * (the enrollment's), so a key that names another Business's row reads as nothing.
 *
 * WHICH ROW. The fact's own: the document a "signed" trigger is about, the payment a
 * "payment failed" trigger is about, the appointment an appointment trigger is about
 * (TriggerFactResolver). The deal is the one the fact names, else the Contact's single
 * open deal — and when there is none, or several, the answer is null rather than a
 * pick, so an ambiguous journey is never read as a particular deal. A null value reads
 * as "not set" to every operator, exactly as §11 specifies for anything that cannot
 * resolve.
 */
class FactConditionReader
{
    public function __construct(private readonly TriggerFactResolver $facts)
    {
    }

    /** The stage id of the journey's deal, or null. */
    public function opportunityStage(AutomationEnrollment $enrollment, Contacts $contact): ?int
    {
        $deal = $this->deal($enrollment, $contact);

        return $deal === null ? null : (int) $deal->stage_id;
    }

    /** The status (open / won / lost) of the journey's deal, or null. */
    public function opportunityStatus(AutomationEnrollment $enrollment, Contacts $contact): ?string
    {
        $deal = $this->deal($enrollment, $contact);

        return $deal === null ? null : (string) $deal->status;
    }

    public function documentStatus(AutomationEnrollment $enrollment): ?string
    {
        $document = $this->document($enrollment);

        return $document === null ? null : (string) $document->status;
    }

    /** Whether the document has been signed (it stays signed once paid); null with no document. */
    public function documentSigned(AutomationEnrollment $enrollment): ?bool
    {
        $document = $this->document($enrollment);

        return $document === null ? null : $document->signed_at !== null;
    }

    /** Whether the document is paid in full; null with no document. */
    public function documentPaid(AutomationEnrollment $enrollment): ?bool
    {
        $document = $this->document($enrollment);

        return $document === null ? null : (string) $document->status === 'paid';
    }

    public function paymentStatus(AutomationEnrollment $enrollment): ?string
    {
        $paymentId = $this->facts->forEnrollment($enrollment)->paymentId;

        if ($paymentId === null) {
            return null;
        }

        $status = DB::table('business_document_payments')
            ->where('id', $paymentId)
            ->where('business_id', (int) $enrollment->business_id)
            ->value('status');

        return $status === null ? null : (string) $status;
    }

    public function appointmentStatus(AutomationEnrollment $enrollment): ?string
    {
        $appointmentId = $this->facts->forEnrollment($enrollment)->appointmentId;

        if ($appointmentId === null) {
            return null;
        }

        $status = DB::table('appointments as a')
            ->join('business_locations as l', 'l.id', '=', 'a.business_location_id')
            ->where('a.id', $appointmentId)
            ->where('l.business_id', (int) $enrollment->business_id)
            ->value('a.status');

        return $status === null ? null : (string) $status;
    }

    private function document(AutomationEnrollment $enrollment): ?object
    {
        $documentId = $this->facts->forEnrollment($enrollment)->documentId;

        if ($documentId === null) {
            return null;
        }

        return DB::table('business_documents')
            ->where('id', $documentId)
            ->where('business_id', (int) $enrollment->business_id)
            ->first(['status', 'signed_at']);
    }

    /** The fact's deal, else the Contact's single open deal; null when there is none or several. */
    private function deal(AutomationEnrollment $enrollment, Contacts $contact): ?object
    {
        $businessId = (int) $enrollment->business_id;
        $factDealId = $this->facts->forEnrollment($enrollment)->opportunityId;

        if ($factDealId !== null) {
            $deal = DB::table('crm_opportunities')
                ->where('id', $factDealId)
                ->where('business_id', $businessId)
                ->where('contact_id', (int) $contact->id)
                ->first(['stage_id', 'status']);

            if ($deal !== null) {
                return $deal;
            }
        }

        $open = DB::table('crm_opportunities')
            ->where('business_id', $businessId)
            ->where('contact_id', (int) $contact->id)
            ->where('status', 'open')
            ->limit(2)
            ->get(['stage_id', 'status']);

        return $open->count() === 1 ? $open->first() : null;
    }
}
