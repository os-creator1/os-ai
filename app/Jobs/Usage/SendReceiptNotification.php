<?php

namespace App\Jobs\Usage;

use App\Enums\Usage\FundingAttemptState;
use App\Exceptions\Usage\ReceiptEvidenceUnavailableException;
use App\Jobs\Base;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Notifications\Usage\ReceiptAvailableNotification;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Receipt Boundary Correction Contract §7 — orchestration only. Never
 * writes business_billing_receipts directly; delegates provider retrieval
 * and persistence to UsageBillingCheckoutManager::ensureFundingReceipt(),
 * whose own sole write path is UsageWalletManager::attachFundingReceipt().
 *
 * Dispatched from inside UsageWalletManager::creditFromFunding()'s own
 * transaction, after commit — accounting has always already succeeded by
 * the time this job ever runs; nothing here can undo it. Inherits Base's
 * $tries = 1 / $maxExceptions = 1 unchanged — a missing-evidence failure
 * is recoverable only via a manual/operator re-dispatch of the same two
 * ids, never an automatic retry count this job invents.
 */
class SendReceiptNotification extends Base implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly int $fundingAttemptId,
        private readonly int $ledgerEntryId,
    ) {
    }

    public function handle(
        BusinessFundingAttemptRepository $attemptRepository,
        BusinessUsageLedgerEntryRepository $ledgerRepository,
        BusinessBillingContactRepository $billingContactRepository,
        UsageBillingCheckoutManager $checkoutManager,
    ): void {
        $attempt = $attemptRepository->findById($this->fundingAttemptId);

        if ($attempt === null) {
            throw new \RuntimeException("Funding attempt {$this->fundingAttemptId} not found.");
        }

        if ($attempt->state !== FundingAttemptState::Succeeded) {
            throw new \RuntimeException("Funding attempt {$this->fundingAttemptId} is not Succeeded.");
        }

        $ledgerEntry = $ledgerRepository->findById($this->ledgerEntryId);

        if ($ledgerEntry === null
            || (int) $ledgerEntry->funding_attempt_id !== (int) $attempt->id
            || (int) $ledgerEntry->business_id !== (int) $attempt->business_id
        ) {
            throw new \InvalidArgumentException("Ledger entry {$this->ledgerEntryId} does not match funding attempt {$this->fundingAttemptId}.");
        }

        $receipt = $checkoutManager->ensureFundingReceipt($attempt, $this->ledgerEntryId);

        if ($receipt === null) {
            throw new ReceiptEvidenceUnavailableException($this->fundingAttemptId, $this->ledgerEntryId);
        }

        $contact = $billingContactRepository->findByBusinessId((int) $attempt->business_id);

        if ($contact === null) {
            Log::info('Receipt notification skipped: no billing contact configured.', ['business_id' => $attempt->business_id]);

            return;
        }

        if (! $contact->notification_opt_in) {
            Log::info('Receipt notification skipped: billing contact opted out.', ['business_id' => $attempt->business_id]);

            return;
        }

        if ($contact->contact_user_id === null) {
            $email = $contact->contact_email;
        } else {
            $email = $contact->contactUser?->email;
        }

        if (blank($email)) {
            Log::warning('Receipt notification skipped: no usable recipient email.', ['business_id' => $attempt->business_id]);

            return;
        }

        Notification::route('mail', $email)->notify(new ReceiptAvailableNotification($receipt->provider_receipt_url));
    }
}
