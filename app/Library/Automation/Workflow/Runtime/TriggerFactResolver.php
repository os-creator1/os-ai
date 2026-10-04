<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Models\AutomationEnrollment;
use Illuminate\Support\Facades\DB;

/**
 * Which domain rows the fact that started a journey points at.
 *
 * Conditions ("document is signed", "appointment is cancelled") and actions ("move
 * THE opportunity", "request payment for THIS document") need to know what the
 * journey is ABOUT. That is never copied onto the enrollment; it is read back from
 * the owning domain's own row through the occurrence key the enrollment was claimed
 * with — the same keys the trigger sources compose, which are functions of
 * persisted rows alone:
 *
 *   crm_opportunity_history:{id}              the deal that changed
 *   form_submission:{uid}                     the submission, and the deal it made
 *   appointment_{scheduled|cancelled|rescheduled}:{id}[:{n}]   the appointment
 *   document_version_sent:{id} / document_signature:{id}       the document
 *   document_payment_{succeeded|failed}:{id}  the payment, and its document
 *
 * Every read is filtered on the enrollment's Business, so a key that names another
 * Business's row resolves to nothing. A journey started by anything else (a contact
 * created, a date, by hand, a tag, a message) has no fact.
 */
class TriggerFactResolver
{
    public function forEnrollment(AutomationEnrollment $enrollment): TriggerFact
    {
        $key = (string) $enrollment->trigger_occurrence_key;
        $businessId = (int) $enrollment->business_id;

        if (preg_match('/^crm_opportunity_history:(\d+)$/', $key, $m) === 1) {
            $opportunity = DB::table('crm_opportunity_history')
                ->where('id', (int) $m[1])->where('business_id', $businessId)->value('opportunity_id');

            return new TriggerFact(opportunityId: $opportunity === null ? null : (int) $opportunity);
        }

        if (str_starts_with($key, 'form_submission:')) {
            $row = DB::table('form_submissions')
                ->where('uid', substr($key, strlen('form_submission:')))->where('business_id', $businessId)
                ->first(['id', 'crm_opportunity_id']);

            return $row === null ? TriggerFact::none() : new TriggerFact(
                opportunityId: $row->crm_opportunity_id === null ? null : (int) $row->crm_opportunity_id,
                formSubmissionId: (int) $row->id,
            );
        }

        if (preg_match('/^appointment_(?:scheduled|cancelled|rescheduled):(\d+)(?::\d+)?$/', $key, $m) === 1) {
            $row = DB::table('appointments as a')
                ->join('business_locations as l', 'l.id', '=', 'a.business_location_id')
                ->where('a.id', (int) $m[1])->where('l.business_id', $businessId)
                ->first(['a.id', 'a.crm_opportunity_id']);

            return $row === null ? TriggerFact::none() : new TriggerFact(
                opportunityId: $row->crm_opportunity_id === null ? null : (int) $row->crm_opportunity_id,
                appointmentId: (int) $row->id,
            );
        }

        if (preg_match('/^document_version_sent:(\d+)$/', $key, $m) === 1) {
            $document = DB::table('business_document_versions as v')
                ->join('business_documents as d', 'd.id', '=', 'v.business_document_id')
                ->where('v.id', (int) $m[1])->where('d.business_id', $businessId)
                ->first(['d.id', 'd.crm_opportunity_id']);

            return $this->documentFact($document);
        }

        if (preg_match('/^document_signature:(\d+)$/', $key, $m) === 1) {
            $document = DB::table('business_document_signatures as s')
                ->join('business_documents as d', 'd.id', '=', 's.business_document_id')
                ->where('s.id', (int) $m[1])->where('d.business_id', $businessId)
                ->first(['d.id', 'd.crm_opportunity_id']);

            return $this->documentFact($document);
        }

        if (preg_match('/^document_payment_(?:succeeded|failed):(\d+)$/', $key, $m) === 1) {
            $row = DB::table('business_document_payments as p')
                ->join('business_documents as d', 'd.id', '=', 'p.business_document_id')
                ->where('p.id', (int) $m[1])->where('p.business_id', $businessId)->where('d.business_id', $businessId)
                ->first(['p.id as payment_id', 'd.id', 'd.crm_opportunity_id']);

            return $row === null ? TriggerFact::none() : new TriggerFact(
                documentId: (int) $row->id,
                paymentId: (int) $row->payment_id,
                opportunityId: $row->crm_opportunity_id === null ? null : (int) $row->crm_opportunity_id,
            );
        }

        return TriggerFact::none();
    }

    private function documentFact(?object $document): TriggerFact
    {
        return $document === null ? TriggerFact::none() : new TriggerFact(
            documentId: (int) $document->id,
            opportunityId: $document->crm_opportunity_id === null ? null : (int) $document->crm_opportunity_id,
        );
    }
}
