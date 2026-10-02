<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowVersion;
use App\Models\Contacts;

/**
 * The one question every Location-sensitive action asks before it touches a
 * Contact: "does this journey's pinned Location still hold?"
 *
 * It matters only for a journey whose pinned VERSION is bound to a Location;
 * a Business-wide version has nothing to hold and gets no answer here (null).
 * For a bound one the action may proceed only when
 *
 *   - the enrollment's pinned Location is the version's own (they can only differ
 *     if a row was tampered with), and
 *   - the Contact is still AT that Location.
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

    /** The Location the pinned version is bound to, or null when it is Business-wide. */
    public static function boundTo(AutomationEnrollment $enrollment): ?int
    {
        $bound = AutomationWorkflowVersion::query()
            ->whereKey((int) $enrollment->version_id)
            ->value('business_location_id');

        return $bound === null ? null : (int) $bound;
    }

    /** The reason this action must not run on this Contact, or null when it may. */
    public static function violation(AutomationEnrollment $enrollment, Contacts $contact): ?string
    {
        $bound = self::boundTo($enrollment);

        if ($bound === null) {
            return null;
        }

        if ($enrollment->business_location_id === null || (int) $enrollment->business_location_id !== $bound) {
            return self::RUN_MISMATCH;
        }

        if ($contact->location_id === null || (int) $contact->location_id !== $bound) {
            return self::CONTACT_MOVED;
        }

        return null;
    }
}
