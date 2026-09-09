<?php

namespace App\Library\Usage;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Entitlement\EntitlementManager;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Enums\Usage\BillingStatusTransitionSource;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Enums\Usage\RoundingRule;
use App\Enums\Usage\UsageLedgerEntryType;
use App\Enums\Usage\UsageLimitType;
use App\Enums\Usage\UsageReservationStatus;
use App\Enums\Usage\WalletBillingStatus;
use App\Events\Usage\BusinessWalletBillingStatusChanged;
use App\Exceptions\Usage\BusinessCurrencyUnresolvableException;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Exceptions\Usage\FeatureLimitExceedsPlatformSafetyLimitException;
use App\Exceptions\Usage\InvalidAdminCreditAmountException;
use App\Exceptions\Usage\InvalidAdminCreditEntryTypeException;
use App\Exceptions\Usage\InvalidAdminCreditOperationIdException;
use App\Exceptions\Usage\InvalidAdminCreditReasonException;
use App\Exceptions\Usage\InvalidReservationStateTransitionException;
use App\Exceptions\Usage\ManualCreditOperationConflictException;
use App\Exceptions\Usage\NoActiveRateForFeatureException;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Exceptions\Usage\UsageMeterBusinessScopeMismatchException;
use App\Exceptions\Usage\UsageMeterCurrencyMismatchException;
use App\Exceptions\Usage\UsageMeterNotMeteredException;
use App\Exceptions\Usage\UsageMeterRateIntegrityException;
use App\Exceptions\Usage\UsageReservationNotFoundException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Models\Business;
use App\Models\BusinessUsageLedgerEntry;
use App\Models\BusinessUsageReservation;
use App\Models\BusinessUsageWallet;
use App\Models\Currency;
use App\Models\BusinessBillingReceipt;
use App\Repositories\Contracts\BusinessBillingReceiptRepository;
use App\Repositories\Contracts\BusinessFeatureUsageLimitRepository;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
use App\Repositories\Contracts\BusinessUsageMeasurementRepository;
use App\Models\BusinessUsageMeasurement;
use App\Repositories\Contracts\BusinessUsageLimitTransitionRepository;
use App\Repositories\Contracts\BusinessUsageRateActivationRepository;
use App\Repositories\Contracts\BusinessUsageRateRepository;
use App\Repositories\Contracts\BusinessUsageReservationRepository;
use App\Repositories\Contracts\BusinessUsageWalletBillingStatusTransitionRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PlatformFeatureUsageSafetyLimitRepository;
use App\Repositories\Contracts\UsageMeterRepository;
use App\Repositories\Contracts\UsageMeterTransitionRepository;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Models\Workspace;
use App\Notifications\Usage\AutoRechargeFailedNotification;
use App\Notifications\Usage\SpendingLimitReachedNotification;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Sole write authority for all seven RFC-005 Milestone 1 tables (M1
 * contract §10.1), extended at M2 (creditFromFunding() debt-clearing
 * formula) and M3 (configureAutoRecharge(), and the
 * EvaluateBusinessAutoRecharge::dispatch() trigger — M3 contract §15,
 * item 100). Dispatched only after the owning DB::transaction() closure
 * returns, never from inside an open transaction/lock, and only when the
 * write that just occurred actually produced a negative
 * available_delta_micro (reserve()'s reservation insert; commit()'s
 * overage-from-available portion) — never for an idempotent no-op repeat,
 * a zero-amount reservation, or an overage fully absorbed by debt.
 */
class UsageWalletManager
{
    private const RESERVATION_TTL_MINUTES = 30;

    /**
     * Customer Experience Slice 5 (contract §12.2, §28.9) — the manual
     * top-up floor: exactly 5.00 units of the wallet's currency, in the
     * repository's micro-unit convention (1 major unit = 1 000 000 micro).
     * Enforced here at the manager boundary and again by
     * InitiateTopUpRequest; $4.99 (4 990 000) is refused, $5.00 accepted.
     */
    public const MINIMUM_MANUAL_TOP_UP_MICRO = 5_000_000;

    /**
     * The only automatic top-up amounts that exist (contract §12.2). A
     * custom amount is prohibited server-side until §28.9's bounds are
     * approved by the owner; the recommended $5–$500 range is deliberately
     * NOT implemented.
     */
    public const AUTO_RECHARGE_PRESETS_MICRO = [5_000_000, 10_000_000, 25_000_000, 50_000_000];

    /**
     * Customer Experience Slice 5, Correction Round 1 §2/§3 — the owner-
     * approved automatic top-up policy, in one place. Every request rule,
     * manager check, job decision and view figure reads these constants;
     * no other numeric literal for this policy exists in the codebase.
     *
     * - The suggested preset is only a visual preselection on the page
     *   (never persisted, never consent, never a charge).
     * - The two monthly maxima are automatic-charge safety maxima, not
     *   default ceilings: the payer must deliberately choose a ceiling at
     *   or below them before any automatic charge can run.
     * - At most AUTO_RECHARGE_MAX_PER_ROLLING_WINDOW automatically
     *   INITIATED top-ups per Business inside any rolling
     *   AUTO_RECHARGE_ROLLING_WINDOW_HOURS, on exact timestamps (an
     *   attempt counts while created_at > now - window; exactly
     *   window-old no longer counts). Correction Round 2: the slot is
     *   consumed by the creation of the attempt row and held for the whole
     *   window whatever the attempt's outcome — a declined attempt already
     *   contacted the provider — while its MONEY is released as soon as it
     *   fails or is canceled. Frequency and monetary headroom are two
     *   separate calculations.
     */
    public const AUTO_RECHARGE_SUGGESTED_PRESET_MICRO = 5_000_000;
    public const BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO = 500_000_000;
    public const WORKSPACE_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO = 500_000_000;
    public const AUTO_RECHARGE_MAX_PER_ROLLING_WINDOW = 2;
    public const AUTO_RECHARGE_ROLLING_WINDOW_HOURS = 24;

    /** Automatic top-up refusal reasons (policy refusals, never payment failures). */
    public const DENIAL_BUSINESS_RECHARGE_CAP = 'business_recharge_cap';
    public const DENIAL_BUSINESS_RECHARGE_CAP_MISSING = 'business_recharge_cap_missing';
    public const DENIAL_WORKSPACE_RECHARGE_CAP_MISSING = 'workspace_recharge_cap_missing';
    public const DENIAL_AUTO_RECHARGE_FREQUENCY = 'auto_recharge_frequency';
    public const AUTO_RECHARGE_REFUSAL_REASONS = [
        self::DENIAL_BUSINESS_RECHARGE_CAP,
        self::DENIAL_BUSINESS_RECHARGE_CAP_MISSING,
        'workspace_recharge_cap',
        self::DENIAL_WORKSPACE_RECHARGE_CAP_MISSING,
        self::DENIAL_AUTO_RECHARGE_FREQUENCY,
    ];

    /** Reservation denial reasons introduced by this slice (contract §12.2, §20 C-10). */
    public const DENIAL_PAID_ACTIVITY_PAUSED = 'paid_activity_paused';

    public const DENIAL_WORKSPACE_PAID_ACTIVITY_PAUSED = 'workspace_paid_activity_paused';

    public const DENIAL_WORKSPACE_SPEND_CAP = 'workspace_spend_cap';

    public const DENIAL_WORKSPACE_RECHARGE_CAP = 'workspace_recharge_cap';

    public const DENIAL_BELOW_MINIMUM_TOP_UP = 'below_minimum_top_up';

    /** usage_control_transitions.control values written by this slice. */
    public const CONTROL_BUSINESS_PAID_ACTIVITY = 'business_paid_activity';

    public const CONTROL_WORKSPACE_PAID_ACTIVITY = 'workspace_paid_activity';

    public const CONTROL_WORKSPACE_SPEND_CAP = 'workspace_aggregate_spend_cap';

    public const CONTROL_WORKSPACE_RECHARGE_CAP = 'workspace_aggregate_recharge_cap';

    /** Alert once per period when consumption reaches this share of a limit. */
    private const SPENDING_THRESHOLD_ALERT_PERCENT = 80;

    public function __construct(
        private readonly BusinessUsageWalletRepository $walletRepository,
        private readonly BusinessUsageRateRepository $rateRepository,
        private readonly BusinessUsageRateActivationRepository $rateActivationRepository,
        private readonly UsageMeterRepository $meterRepository,
        private readonly UsageMeterTransitionRepository $meterTransitionRepository,
        private readonly BusinessUsageReservationRepository $reservationRepository,
        private readonly BusinessUsageLedgerEntryRepository $ledgerRepository,
        private readonly BusinessFeatureUsageLimitRepository $featureLimitRepository,
        private readonly PlatformFeatureUsageSafetyLimitRepository $safetyLimitRepository,
        private readonly BusinessUsageLimitTransitionRepository $limitTransitionRepository,
        private readonly BusinessUsageWalletBillingStatusTransitionRepository $billingStatusTransitionRepository,
        private readonly BusinessBillingReceiptRepository $receiptRepository,
        // Customer Experience Slice 3 §4.8 — additive dependency only; no
        // existing parameter is modified, reordered or removed.
        private readonly BusinessUsageMeasurementRepository $measurementRepository,
    ) {
    }

    /**
     * Customer Experience Slice 3 §4.8 — record that a measurable quantity of
     * a feature was consumed, WITHOUT pricing it.
     *
     * This is deliberately not an accounting call. It takes no reservation,
     * writes no ledger entry, activates no rate and creates no
     * platform_feature_usage_classifications row; it only records quantity
     * and unit against a Business, idempotently by $idempotencyKey.
     *
     * Kept here, on the manager, because RFC-005 requires UsageWalletManager
     * to be the single write authority for usage-billing-adjacent state — and
     * it delegates the write to the repository rather than touching the table
     * itself, following this class's own established
     * manager-calls-repository layering.
     *
     * $quantity is a decimal-safe string, matching reserve()'s own
     * ?string $estimatedQuantity convention — never a native float.
     */
    public function recordMeasurement(
        Business $business,
        PlatformFeature $featureKey,
        string $quantity,
        string $unit,
        string $idempotencyKey,
        ?string $transportMarker = null,
    ): BusinessUsageMeasurement {
        return $this->measurementRepository->recordOnce(
            $business,
            $featureKey,
            $quantity,
            $unit,
            $idempotencyKey,
            $transportMarker,
        );
    }

    /**
     * Idempotent — a Business that already has a wallet is a no-op.
     * Resolves currency_id exclusively from that Business's own
     * currency_code (M1 contract §5.5, Correction Round 1) — never a
     * platform-wide fallback. On resolution failure, no wallet and no
     * partial state is left behind.
     */
    public function initializeWalletForNewBusiness(int $businessId): void
    {
        if ($this->walletRepository->findByBusinessId($businessId) !== null) {
            return;
        }

        $business = Business::query()->findOrFail($businessId);
        $currencyId = $this->resolveCurrencyId($business);
        $timezone = $business->timezone !== '' && $business->timezone !== null
            ? $business->timezone
            : config('app.timezone');
        $now = Carbon::now();

        $spend = $this->computePeriodBoundaries($timezone, $now);
        $recharge = $this->computePeriodBoundaries($timezone, $now);

        try {
            DB::transaction(function () use ($businessId, $currencyId, $spend, $recharge) {
                $this->walletRepository->create([
                    'business_id' => $businessId,
                    'currency_id' => $currencyId,
                    'available_balance_micro' => 0,
                    'reserved_balance_micro' => 0,
                    'debt_balance_micro' => 0,
                    'spend_period_key' => $spend['key'],
                    'spend_period_start_utc' => $spend['start_utc'],
                    'spend_period_end_utc' => $spend['end_utc'],
                    'auto_recharge_enabled' => false,
                    'recharge_period_key' => $recharge['key'],
                    'recharge_period_start_utc' => $recharge['start_utc'],
                    'recharge_period_end_utc' => $recharge['end_utc'],
                    'committed_spend_this_period_micro' => 0,
                    'reserved_spend_this_period_micro' => 0,
                    'recharged_this_period_micro' => 0,
                    'consecutive_recharge_failures' => 0,
                    'billing_status' => WalletBillingStatus::Active->value,
                ]);
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateRace($e, 'business_usage_wallets_business_id_unique')) {
                return;
            }

            throw $e;
        }
    }

    /**
     * Resolves currency_id from that Business's own currency_code,
     * normalized identically to Currency::boot()'s own strtoupper() rule,
     * matched against currencies.code with status=true. Exactly one match
     * is required. Never a fallback (M1 contract §5.5, Correction Round 1).
     */
    private function resolveCurrencyId(Business $business): int
    {
        $normalizedCode = strtoupper((string) $business->currency_code);

        $matches = Currency::query()
            ->whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->where('status', true)
            ->get();

        if ($matches->count() === 0) {
            throw new BusinessCurrencyUnresolvableException(
                $business->id,
                BusinessCurrencyUnresolvableException::CLASSIFICATION_NOT_FOUND,
            );
        }

        if ($matches->count() > 1) {
            throw new BusinessCurrencyUnresolvableException(
                $business->id,
                BusinessCurrencyUnresolvableException::CLASSIFICATION_AMBIGUOUS,
            );
        }

        return (int) $matches->first()->id;
    }

    /**
     * Genuine calendar-month construction (RFC-005 §15) — never a
     * fixed-duration approximation. Reading the current local calendar
     * month directly from $now (rather than incrementing from a stale
     * period_start) means a wallet dormant for any number of months lands
     * correctly in one step (multi-month dormancy, no iteration).
     *
     * @return array{key: string, start_utc: Carbon, end_utc: Carbon}
     */
    private function computePeriodBoundaries(string $timezone, Carbon $now): array
    {
        $local = CarbonImmutable::instance($now)->setTimezone($timezone);
        $periodStartLocal = $local->startOfMonth();
        $periodEndLocal = $periodStartLocal->addMonthNoOverflow();

        return [
            'key' => $periodStartLocal->format('Y-m'),
            'start_utc' => Carbon::instance($periodStartLocal->setTimezone('UTC')),
            'end_utc' => Carbon::instance($periodEndLocal->setTimezone('UTC')),
        ];
    }

    /**
     * Lazily rolls the wallet's spend and recharge periods over
     * independently, whenever now() >= the relevant *_period_end_utc.
     * Reads, never re-rolls mid-operation once read for the current call
     * (M1 contract §10.3). No-op (returns the same wallet, unmodified in
     * the database) when neither period needs rolling.
     */
    private function rollOverPeriodsIfNeeded(BusinessUsageWallet $wallet, Business $business): BusinessUsageWallet
    {
        $timezone = $business->timezone !== '' && $business->timezone !== null
            ? $business->timezone
            : config('app.timezone');
        $now = Carbon::now();
        $update = [];

        if ($now->gte($wallet->spend_period_end_utc)) {
            $spend = $this->computePeriodBoundaries($timezone, $now);
            $update['spend_period_key'] = $spend['key'];
            $update['spend_period_start_utc'] = $spend['start_utc'];
            $update['spend_period_end_utc'] = $spend['end_utc'];
            $update['committed_spend_this_period_micro'] = 0;
            $update['reserved_spend_this_period_micro'] = 0;
        }

        if ($now->gte($wallet->recharge_period_end_utc)) {
            $recharge = $this->computePeriodBoundaries($timezone, $now);
            $update['recharge_period_key'] = $recharge['key'];
            $update['recharge_period_start_utc'] = $recharge['start_utc'];
            $update['recharge_period_end_utc'] = $recharge['end_utc'];
            $update['recharged_this_period_micro'] = 0;
        }

        if ($update === []) {
            return $wallet;
        }

        return $this->walletRepository->update($wallet, $update);
    }

    /**
     * RFC-005 Reservation Admission Correction Contract §4/§5 — evaluates
     * one admission control's headroom as a non-negative quantity,
     * consistently for the per-feature limit, the Business spend cap, and
     * the platform safety limit alike. $configuredLimitMicro === null
     * means the control is unconfigured and always allows (headroom
     * null, not evaluated). $denialReason is embedded directly into the
     * returned CapEvaluation only on denial, since only the caller knows
     * which of the three controls this particular call represents.
     * max(0, ...) is the entire fix for the case where consumption
     * already exceeds a since-tightened limit: headroom clamps to
     * exactly zero rather than going negative, which is what keeps a
     * zero-amount candidate always allowed and never fabricates a denial
     * for reasons rooted in already-historical spend.
     */
    private function evaluateHeadroom(?int $configuredLimitMicro, int $consumptionMicro, int $candidateMicro, string $denialReason): CapEvaluation
    {
        if ($configuredLimitMicro === null) {
            return new CapEvaluation(true, null, null);
        }

        $headroomMicro = max(0, $configuredLimitMicro - $consumptionMicro);

        if ($candidateMicro > $headroomMicro) {
            return new CapEvaluation(false, $denialReason, (string) $headroomMicro);
        }

        return new CapEvaluation(true, null, (string) $headroomMicro);
    }

    /**
     * RFC-005 §13's reserve() algorithm. RFC-005 Reservation Admission
     * Correction Contract §6 — full wallet-admission order, all six
     * steps: billing_status -> outstanding_debt -> per-feature limit ->
     * Business spend cap -> platform safety limit -> available-balance
     * sufficiency. Three of these six were M1's original scope:
     * billing_status, outstanding_debt, and available-balance sufficiency;
     * the per-feature limit, Business spend cap, and platform safety
     * limit were M2-designed but never connected here until this
     * correction — M1 contract §8 item 1 deferred them only because their
     * tables did not exist yet, not because they are out of reserve()'s
     * own scope. M3 contract §15 — dispatches EvaluateBusinessAutoRecharge
     * after commit, only for a genuine new negative-available_delta_micro
     * reservation (never the idempotent-repeat early return above, and
     * never a zero-amount reservation).
     */
    public function reserve(Business $business, string $featureKey, string $idempotencyKey, ?string $estimatedQuantity = null): ReservationResult
    {
        $existing = $this->reservationRepository->findByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            return new ReservationResult(true, $existing->id, null, false);
        }

        $shouldDispatchAutoRecharge = false;
        $shouldDispatchLowBalanceNotification = false;
        $spendingLimitAlertReason = null;

        // RFC-005 Milestone 5 §3.8 correction — the race-loser catch must
        // surround DB::transaction() itself, not sit inside the closure.
        // DB::transaction() rolls the losing transaction back completely
        // (and rethrows) before this catch ever runs, so the narrow
        // constraint-name match and refetch below only ever happen against
        // a fully-closed transaction — never while the loser's own
        // transaction is still open.
        try {
            $result = DB::transaction(function () use ($business, $featureKey, $idempotencyKey, $estimatedQuantity, &$shouldDispatchAutoRecharge, &$shouldDispatchLowBalanceNotification, &$spendingLimitAlertReason) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId($business->id);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException($business->id);
            }

            $wallet = $this->rollOverPeriodsIfNeeded($wallet, $business);

            // Customer Experience Slice 5 (contract §12.2 E-20, §20 C-1,
            // T-CAP-5) — the emergency stops are evaluated first, under the
            // wallet lock, before any meter, cap or balance work and
            // therefore before any caller could reach a provider. They stop
            // NEW cost-producing work only: existing reservations keep
            // their normal commit/release/expiry lifecycle and no ledger
            // row is touched. Lock order is fixed — wallet row, then the
            // Workspace controls row — so two Businesses of one Workspace
            // serialize on the shared row and can never both consume the
            // final unit of the aggregate allowance (T-CAP-2, T-CAP-4).
            if ($wallet->paid_activity_paused_at !== null) {
                return new ReservationResult(false, null, self::DENIAL_PAID_ACTIVITY_PAUSED, false);
            }

            $workspaceControls = $this->lockWorkspaceControls((int) $business->workspace_id);

            if ($workspaceControls !== null && $workspaceControls->paid_activity_paused_at !== null) {
                return new ReservationResult(false, null, self::DENIAL_WORKSPACE_PAID_ACTIVITY_PAUSED, false);
            }

            $meter = $this->meterRepository->findByMeterKey($featureKey);

            if ($meter === null) {
                throw new NoActiveRateForFeatureException($featureKey);
            }

            if ($meter->business_id !== null && (int) $meter->business_id !== (int) $business->id) {
                throw new UsageMeterBusinessScopeMismatchException($featureKey, (int) $business->id);
            }

            if ((int) $wallet->currency_id !== (int) $meter->currency_id) {
                throw new UsageMeterCurrencyMismatchException($featureKey, (int) $wallet->currency_id, (int) $meter->currency_id);
            }

            if ($meter->active_rate_id === null) {
                throw new NoActiveRateForFeatureException($featureKey);
            }

            if (! $meter->is_metered) {
                throw new UsageMeterNotMeteredException($featureKey);
            }

            $rate = $this->rateRepository->findById((int) $meter->active_rate_id);

            if ($rate === null) {
                throw new NoActiveRateForFeatureException($featureKey);
            }

            if ($rate->meter_key !== $meter->meter_key || (int) $rate->currency_id !== (int) $meter->currency_id) {
                throw new UsageMeterRateIntegrityException($featureKey, (int) $rate->id);
            }

            $quantity = $estimatedQuantity ?? '1';
            $reservedAmountMicro = (int) self::bcRoundHalfUp(
                bcmul((string) $rate->retail_rate_micro, $quantity, 10),
                '1',
            );

            if ($wallet->billing_status === WalletBillingStatus::Suspended) {
                return new ReservationResult(false, null, 'wallet_suspended', false);
            }

            if ($wallet->debt_balance_micro > 0) {
                return new ReservationResult(false, null, 'outstanding_debt', false);
            }

            // RFC-005 Reservation Admission Correction Contract §4.B —
            // keyed by feature_key, never meter_key: Amendment 1 permits
            // multiple meter_keys to share one feature_key, and this is
            // the same consumption figure §4.C's platform safety limit
            // reuses below, so it is computed exactly once.
            $featureConsumptionMicro = $this->reservationRepository->sumPendingReservedAmountForFeature(
                (int) $business->id,
                $meter->feature_key,
                $wallet->spend_period_key,
            ) + $this->ledgerRepository->sumCommittedAmountForFeature(
                (int) $business->id,
                $meter->feature_key,
                $wallet->spend_period_key,
            );

            // Contract §8.B — the row-locking variant is required here:
            // this is the same Business+feature-scoped row setFeatureLimit()
            // already locks, so the two interoperate safely without any
            // cross-Business contention or deadlock risk.
            $featureLimit = $this->featureLimitRepository->findForUpdateByBusinessAndFeature((int) $business->id, $meter->feature_key);
            $featureLimitEvaluation = $this->evaluateHeadroom(
                $featureLimit?->monthly_limit_micro,
                $featureConsumptionMicro,
                $reservedAmountMicro,
                'feature_limit',
            );

            if (! $featureLimitEvaluation->allowed) {
                return new ReservationResult(false, null, $featureLimitEvaluation->denialReason, false);
            }

            // Contract §4.A — reuses the wallet's own already-correct
            // cached counters; no new query.
            $businessSpendCapEvaluation = $this->evaluateHeadroom(
                $wallet->monthly_spend_cap_micro,
                $wallet->committed_spend_this_period_micro + $wallet->reserved_spend_this_period_micro,
                $reservedAmountMicro,
                'business_spend_cap',
            );

            if (! $businessSpendCapEvaluation->allowed) {
                $spendingLimitAlertReason = $this->markSpendingLimitAlert($wallet, $businessSpendCapEvaluation->denialReason);

                return new ReservationResult(false, null, $businessSpendCapEvaluation->denialReason, false);
            }

            // Customer Experience Slice 5 (contract §12.2 E-19, T-CAP-2) —
            // the Workspace aggregate monthly limit covers every Business
            // whose usage the Workspace pays for. Evaluated with the same
            // exact-integer headroom rule as the Business cap, against the
            // sum of this period's committed + reserved spend of all
            // Workspace-paid wallets, while the Workspace controls row is
            // locked (above). Unconfigured, or a Business paying for
            // itself, never denies.
            if ($workspaceControls !== null && $workspaceControls->monthly_aggregate_spend_cap_micro !== null && $this->isWorkspacePaid((int) $business->id)) {
                $workspaceCapEvaluation = $this->evaluateHeadroom(
                    (int) $workspaceControls->monthly_aggregate_spend_cap_micro,
                    $this->workspacePaidSpendThisPeriod((int) $business->workspace_id, $wallet->spend_period_key),
                    $reservedAmountMicro,
                    self::DENIAL_WORKSPACE_SPEND_CAP,
                );

                if (! $workspaceCapEvaluation->allowed) {
                    $spendingLimitAlertReason = $this->markSpendingLimitAlert($wallet, self::DENIAL_WORKSPACE_SPEND_CAP);

                    return new ReservationResult(false, null, self::DENIAL_WORKSPACE_SPEND_CAP, false);
                }
            }

            // Contract §8.C — deliberately the plain, non-locking read:
            // locking this platform-global row here would serialize every
            // Business's reservations for this feature against one shared
            // row, which the contract explicitly forbids.
            $safetyLimit = $this->safetyLimitRepository->findByFeatureKey($meter->feature_key);
            $safetyLimitEvaluation = $this->evaluateHeadroom(
                $safetyLimit?->max_monthly_limit_micro,
                $featureConsumptionMicro,
                $reservedAmountMicro,
                'platform_safety_limit',
            );

            if (! $safetyLimitEvaluation->allowed) {
                return new ReservationResult(false, null, $safetyLimitEvaluation->denialReason, false);
            }

            if ($wallet->available_balance_micro < $reservedAmountMicro) {
                $spendingLimitAlertReason = $this->markSpendingLimitAlert($wallet, 'insufficient_balance');

                return new ReservationResult(false, null, 'insufficient_balance', false);
            }

            $reservedAt = Carbon::now();

            // RFC-005 Remediation #6 §6 — paid-first consumption: the
            // paid-attributable share of this reservation is removed from
            // the wallet's refundable_paid_available_micro counter now,
            // and durably snapshotted on the reservation row itself —
            // commit()/release() later consume or restore exactly this
            // stored value, never re-derived.
            $paidAttributable = min($reservedAmountMicro, max(0, $wallet->refundable_paid_available_micro));

            // RFC-005 Milestone 5 §3.8/§6 widening: idempotencyKey carries
            // a real database UNIQUE constraint
            // (business_usage_reservations_idempotency_key_unique). Two
            // concurrent invocations racing the same key can both pass the
            // pre-transaction findByIdempotencyKey() read above; only one
            // of them can win this insert. The loser lets this exception
            // propagate out of the transaction closure — no provider call
            // may ever be reachable from inside this still-open
            // transaction — and is handled only after DB::transaction()
            // below has fully rolled it back (outer try/catch).
            $reservation = $this->reservationRepository->create([
                'business_id' => $business->id,
                'wallet_id' => $wallet->id,
                'feature_key' => $meter->feature_key,
                'meter_key' => $meter->meter_key,
                'period_key' => $wallet->spend_period_key,
                'status' => UsageReservationStatus::Pending->value,
                'reserved_amount_micro' => $reservedAmountMicro,
                'paid_attributable_amount_micro' => $paidAttributable,
                'estimated_quantity' => $quantity,
                'rate_id' => $rate->id,
                'rate_version' => $rate->version,
                'retail_rate_micro' => $rate->retail_rate_micro,
                'provider_cost_micro' => $rate->provider_cost_micro,
                'rounding_rule' => $rate->rounding_rule->value,
                'idempotency_key' => $idempotencyKey,
                'correlation_key' => $idempotencyKey,
                'reserved_at' => $reservedAt,
                'expires_at' => $reservedAt->clone()->addMinutes(self::RESERVATION_TTL_MINUTES),
            ]);

            $this->ledgerRepository->create([
                'business_id' => $business->id,
                'wallet_id' => $wallet->id,
                'entry_type' => UsageLedgerEntryType::Reservation->value,
                'available_delta_micro' => -$reservedAmountMicro,
                'reserved_delta_micro' => $reservedAmountMicro,
                'debt_delta_micro' => 0,
                'refundable_paid_delta_micro' => -$paidAttributable,
                'currency_id' => $wallet->currency_id,
                'feature_key' => $reservation->feature_key,
                'meter_key' => $reservation->meter_key,
                'period_key' => $wallet->spend_period_key,
                'quantity' => $quantity,
                'rate_id' => $rate->id,
                'rate_version' => $rate->version,
                'retail_rate_micro' => $rate->retail_rate_micro,
                'provider_cost_micro' => $rate->provider_cost_micro,
                'unit_label' => $rate->unit_label,
                'rounding_rule' => $rate->rounding_rule->value,
                'reservation_id' => $reservation->id,
                'correlation_key' => $idempotencyKey.':reservation',
                'created_at' => $reservedAt,
            ]);

            if ($reservedAmountMicro > 0) {
                $shouldDispatchAutoRecharge = true;
            }

            $newAvailableBalanceMicro = $wallet->available_balance_micro - $reservedAmountMicro;

            $this->walletRepository->update($wallet, array_merge([
                'available_balance_micro' => $newAvailableBalanceMicro,
                'refundable_paid_available_micro' => $wallet->refundable_paid_available_micro - $paidAttributable,
                'reserved_balance_micro' => $wallet->reserved_balance_micro + $reservedAmountMicro,
                'reserved_spend_this_period_micro' => $wallet->reserved_spend_this_period_micro + $reservedAmountMicro,
            ], $this->lowBalanceMarkerUpdate($wallet, $newAvailableBalanceMicro, $shouldDispatchLowBalanceNotification)));

            \App\Events\Usage\BusinessUsageReserved::dispatch(
                (int) $business->id,
                (int) $reservation->id,
                $reservation->feature_key,
                $reservedAmountMicro,
            );

            return new ReservationResult(true, $reservation->id, null, true);
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->isDuplicateRace($e, 'business_usage_reservations_idempotency_key_unique')) {
                throw $e;
            }

            $winner = $this->reservationRepository->findByIdempotencyKey($idempotencyKey);

            if ($winner === null) {
                throw $e;
            }

            return new ReservationResult(true, $winner->id, null, false);
        }

        if ($shouldDispatchAutoRecharge) {
            EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        }

        if ($shouldDispatchLowBalanceNotification) {
            \App\Jobs\Usage\SendLowBalanceNotification::dispatch((int) $business->id);
        }

        // Customer Experience Slice 5 (contract §12.2 "alerts before
        // thresholds", §20 C-10) — a refusal caused by a spending limit or
        // an empty balance is announced to the billing contact once per
        // period, strictly after the (already rolled-back or committed)
        // transaction, never from inside it.
        if ($spendingLimitAlertReason !== null) {
            $this->notifyBillingContact((int) $business->id, new SpendingLimitReachedNotification(
                $business->name,
                $spendingLimitAlertReason,
                $this->customerMessageForDenial($spendingLimitAlertReason),
            ));
        }

        return $result;
    }

    /**
     * RFC-005 §13's commit() algorithm, using the corrected committed-
     * amount formula. Idempotent: a repeat commit on an already-committed
     * reservation is a no-op that reconstructs the original CommitResult.
     * M3 contract §15 — dispatches EvaluateBusinessAutoRecharge after
     * commit, only when the overage-charge entry's available_delta_micro
     * portion is genuinely negative (an overage fully absorbed by debt,
     * with zero taken from available balance, does not dispatch).
     */
    public function commit(int $reservationId, ?string $finalQuantity = null): CommitResult
    {
        $shouldDispatchAutoRecharge = false;
        $dispatchBusinessId = null;
        $shouldDispatchLowBalanceNotification = false;

        $result = DB::transaction(function () use ($reservationId, $finalQuantity, &$shouldDispatchAutoRecharge, &$dispatchBusinessId, &$shouldDispatchLowBalanceNotification) {
            $peek = $this->reservationRepository->findById($reservationId);

            if ($peek === null) {
                throw new UsageReservationNotFoundException($reservationId);
            }

            $wallet = $this->walletRepository->findForUpdateByBusinessId($peek->business_id);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException($peek->business_id);
            }

            $business = $wallet->business;
            $wallet = $this->rollOverPeriodsIfNeeded($wallet, $business);

            $reservation = $this->reservationRepository->findForUpdateById($reservationId);

            if ($reservation->status === UsageReservationStatus::Committed) {
                return new CommitResult(
                    $reservation->id,
                    (string) $reservation->final_amount_micro,
                    (string) $reservation->reserved_amount_micro,
                    (int) $reservation->final_amount_micro > (int) $reservation->reserved_amount_micro,
                    (int) $reservation->final_amount_micro < (int) $reservation->reserved_amount_micro,
                );
            }

            if ($reservation->status !== UsageReservationStatus::Pending) {
                throw new InvalidReservationStateTransitionException(
                    $reservation->id,
                    $reservation->status->value,
                    'commit',
                );
            }

            $dispatchBusinessId = (int) $reservation->business_id;

            $quantity = $finalQuantity ?? (string) $reservation->estimated_quantity;
            $finalAmountMicro = (int) self::bcRoundHalfUp(
                bcmul((string) $reservation->retail_rate_micro, $quantity, 10),
                '1',
            );
            $reservedAmountMicro = (int) $reservation->reserved_amount_micro;

            $committedAt = Carbon::now();
            $isCurrentPeriod = $reservation->period_key === $wallet->spend_period_key;

            $availableDelta = 0;
            $reservedDelta = 0;
            $debtDelta = 0;
            $committedFormulaAmount = 0;
            $hadOverage = false;
            $hadUnusedRelease = false;

            $overageLedgerEntry = null;
            $overageFromAvailable = 0;
            $overageToDebt = 0;
            $lowBalanceFragment = [];

            $chargedPortion = min($finalAmountMicro, $reservedAmountMicro);
            $paidAttributableMicro = (int) $reservation->paid_attributable_amount_micro;
            $refundablePaidDelta = 0;

            // RFC-005 Remediation #6 §6 — the committed portion's own
            // paid-attributable share was already removed from
            // refundable_paid_available_micro at reserve() time;
            // committing it merely converts a reservation into a
            // permanent charge and requires no further counter mutation.
            $this->ledgerRepository->create([
                'business_id' => $reservation->business_id,
                'wallet_id' => $wallet->id,
                'entry_type' => UsageLedgerEntryType::UsageCharge->value,
                'available_delta_micro' => 0,
                'reserved_delta_micro' => -$chargedPortion,
                'debt_delta_micro' => 0,
                'refundable_paid_delta_micro' => 0,
                'currency_id' => $wallet->currency_id,
                'feature_key' => $reservation->feature_key,
                'meter_key' => $reservation->meter_key,
                'period_key' => $reservation->period_key,
                'quantity' => $quantity,
                'rate_id' => $reservation->rate_id,
                'rate_version' => $reservation->rate_version,
                'retail_rate_micro' => $reservation->retail_rate_micro,
                'provider_cost_micro' => $reservation->provider_cost_micro,
                'rounding_rule' => $reservation->rounding_rule->value,
                'reservation_id' => $reservation->id,
                'correlation_key' => $reservation->correlation_key.':charge',
                'created_at' => $committedAt,
            ]);
            $reservedDelta -= $chargedPortion;
            $committedFormulaAmount += $chargedPortion;

            if ($finalAmountMicro > $reservedAmountMicro) {
                $hadOverage = true;
                $overage = $finalAmountMicro - $reservedAmountMicro;
                $overageFromAvailable = min($overage, max(0, $wallet->available_balance_micro));
                $overageToDebt = $overage - $overageFromAvailable;

                // RFC-005 Remediation #6 §6 — overage was never
                // pre-reserved, so its paid-attributable portion draws
                // directly against the wallet's current counter, not the
                // reservation's own snapshot.
                $overagePaidPortion = min($overageFromAvailable, max(0, $wallet->refundable_paid_available_micro));
                $refundablePaidDelta -= $overagePaidPortion;

                $overageLedgerEntry = $this->ledgerRepository->create([
                    'business_id' => $reservation->business_id,
                    'wallet_id' => $wallet->id,
                    'entry_type' => UsageLedgerEntryType::UsageOverageCharge->value,
                    'available_delta_micro' => -$overageFromAvailable,
                    'reserved_delta_micro' => 0,
                    'debt_delta_micro' => $overageToDebt,
                    'refundable_paid_delta_micro' => -$overagePaidPortion,
                    'currency_id' => $wallet->currency_id,
                    'feature_key' => $reservation->feature_key,
                    'meter_key' => $reservation->meter_key,
                    'period_key' => $reservation->period_key,
                    'quantity' => $quantity,
                    'rate_id' => $reservation->rate_id,
                    'rate_version' => $reservation->rate_version,
                    'retail_rate_micro' => $reservation->retail_rate_micro,
                    'provider_cost_micro' => $reservation->provider_cost_micro,
                    'rounding_rule' => $reservation->rounding_rule->value,
                    'reservation_id' => $reservation->id,
                    'correlation_key' => $reservation->correlation_key.':overage',
                    'created_at' => $committedAt,
                ]);

                $availableDelta -= $overageFromAvailable;
                $debtDelta += $overageToDebt;
                $committedFormulaAmount += $overageFromAvailable + $overageToDebt;

                if ($overageFromAvailable > 0) {
                    $shouldDispatchAutoRecharge = true;

                    $lowBalanceFragment = $this->lowBalanceMarkerUpdate(
                        $wallet,
                        $wallet->available_balance_micro + $availableDelta,
                        $shouldDispatchLowBalanceNotification,
                    );
                }
            } elseif ($finalAmountMicro < $reservedAmountMicro) {
                $hadUnusedRelease = true;
                $unused = $reservedAmountMicro - $finalAmountMicro;

                // RFC-005 Remediation #6 §6 — actual final usage consumes
                // the reservation's own paid-attributable snapshot first,
                // identically to the reservation's original paid-first
                // allocation: exact, no proportional/fractional math.
                $unusedPaidPortion = $paidAttributableMicro - min($finalAmountMicro, $paidAttributableMicro);
                $refundablePaidDelta += $unusedPaidPortion;

                $this->ledgerRepository->create([
                    'business_id' => $reservation->business_id,
                    'wallet_id' => $wallet->id,
                    'entry_type' => UsageLedgerEntryType::ReservationRelease->value,
                    'available_delta_micro' => $unused,
                    'reserved_delta_micro' => -$unused,
                    'debt_delta_micro' => 0,
                    'refundable_paid_delta_micro' => $unusedPaidPortion,
                    'currency_id' => $wallet->currency_id,
                    'feature_key' => $reservation->feature_key,
                    'meter_key' => $reservation->meter_key,
                    'period_key' => $reservation->period_key,
                    'reservation_id' => $reservation->id,
                    'correlation_key' => $reservation->correlation_key.':release',
                    'created_at' => $committedAt,
                ]);

                $availableDelta += $unused;
                $reservedDelta -= $unused;

                $lowBalanceFragment = $this->lowBalanceMarkerUpdate(
                    $wallet,
                    $wallet->available_balance_micro + $availableDelta,
                    $shouldDispatchLowBalanceNotification,
                );
            }

            $this->reservationRepository->update($reservation, [
                'status' => UsageReservationStatus::Committed->value,
                'committed_at' => $committedAt,
                'final_quantity' => $quantity,
                'final_amount_micro' => $finalAmountMicro,
            ]);

            $walletUpdate = [
                'available_balance_micro' => $wallet->available_balance_micro + $availableDelta,
                'refundable_paid_available_micro' => $wallet->refundable_paid_available_micro + $refundablePaidDelta,
                'reserved_balance_micro' => $wallet->reserved_balance_micro + $reservedDelta,
                'debt_balance_micro' => $wallet->debt_balance_micro + $debtDelta,
            ];

            if ($isCurrentPeriod) {
                $walletUpdate['committed_spend_this_period_micro'] = $wallet->committed_spend_this_period_micro + $committedFormulaAmount;
                $walletUpdate['reserved_spend_this_period_micro'] = $wallet->reserved_spend_this_period_micro - $reservedAmountMicro;
            }

            $this->walletRepository->update($wallet, array_merge($walletUpdate, $lowBalanceFragment));

            \App\Events\Usage\BusinessUsageCommitted::dispatch(
                (int) $reservation->business_id,
                (int) $reservation->id,
                $reservation->feature_key,
                $finalAmountMicro,
                $reservedAmountMicro,
            );

            if ($overageLedgerEntry !== null) {
                if ($overageFromAvailable > 0) {
                    \App\Events\Usage\BusinessWalletDebited::dispatch(
                        (int) $reservation->business_id,
                        (int) $wallet->id,
                        (int) $overageLedgerEntry->id,
                        $overageFromAvailable,
                    );
                }

                if ($overageToDebt > 0) {
                    \App\Events\Usage\BusinessWalletDebtIncurred::dispatch(
                        (int) $reservation->business_id,
                        (int) $wallet->id,
                        (int) $overageLedgerEntry->id,
                        $overageToDebt,
                    );
                }
            }

            return new CommitResult(
                $reservation->id,
                (string) $finalAmountMicro,
                (string) $reservedAmountMicro,
                $hadOverage,
                $hadUnusedRelease,
            );
        });

        if ($shouldDispatchAutoRecharge) {
            EvaluateBusinessAutoRecharge::dispatch($dispatchBusinessId);
        }

        if ($shouldDispatchLowBalanceNotification) {
            \App\Jobs\Usage\SendLowBalanceNotification::dispatch($dispatchBusinessId);
        }

        return $result;
    }

    /**
     * Releases a pending reservation. Idempotent on an already-terminal
     * released/expired row. Whether the resulting terminal status is
     * 'released' or 'expired' is determined by comparing the reservation's
     * own expires_at to the instant release() actually runs — a manual
     * release before expiry is 'released'; ExpireStaleUsageReservations
     * only ever calls this after expires_at has passed, so its calls
     * naturally resolve to 'expired'. Both terminal outcomes share the
     * same released_at column (RFC-005 §13 has no separate expired_at).
     */
    public function release(int $reservationId): void
    {
        $shouldDispatchLowBalanceNotification = false;
        $dispatchBusinessId = null;

        DB::transaction(function () use ($reservationId, &$shouldDispatchLowBalanceNotification, &$dispatchBusinessId) {
            $peek = $this->reservationRepository->findById($reservationId);

            if ($peek === null) {
                throw new UsageReservationNotFoundException($reservationId);
            }

            $wallet = $this->walletRepository->findForUpdateByBusinessId($peek->business_id);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException($peek->business_id);
            }

            $business = $wallet->business;
            $wallet = $this->rollOverPeriodsIfNeeded($wallet, $business);

            $reservation = $this->reservationRepository->findForUpdateById($reservationId);

            if ($reservation->status === UsageReservationStatus::Released
                || $reservation->status === UsageReservationStatus::Expired) {
                return;
            }

            if ($reservation->status !== UsageReservationStatus::Pending) {
                throw new InvalidReservationStateTransitionException(
                    $reservation->id,
                    $reservation->status->value,
                    'release',
                );
            }

            $releasedAt = Carbon::now();
            $amount = (int) $reservation->reserved_amount_micro;
            $paidAttributableMicro = (int) $reservation->paid_attributable_amount_micro;
            $resultingStatus = $releasedAt->gte($reservation->expires_at)
                ? UsageReservationStatus::Expired
                : UsageReservationStatus::Released;

            $this->ledgerRepository->create([
                'business_id' => $reservation->business_id,
                'wallet_id' => $wallet->id,
                'entry_type' => UsageLedgerEntryType::ReservationRelease->value,
                'available_delta_micro' => $amount,
                'reserved_delta_micro' => -$amount,
                'debt_delta_micro' => 0,
                'refundable_paid_delta_micro' => $paidAttributableMicro,
                'currency_id' => $wallet->currency_id,
                'feature_key' => $reservation->feature_key,
                'meter_key' => $reservation->meter_key,
                'period_key' => $reservation->period_key,
                'reservation_id' => $reservation->id,
                'correlation_key' => $reservation->correlation_key.':release',
                'created_at' => $releasedAt,
            ]);

            $this->reservationRepository->update($reservation, [
                'status' => $resultingStatus->value,
                'released_at' => $releasedAt,
            ]);

            $walletUpdate = [
                'available_balance_micro' => $wallet->available_balance_micro + $amount,
                'refundable_paid_available_micro' => $wallet->refundable_paid_available_micro + $paidAttributableMicro,
                'reserved_balance_micro' => $wallet->reserved_balance_micro - $amount,
            ];

            if ($reservation->period_key === $wallet->spend_period_key) {
                $walletUpdate['reserved_spend_this_period_micro'] = $wallet->reserved_spend_this_period_micro - $amount;
            }

            $dispatchBusinessId = (int) $reservation->business_id;

            $lowBalanceFragment = $amount > 0
                ? $this->lowBalanceMarkerUpdate($wallet, $wallet->available_balance_micro + $amount, $shouldDispatchLowBalanceNotification)
                : [];

            $this->walletRepository->update($wallet, array_merge($walletUpdate, $lowBalanceFragment));

            \App\Events\Usage\BusinessUsageReservationReleased::dispatch(
                (int) $reservation->business_id,
                (int) $reservation->id,
                $reservation->feature_key,
                $amount,
                $resultingStatus->value,
            );
        });

        if ($shouldDispatchLowBalanceNotification) {
            \App\Jobs\Usage\SendLowBalanceNotification::dispatch($dispatchBusinessId);
        }
    }

    /**
     * M3 contract §11 item 7/§15 — the single wallet-crediting mechanism
     * for both a confirmed manual top-up (UsageLedgerEntryType::PaidTopUp)
     * and a confirmed auto-recharge (UsageLedgerEntryType::AutoRecharge).
     * Called only by UsageBillingCheckoutManager, only after authoritative
     * provider confirmation (never a browser redirect alone) — this
     * method itself performs no provider call and makes no confirmation
     * decision; it is purely the accounting effect of an already-verified
     * successful charge. Debt-clearing follows RFC-005 §13's own formula
     * for these two entry types exactly: available_delta = +remainder
     * after debt-clear, reserved_delta = 0, debt_delta = -min(amt, debt).
     * Idempotent at the caller's own layer (UsageBillingCheckoutManager
     * never calls this twice for the same funding_attempt_id, §11 item 8)
     * — this method itself does not re-check funding-attempt state, since
     * it has no FK/visibility into that M3 table by design (M1's own
     * sole-write-authority boundary is preserved: this class still never
     * references a table outside RFC-005 §12/§13's original seven).
     */
    public function creditFromFunding(
        int $businessId,
        UsageLedgerEntryType $entryType,
        int $amountMicro,
        int $fundingAttemptId,
        string $correlationKey,
    ): void {
        DB::transaction(function () use ($businessId, $entryType, $amountMicro, $fundingAttemptId, $correlationKey) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId($businessId);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException($businessId);
            }

            $business = $wallet->business;
            $wallet = $this->rollOverPeriodsIfNeeded($wallet, $business);

            $debtCleared = min($amountMicro, max(0, $wallet->debt_balance_micro));
            $remainder = $amountMicro - $debtCleared;

            $ledgerEntry = $this->ledgerRepository->create([
                'business_id' => $businessId,
                'wallet_id' => $wallet->id,
                'entry_type' => $entryType->value,
                'available_delta_micro' => $remainder,
                'reserved_delta_micro' => 0,
                'debt_delta_micro' => -$debtCleared,
                'refundable_paid_delta_micro' => $remainder,
                'currency_id' => $wallet->currency_id,
                'funding_attempt_id' => $fundingAttemptId,
                'correlation_key' => $correlationKey,
                'created_at' => Carbon::now(),
            ]);

            $walletUpdate = [
                'available_balance_micro' => $wallet->available_balance_micro + $remainder,
                'refundable_paid_available_micro' => $wallet->refundable_paid_available_micro + $remainder,
                'debt_balance_micro' => $wallet->debt_balance_micro - $debtCleared,
            ];

            if ($entryType === UsageLedgerEntryType::AutoRecharge && $wallet->recharge_period_key !== null) {
                $walletUpdate['recharged_this_period_micro'] = $wallet->recharged_this_period_micro + $amountMicro;
                $walletUpdate['consecutive_recharge_failures'] = 0;
            }

            $shouldDispatchLowBalanceNotification = false;
            $lowBalanceFragment = $remainder > 0
                ? $this->lowBalanceMarkerUpdate($wallet, $wallet->available_balance_micro + $remainder, $shouldDispatchLowBalanceNotification)
                : [];

            $this->walletRepository->update($wallet, array_merge($walletUpdate, $lowBalanceFragment));

            if ($remainder > 0) {
                \App\Events\Usage\BusinessWalletCredited::dispatch(
                    $businessId,
                    (int) $wallet->id,
                    (int) $ledgerEntry->id,
                    $remainder,
                );
            }

            if ($debtCleared > 0) {
                \App\Events\Usage\BusinessWalletDebtCleared::dispatch(
                    $businessId,
                    (int) $wallet->id,
                    (int) $ledgerEntry->id,
                    $debtCleared,
                );
            }

            if ($shouldDispatchLowBalanceNotification) {
                \App\Jobs\Usage\SendLowBalanceNotification::dispatch($businessId)->afterCommit();
            }

            \App\Jobs\Usage\SendReceiptNotification::dispatch($fundingAttemptId, (int) $ledgerEntry->id)
                ->afterCommit();
        });
    }

    /**
     * Receipt Boundary Correction Contract §5/§6 — the sole write
     * authority for business_billing_receipts. Locks the already-existing
     * ledger entry row (never a new UNIQUE constraint) as the sole
     * idempotency mechanism: whichever caller reaches this first for a
     * given ledgerEntryId wins the "no existing receipt" check; every
     * later caller (a manual re-dispatch, a genuinely concurrent race)
     * converges on the same already-created row.
     */
    public function attachFundingReceipt(
        int $ledgerEntryId,
        int $fundingAttemptId,
        int $businessId,
        string $providerReceiptUrl,
        string $providerReference,
    ): BusinessBillingReceipt {
        return DB::transaction(function () use ($ledgerEntryId, $fundingAttemptId, $businessId, $providerReceiptUrl, $providerReference) {
            $ledgerEntry = $this->ledgerRepository->findForUpdateById($ledgerEntryId);

            if ($ledgerEntry === null) {
                throw new \InvalidArgumentException("Ledger entry {$ledgerEntryId} does not exist.");
            }

            if ((int) $ledgerEntry->business_id !== $businessId || (int) $ledgerEntry->funding_attempt_id !== $fundingAttemptId) {
                throw new \InvalidArgumentException("Ledger entry {$ledgerEntryId} does not match business {$businessId}/funding attempt {$fundingAttemptId}.");
            }

            $existing = $this->receiptRepository->findByLedgerEntryId($ledgerEntryId);

            if ($existing !== null) {
                return $existing;
            }

            return $this->receiptRepository->create([
                'business_id' => $businessId,
                'ledger_entry_id' => $ledgerEntryId,
                'provider_receipt_url' => $providerReceiptUrl,
                'provider_reference' => $providerReference,
                'created_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Receipt Boundary Correction Contract §6 — thin, unlocked read,
     * used by ensureFundingReceipt() to avoid a wasted provider call
     * when a receipt already exists.
     */
    public function findFundingReceipt(int $ledgerEntryId): ?BusinessBillingReceipt
    {
        return $this->receiptRepository->findByLedgerEntryId($ledgerEntryId);
    }

    /**
     * Finds every pending reservation past its own expires_at and
     * releases it. Never auto-commits a stale reservation (RFC-005 §13).
     * Bounded by $limit per call — the calling job re-dispatches itself
     * when a full page was processed.
     */
    public function expireStaleReservations(int $limit = 500): int
    {
        $expired = $this->reservationRepository->findExpiredPending($limit);

        foreach ($expired as $reservation) {
            $this->release((int) $reservation->id);
        }

        return $expired->count();
    }

    /**
     * RFC-005 Amendment 1 Slice 3 CONTRACT §5.3's setActiveRate() final
     * shape — the meter-local allocator, no feature-wide retry loop.
     * $featureKey is semantically the meter key (signature frozen).
     * Same-meter concurrency is serialized by findForUpdateByMeterKey()'s
     * own row lock alone; sibling meters sharing one legacy feature can no
     * longer collide at all, at any concurrency level, once
     * business_usage_rates_feature_key_version_unique no longer exists.
     */
    public function setActiveRate(
        string $featureKey,
        string $retailRateMicro,
        string $providerCostMicro,
        string $unitLabel,
        int $currencyId,
        int $actorUserId,
        string $reason,
    ): \App\Models\BusinessUsageRate {
        return DB::transaction(function () use ($featureKey, $retailRateMicro, $providerCostMicro, $unitLabel, $currencyId, $actorUserId, $reason) {
            $meter = $this->meterRepository->findForUpdateByMeterKey($featureKey);

            if ($meter === null) {
                throw new NoActiveRateForFeatureException($featureKey);
            }

            $nextVersion = $this->rateRepository->latestVersionForMeter($meter->meter_key) + 1;

            $rate = $this->rateRepository->create([
                'meter_key' => $meter->meter_key,
                'version' => $nextVersion,
                'retail_rate_micro' => $retailRateMicro,
                'provider_cost_micro' => $providerCostMicro,
                'unit_label' => $unitLabel,
                'rounding_rule' => RoundingRule::RoundHalfUp->value,
                'currency_id' => $currencyId,
                'created_by_user_id' => $actorUserId,
                'created_at' => Carbon::now(),
            ]);

            $this->rateActivationRepository->create([
                'meter_key' => $meter->meter_key,
                'rate_id' => $rate->id,
                'activated_at' => Carbon::now(),
                'activated_by_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => Carbon::now(),
            ]);

            $this->meterRepository->update($meter, [
                'active_rate_id' => $rate->id,
                'updated_by_user_id' => $actorUserId,
            ]);

            return $rate;
        });
    }

    /**
     * RFC-005 Amendment 1 §5.7's activateMetering(), re-pointed from the
     * legacy classification row to UsageMeter/UsageMeterTransition (Slice
     * 2 CUTOVER). Requires an already-activated rate for the meter.
     */
    public function activateMetering(string $featureKey, int $actorUserId, string $reason): void
    {
        DB::transaction(function () use ($featureKey, $actorUserId, $reason) {
            $meter = $this->meterRepository->findForUpdateByMeterKey($featureKey);

            if ($meter === null || $meter->active_rate_id === null) {
                throw new NoActiveRateForFeatureException($featureKey);
            }

            $this->meterTransitionRepository->create([
                'meter_key' => $meter->meter_key,
                'from_is_metered' => $meter->is_metered,
                'to_is_metered' => true,
                'from_active_rate_id' => $meter->active_rate_id,
                'to_active_rate_id' => $meter->active_rate_id,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => Carbon::now(),
            ]);

            $this->meterRepository->update($meter, [
                'is_metered' => true,
                'updated_by_user_id' => $actorUserId,
            ]);
        });
    }

    /**
     * Internal-only coarse capacity gate consumed exclusively by
     * RealUsageAuthorizationGateway. Never surfaced past that boundary
     * (RFC-005 §14). RFC-005 Amendment 1 §5.8, Slice 2 CUTOVER — feature
     * entitlement must not depend on wallet health; parameters remain
     * present (frozen signature) even though the body no longer reads
     * them.
     */
    public function evaluateCoarseCapacity(Business $business, PlatformFeature $feature): UsageCapacityDecision
    {
        return new UsageCapacityDecision(true);
    }

    /**
     * RFC-005 §15, M2 contract §6.A/§7 — nullable/unconfigured by default;
     * no default value is ever invented. Prospective only: changes future
     * reservation-admission headroom, never rewrites already-committed
     * historical spend. A value below already-committed current-period
     * spend is explicitly allowed (M2 contract §6.D) — never rejected,
     * never touches committed_spend_this_period_micro.
     */
    public function setSpendCap(Business $business, ?string $capMicro, int $actorUserId, string $reason): void
    {
        DB::transaction(function () use ($business, $capMicro, $actorUserId, $reason) {
            $this->assertCanManagePayerControls($business, $actorUserId);

            $wallet = $this->walletRepository->findForUpdateByBusinessId((int) $business->id);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException((int) $business->id);
            }

            $fromValue = $wallet->monthly_spend_cap_micro !== null ? (string) $wallet->monthly_spend_cap_micro : null;

            $this->limitTransitionRepository->create([
                'business_id' => $business->id,
                'limit_type' => UsageLimitType::BusinessSpendCap->value,
                'feature_key' => null,
                'from_value_micro' => $fromValue,
                'to_value_micro' => $capMicro,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => Carbon::now(),
            ]);

            $this->walletRepository->update($wallet, [
                'monthly_spend_cap_micro' => $capMicro,
            ]);
        });
    }

    /**
     * RFC-005 §15, M2 contract §6.B/§7 — settable ahead of a feature's own
     * future metering activation (validated only through
     * PlatformFeatureRegistry::isAvailable(), never requiring is_metered
     * already true). Bounded above by any configured
     * platform_feature_usage_safety_limits ceiling for that feature
     * (Human requirement 4) — a customer may always tighten, never loosen
     * past the platform ceiling. $limitMicro === null clears the limit
     * (deletes the row — absence means "no limit configured," M2 contract
     * §11.1).
     */
    public function setFeatureLimit(Business $business, string $featureKey, ?string $limitMicro, int $actorUserId, string $reason): void
    {
        // Correction 2 — a Workspace-scoped feature (ProspectOutreach) has
        // no owning Business at all, so a direct programmatic call must
        // independently reject it here too, exactly like an unavailable
        // feature — the controller's own guard (UsageBillingController::
        // updateFeatureLimit()) is not the only enforcement point.
        if (! PlatformFeatureRegistry::isAvailable($featureKey) || ! PlatformFeatureRegistry::isBusinessScoped($featureKey)) {
            throw new NoActiveRateForFeatureException($featureKey);
        }

        DB::transaction(function () use ($business, $featureKey, $limitMicro, $actorUserId, $reason) {
            $this->assertCanManagePayerControls($business, $actorUserId);

            if ($limitMicro !== null) {
                $safetyLimit = $this->safetyLimitRepository->findByFeatureKey($featureKey);

                if ($safetyLimit !== null && bccomp($limitMicro, (string) $safetyLimit->max_monthly_limit_micro) > 0) {
                    throw new FeatureLimitExceedsPlatformSafetyLimitException((int) $business->id, $featureKey);
                }
            }

            $existing = $this->featureLimitRepository->findForUpdateByBusinessAndFeature((int) $business->id, $featureKey);
            $fromValue = $existing?->monthly_limit_micro !== null ? (string) $existing->monthly_limit_micro : null;

            $this->limitTransitionRepository->create([
                'business_id' => $business->id,
                'limit_type' => UsageLimitType::FeatureLimit->value,
                'feature_key' => $featureKey,
                'from_value_micro' => $fromValue,
                'to_value_micro' => $limitMicro,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => Carbon::now(),
            ]);

            if ($limitMicro === null) {
                if ($existing !== null) {
                    $this->featureLimitRepository->delete($existing);
                }

                return;
            }

            if ($existing === null) {
                $this->featureLimitRepository->create([
                    'business_id' => $business->id,
                    'feature_key' => $featureKey,
                    'monthly_limit_micro' => $limitMicro,
                    'updated_by_user_id' => $actorUserId,
                ]);

                return;
            }

            $this->featureLimitRepository->update($existing, [
                'monthly_limit_micro' => $limitMicro,
                'updated_by_user_id' => $actorUserId,
            ]);
        });
    }

    /**
     * RFC-005 §15, M2 contract §6.C — platform-administrator-only. Ships
     * as a fully functional, tested capability with zero calling
     * production code path at M2 (no feature is metered until M5,
     * mirroring M1's own business_usage_rates precedent exactly).
     */
    public function setSafetyLimit(string $featureKey, string $maxMonthlyLimitMicro, int $actorUserId, string $reason): void
    {
        $this->assertPlatformAdministrator($actorUserId);

        DB::transaction(function () use ($featureKey, $maxMonthlyLimitMicro, $actorUserId, $reason) {
            $existing = $this->safetyLimitRepository->findForUpdateByFeatureKey($featureKey);
            $fromValue = $existing !== null ? (string) $existing->max_monthly_limit_micro : null;

            $this->limitTransitionRepository->create([
                'business_id' => null,
                'limit_type' => UsageLimitType::PlatformSafetyLimit->value,
                'feature_key' => $featureKey,
                'from_value_micro' => $fromValue,
                'to_value_micro' => $maxMonthlyLimitMicro,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => Carbon::now(),
            ]);

            if ($existing === null) {
                $this->safetyLimitRepository->create([
                    'feature_key' => $featureKey,
                    'max_monthly_limit_micro' => $maxMonthlyLimitMicro,
                    'updated_by_user_id' => $actorUserId,
                ]);

                return;
            }

            $this->safetyLimitRepository->update($existing, [
                'max_monthly_limit_micro' => $maxMonthlyLimitMicro,
                'updated_by_user_id' => $actorUserId,
            ]);
        });
    }

    /**
     * RFC-005 §12, M2 contract §6.G — platform-administrator-only for
     * source = admin_action (actorUserId required); actorUserId is null
     * only for source = dispute_webhook (M3 scope, never produced by any
     * M2 code path). Ships as a fully functional, tested capability with
     * zero calling production code path at M2 — no admin HTTP route
     * exists yet (M2 contract §9).
     */
    public function setBillingStatus(Business $business, WalletBillingStatus $status, BillingStatusTransitionSource $source, ?int $actorUserId, string $reason): void
    {
        if ($source === BillingStatusTransitionSource::AdminAction) {
            if ($actorUserId === null) {
                throw new UnauthorizedUsageBillingManagementException(0, (int) $business->id);
            }

            $this->assertPlatformAdministrator($actorUserId);
        }

        DB::transaction(function () use ($business, $status, $source, $actorUserId, $reason) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId((int) $business->id);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException((int) $business->id);
            }

            $fromStatus = $wallet->billing_status;

            $this->billingStatusTransitionRepository->create([
                'wallet_id' => $wallet->id,
                'business_id' => $business->id,
                'from_status' => $fromStatus->value,
                'to_status' => $status->value,
                'source' => $source->value,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => Carbon::now(),
            ]);

            $this->walletRepository->update($wallet, [
                'billing_status' => $status->value,
            ]);

            BusinessWalletBillingStatusChanged::dispatch((int) $business->id, $fromStatus->value, $status->value);
        });
    }

    /**
     * RFC-005 Admin Usage Billing Surface Contract §2.3 — platform-
     * administrator-only, auditable manual/promotional credit. Never
     * calls creditFromFunding() (this is deliberately new, independent
     * code, not a funding-attempt-backed credit). Idempotent on a
     * deterministic, caller-supplied operation id: an identical replay
     * (same normalized Business/type/amount/actor/reason) returns the
     * original ledger row unchanged; a reused operation id with a
     * different payload throws ManualCreditOperationConflictException
     * and mutates nothing.
     */
    public function issueManualCredit(Business $business, UsageLedgerEntryType $entryType, int $amountMicro, int $actorUserId, string $reason, string $operationId): BusinessUsageLedgerEntry
    {
        $this->assertPlatformAdministrator($actorUserId);

        if (! in_array($entryType, [UsageLedgerEntryType::ManualCredit, UsageLedgerEntryType::PromotionalCredit], true)) {
            throw new InvalidAdminCreditEntryTypeException($entryType->value);
        }

        if ($amountMicro <= 0) {
            throw new InvalidAdminCreditAmountException($amountMicro);
        }

        $normalizedReason = trim($reason);
        if ($normalizedReason === '') {
            throw new InvalidAdminCreditReasonException((int) $business->id);
        }

        $normalizedOperationId = strtolower(trim($operationId));
        if (! Str::isUuid($normalizedOperationId)) {
            throw new InvalidAdminCreditOperationIdException($operationId);
        }

        $correlationKey = 'admin_credit:'.$business->id.':'.$normalizedOperationId;

        return DB::transaction(function () use ($business, $entryType, $amountMicro, $actorUserId, $normalizedReason, $correlationKey) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId((int) $business->id);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException((int) $business->id);
            }

            $existing = $this->ledgerRepository->findByCorrelationKey($correlationKey);

            if ($existing !== null) {
                $samePayload = (int) $existing->business_id === (int) $business->id
                    && $existing->entry_type === $entryType
                    && (int) $existing->gross_amount_micro === $amountMicro
                    && (int) $existing->actor_user_id === $actorUserId
                    && $existing->reason === $normalizedReason;

                if (! $samePayload) {
                    throw new ManualCreditOperationConflictException($correlationKey);
                }

                return $existing; // idempotent replay: zero balance change, zero events, zero new row
            }

            $wallet = $this->rollOverPeriodsIfNeeded($wallet, $business);

            $debtCleared = min($amountMicro, max(0, $wallet->debt_balance_micro));
            $creditedToAvailable = $amountMicro - $debtCleared;

            $ledgerEntry = $this->ledgerRepository->create([
                'business_id' => $business->id,
                'wallet_id' => $wallet->id,
                'entry_type' => $entryType->value,
                'available_delta_micro' => $creditedToAvailable,
                'reserved_delta_micro' => 0,
                'debt_delta_micro' => -$debtCleared,
                'gross_amount_micro' => $amountMicro,
                'currency_id' => $wallet->currency_id,
                'actor_user_id' => $actorUserId,
                'reason' => $normalizedReason,
                'correlation_key' => $correlationKey,
                'created_at' => Carbon::now(),
            ]);

            $walletUpdate = [
                'available_balance_micro' => $wallet->available_balance_micro + $creditedToAvailable,
                'debt_balance_micro' => $wallet->debt_balance_micro - $debtCleared,
            ];

            $shouldDispatchLowBalanceNotification = false;
            $lowBalanceFragment = $creditedToAvailable > 0
                ? $this->lowBalanceMarkerUpdate($wallet, $wallet->available_balance_micro + $creditedToAvailable, $shouldDispatchLowBalanceNotification)
                : [];

            $this->walletRepository->update($wallet, array_merge($walletUpdate, $lowBalanceFragment));

            if ($creditedToAvailable > 0) {
                \App\Events\Usage\BusinessWalletCredited::dispatch($business->id, (int) $wallet->id, (int) $ledgerEntry->id, $creditedToAvailable);
            }

            if ($debtCleared > 0) {
                \App\Events\Usage\BusinessWalletDebtCleared::dispatch($business->id, (int) $wallet->id, (int) $ledgerEntry->id, $debtCleared);
            }

            if ($shouldDispatchLowBalanceNotification) {
                \App\Jobs\Usage\SendLowBalanceNotification::dispatch($business->id)->afterCommit();
            }

            return $ledgerEntry;
        });
    }

    /**
     * RFC-005 Remediation #6 §6/§10/§12/§13 — applies one provider-
     * confirmed cumulative refund delta (already computed and clamped to
     * non-negative by the caller, per §6's own out-of-order fix). Never
     * self-idempotent on correlation_key — the caller is expected to have
     * already derived $providerRefundDelta from the corrected
     * max(0, bcsub(...)) formula, which is itself already 0 for any exact
     * replay or out-of-order-lower report; a genuine concurrent race for
     * the identical cumulative amount is resolved by the ledger's own
     * correlation_key UNIQUE constraint, which the caller must catch.
     *
     * Returns null and mutates nothing for a non-positive delta. Never
     * writes a non-zero debt_delta_micro. Caps the wallet debit at
     * min(available_balance_micro, refundable_paid_available_micro) when
     * $walletBacked; a zero-delta (direct_deliverable) outcome writes an
     * audit-only row and touches no balance column.
     */
    public function applyProviderRefund(
        int $businessId,
        bool $walletBacked,
        int $fundingAttemptId,
        int $providerRefundDelta,
        string $boundedProviderCumulative,
        string $providerChargeReference,
    ): ?BusinessUsageLedgerEntry {
        if ($providerRefundDelta <= 0) {
            return null;
        }

        $shouldDispatchLowBalanceNotification = false;

        $ledgerEntry = DB::transaction(function () use (
            $businessId,
            $walletBacked,
            $fundingAttemptId,
            $providerRefundDelta,
            $boundedProviderCumulative,
            $providerChargeReference,
            &$shouldDispatchLowBalanceNotification,
        ) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId($businessId);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException($businessId);
            }

            $walletDebitMicro = 0;

            if ($walletBacked) {
                $refundHeadroomMicro = min(max(0, $wallet->available_balance_micro), max(0, $wallet->refundable_paid_available_micro));
                $walletDebitMicro = min($providerRefundDelta, $refundHeadroomMicro);
            }

            $ledgerEntry = $this->ledgerRepository->create([
                'business_id' => $businessId,
                'wallet_id' => $wallet->id,
                'funding_attempt_id' => $fundingAttemptId,
                'entry_type' => UsageLedgerEntryType::Refund->value,
                'available_delta_micro' => $walletBacked ? -$walletDebitMicro : 0,
                'reserved_delta_micro' => 0,
                'debt_delta_micro' => 0,
                'refundable_paid_delta_micro' => $walletBacked ? -$walletDebitMicro : 0,
                'gross_amount_micro' => $providerRefundDelta,
                'currency_id' => $wallet->currency_id,
                'correlation_key' => 'refund:'.$fundingAttemptId.':'.$boundedProviderCumulative,
                'provider_reference' => $providerChargeReference,
                'actor_user_id' => null,
                'reason' => "Provider-confirmed refund of {$providerRefundDelta} micro-units against charge {$providerChargeReference}.",
                'reversed_entry_id' => null,
                'created_at' => Carbon::now(),
            ]);

            if (! $walletBacked) {
                return $ledgerEntry;
            }

            $newAvailableBalanceMicro = $wallet->available_balance_micro - $walletDebitMicro;

            $walletUpdate = [
                'available_balance_micro' => $newAvailableBalanceMicro,
                'refundable_paid_available_micro' => $wallet->refundable_paid_available_micro - $walletDebitMicro,
            ];

            $lowBalanceFragment = $walletDebitMicro > 0
                ? $this->lowBalanceMarkerUpdate($wallet, $newAvailableBalanceMicro, $shouldDispatchLowBalanceNotification)
                : [];

            // RFC-005 Remediation #6 §6 — a cumulative refund that could
            // not be fully honored as a cash refund suspends billing,
            // guarded against a redundant transition row, exactly as
            // dispute-driven suspension is guarded.
            $policyExcessMicro = $providerRefundDelta - $walletDebitMicro;
            $suspending = $policyExcessMicro > 0 && $wallet->billing_status !== WalletBillingStatus::Suspended;

            if ($suspending) {
                $fromStatus = $wallet->billing_status;

                $this->billingStatusTransitionRepository->create([
                    'wallet_id' => $wallet->id,
                    'business_id' => $businessId,
                    'from_status' => $fromStatus->value,
                    'to_status' => WalletBillingStatus::Suspended->value,
                    'source' => BillingStatusTransitionSource::ProviderRefundMismatch->value,
                    'actor_user_id' => null,
                    'reason' => "Provider-confirmed refund exceeds refundable balance by {$policyExcessMicro} micro-units.",
                    'created_at' => Carbon::now(),
                ]);

                $walletUpdate['billing_status'] = WalletBillingStatus::Suspended->value;
            }

            $this->walletRepository->update($wallet, array_merge($walletUpdate, $lowBalanceFragment));

            if ($suspending) {
                BusinessWalletBillingStatusChanged::dispatch($businessId, $fromStatus->value, WalletBillingStatus::Suspended->value);
            }

            if ($walletDebitMicro > 0) {
                \App\Events\Usage\BusinessWalletDebited::dispatch($businessId, (int) $wallet->id, (int) $ledgerEntry->id, $walletDebitMicro);
            }

            return $ledgerEntry;
        });

        if ($shouldDispatchLowBalanceNotification) {
            \App\Jobs\Usage\SendLowBalanceNotification::dispatch($businessId)->afterCommit();
        }

        return $ledgerEntry;
    }

    /**
     * RFC-005 Remediation #6 §8/§10/§12 — applies one provider-confirmed
     * dispute-withdrawal balance-transaction amount (already validated and
     * signed by the caller). May create debt — unaffected by the refund
     * policy's own no-debt guarantee. Billing suspension is unconditional
     * (a risk-control decision, never conditional on wallet-credit
     * fulfillment), guarded against a redundant transition row. Never
     * self-idempotent on correlation_key — a genuine concurrent race for
     * the identical balance transaction is resolved by the ledger's own
     * correlation_key UNIQUE constraint, which the caller must catch.
     */
    public function applyDisputeWithdrawal(
        int $businessId,
        bool $walletBacked,
        int $fundingAttemptId,
        int $amountMicro,
        string $providerDisputeId,
        string $balanceTransactionId,
    ): ?BusinessUsageLedgerEntry {
        if ($amountMicro <= 0) {
            return null;
        }

        $shouldDispatchLowBalanceNotification = false;

        $ledgerEntry = DB::transaction(function () use (
            $businessId,
            $walletBacked,
            $fundingAttemptId,
            $amountMicro,
            $providerDisputeId,
            $balanceTransactionId,
            &$shouldDispatchLowBalanceNotification,
        ) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId($businessId);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException($businessId);
            }

            $chargebackFromAvailable = 0;
            $chargebackToDebt = 0;
            $chargebackPaidPortion = 0;

            if ($walletBacked) {
                $chargebackFromAvailable = min($amountMicro, max(0, $wallet->available_balance_micro));
                $chargebackToDebt = $amountMicro - $chargebackFromAvailable;
                $chargebackPaidPortion = min($chargebackFromAvailable, max(0, $wallet->refundable_paid_available_micro));
            }

            $ledgerEntry = $this->ledgerRepository->create([
                'business_id' => $businessId,
                'wallet_id' => $wallet->id,
                'funding_attempt_id' => $fundingAttemptId,
                'entry_type' => UsageLedgerEntryType::DisputeChargeback->value,
                'available_delta_micro' => $walletBacked ? -$chargebackFromAvailable : 0,
                'reserved_delta_micro' => 0,
                'debt_delta_micro' => $walletBacked ? $chargebackToDebt : 0,
                'refundable_paid_delta_micro' => $walletBacked ? -$chargebackPaidPortion : 0,
                'gross_amount_micro' => $amountMicro,
                'currency_id' => $wallet->currency_id,
                'correlation_key' => 'dispute_chargeback:'.$fundingAttemptId.':'.$balanceTransactionId,
                'provider_reference' => $providerDisputeId,
                'actor_user_id' => null,
                'reason' => "Provider-confirmed dispute withdrawal of {$amountMicro} micro-units for dispute {$providerDisputeId}.",
                'reversed_entry_id' => null,
                'created_at' => Carbon::now(),
            ]);

            $walletUpdate = [];

            if ($walletBacked) {
                $walletUpdate['available_balance_micro'] = $wallet->available_balance_micro - $chargebackFromAvailable;
                $walletUpdate['refundable_paid_available_micro'] = $wallet->refundable_paid_available_micro - $chargebackPaidPortion;
                $walletUpdate['debt_balance_micro'] = $wallet->debt_balance_micro + $chargebackToDebt;
            }

            $fromStatus = $wallet->billing_status;
            $suspending = $fromStatus !== WalletBillingStatus::Suspended;

            if ($suspending) {
                $this->billingStatusTransitionRepository->create([
                    'wallet_id' => $wallet->id,
                    'business_id' => $businessId,
                    'from_status' => $fromStatus->value,
                    'to_status' => WalletBillingStatus::Suspended->value,
                    'source' => BillingStatusTransitionSource::DisputeWebhook->value,
                    'actor_user_id' => null,
                    'reason' => "Provider-confirmed dispute {$providerDisputeId} withdrew {$amountMicro} micro-units.",
                    'created_at' => Carbon::now(),
                ]);

                $walletUpdate['billing_status'] = WalletBillingStatus::Suspended->value;
            }

            $lowBalanceFragment = ($walletBacked && $chargebackFromAvailable > 0)
                ? $this->lowBalanceMarkerUpdate($wallet, $wallet->available_balance_micro - $chargebackFromAvailable, $shouldDispatchLowBalanceNotification)
                : [];

            if ($walletUpdate !== [] || $lowBalanceFragment !== []) {
                $this->walletRepository->update($wallet, array_merge($walletUpdate, $lowBalanceFragment));
            }

            if ($suspending) {
                BusinessWalletBillingStatusChanged::dispatch($businessId, $fromStatus->value, WalletBillingStatus::Suspended->value);
            }

            if ($walletBacked && $chargebackFromAvailable > 0) {
                \App\Events\Usage\BusinessWalletDebited::dispatch($businessId, (int) $wallet->id, (int) $ledgerEntry->id, $chargebackFromAvailable);
            }

            if ($walletBacked && $chargebackToDebt > 0) {
                \App\Events\Usage\BusinessWalletDebtIncurred::dispatch($businessId, (int) $wallet->id, (int) $ledgerEntry->id, $chargebackToDebt);
            }

            return $ledgerEntry;
        });

        if ($shouldDispatchLowBalanceNotification) {
            \App\Jobs\Usage\SendLowBalanceNotification::dispatch($businessId)->afterCommit();
        }

        return $ledgerEntry;
    }

    /**
     * RFC-005 Remediation #6 §9/§10/§12 — applies one provider-confirmed
     * dispute-reinstatement amount, already bounded by the caller to the
     * specific dispute's own actual withdrawn-minus-reinstated amount.
     * Never a negation of the original chargeback's own stored deltas.
     * Clears current debt before crediting any remainder to available
     * balance; never produces negative debt.
     * $originalChargebackPaidPortionRemoved (already resolved by the
     * caller via the original chargeback's own signed
     * refundable_paid_delta_micro) bounds how much of the remainder may
     * restore refundable_paid_available_micro — debt-clearing never does.
     */
    public function reinstateDisputedFunds(
        int $businessId,
        bool $walletBacked,
        int $fundingAttemptId,
        int $reinstatementAmountMicro,
        int $originalChargebackPaidPortionRemoved,
        ?int $originalChargebackEntryId,
        string $providerDisputeId,
        string $balanceTransactionId,
    ): ?BusinessUsageLedgerEntry {
        if ($reinstatementAmountMicro <= 0) {
            return null;
        }

        $shouldDispatchLowBalanceNotification = false;

        $ledgerEntry = DB::transaction(function () use (
            $businessId,
            $walletBacked,
            $fundingAttemptId,
            $reinstatementAmountMicro,
            $originalChargebackPaidPortionRemoved,
            $originalChargebackEntryId,
            $providerDisputeId,
            $balanceTransactionId,
            &$shouldDispatchLowBalanceNotification,
        ) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId($businessId);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException($businessId);
            }

            $debtCleared = 0;
            $remainder = 0;
            $reinstatePaidPortion = 0;

            if ($walletBacked) {
                $debtCleared = min($reinstatementAmountMicro, max(0, $wallet->debt_balance_micro));
                $remainder = $reinstatementAmountMicro - $debtCleared;
                $reinstatePaidPortion = min($remainder, max(0, $originalChargebackPaidPortionRemoved));
            }

            $ledgerEntry = $this->ledgerRepository->create([
                'business_id' => $businessId,
                'wallet_id' => $wallet->id,
                'funding_attempt_id' => $fundingAttemptId,
                'entry_type' => UsageLedgerEntryType::CorrectionReversal->value,
                'available_delta_micro' => $walletBacked ? $remainder : 0,
                'reserved_delta_micro' => 0,
                'debt_delta_micro' => $walletBacked ? -$debtCleared : 0,
                'refundable_paid_delta_micro' => $walletBacked ? $reinstatePaidPortion : 0,
                'gross_amount_micro' => $reinstatementAmountMicro,
                'currency_id' => $wallet->currency_id,
                'correlation_key' => 'dispute_reversal:'.$fundingAttemptId.':'.$balanceTransactionId,
                'provider_reference' => $providerDisputeId,
                'actor_user_id' => null,
                'reason' => "Provider-confirmed dispute reinstatement of {$reinstatementAmountMicro} micro-units for dispute {$providerDisputeId}.",
                'reversed_entry_id' => $originalChargebackEntryId,
                'created_at' => Carbon::now(),
            ]);

            if (! $walletBacked) {
                return $ledgerEntry;
            }

            $newAvailableBalanceMicro = $wallet->available_balance_micro + $remainder;

            $walletUpdate = [
                'available_balance_micro' => $newAvailableBalanceMicro,
                'refundable_paid_available_micro' => $wallet->refundable_paid_available_micro + $reinstatePaidPortion,
                'debt_balance_micro' => $wallet->debt_balance_micro - $debtCleared,
            ];

            $lowBalanceFragment = $remainder > 0
                ? $this->lowBalanceMarkerUpdate($wallet, $newAvailableBalanceMicro, $shouldDispatchLowBalanceNotification)
                : [];

            $this->walletRepository->update($wallet, array_merge($walletUpdate, $lowBalanceFragment));

            if ($remainder > 0) {
                \App\Events\Usage\BusinessWalletCredited::dispatch($businessId, (int) $wallet->id, (int) $ledgerEntry->id, $remainder);
            }

            if ($debtCleared > 0) {
                \App\Events\Usage\BusinessWalletDebtCleared::dispatch($businessId, (int) $wallet->id, (int) $ledgerEntry->id, $debtCleared);
            }

            return $ledgerEntry;
        });

        if ($shouldDispatchLowBalanceNotification) {
            \App\Jobs\Usage\SendLowBalanceNotification::dispatch($businessId)->afterCommit();
        }

        return $ledgerEntry;
    }

    /**
     * M3 contract §15/§17/§18 — configures the four M1-shipped auto-
     * recharge wallet columns. Charge-adjacent, gated by the identical
     * narrower payer-consent authority §16 of the RFC extends to every
     * charge-causing action (never the broader
     * assertCanManageBusinessUsageBilling() non-payment authority) — no
     * platform-administrator override exists for newly enabling
     * auto-recharge (M3 contract §15/§17). No default threshold/amount/
     * cap value is ever invented here — every value comes directly from
     * the caller, or is null (M3 contract §5 item 4).
     */
    public function configureAutoRecharge(
        Business $business,
        bool $enabled,
        ?string $thresholdMicro,
        ?string $amountMicro,
        ?string $monthlyCapMicro,
        int $actorUserId,
    ): void {
        $this->assertChargeCausingConsentForAutoRecharge($business, $actorUserId);

        // Customer Experience Slice 5 (contract §12.2, §28.9; T-WALLET-2/4)
        // — enabling requires a threshold and one of the four fixed preset
        // amounts. A custom amount is refused here regardless of what any
        // request layer accepted, so a crafted POST can never enable
        // automatic top-up for an unapproved amount.
        // Correction Round 1 §6.1 — the manager boundary re-validates
        // everything the request layer validates, so a crafted POST can
        // never enable automatic top-up without a preset amount and a
        // deliberately chosen monthly ceiling at or below the hard maximum.
        $problem = self::autoRechargeConfigurationProblem($enabled, $thresholdMicro, $amountMicro, $monthlyCapMicro);

        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }

        DB::transaction(function () use ($business, $enabled, $thresholdMicro, $amountMicro, $monthlyCapMicro, $actorUserId) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId((int) $business->id);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException((int) $business->id);
            }

            $walletUpdate = [
                'auto_recharge_enabled' => $enabled,
                'auto_recharge_threshold_micro' => $enabled ? $thresholdMicro : null,
                'auto_recharge_amount_micro' => $enabled ? $amountMicro : null,
                'monthly_recharge_cap_micro' => $monthlyCapMicro,
            ];

            // RFC-005 Job/Event Dispatch Completion Correction Contract §5
            // item 6 — an explicit disabled->enabled transition starts a
            // new failure episode. Gated strictly on the transition edge,
            // never on every call with enabled: true, so a benign re-save
            // while already enabled never spuriously resets an
            // in-progress (but not yet disabled) failure count.
            if (! $wallet->auto_recharge_enabled && $enabled) {
                $walletUpdate['consecutive_recharge_failures'] = 0;
            }

            // Slice 5 (T-WALLET-5) — consent is recorded only by this
            // explicit, payer-authorized action: who enabled it and when.
            // Never inferred from a stored payment method or an earlier
            // manual top-up. Turning it off clears the record.
            if ($enabled) {
                $walletUpdate['auto_recharge_consented_at'] = Carbon::now();
                $walletUpdate['auto_recharge_consented_by_user_id'] = $actorUserId;
            } else {
                $walletUpdate['auto_recharge_consented_at'] = null;
                $walletUpdate['auto_recharge_consented_by_user_id'] = null;
            }

            $this->walletRepository->update($wallet, $walletUpdate);
        });
    }

    public static function isAutoRechargePreset(string $amountMicro): bool
    {
        if (preg_match('/^\d+$/', $amountMicro) !== 1) {
            return false;
        }

        return in_array((int) $amountMicro, self::AUTO_RECHARGE_PRESETS_MICRO, true);
    }

    /**
     * Correction Round 1 §6.1 — validates one automatic top-up
     * configuration against the approved policy. Returns the problem code
     * (a locale key under usage_billing.validation) or null when the
     * configuration is acceptable. Pure, so the request layer and the
     * manager apply the identical rule. Exact integer strings only; no
     * float ever touches an amount.
     *
     * While disabled, a stored ceiling is still bounded by the hard
     * maximum (never stored above it) but is not required, and is never
     * treated as permission to charge.
     */
    public static function autoRechargeConfigurationProblem(bool $enabled, ?string $thresholdMicro, ?string $amountMicro, ?string $monthlyCapMicro): ?string
    {
        if ($monthlyCapMicro !== null) {
            if (preg_match('/^\d+$/', $monthlyCapMicro) !== 1) {
                return 'monthly_cap_invalid';
            }

            if (bccomp($monthlyCapMicro, (string) self::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO) > 0) {
                return 'monthly_cap_above_maximum';
            }
        }

        if (! $enabled) {
            return null;
        }

        if ($thresholdMicro === null || preg_match('/^\d+$/', $thresholdMicro) !== 1 || bccomp($thresholdMicro, '0') <= 0) {
            return 'auto_recharge_threshold_required';
        }

        if ($amountMicro === null || ! self::isAutoRechargePreset($amountMicro)) {
            return 'auto_recharge_preset_only';
        }

        if ($monthlyCapMicro === null || bccomp($monthlyCapMicro, '0') <= 0) {
            return 'monthly_cap_required';
        }

        if (bccomp($monthlyCapMicro, $amountMicro) < 0) {
            return 'monthly_cap_below_preset';
        }

        return null;
    }

    /**
     * Customer Experience Slice 5 (contract §12.2 E-18, T-WALLET-1) — the
     * manager-boundary half of the $5 floor. Returns the denial reason, or
     * null when the amount may proceed to UsageBillingCheckoutManager.
     */
    public function manualTopUpDenialReason(int $amountMicro): ?string
    {
        return $amountMicro < self::MINIMUM_MANUAL_TOP_UP_MICRO ? self::DENIAL_BELOW_MINIMUM_TOP_UP : null;
    }

    /**
     * Customer Experience Slice 5 (contract §12.3, §20 C-10; T-CAP-3) — the
     * task-oriented sentence a customer reads for a refused action. Never
     * a key, class name or classification value.
     */
    public function customerMessageForDenial(string $reason): string
    {
        $key = 'locale.usage_billing.denials.' . $reason;

        if (Lang::has($key)) {
            return __($key);
        }

        return __('locale.usage_billing.denials.generic');
    }

    /**
     * Customer Experience Slice 5 (E-14; brief §11) — the curated,
     * customer-selectable capabilities a per-capability limit may target:
     * every feature the registry marks Available and Business-scoped.
     * Keys are internal; the view renders capabilityLabel().
     *
     * @return list<string>
     */
    public function customerCapabilityCatalog(): array
    {
        $keys = [];

        foreach (PlatformFeature::cases() as $feature) {
            if (PlatformFeatureRegistry::isAvailable($feature->value) && PlatformFeatureRegistry::isBusinessScoped($feature->value)) {
                $keys[] = $feature->value;
            }
        }

        return $keys;
    }

    public function isCustomerLimitableCapability(string $featureKey): bool
    {
        return in_array($featureKey, $this->customerCapabilityCatalog(), true);
    }

    /**
     * Human label for a feature key — the catalogue's entry, or a readable
     * fallback for a historical key a later catalogue no longer names, so
     * old ledger rows stay legible without ever showing the raw key as the
     * primary label.
     */
    public function capabilityLabel(?string $featureKey): string
    {
        if ($featureKey === null || $featureKey === '') {
            return __('locale.usage_billing.capabilities.general');
        }

        $key = 'locale.usage_billing.capabilities.' . $featureKey . '.label';

        if (Lang::has($key)) {
            return __($key);
        }

        return Str::of($featureKey)->replace(['_', '-'], ' ')->ucfirst()->toString();
    }

    public function capabilityHelp(string $featureKey): ?string
    {
        $key = 'locale.usage_billing.capabilities.' . $featureKey . '.help';

        return Lang::has($key) ? __($key) : null;
    }

    /**
     * Customer Experience Slice 5 (contract §12.2 E-20; T-CAP-5) — the
     * Business-level emergency stop. Idempotent; audited in
     * usage_control_transitions. Authorized like every other non-charge
     * limit: Workspace owner, covering active Admin, or the direct
     * Business owner.
     */
    public function pausePaidActivity(Business $business, int $actorUserId, string $reason): void
    {
        $this->setPaidActivityPaused($business, true, $actorUserId, $reason);
    }

    public function resumePaidActivity(Business $business, int $actorUserId, string $reason): void
    {
        $this->setPaidActivityPaused($business, false, $actorUserId, $reason);
    }

    /**
     * Read model of the Workspace-level controls (never a raw row handed
     * to Blade).
     *
     * @return array{monthly_aggregate_spend_cap_micro: ?string, monthly_aggregate_recharge_cap_micro: ?string, paid_activity_paused: bool, paid_activity_paused_at: ?string, workspace_paid_spend_this_period_micro: string}
     */
    public function workspaceControls(Workspace $workspace, ?string $periodKey = null): array
    {
        $row = DB::table('workspace_usage_controls')->where('workspace_id', (int) $workspace->id)->first();
        $periodKey ??= Carbon::now()->format('Y-m');

        return [
            'monthly_aggregate_spend_cap_micro' => $row?->monthly_aggregate_spend_cap_micro !== null ? (string) $row->monthly_aggregate_spend_cap_micro : null,
            'monthly_aggregate_recharge_cap_micro' => $row?->monthly_aggregate_recharge_cap_micro !== null ? (string) $row->monthly_aggregate_recharge_cap_micro : null,
            'paid_activity_paused' => $row?->paid_activity_paused_at !== null,
            'paid_activity_paused_at' => $row?->paid_activity_paused_at !== null ? (string) $row->paid_activity_paused_at : null,
            'workspace_paid_spend_this_period_micro' => (string) $this->workspacePaidSpendThisPeriod((int) $workspace->id, $periodKey),
        ];
    }

    /**
     * Customer Experience Slice 5 (contract §12.2 E-19; T-CAP-2) — the
     * Agency-wide monthly spending limit. Null clears it. Workspace owner
     * or Agency-wide active Admin only.
     */
    public function setWorkspaceAggregateSpendCap(Workspace $workspace, ?string $capMicro, int $actorUserId, string $reason): void
    {
        $this->assertCanManageWorkspaceUsageControls($workspace, $actorUserId);
        $this->assertNullOrNonNegativeInteger($capMicro);

        DB::transaction(function () use ($workspace, $capMicro, $actorUserId, $reason) {
            $row = $this->lockOrCreateWorkspaceControls((int) $workspace->id, $actorUserId);
            $from = $row->monthly_aggregate_spend_cap_micro !== null ? (string) $row->monthly_aggregate_spend_cap_micro : null;

            $this->recordControlTransition('workspace', (int) $workspace->id, self::CONTROL_WORKSPACE_SPEND_CAP, $from, $capMicro, $actorUserId, $reason);

            DB::table('workspace_usage_controls')->where('id', $row->id)->update([
                'monthly_aggregate_spend_cap_micro' => $capMicro,
                'updated_by_user_id' => $actorUserId,
                'updated_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Customer Experience Slice 5 (T-WALLET-6) — the Agency-wide monthly
     * automatic top-up ceiling. Stored and audited here; its exact
     * platform hard maximum is owner-gated (§28.9 d) and NOT invented.
     */
    public function setWorkspaceAggregateRechargeCap(Workspace $workspace, ?string $capMicro, int $actorUserId, string $reason): void
    {
        $this->assertCanManageWorkspaceUsageControls($workspace, $actorUserId);
        $this->assertNullOrNonNegativeInteger($capMicro);

        // Correction Round 1 §6.2 — bounded by the approved Agency-wide
        // hard maximum at the manager boundary too; a crafted POST above it
        // is refused before anything is written.
        if ($capMicro !== null && bccomp($capMicro, (string) self::WORKSPACE_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO) > 0) {
            throw new \InvalidArgumentException('workspace_recharge_cap_above_maximum');
        }

        DB::transaction(function () use ($workspace, $capMicro, $actorUserId, $reason) {
            $row = $this->lockOrCreateWorkspaceControls((int) $workspace->id, $actorUserId);
            $from = $row->monthly_aggregate_recharge_cap_micro !== null ? (string) $row->monthly_aggregate_recharge_cap_micro : null;

            $this->recordControlTransition('workspace', (int) $workspace->id, self::CONTROL_WORKSPACE_RECHARGE_CAP, $from, $capMicro, $actorUserId, $reason);

            DB::table('workspace_usage_controls')->where('id', $row->id)->update([
                'monthly_aggregate_recharge_cap_micro' => $capMicro,
                'updated_by_user_id' => $actorUserId,
                'updated_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Customer Experience Slice 5 (contract §12.2 E-20) — the Workspace-wide
     * emergency stop: every Business of the Workspace is refused new paid
     * work at reserve() time. Workspace owner or Agency-wide active Admin
     * only; audited; idempotent.
     */
    public function pauseWorkspacePaidActivity(Workspace $workspace, int $actorUserId, string $reason): void
    {
        $this->setWorkspacePaidActivityPaused($workspace, true, $actorUserId, $reason);
    }

    public function resumeWorkspacePaidActivity(Workspace $workspace, int $actorUserId, string $reason): void
    {
        $this->setWorkspacePaidActivityPaused($workspace, false, $actorUserId, $reason);
    }

    /**
     * Customer Experience Slice 5 (T-WALLET-6) — whether one more automatic
     * top-up of $amountMicro for this Business is admitted by BOTH monthly
     * ceilings: the Business's own monthly_recharge_cap_micro and, for a
     * Workspace-paid Business, the Workspace aggregate recharge ceiling
     * (sum of recharged_this_period_micro across the Workspace-paid
     * wallets in the same recharge period). Exact integer boundary:
     * reaching a ceiling exactly is admitted; one unit over is refused.
     */
    public function autoRechargeCeilingAdmission(Business $business, int $amountMicro): CapEvaluation
    {
        $wallet = $this->walletRepository->findByBusinessId((int) $business->id);

        if ($wallet === null) {
            throw new UsageWalletNotFoundException((int) $business->id);
        }

        $payerType = $this->isWorkspacePaid((int) $business->id) ? PayerType::Workspace : PayerType::Business;

        return $this->evaluateAutoRechargeAdmission($wallet, $business, $payerType, $amountMicro);
    }

    /**
     * Correction Round 1 §5.1 — the authoritative admission that precedes
     * the durable claim. MUST be called inside the caller's transaction
     * while it holds the Business wallet row lock
     * (UsageBillingCheckoutManager::initiateCharge()); the funding attempt
     * the caller creates immediately afterwards, in the same transaction,
     * is the claim itself (no second counter, no parallel ledger).
     *
     * Lock order — fixed, and identical to reserve():
     *   1. business_usage_wallets row (taken by the caller, FOR UPDATE);
     *   2. workspace_usage_controls row (taken here, FOR UPDATE, only while
     *      the Workspace pays).
     * Both are locking reads, so no consistent-read snapshot exists yet;
     * every consistent read of the evaluation happens after both locks and
     * therefore sees every claim committed by the previous lock holders.
     * Two Businesses of one Workspace serialize on the Workspace row and
     * can never both consume the final aggregate unit; two evaluations of
     * one Business serialize on its wallet row.
     */
    public function claimAutoRechargeAdmissionUnderLock(BusinessUsageWallet $lockedWallet, Business $business, PayerType $payerType, int $amountMicro): CapEvaluation
    {
        if ($payerType === PayerType::Workspace) {
            $this->lockWorkspaceControls((int) $business->workspace_id);
        }

        $wallet = $this->rollOverPeriodsIfNeeded($lockedWallet, $business);

        return $this->evaluateAutoRechargeAdmission($wallet, $business, $payerType, $amountMicro);
    }

    /**
     * Correction Round 1 §5 — every applicable automatic top-up control,
     * in order, before any provider call:
     *   1. the Business's own monthly ceiling, deliberately chosen (missing
     *      fails closed) and bounded by the approved hard maximum;
     *   2. the rolling-window frequency limit;
     *   3. while the Workspace pays: the Workspace aggregate monthly
     *      ceiling (an Agency Workspace without one fails closed; a Core/
     *      Growth account needs none), bounded by its hard maximum.
     * MONETARY consumption counts, exactly once each, every automatic
     * top-up already added this period (the wallets' own
     * recharged_this_period_micro, incremented only by AutoRecharge
     * credits) plus every non-terminal automatic top-up attempt that may
     * still become a charge (its expected_amount_micro). Manual top-ups,
     * promotional credit, refunds and client-paid Businesses never count.
     * Failed, cancelled and abandoned attempts release that monetary
     * headroom by leaving the outstanding states.
     *
     * The FREQUENCY check in step (b) is deliberately independent of that
     * release (Correction Round 2 §1.1): every automatically initiated
     * attempt row holds its rolling-window slot for the full window, even
     * after it fails or is canceled, so the scheduler can never contact the
     * payment provider more than the approved number of times per window.
     */
    private function evaluateAutoRechargeAdmission(BusinessUsageWallet $wallet, Business $business, PayerType $payerType, int $amountMicro): CapEvaluation
    {
        $attempts = app(BusinessFundingAttemptRepository::class);
        $businessId = (int) $business->id;
        $now = Carbon::now();

        if ($wallet->monthly_recharge_cap_micro === null) {
            return new CapEvaluation(false, self::DENIAL_BUSINESS_RECHARGE_CAP_MISSING, '0');
        }

        $periodOpen = $wallet->recharge_period_end_utc === null || $now->lt($wallet->recharge_period_end_utc);
        $recharged = $periodOpen ? (int) $wallet->recharged_this_period_micro : 0;
        $pendingOwn = $attempts->outstandingAutoRechargeAmountMicroForBusinesses([$businessId]);

        $businessCeiling = $this->evaluateHeadroom(
            min((int) $wallet->monthly_recharge_cap_micro, self::BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO),
            $recharged + $pendingOwn,
            $amountMicro,
            self::DENIAL_BUSINESS_RECHARGE_CAP,
        );

        if (! $businessCeiling->allowed) {
            return $businessCeiling;
        }

        // (b) The rolling-window frequency slot. Counted over EVERY
        // automatically initiated attempt row created inside the window,
        // regardless of its current state — a failed or canceled attempt
        // released its money above but keeps its slot here.
        $windowStart = $now->copy()->subHours(self::AUTO_RECHARGE_ROLLING_WINDOW_HOURS);

        if ($attempts->countAutoRechargeAttemptsCreatedAfter($businessId, $windowStart) >= self::AUTO_RECHARGE_MAX_PER_ROLLING_WINDOW) {
            return new CapEvaluation(false, self::DENIAL_AUTO_RECHARGE_FREQUENCY, '0');
        }

        if ($payerType !== PayerType::Workspace) {
            return $businessCeiling;
        }

        $business->loadMissing('workspace');
        $workspaceId = (int) $business->workspace_id;
        $controls = DB::table('workspace_usage_controls')->where('workspace_id', $workspaceId)->first();
        $workspaceCap = $controls?->monthly_aggregate_recharge_cap_micro;

        if ($workspaceCap === null) {
            $isAgency = app(EntitlementManager::class)->getWorkspaceEntitlementSummary($business->workspace)->tier === WorkspacePlanTier::Agency;

            return $isAgency
                ? new CapEvaluation(false, self::DENIAL_WORKSPACE_RECHARGE_CAP_MISSING, '0')
                : $businessCeiling;
        }

        $timezone = $business->timezone !== '' && $business->timezone !== null ? $business->timezone : config('app.timezone');
        $periodKey = $periodOpen ? (string) $wallet->recharge_period_key : $this->computePeriodBoundaries($timezone, $now)['key'];
        $workspacePaidIds = $this->workspacePaidBusinessIdsQuery($workspaceId)->pluck('business_id')->map(static fn ($id): int => (int) $id)->all();

        $aggregateRecharged = $workspacePaidIds === [] ? 0 : (int) BusinessUsageWallet::query()
            ->whereIn('business_id', $workspacePaidIds)
            ->where('recharge_period_key', $periodKey)
            ->sum('recharged_this_period_micro');
        $aggregatePending = $workspacePaidIds === [] ? 0 : $attempts->outstandingAutoRechargeAmountMicroForBusinesses($workspacePaidIds, PayerType::Workspace->value);

        return $this->evaluateHeadroom(
            min((int) $workspaceCap, self::WORKSPACE_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO),
            $aggregateRecharged + $aggregatePending,
            $amountMicro,
            self::DENIAL_WORKSPACE_RECHARGE_CAP,
        );
    }

    /**
     * Correction Round 1 §7.3 — announces a refused automatic top-up (a
     * ceiling or the rolling-window limit) to the opted-in billing contact
     * at most once per rolling window, so the evaluation job's repeated
     * runs never spam the payer. The marker is set under the wallet lock;
     * balances, attempts and the failure counter are never touched — a
     * policy refusal is not a payment failure.
     */
    public function notifyAutoRechargeRefusal(int $businessId, string $reason): void
    {
        $shouldNotify = DB::transaction(function () use ($businessId): bool {
            $wallet = $this->walletRepository->findForUpdateByBusinessId($businessId);

            if ($wallet === null) {
                return false;
            }

            $windowStart = Carbon::now()->subHours(self::AUTO_RECHARGE_ROLLING_WINDOW_HOURS);

            if ($wallet->auto_recharge_refusal_notified_at !== null && Carbon::parse((string) $wallet->auto_recharge_refusal_notified_at)->gt($windowStart)) {
                return false;
            }

            $this->walletRepository->update($wallet, ['auto_recharge_refusal_notified_at' => Carbon::now()]);

            return true;
        });

        if (! $shouldNotify) {
            return;
        }

        $business = Business::query()->find($businessId);

        if ($business !== null) {
            $this->notifyBillingContact($businessId, new SpendingLimitReachedNotification($business->name, $reason, $this->customerMessageForDenial($reason)));
        }
    }

    /**
     * Customer Experience Slice 5 — "alerts before thresholds": announce,
     * once per period, every Business whose committed + reserved spend has
     * reached the alert share of its monthly limit (or of the Workspace
     * aggregate limit it counts towards). Called by the
     * usage:spending-threshold-alerts command. Returns the number of
     * Businesses alerted.
     */
    public function sendSpendingThresholdAlerts(): int
    {
        $sent = 0;

        $wallets = BusinessUsageWallet::query()
            ->whereNotNull('monthly_spend_cap_micro')
            ->where('monthly_spend_cap_micro', '>', 0)
            ->orderBy('id')
            ->get();

        foreach ($wallets as $wallet) {
            if ($wallet->spending_limit_alert_period_key === $wallet->spend_period_key) {
                continue;
            }

            $consumed = (int) $wallet->committed_spend_this_period_micro + (int) $wallet->reserved_spend_this_period_micro;
            $threshold = (int) bcdiv(bcmul((string) $wallet->monthly_spend_cap_micro, (string) self::SPENDING_THRESHOLD_ALERT_PERCENT), '100', 0);

            if ($consumed < $threshold) {
                continue;
            }

            $business = Business::query()->find((int) $wallet->business_id);

            if ($business === null) {
                continue;
            }

            $this->walletRepository->update($wallet, ['spending_limit_alert_period_key' => $wallet->spend_period_key]);

            $this->notifyBillingContact((int) $business->id, new SpendingLimitReachedNotification(
                $business->name,
                'business_spend_cap_threshold',
                __('locale.usage_billing.denials.business_spend_cap_threshold', ['percent' => self::SPENDING_THRESHOLD_ALERT_PERCENT]),
            ));

            $sent++;
        }

        return $sent;
    }

    private function setPaidActivityPaused(Business $business, bool $paused, int $actorUserId, string $reason): void
    {
        DB::transaction(function () use ($business, $paused, $actorUserId, $reason) {
            $this->assertCanManagePayerControls($business, $actorUserId);

            $wallet = $this->walletRepository->findForUpdateByBusinessId((int) $business->id);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException((int) $business->id);
            }

            $currentlyPaused = $wallet->paid_activity_paused_at !== null;

            if ($currentlyPaused === $paused) {
                return;
            }

            $this->recordControlTransition('business', (int) $business->id, self::CONTROL_BUSINESS_PAID_ACTIVITY, $currentlyPaused ? 'paused' : 'active', $paused ? 'paused' : 'active', $actorUserId, $reason);

            $this->walletRepository->update($wallet, [
                'paid_activity_paused_at' => $paused ? Carbon::now() : null,
                'paid_activity_paused_by_user_id' => $paused ? $actorUserId : null,
            ]);
        });
    }

    private function setWorkspacePaidActivityPaused(Workspace $workspace, bool $paused, int $actorUserId, string $reason): void
    {
        $this->assertCanManageWorkspaceUsageControls($workspace, $actorUserId);

        DB::transaction(function () use ($workspace, $paused, $actorUserId, $reason) {
            $row = $this->lockOrCreateWorkspaceControls((int) $workspace->id, $actorUserId);
            $currentlyPaused = $row->paid_activity_paused_at !== null;

            if ($currentlyPaused === $paused) {
                return;
            }

            $this->recordControlTransition('workspace', (int) $workspace->id, self::CONTROL_WORKSPACE_PAID_ACTIVITY, $currentlyPaused ? 'paused' : 'active', $paused ? 'paused' : 'active', $actorUserId, $reason);

            DB::table('workspace_usage_controls')->where('id', $row->id)->update([
                'paid_activity_paused_at' => $paused ? Carbon::now() : null,
                'paid_activity_paused_by_user_id' => $paused ? $actorUserId : null,
                'updated_by_user_id' => $actorUserId,
                'updated_at' => Carbon::now(),
            ]);
        });
    }

    private function lockWorkspaceControls(int $workspaceId): ?object
    {
        return DB::table('workspace_usage_controls')->where('workspace_id', $workspaceId)->lockForUpdate()->first();
    }

    private function lockOrCreateWorkspaceControls(int $workspaceId, int $actorUserId): object
    {
        $row = $this->lockWorkspaceControls($workspaceId);

        if ($row !== null) {
            return $row;
        }

        try {
            DB::table('workspace_usage_controls')->insert([
                'workspace_id' => $workspaceId,
                'updated_by_user_id' => $actorUserId,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent creator won; fall through to the locked read.
        }

        $row = $this->lockWorkspaceControls($workspaceId);

        if ($row === null) {
            throw new \RuntimeException("Workspace usage controls row for workspace {$workspaceId} could not be created.");
        }

        return $row;
    }

    private function recordControlTransition(string $scope, int $scopeId, string $control, ?string $from, ?string $to, int $actorUserId, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException('A reason is required for every spending-control change.');
        }

        DB::table('usage_control_transitions')->insert([
            'scope' => $scope,
            'scope_id' => $scopeId,
            'control' => $control,
            'from_value' => $from,
            'to_value' => $to,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'created_at' => Carbon::now(),
        ]);
    }

    private function isWorkspacePaid(int $businessId): bool
    {
        $assignment = app(BusinessPayerAssignmentRepository::class)->findByBusinessId($businessId);

        return ($assignment?->payer_type ?? \App\Enums\Usage\PayerType::Workspace) === \App\Enums\Usage\PayerType::Workspace;
    }

    /**
     * Committed + reserved spend this period across every wallet in the
     * Workspace whose Business is paid by the Workspace, in the same spend
     * period. Integer sum, read under the Workspace controls lock when
     * called from reserve().
     */
    private function workspacePaidSpendThisPeriod(int $workspaceId, string $periodKey): int
    {
        $row = BusinessUsageWallet::query()
            ->whereIn('business_id', $this->workspacePaidBusinessIdsQuery($workspaceId))
            ->where('spend_period_key', $periodKey)
            ->selectRaw('COALESCE(SUM(committed_spend_this_period_micro + reserved_spend_this_period_micro), 0) as total')
            ->toBase()
            ->first();

        return (int) ($row->total ?? 0);
    }

    /**
     * Customer Experience Slice 5 — the Business ids in a Workspace whose
     * payer is the Workspace: the wallets that count towards the Workspace
     * aggregate ceilings. A subquery resolved through the Eloquent models,
     * never a raw billing-table query (the Usage surface-boundary tests
     * reserve those for the Eloquent repository implementations).
     */
    private function workspacePaidBusinessIdsQuery(int $workspaceId): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Models\BusinessPayerAssignment::query()
            ->where('payer_type', \App\Enums\Usage\PayerType::Workspace->value)
            ->whereIn('business_id', Business::query()->where('workspace_id', $workspaceId)->select('id'))
            ->select('business_id');
    }

    /**
     * Sets the once-per-period alert marker under the wallet lock and
     * returns the reason to announce, or null when this period was already
     * announced.
     */
    private function markSpendingLimitAlert(BusinessUsageWallet $wallet, string $reason): ?string
    {
        if ($wallet->spending_limit_alert_period_key === $wallet->spend_period_key) {
            return null;
        }

        $this->walletRepository->update($wallet, ['spending_limit_alert_period_key' => $wallet->spend_period_key]);

        return $reason;
    }

    /**
     * Recipient resolution mirrors SendLowBalanceNotification exactly: the
     * opted-in billing contact's email, else nothing (never a guess).
     */
    private function notifyBillingContact(int $businessId, \Illuminate\Notifications\Notification $notification): void
    {
        $contact = app(BusinessBillingContactRepository::class)->findByBusinessId($businessId);

        if ($contact === null || ! $contact->notification_opt_in) {
            return;
        }

        $email = $contact->contact_user_id === null ? $contact->contact_email : $contact->contactUser?->email;

        if (blank($email)) {
            return;
        }

        Notification::route('mail', $email)->notify($notification);
    }

    private function assertNullOrNonNegativeInteger(?string $value): void
    {
        if ($value !== null && preg_match('/^\d+$/', $value) !== 1) {
            throw new \InvalidArgumentException('A spending limit must be a whole non-negative amount.');
        }
    }

    /**
     * Workspace-level controls belong to the account owner or an
     * Agency-wide (all-Business) active Admin — never a Business-scoped
     * member, Staff, or a Business user (contract §12.4, §18 S-6).
     */
    private function assertCanManageWorkspaceUsageControls(Workspace $workspace, int $actorUserId): void
    {
        if ((int) $workspace->owner_user_id === $actorUserId) {
            return;
        }

        $membership = app(WorkspaceMembershipRepository::class)->findByWorkspaceAndUser($workspace, $actorUserId);

        if ($membership !== null
            && $membership->is_active
            && $membership->role === WorkspaceMembershipRole::Admin
            && $membership->business_access_scope === WorkspaceBusinessAccessScope::All) {
            return;
        }

        throw new UnauthorizedUsageBillingManagementException($actorUserId, 0);
    }

    /**
     * M3 contract §15 — "Failed payment behavior: consecutive_recharge_failures
     * incremented (M1 column, first written by M3)." Called only by
     * EvaluateBusinessAutoRecharge, whenever a triggered auto-recharge
     * attempt reaches FundingAttemptState::Failed or RequiresAction within
     * that same job execution (RFC-005 §19, made authoritative by the
     * Job/Event Dispatch Completion Correction Contract §5 — superseding
     * this method's own prior Failed-only behavior). Preserves this
     * class's sole write authority for business_usage_wallets — the job
     * itself never writes this table directly.
     *
     * Correction Contract §5 items 3-5 — the 2->3 transition, while
     * auto_recharge_enabled is currently true, is the system-disable
     * edge: set exactly once, on that exact atomic mutation, never
     * reusing configureAutoRecharge(enabled: false) internally (that
     * method nulls threshold/amount/cap, which a system disable must
     * never do). Gated on === 3, not >= 3, so a defensive out-of-band
     * caller reached after the wallet is already disabled never
     * re-notifies for the same episode.
     */
    public function recordAutoRechargeFailure(int $businessId): void
    {
        DB::transaction(function () use ($businessId) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId($businessId);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException($businessId);
            }

            $newFailureCount = $wallet->consecutive_recharge_failures + 1;
            $walletUpdate = ['consecutive_recharge_failures' => $newFailureCount];

            $shouldDisableAndNotify = $newFailureCount === 3 && $wallet->auto_recharge_enabled;

            if ($shouldDisableAndNotify) {
                $walletUpdate['auto_recharge_enabled'] = false;
            }

            $this->walletRepository->update($wallet, $walletUpdate);

            if ($shouldDisableAndNotify) {
                \App\Jobs\Usage\SendAutoRechargeDisabledNotification::dispatch($businessId)->afterCommit();
            }
        });

        // Customer Experience Slice 5 (brief §7 "failed recharge must be
        // visible and alert the payer") — every failed attempt is announced
        // to the billing contact, after commit; the balance is never
        // touched here (the attempt row already records the failure).
        $business = Business::query()->find($businessId);

        if ($business !== null) {
            $this->notifyBillingContact($businessId, new AutoRechargeFailedNotification($business->name));
        }
    }

    /**
     * RFC-005 §16's "consent extended to every charge-causing action" rule
     * — evaluated against the wallet's CURRENT payer_type, mirroring
     * BillingProfileManager::assertPayerConsent() and
     * PaymentInstrumentManager/UsageBillingCheckoutManager's own identical
     * private method exactly (duplicated rather than shared, matching
     * this class's own existing assertCanManageBusinessUsageBilling()
     * duplication precedent — no common ancestor is authorized by any
     * merged contract).
     */
    private function assertChargeCausingConsentForAutoRecharge(Business $business, int $actorUserId): void
    {
        $business->loadMissing('workspace');

        $assignment = app(\App\Repositories\Contracts\BusinessPayerAssignmentRepository::class)->findByBusinessId((int) $business->id);
        $payerType = $assignment?->payer_type ?? \App\Enums\Usage\PayerType::Workspace;

        if ($payerType === \App\Enums\Usage\PayerType::Workspace) {
            if ((int) $business->workspace->owner_user_id === $actorUserId) {
                return;
            }

            throw new UnauthorizedUsageBillingManagementException($actorUserId, (int) $business->id);
        }

        if ((int) $business->customer_id === $actorUserId) {
            return;
        }

        throw new UnauthorizedUsageBillingManagementException($actorUserId, (int) $business->id);
    }

    /**
     * M2 contract §7 non-payer mutation authority: Workspace owner,
     * active Admin whose business_access_scope covers this Business, or
     * the direct Business owner/customer. Staff is never authorized to
     * mutate, even with matching scope. Mirrors
     * BillingProfileManager::assertCanManageBusinessUsageBilling()
     * exactly — duplicated rather than shared, since the two classes have
     * no common ancestor authorized by either contract.
     *
     * WorkspaceMembershipRepository/WorkspaceMembershipBusinessRepository
     * are resolved lazily here, rather than constructor-injected, since
     * only this one M2 method needs them — every M1 hot-path method
     * (reserve()/commit()/release()) never touches either, and eagerly
     * resolving them on every UsageWalletManager instantiation (including
     * every M1 call) is unnecessary constructor-resolution overhead this
     * class should not pay on paths that never use them.
     */
    /**
     * Correction Round 1 §9 — financial controls (spending limit,
     * capability limits, pause/resume) belong to the payer side: while the
     * Workspace pays, the Workspace owner or an Agency-wide active Admin;
     * while the Business pays, the direct Business owner. Generic
     * billing-management authority (assertCanManageBusinessUsageBilling(),
     * kept below) never implies it. The one matrix lives in
     * BillingProfileManager::actorManagesPayerControls().
     */
    private function assertCanManagePayerControls(Business $business, int $actorUserId): void
    {
        app(BillingProfileManager::class)->assertActorManagesPayerControls($business, $actorUserId);
    }

    private function assertCanManageBusinessUsageBilling(Business $business, int $actorUserId): void
    {
        $business->loadMissing('workspace');

        if ((int) $business->customer_id === $actorUserId) {
            return;
        }

        if ((int) $business->workspace->owner_user_id === $actorUserId) {
            return;
        }

        $membershipRepository = app(WorkspaceMembershipRepository::class);
        $membership = $membershipRepository->findByWorkspaceAndUser($business->workspace, $actorUserId);

        if ($membership !== null
            && $membership->is_active
            && $membership->role === WorkspaceMembershipRole::Admin
            && (
                $membership->business_access_scope === WorkspaceBusinessAccessScope::All
                || app(WorkspaceMembershipBusinessRepository::class)->isAssigned($membership, (int) $business->id)
            )
        ) {
            return;
        }

        throw new UnauthorizedUsageBillingManagementException($actorUserId, (int) $business->id);
    }

    /**
     * Mirrors EntitlementManager::assertPlatformAdministrator()'s exact
     * shape (RFC-004 §20) — a direct users.is_admin read, not one of the
     * seven M1 or seven M2 tenancy tables this class otherwise restricts
     * raw access to.
     */
    private function assertPlatformAdministrator(int $actorUserId): void
    {
        $isAdmin = (bool) DB::table('users')->where('id', $actorUserId)->value('is_admin');

        if (! $isAdmin) {
            throw new UnauthorizedUsageBillingManagementException($actorUserId, 0);
        }
    }

    /**
     * Round a non-negative bcmath numerator/denominator quotient to
     * $scale decimal places, half-up, without bcround() (unavailable
     * pre-PHP 8.4) — RFC-005 §10's exact algorithm.
     */
    public static function bcRoundHalfUp(string $numerator, string $denominator, int $scale = 0): string
    {
        $extraPrecision = $scale + 4;
        $rawQuotient = bcdiv($numerator, $denominator, $extraPrecision);
        $shift = bcpow('10', (string) $scale, 0);
        $shifted = bcmul($rawQuotient, $shift, $extraPrecision);

        return bcadd($shifted, '0.5', 0);
    }

    /**
     * RFC-005 Job/Event Dispatch Completion Correction Contract §4 — the
     * shared low_balance_notified_at set/clear evaluation, called from
     * every mutation site that changes available_balance_micro (reserve(),
     * commit()'s overage and unused-release branches, creditFromFunding(),
     * release()). Returns only the fragment to merge into that same
     * caller's own already-open walletRepository->update() call — never a
     * second query or a second write. Applies only when
     * auto_recharge_enabled is true and auto_recharge_threshold_micro is
     * configured (contract §4 item 2); a wallet outside that condition
     * returns an empty fragment and never sets $shouldDispatch.
     *
     * The episode rule is symmetric around the resulting balance, not the
     * direction of the caller's own delta: a positive-delta caller can
     * still legitimately set the marker (and set $shouldDispatch) if the
     * wallet remains at/below threshold and the marker was null — e.g.
     * eligibility was just established while already low — and a
     * negative-delta caller can still legitimately clear it if the
     * mutation happens to be a net recovery.
     */
    private function lowBalanceMarkerUpdate(BusinessUsageWallet $wallet, int $newAvailableBalanceMicro, bool &$shouldDispatch): array
    {
        $shouldDispatch = false;

        if (! $wallet->auto_recharge_enabled || $wallet->auto_recharge_threshold_micro === null) {
            return [];
        }

        $threshold = (int) $wallet->auto_recharge_threshold_micro;

        if ($newAvailableBalanceMicro <= $threshold) {
            if ($wallet->low_balance_notified_at === null) {
                $shouldDispatch = true;

                return ['low_balance_notified_at' => Carbon::now()];
            }

            return [];
        }

        if ($wallet->low_balance_notified_at !== null) {
            return ['low_balance_notified_at' => null];
        }

        return [];
    }

    /**
     * Narrowly identifies a MySQL duplicate-entry error (driver code
     * 1062) against the exact named unique constraint — mirroring
     * WorkspaceEntitlementBackfillV1's own narrow race-detection
     * discipline. Every other QueryException is not matched here.
     */
    private function isDuplicateRace(QueryException $e, string $constraintName): bool
    {
        $driverErrorCode = (int) ($e->errorInfo[1] ?? 0);

        return $driverErrorCode === 1062 && str_contains($e->getMessage(), $constraintName);
    }
}
