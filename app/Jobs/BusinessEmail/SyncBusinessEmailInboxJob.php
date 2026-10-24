<?php

namespace App\Jobs\BusinessEmail;

use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\BusinessEmailInboundSyncer;
use App\Models\BusinessEmailAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Syncs one connected mailbox's inbox; unique per account so runs never overlap. */
class SyncBusinessEmailInboxJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $accountId)
    {
    }

    public function uniqueId(): string
    {
        return 'business-email-inbox-' . $this->accountId;
    }

    public function handle(BusinessEmailInboundSyncer $syncer): void
    {
        $account = BusinessEmailAccount::query()->find($this->accountId);

        if ($account === null) {
            return;
        }

        try {
            $result = $syncer->sync($account);
        } catch (BusinessEmailProviderException $exception) {
            // Revoked / permission problems are already recorded on the account
            // and must not be retried; only temporary failures are.
            if ($exception->category->isRetryable() && $this->attempts() < $this->tries) {
                $this->release(60 * $this->attempts());
            }

            return;
        }

        if ($result['has_more']) {
            static::dispatch($this->accountId)->delay(now()->addSeconds(5));
        }
    }
}
