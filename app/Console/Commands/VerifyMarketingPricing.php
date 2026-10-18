<?php

namespace App\Console\Commands;

use App\Library\PlatformBilling\BillingCycle;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

    /** Compare the plan-comparison table with the catalog's feature packaging. True when a claim is wrong. */
    private function verifyFeatureClaims(): bool
    {
        $tiers = array_keys((array) config('marketing.plans'));
        $keys = (array) config('marketing.plan_feature_keys');
        $mismatches = [];

        foreach ($tiers as $tier) {
            $catalog = WorkspacePlanCatalog::query()->where('tier', $tier)->first();
            $has = $catalog === null ? [] : DB::table('workspace_plan_features')->where('workspace_plan_catalog_id', $catalog->id)->pluck('feature_key')->all();

            foreach ((array) config('marketing.plan_comparison') as $group) {
                foreach ($group['rows'] as $row) {
                    $key = $keys[$row[0]] ?? null;
                    $claim = $row[1][array_search($tier, $tiers, true)] ?? null;

                    if ($key === null || $claim === 'confirm') {
                        continue;
                    }

                    if ((bool) $claim !== in_array($key, $has, true)) {
                        $mismatches[] = [ucfirst($tier), $row[0], $claim ? 'claims included' : 'claims not included', in_array($key, $has, true) ? 'catalog includes it' : 'catalog lacks it'];
                    }
                }
            }
        }

        if ($mismatches !== []) {
            $this->error('Plan comparison does not match the catalog packaging:');
            $this->table(['Plan', 'Feature', 'Website', 'Catalog'], $mismatches);
        } else {
            $this->line('Plan comparison matches the catalog feature packaging.');
        }

        return $mismatches !== [];
    }

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

        $failed = $this->verifyFeatureClaims() || $failed;

        foreach (WorkspacePlanCatalog::query()->where('trial_enabled', true)->pluck('tier') as $tier) {
            $this->warn('Catalog tier '.($tier->value ?? $tier).' has a trial enabled. The website never mentions it, but check that the signup flow is meant to offer it.');
        }

        $this->line('The website never mentions a trial or a private offer; this check does not read either.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
