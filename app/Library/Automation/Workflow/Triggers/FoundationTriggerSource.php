<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Contracts\TriggerSource;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\Contacts;

/**
 * The shared tail of the merged-foundation trigger sources (Contact Tags, Forms,
 * Calendar appointments).
 *
 * Each concrete source owns the part that differs — which domain event it
 * consumes, how it re-derives the fact from that domain's own rows, and what its
 * trigger-node filters mean. What is identical lives here, once:
 *
 *   1. THE CONTACT IS RE-DERIVED, scoped to the fact's Business. A contact id
 *      that is absent, or belongs to another Business, enrolls nobody; a journey
 *      is always one contact's.
 *   2. THE LISTENERS are the Business's own published workflows on this trigger
 *      (ListeningWorkflows), narrowed by the source's filter.
 *   3. THE CLAIM is EnrollmentService, with the domain event's own deterministic
 *      occurrence key. A redelivered event composes the same key and loses the
 *      same unique claim, so duplicate delivery enrolls once.
 *   4. CAUSATION. When the fact was produced by another automation step, the
 *      producing workflow never re-triggers itself off its own output, and the
 *      new journey inherits the producer's depth plus one — the existing
 *      MessageReceivedTriggerSource rules, applied to this domain's facts.
 *
 * Nothing here calls a domain service or writes a domain row.
 */
abstract class FoundationTriggerSource implements TriggerSource
{
    public const SKIPPED_NO_FACT = 'no_matching_fact';
    public const SKIPPED_NO_CONTACT = 'no_contact';
    public const SKIPPED_NOT_ENROLLED = 'not_enrolled';
    public const SKIPPED_SELF_TRIGGER = 'self_trigger';
    public const SKIPPED_CAUSATION_DEPTH = 'causation_depth';

    public function __construct(
        protected readonly EnrollmentService $enrollments,
        protected readonly ListeningWorkflows $listening,
        protected readonly WorkflowTriggerType $triggerType,
    ) {
        $this->assertServes($triggerType);
    }

    /** Refuse a trigger type this concrete source does not serve. */
    abstract protected function assertServes(WorkflowTriggerType $triggerType): void;

    public function triggerType(): WorkflowTriggerType
    {
        return $this->triggerType;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Enroll the contact into every listening workflow the filter admits.
     *
     * @param \Closure(array<string, mixed>): bool|null $admits trigger-node filter
     * @param array{workflow_id: int, depth: int}|null $cause the automation step
     *        that produced this fact, when one did
     * @param array{enrolled: int, skipped: array<string, int>} $result
     *
     * @return array{enrolled: int, skipped: array<string, int>}
     */
    protected function enrollListening(
        array $result,
        int $businessId,
        ?int $contactId,
        string $occurrenceKey,
        ?\Closure $admits = null,
        ?array $cause = null,
    ): array {
        $contact = $contactId === null
            ? null
            : Contacts::query()->where('business_id', $businessId)->whereKey($contactId)->first();

        if ($contact === null) {
            return $this->skip($result, self::SKIPPED_NO_CONTACT);
        }

        foreach ($this->listening->for($businessId, $this->triggerType, $admits) as $workflow) {
            if ($cause !== null && $cause['workflow_id'] === (int) $workflow->getKey()) {
                $result = $this->skip($result, self::SKIPPED_SELF_TRIGGER);

                continue;
            }

            $depth = $cause === null ? 0 : $cause['depth'] + 1;

            if ($depth > WorkflowLimits::MAX_CAUSATION_DEPTH) {
                $result = $this->skip($result, self::SKIPPED_CAUSATION_DEPTH);

                continue;
            }

            $enrollment = $this->enrollments->enroll($workflow, $contact, $occurrenceKey, $depth);

            // Null is EnrollmentService's own refusal: the same fact replayed, the
            // contact still part-way through, the workflow paused since.
            if ($enrollment === null) {
                $result = $this->skip($result, self::SKIPPED_NOT_ENROLLED);

                continue;
            }

            $result['enrolled']++;
            AdvanceWorkflowEnrollment::dispatch((int) $enrollment->getKey());
        }

        return $result;
    }

    /** @return array{enrolled: int, skipped: array<string, int>} */
    protected function emptyResult(): array
    {
        return ['enrolled' => 0, 'skipped' => []];
    }

    /**
     * @param array{enrolled: int, skipped: array<string, int>} $result
     * @return array{enrolled: int, skipped: array<string, int>}
     */
    protected function skip(array $result, string $reason): array
    {
        $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;

        return $result;
    }

    /** An optional filter id from a trigger config: null means "any". */
    protected static function filterId(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0
            ? (int) $value
            : null;
    }
}
