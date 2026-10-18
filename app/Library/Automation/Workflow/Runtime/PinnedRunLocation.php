<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Library\Automation\Workflow\WorkflowLocationScope;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowVersion;
use App\Models\Contacts;

/**
 * The one question every Location-sensitive action asks before it touches a
 * Contact: "does this journey's pinned Location still hold?"
 *
 * A RUN IS NEVER MULTI-LOCATION. Whatever the workflow's scope — one Location or a
 * list of selected ones — enrollment pinned the ONE Location of the triggering
 * fact (`automation_enrollments.business_location_id`), once, and that pin is what
 * every action works at. The scope only decided which facts were admitted.
 *
 * It matters for a journey whose pinned VERSION is bound to Locations; a
 * Business-wide version has nothing to hold and gets no answer here (null). For a
 * bound one the action may proceed only when
 *
 *   - the enrollment's pinned Location is one the version's scope admits (it can
 *     only be otherwise if a row was tampered with), and
 *   - the Contact is still AT that pinned Location.
 *
 * A Contact who has moved on is never acted on under the old scope, and the
 * action never uses the Contact's NEW Location instead: it is skipped with a
 * deterministic reason. The version's scope is read from the pinned version row,
 * never from node config.
 */
final class PinnedRunLocation
{
    public const RUN_MISMATCH = 'run_location_mismatch';

    public const CONTACT_MOVED = 'contact_outside_workflow_location';

    /** A resource (a booking type, a deal) that belongs to a different Location than the run's. */
    public const RESOURCE_OUTSIDE = 'resource_outside_workflow_location';

    /** The Location scope of the version this journey is pinned to. */
    public static function scopeOf(AutomationEnrollment $enrollment): WorkflowLocationScope
    {
        $version = AutomationWorkflowVersion::query()->find((int) $enrollment->version_id);

        // A version that cannot be read admits nothing: never Business-wide.
        return $version === null
            ? new WorkflowLocationScope(WorkflowLocationScope::ONE, [])
            : $version->scope();
    }

    /** The one Location this journey was pinned to when it enrolled, or null. */
    public static function pinned(AutomationEnrollment $enrollment): ?int
    {
        return $enrollment->business_location_id === null ? null : (int) $enrollment->business_location_id;
    }

    /**
     * Whether a resource that belongs to ONE Location (a booking type, a deal) may
     * be used by this journey: when the journey is pinned to a Location the resource
     * must be that one's. A resource with no Location of its own (a deal that never
     * had one) is Business-level and is not refused here; an unpinned journey has no
     * Location to disagree with.
     *
     * Checked in addition to violation(), never instead of it.
     */
    public static function resourceViolation(AutomationEnrollment $enrollment, ?int $resourceLocationId): ?string
    {
        $pinned = self::pinned($enrollment);

        if ($pinned !== null && $resourceLocationId !== null && $resourceLocationId !== $pinned) {
            return self::RESOURCE_OUTSIDE;
        }

        return null;
    }

    /** The reason this action must not run on this Contact, or null when it may. */
    public static function violation(AutomationEnrollment $enrollment, Contacts $contact): ?string
    {
        $scope = self::scopeOf($enrollment);

        if ($scope->isBusinessWide()) {
            return null;
        }

        $pinned = self::pinned($enrollment);

        if (! $scope->allows($pinned)) {
            return self::RUN_MISMATCH;
        }

        if ($contact->location_id === null || (int) $contact->location_id !== $pinned) {
            return self::CONTACT_MOVED;
        }

        return null;
    }
}
