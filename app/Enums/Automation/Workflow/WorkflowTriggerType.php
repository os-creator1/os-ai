<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §9/§9.1 — the closed set of v2 triggers, and the
 * trigger-aware enrollment defaults.
 *
 * Only triggers with a real event source in this repository appear here. Customer
 * payments, documents and proposals are deliberately absent: their integrations
 * are separate lanes (§9), and inventing a case for one would let a workflow be
 * published that can never fire. Tags, forms and appointments joined once their
 * foundations merged and emitted stable after-commit events.
 *
 * `MessageReceived` is declared because V2-F's producer is contracted and its
 * Business-scoping seam now exists (`chat_boxes.business_id`). Until V2-F lands
 * there is no producer, so `isIngestableInThisSlice()` reports which triggers
 * can actually enroll today — the validator uses it to refuse publishing a
 * workflow whose trigger nothing can yet fire.
 */
enum WorkflowTriggerType: string
{
    case ContactCreated = 'contact_created';
    case ContactDateReached = 'contact_date_reached';
    case ManualEnrollment = 'manual_enrollment';
    case MessageReceived = 'message_received';

    /*
     * CRM sales opportunities (crm_opportunities) — never the AI COO / Advisor
     * `opportunities` domain. Each value is the CRM event's own canonical name
     * (App\Events\Crm\CrmOpportunityEvent::NAME), so the event that happened and
     * the trigger that listens for it are one word, not a mapping to keep in step.
     */
    case OpportunityCreated = 'opportunity_created';
    case OpportunityStageChanged = 'opportunity_stage_changed';
    case OpportunityWon = 'opportunity_won';
    case OpportunityLost = 'opportunity_lost';

    /*
     * Merged-foundation facts. Each value is the owning domain's own after-commit
     * event, consumed through a queued listener; the domains never call
     * Automations. Payments, documents, proposals and contracts are deliberately
     * absent — their integrations are separate lanes.
     *
     *   contact_tag_*          App\Events\Crm\ContactTagAdded / ContactTagRemoved
     *   form_submitted         App\Events\Forms\FormSubmissionRecorded (final only)
     *   appointment_scheduled  App\Events\Calendar\AppointmentScheduled
     *   appointment_cancelled  App\Events\Calendar\AppointmentCancelled
     *   appointment_rescheduled App\Events\Calendar\AppointmentRescheduled
     *
     * There is no "confirmed" appointment trigger: the Calendar has no confirmed
     * state (AppointmentStatus is scheduled / cancelled / completed / no_show), and
     * inventing a lifecycle state to fill a list is exactly what §9 forbids.
     */
    case ContactTagAdded = 'contact_tag_added';
    case ContactTagRemoved = 'contact_tag_removed';
    case FormSubmitted = 'form_submitted';
    case AppointmentScheduled = 'appointment_scheduled';
    case AppointmentCancelled = 'appointment_cancelled';
    case AppointmentRescheduled = 'appointment_rescheduled';

    /*
     * Documents, payments and questionnaires — each the owning domain's own
     * durable after-commit event, never a browser redirect.
     *
     *   document_sent          App\Events\DocumentSent (one issued version)
     *   document_signed        App\Events\DocumentSigned (the one signature row)
     *   payment_succeeded      App\Events\DocumentPaymentSucceeded (the single
     *                          transition into `succeeded` of one payment row)
     *   payment_failed         App\Events\DocumentPaymentFailed (the single
     *                          transition into `failed` of one payment row)
     *   questionnaire_submitted App\Events\Forms\FormSubmissionRecorded for a form
     *                          whose pinned version has two or more pages
     *
     * There is no separate "contract" trigger: a contract IS a proposal that
     * requires a signature (DocumentKind has `proposal` and `invoice`), so
     * document_sent / document_signed carry the document's kind and a workflow may
     * narrow to it. Inventing a DocumentKind to fit a trigger name is what the
     * documents contract forbids.
     */
    case DocumentSent = 'document_sent';
    case DocumentSigned = 'document_signed';
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed = 'payment_failed';
    case QuestionnaireSubmitted = 'questionnaire_submitted';

    /**
     * THE TRIGGER-AWARE DEFAULT (§9.1, owner decision D3).
     *
     * There is deliberately no single global default. A global `once_ever`
     * applied to a date trigger would quietly turn a yearly birthday workflow
     * into a one-time one, which is why this lives on the trigger.
     */
    public function defaultEnrollmentPolicy(): EnrollmentPolicy
    {
        return match ($this) {
            self::ContactCreated, self::ManualEnrollment => EnrollmentPolicy::OnceEver,
            self::ContactDateReached, self::MessageReceived => EnrollmentPolicy::OncePerOccurrence,
            // One contact can have many deals, and one deal many moves: each is its
            // own occurrence, so a second deal must not be refused as a repeat.
            self::OpportunityCreated,
            self::OpportunityStageChanged,
            self::OpportunityWon,
            self::OpportunityLost,
            // A contact can be tagged, submit a form or book again: each time is
            // its own occurrence, keyed by the owning domain's own identity.
            self::ContactTagAdded,
            self::ContactTagRemoved,
            self::FormSubmitted,
            self::AppointmentScheduled,
            self::AppointmentCancelled,
            self::AppointmentRescheduled,
            // A document is sent, signed and paid against many times (a revision, a
            // deposit and a balance); each is its own occurrence, keyed by the
            // owning domain's own row.
            self::DocumentSent,
            self::DocumentSigned,
            self::PaymentSucceeded,
            self::PaymentFailed,
            self::QuestionnaireSubmitted => EnrollmentPolicy::OncePerOccurrence,
        };
    }

    /** The two triggers fed by a document's lifecycle (sent, signed). */
    public function isDocument(): bool
    {
        return $this === self::DocumentSent || $this === self::DocumentSigned;
    }

    /** The two triggers fed by a document payment's outcome. */
    public function isPayment(): bool
    {
        return $this === self::PaymentSucceeded || $this === self::PaymentFailed;
    }

    /** Triggers whose fact is a document (or a payment against one). */
    public function hasDocumentFact(): bool
    {
        return $this->isDocument() || $this->isPayment();
    }

    /** The two triggers fed by Contact Tag membership events. */
    public function isContactTag(): bool
    {
        return $this === self::ContactTagAdded || $this === self::ContactTagRemoved;
    }

    /** The three triggers fed by Calendar appointment lifecycle events. */
    public function isAppointment(): bool
    {
        return in_array($this, [
            self::AppointmentScheduled,
            self::AppointmentCancelled,
            self::AppointmentRescheduled,
        ], true);
    }

    /** The four triggers fed by CRM sales opportunity events. */
    public function isCrmOpportunity(): bool
    {
        return in_array($this, [
            self::OpportunityCreated,
            self::OpportunityStageChanged,
            self::OpportunityWon,
            self::OpportunityLost,
        ], true);
    }

    /**
     * Whether a producer exists today. Every trigger now has one: V2-F shipped
     * MessageReceived's after-commit producer (InboundMessageReceived, emitted
     * from both inbound paths) and its trigger source. The method stays, because
     * the validator asks it — a future trigger declared before its producer
     * exists returns false here and is refused at publish.
     */
    public function isIngestableInThisSlice(): bool
    {
        return true;
    }

    public function label(): string
    {
        return match ($this) {
            self::ContactCreated => 'A contact is created',
            self::ContactDateReached => 'A contact date arrives',
            self::ManualEnrollment => 'I enroll someone by hand',
            self::MessageReceived => 'A message is received',
            self::OpportunityCreated => 'Opportunity created',
            self::OpportunityStageChanged => 'Opportunity moves stage',
            self::OpportunityWon => 'Opportunity marked won',
            self::OpportunityLost => 'Opportunity marked lost',
            self::ContactTagAdded => 'A tag is added to a contact',
            self::ContactTagRemoved => 'A tag is removed from a contact',
            self::FormSubmitted => 'A form is submitted',
            self::AppointmentScheduled => 'An appointment is booked',
            self::AppointmentCancelled => 'An appointment is cancelled',
            self::AppointmentRescheduled => 'An appointment is rescheduled',
            self::DocumentSent => 'A proposal or document is sent',
            self::DocumentSigned => 'A proposal or document is signed',
            self::PaymentSucceeded => 'A payment succeeds',
            self::PaymentFailed => 'A payment fails',
            self::QuestionnaireSubmitted => 'A questionnaire is submitted',
        };
    }
}
