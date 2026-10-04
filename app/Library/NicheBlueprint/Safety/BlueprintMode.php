<?php

namespace App\Library\NicheBlueprint\Safety;

/**
 * Blueprint Safety Mode — request/process-scoped flag, set while a Platform
 * Owner is inside the Blueprint Workspace.
 *
 * The Workspace has no Business, so nothing in it has a customer to message or
 * charge — but a niche is configured by pretending to be a business, and "the
 * Workspace can never reach a real person" must not depend on every future
 * screen remembering that. While this is active, BlueprintSafetyGuard refuses
 * at the real choke points (SMS dispatch, mail, notifications, payments,
 * Stripe Connect, provider HTTP, Google Ads, website publish, booking).
 *
 * Bound as a singleton; the Workspace middleware enters it and always leaves
 * it, including on exceptions.
 */
final class BlueprintMode
{
    private int $depth = 0;

    public function enter(): void
    {
        $this->depth++;
    }

    public function leave(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    public function active(): bool
    {
        return $this->depth > 0;
    }

    /** Runs $callback with the mode active, always leaving it afterwards. */
    public function within(callable $callback): mixed
    {
        $this->enter();

        try {
            return $callback();
        } finally {
            $this->leave();
        }
    }
}
