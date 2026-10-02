<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Crm\ContactTagAdded;
use App\Events\Crm\ContactTagEvent;
use App\Events\Crm\ContactTagRemoved;
use Illuminate\Support\Facades\DB;

/**
 * Automations V2 — Contact Tag triggers: "A tag is added to a contact" and "A tag
 * is removed from a contact".
 *
 * THE TAGS DOMAIN DOES NOT CALL AUTOMATIONS. TagManager emits ContactTagAdded and
 * ContactTagRemoved after commit — once per genuinely new membership change,
 * never for a duplicate attach or an absent detach — and a queued listener hands
 * each here. One class serves both triggers, registered once per type.
 *
 * WHAT IS TRUSTED. The event is ids only and a listener may run late or twice, so
 * the Contact and the Tag are re-read, each filtered on the event's Business. A
 * Contact or Tag of another Business, or one that no longer exists, enrolls
 * nobody. Tags are Business-wide (never Location-scoped), so there is no Location
 * to check on the tag; the event's `locationId` is the Contact's at the moment of
 * the change and is informational.
 *
 * THE OCCURRENCE KEY is the event's own (`contact_tag_added:{membership id}` /
 * `contact_tag_removed:{membership id}`), built from a row id that is never
 * reused. Redelivery composes the same key and EnrollmentService refuses the
 * duplicate; a remove-then-re-add of the same pair is a genuinely new occurrence.
 *
 * LOOP PREVENTION. An automation's own tag action tells TagManager so
 * (`origin = automation_step_run:{id}`), and the event carries that. The step run
 * is resolved INSIDE the event's Business to the workflow and causation depth
 * that produced it; EnrollFromContactTagEvent's source then (a) never re-triggers
 * the producing workflow off its own output, and (b) enrolls any other workflow at
 * depth + 1, which EnrollmentService refuses beyond MAX_CAUSATION_DEPTH. So
 * "tag added → remove tag" paired with "tag removed → add tag" ping-pongs at most
 * a few links rather than forever, while a tag a person adds is depth 0 and never
 * suppressed. This is the engine's existing causation mechanism, not a new one.
 */
class ContactTagTriggerSource extends FoundationTriggerSource
{
    /** The causation reference TagManager callers attach for an automation write. */
    public const ORIGIN_PREFIX = 'automation_step_run:';

    protected function assertServes(WorkflowTriggerType $triggerType): void
    {
        if (! $triggerType->isContactTag()) {
            throw new \InvalidArgumentException('A contact tag trigger source serves only contact tag triggers.');
        }
    }

    /** The causation reference for one claimed step run. */
    public static function originFor(int $stepRunId): string
    {
        return self::ORIGIN_PREFIX . $stepRunId;
    }

    /**
     * Handle one Contact Tag event.
     *
     * @return array{enrolled: int, skipped: array<string, int>}
     */
    public function handle(ContactTagEvent $event): array
    {
        $result = $this->emptyResult();

        $expected = match (true) {
            $event instanceof ContactTagAdded => WorkflowTriggerType::ContactTagAdded,
            $event instanceof ContactTagRemoved => WorkflowTriggerType::ContactTagRemoved,
            default => null,
        };

        if ($expected !== $this->triggerType) {
            return $this->skip($result, self::SKIPPED_NO_FACT);
        }

        // The tag must be a tag of the event's Business. Archived tags still
        // announce removals, so archival is not a reason to refuse.
        $tagExists = DB::table('tags')
            ->where('id', $event->tagId)
            ->where('business_id', $event->businessId)
            ->exists();

        if (! $tagExists) {
            return $this->skip($result, self::SKIPPED_NO_FACT);
        }

        $tagId = $event->tagId;

        return $this->enrollListening(
            $result,
            $event->businessId,
            $event->contactId,
            $event->occurrenceKey(),
            // "Any tag" when the filter is absent; otherwise exactly that tag.
            fn (array $config): bool => ($wanted = self::filterId($config['tag_id'] ?? null)) === null || $wanted === $tagId,
            $this->causeOf($event),
            // The Location is the Contact's AS OF the mutation, which the event
            // captured under TagManager's row lock — never the tag's (it has none)
            // and never re-read from the Contact now. A Location-bound workflow
            // takes only exactly this; a Contact with none enrolls no bound one.
            $event->locationId,
        );
    }

    /**
     * The workflow and depth of the automation step that made this change, or
     * null for a person's own change — or for a reference that does not resolve
     * to a step run inside this Business, which is treated as no mark at all.
     *
     * @return array{workflow_id: int, depth: int}|null
     */
    private function causeOf(ContactTagEvent $event): ?array
    {
        $origin = (string) $event->origin;

        if (! str_starts_with($origin, self::ORIGIN_PREFIX)) {
            return null;
        }

        $stepRunId = substr($origin, strlen(self::ORIGIN_PREFIX));

        if (! ctype_digit($stepRunId) || (int) $stepRunId <= 0) {
            return null;
        }

        $producer = DB::table('automation_step_runs as s')
            ->join('automation_enrollments as e', 'e.id', '=', 's.enrollment_id')
            ->where('s.id', (int) $stepRunId)
            ->where('e.business_id', $event->businessId)
            ->first(['e.workflow_id', 'e.causation_depth']);

        return $producer === null
            ? null
            : ['workflow_id' => (int) $producer->workflow_id, 'depth' => (int) $producer->causation_depth];
    }
}
