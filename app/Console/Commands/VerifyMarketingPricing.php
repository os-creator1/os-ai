<?php

namespace App\Console\Commands;

use App\Library\PlatformBilling\BillingCycle;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Console\Command;

/**
 * Read-only. Compares the prices the public marketing website shows (config/marketing.php)
 * with the authoritative plan catalog (workspace_plan_catalog), so the website can never
 * quietly advertise a price the platform does not charge. Changes nothing, calls no provider.
 *
 *   php artisan marketing:verify-pricing
 *
 * Exit code 1 when any public plan is missing from the catalog, is priced differently, is not on the
 * 28-day cycle, or is not currently sellable.
 */
class VerifyMarketingPricing extends Command
{
    protected $signature = 'marketing:verify-pricing';

    protected $description = 'Verify the public marketing prices against the plan catalog (read-only)';

    public function handle(): int
    {
        $rows = [];
        $failed = false;

        foreach ((array) config('marketing.plans') as $tier => $plan) {
            $catalog = WorkspacePlanCatalog::query()->where('tier', $tier)->first();
            $problems = [];

            if ($catalog === null) {
                $problems[] = 'not in the plan catalog';
            } else {
                if ($catalog->price === null || (float) $catalog->price !== (float) $plan['price']) {
                    $problems[] = 'catalog price is '.($catalog->price === null ? 'unset' : $catalog->price).', website shows '.$plan['price'];
                }

                if ($catalog->billing_cycle !== BillingCycle::EVERY_28_DAYS || ($plan['cycle'] ?? null) !== BillingCycle::EVERY_28_DAYS) {
                    $problems[] = 'billing cycle is '.($catalog->billing_cycle ?: 'unset').', website says every 28 days';
                }

                if (! $catalog->isSellable()) {
                    $problems[] = 'not currently sellable (inactive, closed to signup, unpriced or no provider price)';
                }
            }

            $failed = $failed || $problems !== [];
            $rows[] = [
                $plan['name'],
                '$'.$plan['price'].' / 28 days',
                $catalog?->price !== null ? $catalog->price.' / '.$catalog->billing_cycle : '—',
                $problems === [] ? 'OK' : implode('; ', $problems),
            ];
        }

        $this->table(['Plan', 'Website', 'Catalog', 'Result'], $rows);

        $this->line('The website never mentions a trial or a private offer; this check does not read either.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
