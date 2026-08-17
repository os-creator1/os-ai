<?php

namespace App\Jobs\Usage;

use App\Enums\Usage\FundingAttemptState;
use App\Jobs\Base;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;

/**
 * M3 contract §11 item 10 — finds funding attempts stuck in
 * provider_pending/requires_action past a bounded age and independently
 * re-queries the provider for their true current state, rather than
 * waiting indefinitely for a webhook that may never arrive. Every
 * provider call happens outside any wallet lock (the attempt is only
 * ever briefly locked by UsageBillingCheckoutManager's own confirmation
 * path, never held across the outbound call here).
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

        foreach ($stuck as $attempt) {
            $checkoutManager->confirmAttemptFromReturn($attempt);
        }
    }
}
