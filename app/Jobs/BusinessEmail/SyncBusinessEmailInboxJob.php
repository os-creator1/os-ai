<?php

namespace App\Jobs\BusinessEmail;

use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\BusinessEmailInboundSyncer;
use App\Models\BusinessEmailAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Syncs one connected mailbox's inbox. At most ONE pending job per account
 * (the uniqueness lock is released when the job starts, so a continuation can
 * be queued from inside handle() without being swallowed by its own lock),
 * and the syncer's per-account cache lock keeps two RUNNING syncs from
 * overlapping. The continuation exists only while the provider reports more
 * to read, so the queue cannot grow without bound; the 5-minute scheduler
 * tick remains the safety net.
 */
class SyncBusinessEmailInboxJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
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
