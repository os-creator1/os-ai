<?php

namespace App\Library\Usage;

use App\Enums\Usage\FundingAttemptState;

/**
 * RFC-005 Remediation #6 §18 — the typed outcome result every refund/
 * dispute orchestration method returns, driving the terminal
 * ProcessPaymentProviderEvent attribution write. Never a job-side
 * recomputation — every field here is read directly into $attribution.
 *
 * Four distinct, unambiguous amount fields (never a single ambiguous
 * "reported"/"applied" pair):
 *  - reportedAmountMicro: the provider's own reported figure (a refund's
 *    bounded cumulative total, or a dispute balance transaction's own
 *    reported amount) — unchanged by whether this event is a replay.
 *  - outcomeDeltaMicro: the newly-accepted, idempotent financial-outcome
 *    progress this run recorded; 0 on a pure replay; equals the newly
 *    written ledger row's own gross_amount_micro, including for a
 *    direct_deliverable zero-wallet-delta outcome.
 *  - walletDeltaMicro: the actual, positive-magnitude wallet-balance
 *    movement this event caused; 0 for direct_deliverable/replay;
 *    strictly less than outcomeDeltaMicro for a policy-excess refund.
 *  - policyExcessMicro: only the newly-accepted refund delta that could
 *    not be honored as a cash refund; 0 otherwise.
 */
final readonly class ProviderOutcomeResult
{
    public function __construct(
        public string $normalizedOutcome,
        public int $reportedAmountMicro,
        public int $outcomeDeltaMicro,
        public int $walletDeltaMicro,
        public int $policyExcessMicro,
        public ?int $ledgerEntryId,
        public FundingAttemptState $resultingState,
    ) {
    }
}
