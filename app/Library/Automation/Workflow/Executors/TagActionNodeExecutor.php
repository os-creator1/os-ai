<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Automation\Workflow\Runtime\ClaimedStepRun;
use App\Library\Automation\Workflow\Triggers\ContactTagTriggerSource;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Library\Crm\TagManager;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\Tag;
use Throwable;

/**
 * The shared body of the two tag actions, "Add a tag" and "Remove a tag".
 *
 * THE WRITE PATH IS NOT NEW. Both reach a Contact's tags through exactly one
 * door, the Contact Tags foundation's TagManager (attachTag / detachMembership).
 * No `contact_tags` row is written here and no event is dispatched here: the
 * manager owns the membership, its uniqueness, its row locks and its after-commit
 * ContactTagAdded / ContactTagRemoved event.
 *
 * TENANCY IS RE-DERIVED, NEVER TRUSTED. The Contact arrived through the
 * advancer's checkpoint, which already proved it belongs to this Business; the
 * Tag id comes from the pinned node config, which outlives whatever was true when
 * it was published, so it is re-read here filtered on the run's Business. A tag
 * of another Business, a deleted one, and a forged id are indistinguishable and
 * write nothing. TagManager then re-derives both again under its own locks.
 *
 * IDEMPOTENT BY THE MANAGER'S CONTRACT. Adding a tag the Contact already holds,
 * or removing one it does not, writes nothing and emits no event — so replaying
 * a step, or two workflows adding the same tag, converges harmlessly. That is why
 * these are the IdempotentDatabase side-effect class.
 *
 * LOOP PREVENTION. The write is made with the claimed step run as its causation
 * reference (`origin = automation_step_run:{id}`), which rides on the resulting
 * tag event; ContactTagTriggerSource resolves it to this workflow and its
 * causation depth. See that class for what then refuses a loop.
 */
abstract class TagActionNodeExecutor implements NodeExecutor
{
    public function __construct(protected readonly TagManager $tags)
    {
    }

    /** Perform the one domain mutation. @return string the safe result summary */
    abstract protected function apply(Business $business, Contacts $contact, Tag $tag, ?string $origin): string;

    /** Whether an archived tag may still be the subject of this action. */
    abstract protected function acceptsArchivedTag(): bool;

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $config = is_array($node->config) ? $node->config : [];
        $tagId = $config['tag_id'] ?? null;

        if (! (is_int($tagId) || (is_string($tagId) && ctype_digit($tagId))) || (int) $tagId <= 0) {
            return NodeExecutionOutcome::skipped('tag_config_invalid');
        }

        $tag = Tag::query()->where('business_id', (int) $business->id)->whereKey((int) $tagId)->first();

        if ($tag === null) {
            return NodeExecutionOutcome::skipped('tag_not_in_business');
        }

        if ($tag->isArchived() && ! $this->acceptsArchivedTag()) {
            return NodeExecutionOutcome::skipped('tag_archived');
        }

        $stepRunId = ClaimedStepRun::idFor($node, $enrollment);

        try {
            $summary = $this->apply(
                $business,
                $contact,
                $tag,
                $stepRunId === null ? null : ContactTagTriggerSource::originFor($stepRunId),
            );
        } catch (CrmRuleException) {
            // The manager refused (the contact or tag no longer belongs to the
            // Business, or the tag was archived between the read and its lock).
            return NodeExecutionOutcome::skipped('tag_refused');
        } catch (Throwable $exception) {
            return NodeExecutionOutcome::failed('tag_exception: ' . class_basename($exception));
        }

        return NodeExecutionOutcome::succeeded($summary);
    }
}
