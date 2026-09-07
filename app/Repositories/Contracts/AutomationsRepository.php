<?php

namespace App\Repositories\Contracts;

use App\Models\Automation;

/**
 * B4 Business Automations — reduced to the three single-record lifecycle
 * mutations the M2 UI needs (contract §12). Every method receives an
 * Automation the controller has ALREADY resolved inside an explicit
 * Business; nothing here ever looks a record up by request-supplied id.
 *
 * The legacy `automationBuilder()` and the unscoped `batchEnable()` /
 * `batchDisable()` / `batchDelete()` methods are removed outright rather
 * than re-secured (§12.2): a global `whereIn('uid', $requestIds)` mutation
 * is permanently forbidden.
 */
interface AutomationsRepository extends BaseRepository
{
    public function enable(Automation $automation): Automation;

    public function disable(Automation $automation): Automation;

    public function delete(Automation $automation): bool;
}
