<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §7.5 — whether a workflow's enrollment policy is the
 * trigger's default or a deliberate human choice.
 *
 * This exists so a trigger change can never silently alter meaning. If the
 * source is `Default`, changing the trigger updates the policy to the new
 * trigger's default. If it is `User`, the builder must ask before changing it,
 * and the validator refuses to publish a policy that differs from the trigger's
 * default while still claiming to be a default — that combination can only mean
 * a stale default carried over from a previous trigger.
 *
 * A deliberate `once_ever` on a date trigger (a one-time "one year with us"
 * message) is perfectly valid — but only as `User`.
 */
enum EnrollmentPolicySource: string
{
    case Default = 'default';
    case User = 'user';
}
