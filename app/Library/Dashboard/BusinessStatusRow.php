<?php

namespace App\Library\Dashboard;

use App\Enums\Dashboard\AttentionType;
use Carbon\CarbonImmutable;

/**
 * Customer Experience Slice 4 §5.2 / §7 — one Business's wallet, website and
 * Google status, exactly as DashboardStatusReader read it. Money stays an
 * integer micro-unit string (never a float), as on the Usage & Billing page.
 *
 * A Business with no wallet row, no website row or no Google connection simply
 * has nothing to say about that part: absence is never an attention item.
 */
final class BusinessStatusRow
{
    public function __construct(
        public readonly int $businessId,
        public readonly bool $hasWallet,
        public readonly ?string $billingStatus,
        public readonly string $debtBalanceMicro,
        public readonly bool $paidActivityPaused,
        public readonly string $availableBalanceMicro,
        public readonly ?string $autoRechargeThresholdMicro,
        public readonly bool $autoRechargeEnabled,
        public readonly int $consecutiveRechargeFailures,
        public readonly string $committedSpendThisPeriodMicro,
        public readonly ?CarbonImmutable $spendPeriodEndUtc,
        public readonly ?string $monthlySpendCapMicro,
        public readonly ?string $currencyCode,
        public readonly ?string $websiteStatus,
        public readonly ?string $googleConnectionState,
        public readonly int $unhealthyGoogleLocations,
        // Launch-readiness facts (HomeSetupJourney). Each is a boolean read of a canonical table inside the
        // one status statement, so Home pays no extra query for them. Defaults keep older callers valid.
        public readonly bool $hasPrimaryLocation = false,
        public readonly bool $hasPrimaryService = false,
        public readonly bool $hasLiveForm = false,
        public readonly bool $hasActiveWebsiteForm = false,
        public readonly bool $hasCompletedExternalAudit = false,
        // Business-wide count of ACTIVE Locations, so a caller can tell whether an actor-scoped Calendar summary
        // covers every Location or only the ones that actor may reach.
        public readonly int $activeLocationCount = 0,
        // Which Home readiness policy governs this Business (businesses.readiness_policy): 'seo_first_v1' or null (legacy).
        public readonly ?string $readinessPolicy = null,
        // False for a placeholder row: nothing was actually read, so no readiness fact may be trusted.
        public readonly bool $known = true,
        // True when the customer's onboarding wizard is bound to this very Business, so its steps edit THIS Business.
        public readonly bool $onboardingBound = false,
    ) {
    }

    /** A Business the reader found no row for at all. */
    public static function empty(int $businessId): self
    {
        return new self($businessId, false, null, '0', false, '0', null, false, 0, '0', null, null, null, null, null, 0, false, false, false, false, false, 0, null, false);
    }

    /**
     * The status-derived attention types, each from the one column §5.2
     * proves. AutomationFailing is not here: it comes from B5, never from a
     * status column.
     *
     * @return array<int, AttentionType>
     */
    public function attentionTypes(): array
    {
        $types = [];

        if ($this->hasWallet) {
            if ($this->billingStatus === 'suspended') {
                $types[] = AttentionType::WalletSuspended;
            }

            if (bccomp($this->debtBalanceMicro, '0') > 0) {
                $types[] = AttentionType::OutstandingDebt;
            }

            if ($this->paidActivityPaused) {
                $types[] = AttentionType::PaidActivityPaused;
            }

            if ($this->autoRechargeThresholdMicro !== null
                && bccomp($this->availableBalanceMicro, $this->autoRechargeThresholdMicro) < 0) {
                $types[] = AttentionType::LowBalance;
            }

            if ($this->consecutiveRechargeFailures > 0) {
                $types[] = AttentionType::AutoRechargeFailing;
            }
        }

        if ($this->websiteStatus === 'draft') {
            $types[] = AttentionType::WebsiteUnpublished;
        }

        if (in_array($this->googleConnectionState, ['revoked', 'disconnected'], true)) {
            $types[] = AttentionType::GoogleConnectionLost;
        }

        if ($this->unhealthyGoogleLocations > 0) {
            $types[] = AttentionType::GoogleLocationUnhealthy;
        }

        return $types;
    }

    /**
     * Spend committed in the wallet's current spend period. The wallet rolls
     * its period over lazily, on its next paid operation, once now() reaches
     * spend_period_end_utc (UsageWalletManager::rollOverPeriodsIfNeeded());
     * until then the stored figure belongs to a period that has ended, so it
     * reads as nothing spent yet — never last period's total.
     */
    public function spentThisPeriodMicro(?CarbonImmutable $now = null): string
    {
        if ($this->spendPeriodEndUtc === null) {
            return '0';
        }

        $now ??= CarbonImmutable::now('UTC');

        return $now->lt($this->spendPeriodEndUtc) ? $this->committedSpendThisPeriodMicro : '0';
    }
}
