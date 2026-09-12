<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §9/§9.1 — the closed set of v2 triggers, and the
 * trigger-aware enrollment defaults.
 *
 * Only triggers with a real event source in this repository appear here. Forms,
 * appointments, customer payments and tags are deliberately absent: there is
 * nothing in the product for them to listen to yet (§9), and inventing a case
 * for one would let a workflow be published that can never fire.
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
        };
    }

    /**
     * Whether a producer exists today. V2-F flips MessageReceived to true when
     * it ships its after-commit producer; nothing else changes.
     */
    public function isIngestableInThisSlice(): bool
    {
        return $this !== self::MessageReceived;
    }

    public function label(): string
    {
        return match ($this) {
            self::ContactCreated => 'A contact is created',
            self::ContactDateReached => 'A contact date arrives',
            self::ManualEnrollment => 'I enroll someone by hand',
            self::MessageReceived => 'A message is received',
        };
    }
}
