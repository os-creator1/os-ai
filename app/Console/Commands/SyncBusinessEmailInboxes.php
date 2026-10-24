<?php

namespace App\Console\Commands;

use App\Enums\BusinessEmail\BusinessEmailAccountState;
use App\Jobs\BusinessEmail\SyncBusinessEmailInboxJob;
use App\Models\BusinessEmailAccount;
use Illuminate\Console\Command;

class SyncBusinessEmailInboxes extends Command
{
    protected $signature = 'business-email:sync-inbound';

    protected $description = 'Queue an inbound sync for every active connected mailbox.';

    public function handle(): int
    {
        BusinessEmailAccount::query()
            ->where('state', BusinessEmailAccountState::Active->value)
            ->whereNotNull('refresh_token_encrypted')
            ->where('provider', 'google')
            ->where('granted_scopes', 'like', '%gmail.readonly%')
            ->orderBy('id')
            ->each(fn (BusinessEmailAccount $account) => SyncBusinessEmailInboxJob::dispatch((int) $account->id));

        return self::SUCCESS;
    }
}
