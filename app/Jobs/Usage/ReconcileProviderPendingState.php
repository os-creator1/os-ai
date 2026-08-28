<?php

namespace App\Jobs\Usage;

use App\Enums\Usage\FundingAttemptState;
use App\Jobs\Base;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * M3 contract §11 item 10 — finds funding attempts stuck in
 * provider_pending/requires_action past a bounded age and independently
 * re-queries the provider for their true current state, rather than
 * waiting indefinitely for a webhook that may never arrive. Every
 * provider call happens outside any wallet lock (the attempt is only
 * ever briefly locked by UsageBillingCheckoutManager's own confirmation
 * path, never held across the outbound call here).
 *
 * RFC-005 Reconciliation-Race Correction Contract §2 — the initially
 * loaded collection can go stale between load and iteration (a webhook
 * may confirm/fail/cancel an attempt after `$stuck` is loaded but before
 * the loop reaches it); each iteration re-fetches persisted state
 * immediately before confirming, and the narrow ledger-correlation
 * UniqueConstraintViolationException from a genuinely simultaneous
 * confirmation race (§1 Tier 2) is caught so this one recognized
 * collision cannot abort reconciliation of the remaining collection.
 */
class ReconcileProviderPendingState extends Base
{
    private const STUCK_AFTER_MINUTES = 30;

    public function handle(
        BusinessFundingAttemptRepository $attemptRepository,
        UsageBillingCheckoutManager $checkoutManager,
    ): void {
        $cutoff = now()->subMinutes(self::STUCK_AFTER_MINUTES);

        $stuck = \App\Models\BusinessFundingAttempt::query()
            ->whereIn('state', [FundingAttemptState::ProviderPending->value, FundingAttemptState::RequiresAction->value])
            ->where('updated_at', '<', $cutoff)
            ->whereNotNull('provider_session_or_intent_reference')
            ->get();

        foreach ($stuck as $staleAttempt) {
            $attempt = $attemptRepository->findById($staleAttempt->id);

            if ($attempt === null) {
                continue;
            }

            if (! in_array($attempt->state, [FundingAttemptState::ProviderPending, FundingAttemptState::RequiresAction], true)) {
                continue;
            }

            try {
                $checkoutManager->confirmAttemptFromReturn($attempt);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }
    }
}
