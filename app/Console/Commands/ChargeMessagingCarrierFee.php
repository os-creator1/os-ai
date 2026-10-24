<?php

namespace App\Console\Commands;

use App\Library\Messaging\ChargeCarrierFee;
use App\Models\Business;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Bills a carrier charge that was actually incurred (for example a $15
 * 10DLC campaign review) to the Business it belongs to, at the carrier's
 * exact cost times the owner's multiplier. Idempotent per --operation-id;
 * refuses when the pass-through price is unconfigured or the wallet cannot
 * pay (never creates debt silently). Nothing is charged by --dry-run.
 */
class ChargeMessagingCarrierFee extends Command
{
    protected $signature = 'messaging:charge-carrier-fee
        {business : Business id}
        {carrier-cost-micro : the carrier invoice amount in micro-USD (15.00 = 15000000)}
        {--operation-id= : a unique id for this carrier charge, e.g. the Telnyx invoice line (required)}
        {--actor-user-id= : platform administrator authorizing the charge (required)}
        {--reason= : what the carrier charged for (required)}
        {--dry-run : show what would be billed; charge nothing}';

    protected $description = 'Explicitly bill a real carrier charge (e.g. a campaign review) to the owning Business at cost.';

    public function handle(ChargeCarrierFee $fees): int
    {
        $amount = (string) $this->argument('carrier-cost-micro');
        $operationId = trim((string) $this->option('operation-id'));
        $reason = trim((string) $this->option('reason'));
        $actor = $this->option('actor-user-id');

        if (! ctype_digit($amount) || (int) $amount < 1) {
            $this->error('carrier-cost-micro must be a positive whole number.');

            return self::FAILURE;
        }

        if ($operationId === '' || $reason === '' || $actor === null || ! ctype_digit((string) $actor) || ! User::query()->whereKey((int) $actor)->value('is_admin')) {
            $this->error('--operation-id, --reason and an administrator --actor-user-id are all required.');

            return self::FAILURE;
        }

        $business = Business::query()->find((int) $this->argument('business'));

        if ($business === null) {
            $this->error('Business not found.');

            return self::FAILURE;
        }

        $this->line("Business #{$business->id}: carrier cost {$amount} micro-USD — {$reason} (operation {$operationId}, authorized by user {$actor})");

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was charged.');

            return self::SUCCESS;
        }

        $outcome = $fees->charge($business, (int) $amount, $operationId);

        switch ($outcome['status']) {
            case 'charged':
                $this->info('Charged to the Business wallet.');

                return self::SUCCESS;
            case 'already_charged':
                $this->info('This operation id was already charged — nothing more was billed.');

                return self::SUCCESS;
            case 'not_configured':
                $this->error('The carrier pass-through price is not configured (run messaging:sync-provisioning-rates). Nothing was charged.');

                return self::FAILURE;
            default:
                $this->error('Refused: ' . ($outcome['reason'] ?? 'unknown') . '. Nothing was charged; the Business wallet was not changed.');

                return self::FAILURE;
        }
    }
}
