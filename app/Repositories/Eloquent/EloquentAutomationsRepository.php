<?php

namespace App\Repositories\Eloquent;

use App\Models\Automation;
use App\Repositories\Contracts\AutomationsRepository;

/**
 * B4 Business Automations — see the contract interface. Each method
 * mutates ONLY the exact, already-Business-resolved instance it is
 * handed; there is deliberately no lookup, no `whereIn`, and no batch
 * surface left here (contract §12.2).
 */
class EloquentAutomationsRepository extends EloquentBaseRepository implements AutomationsRepository
{
    public function __construct(Automation $automations)
    {
        parent::__construct($automations);
    }

    public function enable(Automation $automation): Automation
    {
        $automation->forceFill(['status' => Automation::STATUS_ACTIVE, 'reason' => null])->save();

        return $automation;
    }

    public function disable(Automation $automation): Automation
    {
        $automation->forceFill(['status' => Automation::STATUS_INACTIVE])->save();

        return $automation;
    }

    public function delete(Automation $automation): bool
    {
        // automation_executions cascade on the FK; legacy tracking_logs /
        // reports rows likewise cascade on their own automation_id FKs.
        return (bool) $automation->delete();
    }
}
