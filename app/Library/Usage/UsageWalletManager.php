<?php

namespace App\Library\Usage;

use App\Enums\Entitlement\PlatformFeature;
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
use App\Exceptions\Usage\InvalidReservationStateTransitionException;
use App\Exceptions\Usage\NoActiveRateForFeatureException;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Exceptions\Usage\UsageMeterBusinessScopeMismatchException;
use App\Exceptions\Usage\UsageMeterCurrencyMismatchException;
use App\Exceptions\Usage\UsageMeterNotMeteredException;
use App\Exceptions\Usage\UsageMeterRateIntegrityException;
use App\Exceptions\Usage\UsageReservationNotFoundException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Models\Business;
use App\Models\BusinessUsageReservation;
use App\Models\BusinessUsageWallet;
use App\Models\Currency;
use App\Repositories\Contracts\BusinessFeatureUsageLimitRepository;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
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
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    ) {
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
     * sufficiency. The first three of these six were M1's own original
     * scope; the per-feature limit, Business spend cap, and platform
     * safety limit were M2-designed but never connected here until this
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

        // RFC-005 Milestone 5 §3.8 correction — the race-loser catch must
        // surround DB::transaction() itself, not sit inside the closure.
        // DB::transaction() rolls the losing transaction back completely
        // (and rethrows) before this catch ever runs, so the narrow
        // constraint-name match and refetch below only ever happen against
        // a fully-closed transaction — never while the loser's own
        // transaction is still open.
        try {
            $result = DB::transaction(function () use ($business, $featureKey, $idempotencyKey, $estimatedQuantity, &$shouldDispatchAutoRecharge) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId($business->id);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException($business->id);
            }

            $wallet = $this->rollOverPeriodsIfNeeded($wallet, $business);

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
                return new ReservationResult(false, null, $businessSpendCapEvaluation->denialReason, false);
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
                return new ReservationResult(false, null, 'insufficient_balance', false);
            }

            $reservedAt = Carbon::now();

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

            $this->walletRepository->update($wallet, [
                'available_balance_micro' => $wallet->available_balance_micro - $reservedAmountMicro,
                'reserved_balance_micro' => $wallet->reserved_balance_micro + $reservedAmountMicro,
                'reserved_spend_this_period_micro' => $wallet->reserved_spend_this_period_micro + $reservedAmountMicro,
            ]);

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

        $result = DB::transaction(function () use ($reservationId, $finalQuantity, &$shouldDispatchAutoRecharge, &$dispatchBusinessId) {
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

            $chargedPortion = min($finalAmountMicro, $reservedAmountMicro);

            $this->ledgerRepository->create([
                'business_id' => $reservation->business_id,
                'wallet_id' => $wallet->id,
                'entry_type' => UsageLedgerEntryType::UsageCharge->value,
                'available_delta_micro' => 0,
                'reserved_delta_micro' => -$chargedPortion,
                'debt_delta_micro' => 0,
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

                $this->ledgerRepository->create([
                    'business_id' => $reservation->business_id,
                    'wallet_id' => $wallet->id,
                    'entry_type' => UsageLedgerEntryType::UsageOverageCharge->value,
                    'available_delta_micro' => -$overageFromAvailable,
                    'reserved_delta_micro' => 0,
                    'debt_delta_micro' => $overageToDebt,
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
                    $dispatchBusinessId = (int) $reservation->business_id;
                }
            } elseif ($finalAmountMicro < $reservedAmountMicro) {
                $hadUnusedRelease = true;
                $unused = $reservedAmountMicro - $finalAmountMicro;

                $this->ledgerRepository->create([
                    'business_id' => $reservation->business_id,
                    'wallet_id' => $wallet->id,
                    'entry_type' => UsageLedgerEntryType::ReservationRelease->value,
                    'available_delta_micro' => $unused,
                    'reserved_delta_micro' => -$unused,
                    'debt_delta_micro' => 0,
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
            }

            $this->reservationRepository->update($reservation, [
                'status' => UsageReservationStatus::Committed->value,
                'committed_at' => $committedAt,
                'final_quantity' => $quantity,
                'final_amount_micro' => $finalAmountMicro,
            ]);

            $walletUpdate = [
                'available_balance_micro' => $wallet->available_balance_micro + $availableDelta,
                'reserved_balance_micro' => $wallet->reserved_balance_micro + $reservedDelta,
                'debt_balance_micro' => $wallet->debt_balance_micro + $debtDelta,
            ];

            if ($isCurrentPeriod) {
                $walletUpdate['committed_spend_this_period_micro'] = $wallet->committed_spend_this_period_micro + $committedFormulaAmount;
                $walletUpdate['reserved_spend_this_period_micro'] = $wallet->reserved_spend_this_period_micro - $reservedAmountMicro;
            }

            $this->walletRepository->update($wallet, $walletUpdate);

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
        DB::transaction(function () use ($reservationId) {
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
                'reserved_balance_micro' => $wallet->reserved_balance_micro - $amount,
            ];

            if ($reservation->period_key === $wallet->spend_period_key) {
                $walletUpdate['reserved_spend_this_period_micro'] = $wallet->reserved_spend_this_period_micro - $amount;
            }

            $this->walletRepository->update($wallet, $walletUpdate);
        });
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

            $this->ledgerRepository->create([
                'business_id' => $businessId,
                'wallet_id' => $wallet->id,
                'entry_type' => $entryType->value,
                'available_delta_micro' => $remainder,
                'reserved_delta_micro' => 0,
                'debt_delta_micro' => -$debtCleared,
                'currency_id' => $wallet->currency_id,
                'funding_attempt_id' => $fundingAttemptId,
                'correlation_key' => $correlationKey,
                'created_at' => Carbon::now(),
            ]);

            $walletUpdate = [
                'available_balance_micro' => $wallet->available_balance_micro + $remainder,
                'debt_balance_micro' => $wallet->debt_balance_micro - $debtCleared,
            ];

            if ($entryType === UsageLedgerEntryType::AutoRecharge && $wallet->recharge_period_key !== null) {
                $walletUpdate['recharged_this_period_micro'] = $wallet->recharged_this_period_micro + $amountMicro;
                $walletUpdate['consecutive_recharge_failures'] = 0;
            }

            $this->walletRepository->update($wallet, $walletUpdate);
        });
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
            $this->assertCanManageBusinessUsageBilling($business, $actorUserId);

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
        if (! PlatformFeatureRegistry::isAvailable($featureKey)) {
            throw new NoActiveRateForFeatureException($featureKey);
        }

        DB::transaction(function () use ($business, $featureKey, $limitMicro, $actorUserId, $reason) {
            $this->assertCanManageBusinessUsageBilling($business, $actorUserId);

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

        DB::transaction(function () use ($business, $enabled, $thresholdMicro, $amountMicro, $monthlyCapMicro) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId((int) $business->id);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException((int) $business->id);
            }

            $this->walletRepository->update($wallet, [
                'auto_recharge_enabled' => $enabled,
                'auto_recharge_threshold_micro' => $enabled ? $thresholdMicro : null,
                'auto_recharge_amount_micro' => $enabled ? $amountMicro : null,
                'monthly_recharge_cap_micro' => $monthlyCapMicro,
            ]);
        });
    }

    /**
     * M3 contract §15 — "Failed payment behavior: consecutive_recharge_failures
     * incremented (M1 column, first written by M3)." Called only by
     * EvaluateBusinessAutoRecharge, only when a triggered auto-recharge
     * attempt reaches FundingAttemptState::Failed within that same job
     * execution (never on requires_action, never a retry loop). Preserves
     * this class's sole write authority for business_usage_wallets — the
     * job itself never writes this table directly.
     */
    public function recordAutoRechargeFailure(int $businessId): void
    {
        DB::transaction(function () use ($businessId) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId($businessId);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException($businessId);
            }

            $this->walletRepository->update($wallet, [
                'consecutive_recharge_failures' => $wallet->consecutive_recharge_failures + 1,
            ]);
        });
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
