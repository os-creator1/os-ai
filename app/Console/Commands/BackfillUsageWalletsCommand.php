<?php

namespace App\Console\Commands;

use App\Exceptions\Usage\UsageWalletBackfillIncompleteException;
use App\Library\Usage\Migration\UsageWalletBackfillV1;
use Illuminate\Console\Command;

/**
 * Thin operator-facing wrapper around UsageWalletBackfillV1 (M1 contract
 * §9.2). Contains no backfill algorithm of its own. Safe to run
 * repeatedly; the action itself is idempotent, and also serves as the
 * retry path for any Business whose wallet initialization failed via the
 * ongoing-creation listener (M1 contract §9.3).
 */
class BackfillUsageWalletsCommand extends Command
{
    protected $signature = 'usage:backfill-wallets {--dry-run : List Businesses missing a wallet and whether each could be repaired; writes nothing}';

    protected $description = 'Create a business_usage_wallets row for every existing Business lacking one (RFC-005 M1)';

    public function handle(UsageWalletBackfillV1 $backfill): int
    {
        if ($this->option('dry-run')) {
            $rows = $backfill->preview();
            $this->info('Dry run — nothing was written. Businesses missing a wallet: ' . count($rows));

            foreach ($rows as $row) {
                $this->line("  business_id={$row['business_id']} currency_code={$row['currency_code']} " . ($row['resolvable'] ? 'repairable' : "NOT repairable ({$row['classification']})"));
            }

            return self::SUCCESS;
        }

        try {
            $result = $backfill->run();
        } catch (UsageWalletBackfillIncompleteException $e) {
            $this->error($e->getMessage());

            foreach ($e->currencyResolutionFailures as $failure) {
                $this->line("  business_id={$failure['business_id']} classification={$failure['classification']}");
            }

            return self::FAILURE;
        }

        $this->info('Usage wallet backfill complete.');
        $this->line("businesses processed={$result->businessesProcessed}");
        $this->line("wallets created={$result->walletsCreated}");
        $this->line("remaining unwalleted count={$result->remainingUnwalletedCount}");

        foreach ($result->currencyResolutionFailures as $failure) {
            $this->warn("  currency unresolved: business_id={$failure['business_id']} classification={$failure['classification']}");
        }

        return self::SUCCESS;
    }
}
