<?php

namespace App\Console\Commands;

use App\Library\Usage\UsageWalletManager;
use Illuminate\Console\Command;

/**
 * Customer Experience Slice 5 (contract §12.2 "alerts before thresholds")
 * — announces, once per period, every Business whose monthly spending
 * has reached the alert share of its limit. Orchestration only: the
 * decision and the once-per-period marker live in UsageWalletManager.
 * Safe to run on any schedule; a repeat run in the same period sends
 * nothing.
 */
class SendUsageSpendingThresholdAlerts extends Command
{
    protected $signature = 'usage:spending-threshold-alerts';

    protected $description = 'Notify billing contacts whose Business is approaching or has reached its monthly spending limit (once per period).';

    public function handle(UsageWalletManager $walletManager): int
    {
        $sent = $walletManager->sendSpendingThresholdAlerts();

        $this->info("Spending threshold alerts sent: {$sent}.");

        return self::SUCCESS;
    }
}
