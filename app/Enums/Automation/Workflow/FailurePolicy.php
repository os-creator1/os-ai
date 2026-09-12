<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §7.6 — what happens to an enrollment when a step fails.
 *
 * Owner decision D2: `Halt` only. A failed action ends the journey, so no
 * follow-up is sent after a failed first message — the conservative reading of
 * B4's "a duplicate automated message is worse than a missed one."
 *
 * This is an enum with one case on purpose. A `continue` policy is a real
 * product option, and when it is authorized it becomes a second case here plus
 * one branch in the advancer, rather than a boolean somebody has to interpret.
 * Like the enrollment policy, it is part of the VERSIONED definition, so
 * changing it never alters a journey already underway.
 */
enum FailurePolicy: string
{
    case Halt = 'halt';

    public function haltsEnrollment(): bool
    {
        return $this === self::Halt;
    }

    public static function default(): self
    {
        return self::Halt;
    }
}
