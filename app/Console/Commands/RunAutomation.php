<?php

namespace App\Console\Commands;

use App\Enums\Automation\AutomationTriggerType;
use App\Jobs\AutomationJob;
use App\Models\Automation;
use Illuminate\Console\Command;

/**
 * B4 Business Automations — the retained five-minute scheduler sweep
 * (contract §10; registered at app/Console/Kernel.php:88).
 *
 * Selects ONLY active, Business-scoped CONTACT_DATE_REACHED automations —
 * `business_id IS NOT NULL` is what makes every legacy NULL-business row
 * inert (§3.5). The legacy Automation::start() birthday path is no longer
 * reachable from here; each selected automation gets one queued
 * AutomationJob on the existing `automation` queue, which does the actual
 * due-contact evaluation and durable claiming. CONTACT_CREATED never goes
 * through this sweep (it enters via the after-commit hook).
 */
class RunAutomation extends Command
{
    protected $signature = 'automation:run';

    protected $description = 'Evaluate active Business-scoped date-reached automations';

    public function handle(): int
    {
        Automation::query()
            ->where('status', Automation::STATUS_ACTIVE)
            ->where('trigger_type', AutomationTriggerType::ContactDateReached->value)
            ->whereNotNull('business_id')
            ->orderBy('id')
            ->chunkById(50, function ($automations): void {
                foreach ($automations as $automation) {
                    dispatch(AutomationJob::forDateSweep((int) $automation->id));
                }
            });

        return self::SUCCESS;
    }
}
