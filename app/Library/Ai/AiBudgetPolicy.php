<?php

namespace App\Library\Ai;

/**
 * Contract §11.1 — the resolved policy for one Workspace at the moment of
 * a call. `workspaceCapMicrousd = 0` means every call is refused
 * (unassigned, inactive or suspended plan, §11.1) — the gateway needs no
 * special case for that: zero cap fails the ordinary reserve check.
 */
final readonly class AiBudgetPolicy
{
    public function __construct(
        public string $policyKey,
        public int $policyVersion,
        public string $periodKey,
        public int $workspaceCapMicrousd,
        public ?int $businessCapMicrousd,
        public int $interactiveShareBps,
    ) {
    }
}
