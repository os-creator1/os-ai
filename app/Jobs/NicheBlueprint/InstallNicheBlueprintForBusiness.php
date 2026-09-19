<?php

namespace App\Jobs\NicheBlueprint;

use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Contract 20 §9.1 — the ONE idempotent entry point both initial-provisioning
 * triggers dispatch into.
 *
 * DUPLICATE INVOCATION IS HARMLESS BY CONSTRUCTION, which is why the two
 * triggers need no coordination, no de-duplication flag and no ordering
 * guarantee between them: §7.2's `UNIQUE (business_id, blueprint_id,
 * component_key)` plus its re-read-under-lock already make a second run a
 * no-op. In organic onboarding both triggers may fire; exactly one installs
 * and the other finds every record already written.
 *
 * Receives only a scalar id and reloads the Business fresh, so a stale
 * dispatch-time snapshot can never be trusted (the established job
 * convention — `BuildInitialBusinessSnapshot` states the same rule).
 *
 * `tries = 1`: the installer is internally idempotent and already handles
 * per-component failure durably (§7.4), so the recovery path for a lost or
 * failed run is the same documented idempotent re-run — the other trigger, or
 * `blueprint:install-missing` — rather than a blind queue retry that would
 * re-enter adapters for components already decided.
 */
class InstallNicheBlueprintForBusiness implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $businessId)
    {
    }

    public function handle(NicheBlueprintInstaller $installer): void
    {
        $business = Business::query()->whereKey($this->businessId)->first();

        // The Business was deleted between dispatch and execution. Nothing to
        // install, and nothing to report: this is an ordinary outcome, not a
        // failure worth surfacing to the queue.
        if ($business === null) {
            return;
        }

        $installer->installForBusiness($business);
    }
}
