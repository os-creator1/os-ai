<?php

namespace App\Events\Crm;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Contact Tags foundation §4 — the shared shape of `ContactTagAdded` and
 * `ContactTagRemoved`, mirroring `App\Events\Crm\CrmOpportunityEvent`'s own
 * precedent exactly: `ShouldDispatchAfterCommit` so Laravel defers actual
 * delivery until the enclosing `DB::transaction()` in `TagManager` commits
 * — a rolled-back attach/detach fires nothing, by the framework's own
 * guarantee, not by any check this class performs.
 *
 * IDS AND VALUES ONLY, never a loaded relation or a mutable model — the
 * same "AFTER COMMIT, AND IDS ONLY" discipline `InboundMessageReceived`
 * documents: whatever consumes this later re-reads the Contact, Tag and
 * Business fresh rather than trusting data captured at dispatch time.
 *
 * NO PII BEYOND WHAT THE RECIPIENT CAN ALREADY SEE: the Contact is
 * identified by id only (never phone/name), and `tagName` is a short,
 * customer-authored label — not personal data about the Contact.
 *
 * `origin` IS AN OPAQUE CAUSATION REFERENCE, null for a person's own change.
 * A caller that mutates tags on behalf of something else (the Automations
 * engine passes `automation_step_run:{id}`) says so at the TagManager seam, and
 * it rides here unchanged. This domain never reads it; a consumer uses it to
 * tell its own writes from a person's, which is what stops one automation's tag
 * change from re-triggering the automation that made it.
 *
 * LOCATION CONTEXT, PER THE TAGS FOUNDATION'S OWN SCOPING DECISION: tags are
 * Business-wide (never Location-scoped — see `create_tags_table`'s
 * docblock), so there is no Location to read off the TAG. `locationId` is
 * instead the CONTACT's own `location_id`, captured by `TagManager` at the
 * moment of the mutation — the same "pinned at the moment of the
 * triggering fact" discipline the Automations Location run-scope
 * foundation already established for `automation_enrollments
 * .business_location_id`. It may be null: not every Contact has a resolved
 * Location, and this event does not invent one.
 */
abstract class ContactTagEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $businessId,
        public readonly int $contactId,
        public readonly int $tagId,
        public readonly string $tagName,
        public readonly ?int $locationId,
        public readonly int $membershipId,
        public readonly ?string $origin = null,
    ) {
    }

    /**
     * The occurrence identity future Automation idempotency can key on —
     * deterministic from a durable row id that is NEVER reused (MySQL
     * AUTO_INCREMENT), so a detach-then-reattach of the same Contact/Tag
     * pair produces a genuinely NEW occurrence (a new `contact_tags.id`),
     * never a collision with the occurrence that was already removed.
     */
    abstract public function occurrenceKey(): string;
}
