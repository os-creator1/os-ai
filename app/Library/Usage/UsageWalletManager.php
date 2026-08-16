<?php

namespace App\Library\Usage;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Usage\RoundingRule;
use App\Enums\Usage\UsageLedgerEntryType;
use App\Enums\Usage\UsageReservationStatus;
use App\Enums\Usage\WalletBillingStatus;
use App\Exceptions\Usage\BusinessCurrencyUnresolvableException;
use App\Exceptions\Usage\InvalidReservationStateTransitionException;
use App\Exceptions\Usage\NoActiveRateForFeatureException;
use App\Exceptions\Usage\UsageReservationNotFoundException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Models\Business;
use App\Models\BusinessUsageReservation;
use App\Models\BusinessUsageWallet;
use App\Models\Currency;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
use App\Repositories\Contracts\BusinessUsageRateActivationRepository;
use App\Repositories\Contracts\BusinessUsageRateRepository;
use App\Repositories\Contracts\BusinessUsageReservationRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PlatformFeatureUsageClassificationRepository;
use App\Repositories\Contracts\PlatformFeatureUsageClassificationTransitionRepository;
use Illuminate\Database\QueryException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sole write authority for all seven RFC-005 Milestone 1 tables (M1
 * contract §10.1). No credit/top-up/debt-clearing/billing-status-transition
 * method exists here — those are M2+/M3+/M4 scope (M1 contract §5.7). No
 * auto-recharge dispatch of any kind occurs from any method in this class
 * (Correction Round 1, §5.7).
 */
class UsageWalletManager
{
    private const RESERVATION_TTL_MINUTES = 30;

    public function __construct(
        private readonly BusinessUsageWalletRepository $walletRepository,
        private readonly BusinessUsageRateRepository $rateRepository,
        private readonly BusinessUsageRateActivationRepository $rateActivationRepository,
        private readonly PlatformFeatureUsageClassificationRepository $classificationRepository,
        private readonly PlatformFeatureUsageClassificationTransitionRepository $classificationTransitionRepository,
        private readonly BusinessUsageReservationRepository $reservationRepository,
        private readonly BusinessUsageLedgerEntryRepository $ledgerRepository,
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
     * RFC-005 §13's reserve() algorithm, narrowed to M1's own evaluation
     * order (per-feature limit, Business spend cap, and platform safety
     * limit are skipped — their tables do not exist until M2, M1 contract
     * §8 item 1). No EvaluateBusinessAutoRecharge dispatch of any kind
     * (Correction Round 1, §5.7).
     */
    public function reserve(Business $business, string $featureKey, string $idempotencyKey, ?string $estimatedQuantity = null): ReservationResult
    {
        $existing = $this->reservationRepository->findByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            return new ReservationResult(true, $existing->id, null);
        }

        return DB::transaction(function () use ($business, $featureKey, $idempotencyKey, $estimatedQuantity) {
            $wallet = $this->walletRepository->findForUpdateByBusinessId($business->id);

            if ($wallet === null) {
                throw new UsageWalletNotFoundException($business->id);
            }

            $wallet = $this->rollOverPeriodsIfNeeded($wallet, $business);

            $classification = $this->classificationRepository->findByFeatureKey($featureKey);

            if ($classification === null || $classification->active_rate_id === null) {
                throw new NoActiveRateForFeatureException($featureKey);
            }

            $rate = $this->rateRepository->findById((int) $classification->active_rate_id);

            if ($rate === null) {
                throw new NoActiveRateForFeatureException($featureKey);
            }

            $quantity = $estimatedQuantity ?? '1';
            $reservedAmountMicro = (int) self::bcRoundHalfUp(
                bcmul((string) $rate->retail_rate_micro, $quantity, 10),
                '1',
            );

            if ($wallet->billing_status === WalletBillingStatus::Suspended) {
                return new ReservationResult(false, null, 'wallet_suspended');
            }

            if ($wallet->debt_balance_micro > 0) {
                return new ReservationResult(false, null, 'outstanding_debt');
            }

            if ($wallet->available_balance_micro < $reservedAmountMicro) {
                return new ReservationResult(false, null, 'insufficient_balance');
            }

            $reservedAt = Carbon::now();

            $reservation = $this->reservationRepository->create([
                'business_id' => $business->id,
                'wallet_id' => $wallet->id,
                'feature_key' => $featureKey,
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
                'feature_key' => $featureKey,
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

            $this->walletRepository->update($wallet, [
                'available_balance_micro' => $wallet->available_balance_micro - $reservedAmountMicro,
                'reserved_balance_micro' => $wallet->reserved_balance_micro + $reservedAmountMicro,
                'reserved_spend_this_period_micro' => $wallet->reserved_spend_this_period_micro + $reservedAmountMicro,
            ]);

            return new ReservationResult(true, $reservation->id, null);
        });
    }

    /**
     * RFC-005 §13's commit() algorithm, using the corrected committed-
     * amount formula. Idempotent: a repeat commit on an already-committed
     * reservation is a no-op that reconstructs the original CommitResult.
     */
    public function commit(int $reservationId, ?string $finalQuantity = null): CommitResult
    {
        return DB::transaction(function () use ($reservationId, $finalQuantity) {
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
     * RFC-005 §11's setActiveRate() algorithm. Present at M1 so M5 has a
     * ready target; never called by any M1 production code path (no HTTP
     * surface exists). The classification row must already exist
     * (guaranteed by M1's own backfill for every known PlatformFeature
     * case).
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
            $classification = $this->classificationRepository->findForUpdateByFeatureKey($featureKey);

            if ($classification === null) {
                throw new NoActiveRateForFeatureException($featureKey);
            }

            $nextVersion = $this->rateRepository->latestVersionForFeature($featureKey) + 1;

            $rate = $this->rateRepository->create([
                'feature_key' => $featureKey,
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
                'feature_key' => $featureKey,
                'rate_id' => $rate->id,
                'activated_at' => Carbon::now(),
                'activated_by_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => Carbon::now(),
            ]);

            $this->classificationRepository->update($classification, [
                'active_rate_id' => $rate->id,
                'updated_by_user_id' => $actorUserId,
            ]);

            return $rate;
        });
    }

    /**
     * RFC-005 §11's activateMetering(). Present at M1; never called by any
     * M1 production code path (no metered feature is authorized until
     * M5). Requires an already-activated rate for the feature.
     */
    public function activateMetering(string $featureKey, int $actorUserId, string $reason): void
    {
        DB::transaction(function () use ($featureKey, $actorUserId, $reason) {
            $classification = $this->classificationRepository->findForUpdateByFeatureKey($featureKey);

            if ($classification === null || $classification->active_rate_id === null) {
                throw new NoActiveRateForFeatureException($featureKey);
            }

            $this->classificationTransitionRepository->create([
                'feature_key' => $featureKey,
                'from_is_metered' => $classification->is_metered,
                'to_is_metered' => true,
                'from_active_rate_id' => $classification->active_rate_id,
                'to_active_rate_id' => $classification->active_rate_id,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => Carbon::now(),
            ]);

            $this->classificationRepository->update($classification, [
                'is_metered' => true,
                'updated_by_user_id' => $actorUserId,
            ]);
        });
    }

    /**
     * Internal-only coarse capacity gate consumed exclusively by
     * RealUsageAuthorizationGateway. Never surfaced past that boundary
     * (RFC-005 §14). At M1, every PlatformFeature classification has
     * is_metered=false (M1's own backfill, §9.1), so this always
     * short-circuits to authorized:true before touching any wallet state
     * — the safe default for a non-metered/unknown classification.
     */
    public function evaluateCoarseCapacity(Business $business, PlatformFeature $feature): UsageCapacityDecision
    {
        $classification = $this->classificationRepository->findByFeatureKey($feature->value);

        if ($classification === null || ! $classification->is_metered) {
            return new UsageCapacityDecision(true);
        }

        $wallet = $this->walletRepository->findByBusinessId($business->id);

        if ($wallet === null) {
            return new UsageCapacityDecision(false, 'wallet_missing');
        }

        if ($wallet->billing_status === WalletBillingStatus::Suspended) {
            return new UsageCapacityDecision(false, 'wallet_suspended');
        }

        if ($wallet->debt_balance_micro > 0) {
            return new UsageCapacityDecision(false, 'outstanding_debt');
        }

        return new UsageCapacityDecision(true);
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
