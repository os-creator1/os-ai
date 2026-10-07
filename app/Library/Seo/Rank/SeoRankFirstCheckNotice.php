<?php

namespace App\Library\Seo\Rank;

use App\Models\Business;

/**
 * The ONE place that decides what an owner is told right after rank tracking
 * starts (or resumes) for a keyword — so the words always match what the
 * system will actually do.
 *
 * Tracking a keyword takes a slot and queues the scheduler, but a paid check
 * only runs when the budget authority allows it. Saying "the first check is on
 * its way" while the provider is switched off, or while there is nothing to
 * match our own listing against, would be false; so the sentence is chosen from
 * the real state, in this order:
 *
 *   provider off / not configured -> no check can run (providerState())
 *   usage period paused           -> the check waits for the period to reset
 *   no domain and no phone        -> nothing could be matched, so nothing is bought
 *   no domain                     -> local only; organic needs the website domain
 *   otherwise                     -> "on its way"
 *
 * It reads state and returns a sentence: no provider call, no spend, no write.
 */
final class SeoRankFirstCheckNotice
{
    public function __construct(private readonly SeoRankTrackingBudget $budget)
    {
    }

    public function forBusiness(Business $business): string
    {
        if ($this->budget->providerState() !== SeoRankTrackingBudget::PROVIDER_ENABLED) {
            return 'Rank checks are not available right now, so no check will run yet. This keyword stays tracked and will be checked once they are available.';
        }

        if ($this->budget->isPausedBySpend($business)) {
            return 'Rank checks are paused until your usage period resets, so the first check will wait.';
        }

        $identity = SeoRankIdentity::forBusiness($business);

        if (! $identity->canMatchLocal()) {
            return 'No check will run yet. ' . SeoRankDashboardReader::NO_IDENTITY_REASON . '.';
        }

        if (! $identity->canMatchOrganic()) {
            return 'The first local check is on its way. ' . SeoRankDashboardReader::NO_DOMAIN_REASON . '.';
        }

        return 'The first check is on its way.';
    }
}
