<?php

namespace App\Console\Commands;

use App\Enums\PlatformOwner\AccountClosureState;
use App\Library\PlatformOwner\AccountDeletion\PlatformAccountClosureManager;
use App\Models\PlatformAccountClosure;
use Illuminate\Console\Command;
use Throwable;

/**
 * Retries every unfinished customer-account closure (provider cancellation
 * that failed, a carrier number that has since been released, ...). Each run
 * is idempotent and claim-guarded in PlatformAccountClosureManager, so this
 * can overlap an operator's Retry button safely. A closure that is still
 * blocked simply stays Pending cancellation with its list of outstanding items.
 */
class AdvancePlatformAccountClosures extends Command
{
    protected $signature = 'platform:advance-account-closures';

    protected $description = 'Retry unfinished Platform Owner customer-account closures';

    public function handle(PlatformAccountClosureManager $manager): int
    {
        $advanced = 0;
        $failed = 0;

        PlatformAccountClosure::query()->where('state', AccountClosureState::Closing->value)->orderBy('id')->each(function (PlatformAccountClosure $closure) use ($manager, &$advanced, &$failed) {
            try {
                $manager->resume($closure->uid);
                $advanced++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        });

        $this->info("Closures advanced: {$advanced}, failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
