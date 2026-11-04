<?php

namespace App\Console\Commands;

use App\Enums\BusinessEmail\BusinessEmailAccountState;
use App\Jobs\BusinessEmail\SyncBusinessEmailInboxJob;
use App\Library\BusinessEmail\BusinessEmailInboundSyncer;
use App\Models\BusinessEmailAccount;
use Illuminate\Console\Command;

class SyncBusinessEmailInboxes extends Command
{
    protected $signature = 'business-email:sync-inbound';

    protected $description = 'Queue an inbound sync for every active connected mailbox.';

    public function handle(BusinessEmailInboundSyncer $syncer): int
    {
        BusinessEmailAccount::query()
            ->where('state', BusinessEmailAccountState::Active->value)
            ->whereNotNull('refresh_token_encrypted')
            ->orderBy('id')
            ->each(function (BusinessEmailAccount $account) use ($syncer): void {
                // Only mailboxes that can actually be read: the provider supports
                // inbound AND the stored grant carries the read permission (or the
                // provider needs none, as with IMAP).
                if ($syncer->canSync($account)) {
                    SyncBusinessEmailInboxJob::dispatch((int) $account->id);
                }
            });

        return self::SUCCESS;
    }
}
