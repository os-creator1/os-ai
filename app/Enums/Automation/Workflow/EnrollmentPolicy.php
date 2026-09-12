<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §7.5 — how often one contact may enter one workflow, and the
 * composition of the durable claim that enforces it.
 *
 * The key this enum builds is written to
 * `automation_enrollments.enrollment_key`, which is UNIQUE. That is the whole
 * mechanism: "has this contact already entered?" is answered by the database
 * refusing a duplicate insert, never by a read-then-write check that two
 * concurrent triggers could both pass.
 *
 * Independently of the policy, `active_contact_guard` additionally forbids one
 * contact occupying one workflow twice at the same moment.
 */
enum EnrollmentPolicy: string
{
    /** One contact goes through this workflow once, ever. */
    case OnceEver = 'once_ever';

    /** Once per trigger occurrence: a yearly date, a distinct message. */
    case OncePerOccurrence = 'once_per_occurrence';

    /**
     * Compose the durable enrollment claim.
     *
     * Every component is server-derived. A caller may never pass request input
     * as the occurrence key: it is a year computed in the Business timezone, a
     * provider message id, or a manual-request uid minted server-side.
     */
    public function enrollmentKey(int $workflowId, int $contactId, string $triggerOccurrenceKey): string
    {
        return match ($this) {
            self::OnceEver => sprintf('wf:%d:c:%d', $workflowId, $contactId),
            self::OncePerOccurrence => sprintf(
                'wf:%d:c:%d:o:%s',
                $workflowId,
                $contactId,
                $triggerOccurrenceKey,
            ),
        };
    }

    /** Whether the occurrence key participates in the claim. */
    public function usesOccurrenceKey(): bool
    {
        return $this === self::OncePerOccurrence;
    }

    public function label(): string
    {
        return match ($this) {
            self::OnceEver => 'Once ever',
            self::OncePerOccurrence => 'Once each time it happens',
        };
    }
}
