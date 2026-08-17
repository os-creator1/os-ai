<?php

namespace App\Library\Usage;

use App\Enums\Usage\FundingAttemptPurpose;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Enums\Usage\TransitionSource;
use App\Enums\Usage\UsageLedgerEntryType;
use App\Exceptions\Usage\FundingAttemptNotResumableException;
use App\Exceptions\Usage\ProviderApiUnavailableException;
use App\Exceptions\Usage\ProviderCardDeclinedException;
use App\Exceptions\Usage\ProviderInvalidRequestException;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Models\Business;
use App\Models\BusinessFundingAttempt;
use App\Models\PaymentProviderEvent;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessFundingAttemptTransitionRepository;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;
use App\Repositories\Contracts\BusinessPaymentInstrumentRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M3 contract §3.11/§8/§11 — the funding-attempt/provider-event slice of
 * the RFC's own UsageBillingCheckoutManager (RFC-005 §28), scoped
 * exclusively to manual top-up and auto-recharge (business_funding_attempts,
 * business_funding_attempt_transitions, payment_provider_events write
 * authority). Additional-slot-agreement/renewal/add-on-purchase
 * responsibility is explicitly out of scope (M3 contract §7) — a future M4
 * contract extends this same class incrementally, exactly as M2 extended
 * UsageWalletManager. Every outbound PaymentProviderGateway call happens
 * strictly outside any database transaction/lock (§8/§16).
 */
class UsageBillingCheckoutManager
{
    /**
     * M3 contract §12 (Correction Round 1) — Stripe's own documented
     * zero-decimal currency list, re-confirmed against Stripe's current
     * documentation at implementation time.
     */
    private const ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /**
     * M3 contract §12 (Correction Round 1) — Stripe's own documented
     * three-decimal currency list.
     */
    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /**
     * M3 contract §12 (Correction Round 1) — every other currency Stripe
     * supports. M3's own test-mode scope exercises USD only; the fail-
     * closed mechanism and the 0-/3-decimal tiers exist unconditionally
     * but are not exercised by any M3 test.
     */
    private const TWO_DECIMAL_CURRENCIES = ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'CHF', 'NZD', 'SGD', 'HKD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'MXN', 'BRL', 'INR', 'ZAR', 'AED', 'SAR'];

    /**
     * M3 contract §12 — Stripe's own generally-documented minimum charge
     * (the equivalent of $0.50 USD, applied here as the same 50-minor-
     * unit floor Stripe uses for the large majority of its supported
     * currencies) — re-confirmed against Stripe's own current
     * documentation at implementation time. A production launch exercising
     * a currency whose exact minimum differs from this general baseline
     * requires that exact figure to be re-verified before going live —
     * this contract's own test-mode scope exercises USD only (§12).
     */
    private const MINIMUM_MINOR_UNITS = 50;

    /**
     * M3 contract §12 — Stripe's PaymentIntent `amount` currently supports
     * up to eight digits in the currency's smallest unit.
     */
    private const MAXIMUM_MINOR_UNITS = 99_999_999;

    public function __construct(
        private readonly BusinessFundingAttemptRepository $attemptRepository,
        private readonly BusinessFundingAttemptTransitionRepository $transitionRepository,
        private readonly BusinessPaymentInstrumentRepository $instrumentRepository,
        private readonly BusinessPayerAssignmentRepository $payerAssignmentRepository,
        private readonly BusinessBillingContactRepository $billingContactRepository,
        private readonly BusinessUsageWalletRepository $walletRepository,
        private readonly PaymentProviderCustomerRepository $providerCustomerRepository,
        private readonly UsageWalletManager $walletManager,
        private readonly PaymentProviderGateway $gateway,
    ) {
    }

    /**
     * M3 contract §11 — the manual top-up state machine. Creates the
     * local attempt (state: created) in its own short transaction before
     * any provider action (item 1), then charges the Business's current
     * default instrument via an off-session PaymentIntent, strictly
     * outside that transaction (item 3).
     */
    public function initiateTopUp(Business $business, int $actorUserId, int $amountMicro): FundingAttemptResult
    {
        $payerType = $this->assertChargeCausingConsent($business, $actorUserId);

        return $this->initiateCharge($business, FundingAttemptPurpose::ManualTopUp, $payerType, $amountMicro, $actorUserId);
    }

    /**
     * System-initiated — no actor consent check, since EvaluateBusinessAutoRecharge
     * itself already revalidated payer/instrument/threshold/cap before
     * calling this (M3 contract §15).
     */
    public function initiateAutoRecharge(Business $business, int $amountMicro): FundingAttemptResult
    {
        $wallet = $this->walletRepository->findByBusinessId((int) $business->id);
        $payerType = $wallet !== null ? ($this->payerAssignmentRepository->findByBusinessId((int) $business->id)?->payer_type ?? PayerType::Workspace) : PayerType::Workspace;

        return $this->initiateCharge($business, FundingAttemptPurpose::AutoRecharge, $payerType, $amountMicro, null);
    }

    private function initiateCharge(Business $business, FundingAttemptPurpose $purpose, PayerType $payerType, int $amountMicro, ?int $actorUserId): FundingAttemptResult
    {
        $businessId = (int) $business->id;
        $wallet = $this->walletRepository->findByBusinessId($businessId);

        if ($wallet === null) {
            throw new UsageWalletNotFoundException($businessId);
        }

        $business->loadMissing('workspace');
        $providerCustomer = $payerType === PayerType::Workspace
            ? $this->providerCustomerRepository->findActiveByWorkspaceId((int) $business->workspace->id)
            : $this->providerCustomerRepository->findActiveByBusinessId($businessId);

        if ($providerCustomer === null) {
            return new FundingAttemptResult(0, FundingAttemptState::Failed, 'no_provider_customer');
        }

        $instrument = $this->instrumentRepository->findDefaultForProviderCustomer($providerCustomer->id);

        if ($instrument === null) {
            return new FundingAttemptResult(0, FundingAttemptState::Failed, 'no_payment_instrument');
        }

        $contact = $this->billingContactRepository->findByBusinessId($businessId);
        $idempotencyKey = 'funding-attempt-'.$purpose->value.'-'.$businessId.'-'.Str::uuid();

        // M3 contract §15 — "Outstanding attempt idempotency": the wallet
        // row lock serializes this check-then-create against any other
        // concurrent EvaluateBusinessAutoRecharge evaluation for the same
        // Business, closing the TOCTOU window a bare read-then-insert
        // would otherwise leave open (a burst of negative-delta ledger
        // entries must never spawn two concurrent duplicate recharge
        // attempts). Not required for manual top-up (no §15 outstanding-
        // attempt rule applies there), but locking unconditionally keeps
        // attempt creation for both purposes inside one consistent,
        // already-established wallet-row-lock pattern.
        $attempt = DB::transaction(function () use ($businessId, $wallet, $purpose, $payerType, $contact, $providerCustomer, $instrument, $actorUserId, $amountMicro, $idempotencyKey) {
            $this->walletRepository->findForUpdateByBusinessId($businessId);

            if ($purpose === FundingAttemptPurpose::AutoRecharge
                && $this->attemptRepository->findOutstandingForBusiness($businessId, FundingAttemptPurpose::AutoRecharge->value) !== null) {
                return null;
            }

            $attempt = $this->attemptRepository->create([
                'business_id' => $businessId,
                'wallet_id' => $wallet->id,
                'purpose' => $purpose->value,
                'payer_type_snapshot' => $payerType->value,
                'billing_contact_name_snapshot' => $contact?->contact_name,
                'billing_contact_email_snapshot' => $contact?->contact_email,
                'provider_customer_external_id_snapshot' => $providerCustomer->provider_customer_id,
                'provider_customer_id' => $providerCustomer->id,
                'payment_method_display_snapshot' => $this->formatInstrumentDisplay($instrument),
                'requesting_actor_user_id' => $actorUserId,
                'expected_currency_id' => $wallet->currency_id,
                'expected_amount_micro' => $amountMicro,
                'local_idempotency_key' => $idempotencyKey,
                'state' => FundingAttemptState::Created->value,
            ]);

            $this->recordTransition($attempt, null, FundingAttemptState::Created, TransitionSource::SyncResponse, null, $actorUserId);

            return $attempt;
        });

        if ($attempt === null) {
            return new FundingAttemptResult(0, FundingAttemptState::Failed, 'auto_recharge_already_in_flight');
        }

        $currencyCode = (string) DB::table('currencies')->where('id', $wallet->currency_id)->value('code');

        try {
            $minorUnits = $this->microToMinorUnits($amountMicro, $currencyCode);
            $this->assertWithinStripeAmountBounds($minorUnits);

            $paymentIntent = $this->gateway->createOffSessionPaymentIntent(
                $providerCustomer->provider_customer_id,
                $instrument->provider_payment_method_id,
                $minorUnits,
                $currencyCode,
                $idempotencyKey,
                [
                    'app_subject_kind' => 'funding_attempt',
                    'app_subject_id' => (string) $attempt->id,
                    'app_operation_id' => $idempotencyKey,
                ],
            );
        } catch (ProviderCardDeclinedException $e) {
            $this->markFailed($attempt, $e->getMessage(), TransitionSource::SyncResponse, null);

            return new FundingAttemptResult($attempt->id, FundingAttemptState::Failed, 'card_declined');
        } catch (ProviderApiUnavailableException $e) {
            $this->markFailed($attempt, $e->getMessage(), TransitionSource::SyncResponse, null);

            return new FundingAttemptResult($attempt->id, FundingAttemptState::Failed, 'provider_unavailable');
        } catch (ProviderInvalidRequestException $e) {
            $this->markFailed($attempt, $e->getMessage(), TransitionSource::SyncResponse, null);

            return new FundingAttemptResult($attempt->id, FundingAttemptState::Failed, 'invalid_request');
        }

        $attempt = $this->attemptRepository->update($attempt, [
            'provider_session_or_intent_reference' => $paymentIntent->providerPaymentIntentId,
            'state' => $paymentIntent->status === 'requires_action' ? FundingAttemptState::RequiresAction->value : FundingAttemptState::ProviderPending->value,
        ]);

        $newState = $paymentIntent->status === 'requires_action' ? FundingAttemptState::RequiresAction : FundingAttemptState::ProviderPending;
        $this->recordTransition($attempt, FundingAttemptState::Created, $newState, TransitionSource::SyncResponse, null, null);

        if ($paymentIntent->status === 'succeeded') {
            $this->confirmSucceeded($attempt, TransitionSource::SyncResponse, null);

            return new FundingAttemptResult($attempt->id, FundingAttemptState::Succeeded, null);
        }

        return new FundingAttemptResult($attempt->id, $newState, null);
    }

    /**
     * M3 contract §10 item 6/§11 item 11 — the synchronous confirmation
     * path a browser-return handler calls. Never trusts the redirect
     * alone; independently retrieves the PaymentIntent from the provider
     * before ever crediting.
     */
    public function confirmAttemptFromReturn(BusinessFundingAttempt $attempt): FundingAttemptResult
    {
        if ($attempt->state === FundingAttemptState::Succeeded) {
            return new FundingAttemptResult($attempt->id, $attempt->state, null);
        }

        if ($attempt->provider_session_or_intent_reference === null) {
            return new FundingAttemptResult($attempt->id, $attempt->state, null);
        }

        $paymentIntent = $this->gateway->retrievePaymentIntent($attempt->provider_session_or_intent_reference);

        if ($paymentIntent->status === 'succeeded') {
            $this->confirmSucceeded($attempt, TransitionSource::SyncResponse, null);

            return new FundingAttemptResult($attempt->id, FundingAttemptState::Succeeded, null);
        }

        return new FundingAttemptResult($attempt->id, $attempt->state, null);
    }

    /**
     * M3 contract §13 — called by ProcessPaymentProviderEvent once a
     * webhook event has been fully validated against this exact attempt
     * (provider object id, amount, currency, purpose, expected state).
     */
    public function confirmAttemptFromWebhook(BusinessFundingAttempt $attempt, PaymentProviderEvent $event): void
    {
        if ($attempt->state === FundingAttemptState::Succeeded) {
            return;
        }

        $this->confirmSucceeded($attempt, TransitionSource::WebhookEvent, $event->id);
    }

    public function markAttemptFailedFromWebhook(BusinessFundingAttempt $attempt, string $reason, PaymentProviderEvent $event): void
    {
        if (in_array($attempt->state, [FundingAttemptState::Succeeded, FundingAttemptState::Failed, FundingAttemptState::Canceled], true)) {
            return;
        }

        $this->markFailed($attempt, $reason, TransitionSource::WebhookEvent, $event->id);
    }

    /**
     * M3 contract §14/§17 — platform administrator resume-only authority.
     * Never originates a new attempt; only re-drives an already-created,
     * payer-authorized one that is stuck.
     */
    public function retryFundingAttemptAsAdministrator(BusinessFundingAttempt $attempt, int $actorUserId, string $reason): FundingAttemptResult
    {
        if (! in_array($attempt->state, [FundingAttemptState::ProviderPending, FundingAttemptState::RequiresAction, FundingAttemptState::Failed], true)) {
            throw new FundingAttemptNotResumableException($attempt->id, $attempt->state->value);
        }

        if ($attempt->provider_session_or_intent_reference === null) {
            throw new FundingAttemptNotResumableException($attempt->id, $attempt->state->value);
        }

        $paymentIntent = $this->gateway->retrievePaymentIntent($attempt->provider_session_or_intent_reference);

        if ($paymentIntent->status === 'succeeded') {
            $this->confirmSucceeded($attempt, TransitionSource::AdminAction, null, $actorUserId);

            return new FundingAttemptResult($attempt->id, FundingAttemptState::Succeeded, null);
        }

        return new FundingAttemptResult($attempt->id, $attempt->state, null);
    }

    private function confirmSucceeded(BusinessFundingAttempt $attempt, TransitionSource $source, ?int $providerEventId, ?int $actorUserId = null): void
    {
        $fromState = $attempt->state;

        $this->attemptRepository->update($attempt, ['state' => FundingAttemptState::Succeeded->value]);
        $this->recordTransition($attempt, $fromState, FundingAttemptState::Succeeded, $source, $providerEventId, $actorUserId);

        $entryType = $attempt->purpose === FundingAttemptPurpose::AutoRecharge
            ? UsageLedgerEntryType::AutoRecharge
            : UsageLedgerEntryType::PaidTopUp;

        $this->walletManager->creditFromFunding(
            (int) $attempt->business_id,
            $entryType,
            (int) $attempt->expected_amount_micro,
            (int) $attempt->id,
            $attempt->local_idempotency_key.':credit',
        );
    }

    /**
     * M3 contract §13 step 10 — the exact minor-unit amount a verified
     * webhook event must carry to match this attempt's own frozen
     * expectation. Used by ProcessPaymentProviderEvent for pre-mutation
     * validation, never by any code path that itself calls the provider.
     */
    public function expectedMinorUnitsFor(BusinessFundingAttempt $attempt): int
    {
        $currencyCode = (string) DB::table('currencies')->where('id', $attempt->expected_currency_id)->value('code');

        return $this->microToMinorUnits((int) $attempt->expected_amount_micro, $currencyCode);
    }

    /**
     * M3 contract §13 step 10 — the attempt's own frozen settlement
     * currency code, for direct comparison against a verified webhook
     * event's currency without re-deriving the exponent.
     */
    public function expectedCurrencyCodeFor(BusinessFundingAttempt $attempt): string
    {
        return (string) DB::table('currencies')->where('id', $attempt->expected_currency_id)->value('code');
    }

    private function markFailed(BusinessFundingAttempt $attempt, string $reason, TransitionSource $source, ?int $providerEventId): void
    {
        $fromState = $attempt->state;

        $this->attemptRepository->update($attempt, [
            'state' => FundingAttemptState::Failed->value,
            'failure_reason' => $reason,
        ]);

        $this->recordTransition($attempt, $fromState, FundingAttemptState::Failed, $source, $providerEventId, null);
    }

    private function recordTransition(BusinessFundingAttempt $attempt, ?FundingAttemptState $from, FundingAttemptState $to, TransitionSource $source, ?int $providerEventId, ?int $actorUserId): void
    {
        $this->transitionRepository->create([
            'funding_attempt_id' => $attempt->id,
            'from_state' => ($from ?? $to)->value,
            'to_state' => $to->value,
            'source' => $source->value,
            'provider_event_id' => $providerEventId,
            'actor_user_id' => $actorUserId,
            'created_at' => now(),
        ]);
    }

    private function formatInstrumentDisplay($instrument): string
    {
        return sprintf('%s •••• %s, exp %02d/%d', $instrument->brand ?? $instrument->type->value, $instrument->last_four ?? '????', $instrument->expiry_month ?? 0, $instrument->expiry_year ?? 0);
    }

    /**
     * M3 contract §12 (Correction Round 1) — converts internal
     * micro-units to the provider's minor-unit integer. The legacy
     * `currencies` table carries no decimal_places/exponent column of any
     * kind, so the exponent is resolved from `currencies.code` (a column
     * that does exist) through an explicit, static, three-tier map
     * matching Stripe's own currently-published currency-decimal
     * documentation — a provider-authoritative mechanism, not an invented
     * default. A code absent from all three tiers fails closed via
     * ProviderInvalidRequestException before any provider call, never a
     * silent two-decimal guess.
     */
    private function microToMinorUnits(int $amountMicro, string $currencyCode): int
    {
        $exponent = match (true) {
            in_array($currencyCode, self::ZERO_DECIMAL_CURRENCIES, true) => 0,
            in_array($currencyCode, self::THREE_DECIMAL_CURRENCIES, true) => 3,
            in_array($currencyCode, self::TWO_DECIMAL_CURRENCIES, true) => 2,
            default => throw new ProviderInvalidRequestException("Unsupported currency code for minor-unit conversion: {$currencyCode}"),
        };

        $divisor = bcpow('10', (string) (6 - $exponent));

        return (int) bcdiv((string) $amountMicro, $divisor, 0);
    }

    /**
     * M3 contract §12 — every outbound Stripe amount is checked against
     * Stripe's documented minimum and eight-digit maximum before any
     * provider call, never assumed acceptable.
     */
    private function assertWithinStripeAmountBounds(int $minorUnits): void
    {
        if ($minorUnits < self::MINIMUM_MINOR_UNITS) {
            throw new ProviderInvalidRequestException("Amount {$minorUnits} is below Stripe's minimum charge amount.");
        }

        if ($minorUnits > self::MAXIMUM_MINOR_UNITS) {
            throw new ProviderInvalidRequestException("Amount {$minorUnits} exceeds Stripe's eight-digit maximum charge amount.");
        }
    }

    private function assertChargeCausingConsent(Business $business, int $actorUserId): PayerType
    {
        $business->loadMissing('workspace');

        $assignment = $this->payerAssignmentRepository->findByBusinessId((int) $business->id);
        $payerType = $assignment?->payer_type ?? PayerType::Workspace;

        if ($payerType === PayerType::Workspace) {
            if ((int) $business->workspace->owner_user_id === $actorUserId) {
                return $payerType;
            }

            throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $payerType->value);
        }

        if ($payerType === PayerType::Business) {
            if ((int) $business->customer_id === $actorUserId) {
                return $payerType;
            }

            throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $payerType->value);
        }

        throw new UnauthorizedPayerAssignmentException($actorUserId, (int) $business->id, $payerType->value);
    }
}
