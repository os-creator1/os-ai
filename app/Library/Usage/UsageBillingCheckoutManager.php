<?php

namespace App\Library\Usage;

use App\Enums\Usage\AddonPurchaseStatus;
use App\Enums\Usage\FundingAttemptPurpose;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Enums\Usage\SlotAgreementBillingCadence;
use App\Enums\Usage\SlotAgreementState;
use App\Enums\Usage\SlotRenewalChargeInitiatedBy;
use App\Enums\Usage\SlotRenewalChargeKind;
use App\Enums\Usage\TransitionSource;
use App\Enums\Usage\UsageLedgerEntryType;
use App\Events\Usage\AdditionalBusinessSlotAgreementCanceled;
use App\Events\Usage\AdditionalBusinessSlotAgreementCompleted;
use App\Events\Usage\AdditionalBusinessSlotAgreementLapsed;
use App\Events\Usage\AdditionalBusinessSlotAgreementPaymentRecovered;
use App\Events\Usage\AdditionalBusinessSlotAllocationFailed;
use App\Exceptions\Usage\FundingAttemptNotResumableException;
use App\Exceptions\Usage\ProviderApiUnavailableException;
use App\Exceptions\Usage\ProviderCardDeclinedException;
use App\Exceptions\Usage\ProviderInvalidRequestException;
use App\Exceptions\Usage\SlotRenewalChargeNotResumableException;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Usage\UnauthorizedSlotAgreementActionException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Jobs\Usage\SendSlotAgreementPriceChangeNotice;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Models\AdditionalBusinessSlotAgreement;
use App\Models\AdditionalBusinessSlotRenewalCharge;
use App\Models\Business;
use App\Models\BusinessFundingAttempt;
use App\Models\BusinessUsageAddonPurchase;
use App\Models\PaymentProviderEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementTransitionRepository;
use App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository;
use App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeTransitionRepository;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessFundingAttemptTransitionRepository;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;
use App\Repositories\Contracts\BusinessPaymentInstrumentRepository;
use App\Repositories\Contracts\BusinessUsageAddonCatalogRepository;
use App\Repositories\Contracts\BusinessUsageAddonPurchaseRepository;
use App\Repositories\Contracts\BusinessUsageAddonPurchaseTransitionRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * M3 contract §3.11/§8/§11 — the funding-attempt/provider-event slice of
 * the RFC's own UsageBillingCheckoutManager (RFC-005 §28), scoped
 * exclusively to manual top-up and auto-recharge (business_funding_attempts,
 * business_funding_attempt_transitions, payment_provider_events write
 * authority).
 *
 * M4 contract §21 (Correction Round 1 §A/§C/§D/§E) — extends this same
 * class with the additional-slot-agreement/renewal/add-on-purchase
 * responsibility M3's own docblock reserved for a future milestone,
 * exactly as M2 extended UsageWalletManager. Every outbound
 * PaymentProviderGateway call happens strictly outside any database
 * transaction/lock (§8/§16).
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

    /**
     * M4 contract §13/§21/§23 (Correction Round 1) — the exact, locked
     * pre-lapse dunning cap: 1 initial + 2 retries = 3 total attempts per
     * scheduled_renewal charge.
     */
    private const PRE_LAPSE_MAX_ATTEMPTS = 3;

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
        private readonly AdditionalBusinessSlotAgreementRepository $agreementRepository,
        private readonly AdditionalBusinessSlotAgreementTransitionRepository $agreementTransitionRepository,
        private readonly AdditionalBusinessSlotRenewalChargeRepository $renewalChargeRepository,
        private readonly AdditionalBusinessSlotRenewalChargeTransitionRepository $renewalChargeTransitionRepository,
        private readonly BusinessUsageAddonCatalogRepository $addonCatalogRepository,
        private readonly BusinessUsageAddonPurchaseRepository $addonPurchaseRepository,
        private readonly BusinessUsageAddonPurchaseTransitionRepository $addonPurchaseTransitionRepository,
        private readonly EntitlementManager $entitlementManager,
        private readonly PaymentInstrumentManager $paymentInstrumentManager,
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

    /**
     * M4 contract §21 — looks up the (test-created only, §10) active
     * catalog row, fails closed if absent/inactive, then reuses
     * initiateCharge() with purpose: AddonPurchase. The outbound
     * PaymentIntent's own metadata remains app_subject_kind:
     * 'funding_attempt' — this method introduces no new metadata shape.
     */
    public function initiateAddonPurchase(Business $business, string $addonKey, int $actorUserId): AddonPurchaseResult
    {
        $catalogRow = $this->addonCatalogRepository->findActiveByAddonKey($addonKey);

        if ($catalogRow === null) {
            return new AddonPurchaseResult(0, 0, FundingAttemptState::Failed, 'addon_not_found');
        }

        $payerType = $this->assertChargeCausingConsent($business, $actorUserId);

        $createdPurchaseId = null;

        $fundingResult = $this->initiateCharge(
            $business,
            FundingAttemptPurpose::AddonPurchase,
            $payerType,
            (int) $catalogRow->price_micro,
            $actorUserId,
            function (BusinessFundingAttempt $attempt) use ($business, $addonKey, $catalogRow, $actorUserId, &$createdPurchaseId) {
                $purchase = $this->addonPurchaseRepository->create([
                    'business_id' => $business->id,
                    'addon_key' => $addonKey,
                    'price_micro' => $catalogRow->price_micro,
                    'funding_attempt_id' => $attempt->id,
                    'status' => AddonPurchaseStatus::Pending->value,
                    'requested_by_user_id' => $actorUserId,
                    'created_at' => now(),
                ]);

                $createdPurchaseId = $purchase->id;
            },
            (string) $catalogRow->display_name,
        );

        return new AddonPurchaseResult((int) $createdPurchaseId, $fundingResult->fundingAttemptId, $fundingResult->state, $fundingResult->denialReason, $fundingResult->redirectUrl);
    }

    /**
     * M4 contract §21 — internally extended, inside this already-
     * allowlisted method, with an optional post-attempt-creation hook —
     * a narrow, authorized internal refactor, not a new path. For
     * purpose: AddonPurchase only, the hook creates the linked purchase
     * row immediately, still inside the same transaction, before the
     * outbound provider call — closing the webhook race a naive
     * "attempt then purchase" ordering would leave open. For
     * ManualTopUp/AutoRecharge, the hook is simply absent — their own
     * existing behavior, ordering, and observable outcome are entirely
     * unchanged.
     *
     * RFC-005 Funding Provider-Flow Correction Contract §4/§9 — purpose is
     * the sole dispatch authority between the two provider-object
     * families: AutoRecharge remains the original off-session PaymentIntent
     * path (pre-saved default instrument required); ManualTopUp/
     * AddonPurchase route through a one-time Checkout Session (no saved
     * instrument required, no lazy provider-customer creation, §9's Option
     * 1). $lineItemNameOverride lets initiateAddonPurchase() supply the
     * add-on catalog row's own truthful display_name (§7); ManualTopUp
     * always uses the fixed 'Wallet top-up' label.
     */
    private function initiateCharge(Business $business, FundingAttemptPurpose $purpose, PayerType $payerType, int $amountMicro, ?int $actorUserId, ?\Closure $postAttemptCreationHook = null, ?string $lineItemNameOverride = null): FundingAttemptResult
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

        // §9 — a saved default instrument is required only for the
        // off-session PaymentIntent path (AutoRecharge); a Checkout
        // Session (ManualTopUp/AddonPurchase) collects payment information
        // customer-present and never requires one, mirroring the
        // already-conforming additional-slot-agreement Checkout flow.
        $isCheckoutBacked = $purpose === FundingAttemptPurpose::ManualTopUp || $purpose === FundingAttemptPurpose::AddonPurchase;
        $instrument = null;

        if (! $isCheckoutBacked) {
            $instrument = $this->instrumentRepository->findDefaultForProviderCustomer($providerCustomer->id);

            if ($instrument === null) {
                return new FundingAttemptResult(0, FundingAttemptState::Failed, 'no_payment_instrument');
            }
        }

        $contact = $this->billingContactRepository->findByBusinessId($businessId);
        $idempotencyKey = 'funding-attempt-'.$purpose->value.'-'.$businessId.'-'.Str::uuid();

        // §5.A — a Checkout-backed attempt's real payment method is
        // genuinely unknown at creation time; the sentinel is replaced
        // exactly once, by confirmSucceeded(), after authoritative Checkout
        // confirmation (mirrors additional_business_slot_agreements' own
        // already-shipped 'Pending Checkout' precedent). AutoRecharge
        // continues snapshotting its already-known, already-saved
        // instrument at creation time, unchanged.
        $paymentMethodDisplaySnapshot = $isCheckoutBacked ? 'Pending Checkout' : $this->formatInstrumentDisplay($instrument);

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
        $attempt = DB::transaction(function () use ($businessId, $wallet, $purpose, $payerType, $contact, $providerCustomer, $paymentMethodDisplaySnapshot, $actorUserId, $amountMicro, $idempotencyKey, $postAttemptCreationHook) {
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
                'payment_method_display_snapshot' => $paymentMethodDisplaySnapshot,
                'requesting_actor_user_id' => $actorUserId,
                'expected_currency_id' => $wallet->currency_id,
                'expected_amount_micro' => $amountMicro,
                'local_idempotency_key' => $idempotencyKey,
                'state' => FundingAttemptState::Created->value,
            ]);

            $this->recordTransition($attempt, null, FundingAttemptState::Created, TransitionSource::SyncResponse, null, $actorUserId);

            if ($postAttemptCreationHook !== null) {
                $postAttemptCreationHook($attempt);
            }

            return $attempt;
        });

        if ($attempt === null) {
            return new FundingAttemptResult(0, FundingAttemptState::Failed, 'auto_recharge_already_in_flight');
        }

        $currencyCode = (string) DB::table('currencies')->where('id', $wallet->currency_id)->value('code');

        if (! $isCheckoutBacked) {
            return $this->driveOffSessionPaymentIntentAttempt1($attempt, $providerCustomer, $instrument, $amountMicro, $currencyCode, $idempotencyKey);
        }

        return $this->driveCheckoutSessionCreation($business, $attempt, $providerCustomer, $purpose, $amountMicro, $currencyCode, $idempotencyKey, $lineItemNameOverride);
    }

    /**
     * The original AutoRecharge off-session PaymentIntent attempt-1 flow,
     * extracted verbatim (byte-for-byte unchanged behavior) from
     * initiateCharge() so the purpose-based dispatch above can route to it
     * without duplicating any of its logic.
     */
    private function driveOffSessionPaymentIntentAttempt1(BusinessFundingAttempt $attempt, $providerCustomer, $instrument, int $amountMicro, string $currencyCode, string $idempotencyKey): FundingAttemptResult
    {
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
     * RFC-005 Funding Provider-Flow Correction Contract §6/§7/§8 — creates
     * the one-time Checkout Session for a ManualTopUp/AddonPurchase
     * attempt. Never synchronously credits — a Checkout Session always
     * requires a separate confirmation step (§6/§12), unlike an
     * off-session PaymentIntent which can synchronously succeed at
     * creation.
     */
    private function driveCheckoutSessionCreation(Business $business, BusinessFundingAttempt $attempt, $providerCustomer, FundingAttemptPurpose $purpose, int $amountMicro, string $currencyCode, string $idempotencyKey, ?string $lineItemNameOverride): FundingAttemptResult
    {
        if ($purpose === FundingAttemptPurpose::ManualTopUp) {
            $lineItemName = 'Wallet top-up';
            $successUrl = route('customer.workspaces.businesses.usage-billing.top-up.confirm', [
                'workspaceUid' => $business->workspace->uid,
                'businessUid' => $business->uid,
                'attempt' => $attempt->id,
            ]);
            $cancelUrl = route('customer.workspaces.businesses.usage-billing.show', [
                'workspaceUid' => $business->workspace->uid,
                'businessUid' => $business->uid,
            ]);
        } else {
            // §7 — AddonPurchase: no add-on-specific HTTP surface exists;
            // the existing dashboard is the only honest landing page,
            // mirroring the additional-slot agreement's own
            // ?session_id={CHECKOUT_SESSION_ID} success-url convention.
            $lineItemName = $lineItemNameOverride ?? 'Add-on purchase';
            $dashboardUrl = route('customer.workspaces.businesses.usage-billing.show', [
                'workspaceUid' => $business->workspace->uid,
                'businessUid' => $business->uid,
            ]);
            $successUrl = $dashboardUrl.'?session_id={CHECKOUT_SESSION_ID}';
            $cancelUrl = $dashboardUrl;
        }

        try {
            $minorUnits = $this->microToMinorUnits($amountMicro, $currencyCode);
            $this->assertWithinStripeAmountBounds($minorUnits);

            $session = $this->gateway->createCheckoutSession(
                $providerCustomer->provider_customer_id,
                $minorUnits,
                $currencyCode,
                $lineItemName,
                $successUrl,
                $cancelUrl,
                $idempotencyKey,
                [
                    'app_subject_kind' => 'funding_attempt',
                    'app_subject_id' => (string) $attempt->id,
                    'app_operation_id' => $idempotencyKey,
                ],
                false,
            );
        } catch (ProviderApiUnavailableException $e) {
            $this->markFailed($attempt, $e->getMessage(), TransitionSource::SyncResponse, null);

            return new FundingAttemptResult($attempt->id, FundingAttemptState::Failed, 'provider_unavailable');
        } catch (ProviderInvalidRequestException $e) {
            $this->markFailed($attempt, $e->getMessage(), TransitionSource::SyncResponse, null);

            return new FundingAttemptResult($attempt->id, FundingAttemptState::Failed, 'invalid_request');
        }

        $attempt = $this->attemptRepository->update($attempt, [
            'provider_session_or_intent_reference' => $session->providerCheckoutSessionId,
            'state' => FundingAttemptState::ProviderPending->value,
        ]);
        $this->recordTransition($attempt, FundingAttemptState::Created, FundingAttemptState::ProviderPending, TransitionSource::SyncResponse, null, null);

        return new FundingAttemptResult($attempt->id, FundingAttemptState::ProviderPending, null, $session->redirectUrl);
    }

    /**
     * M3 contract §10 item 6/§11 item 11 — the synchronous confirmation
     * path a browser-return handler calls. Never trusts the redirect
     * alone; independently retrieves the PaymentIntent from the provider
     * before ever crediting.
     *
     * M4 contract §21 — the already-Succeeded early return now also
     * idempotently re-finalizes a pending AddonPurchase (the replay-hole
     * fix); every other purpose's early return is unchanged.
     */
    public function confirmAttemptFromReturn(BusinessFundingAttempt $attempt): FundingAttemptResult
    {
        if ($attempt->state === FundingAttemptState::Succeeded) {
            if ($attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
                $this->finalizeAddonPurchaseIfPending($attempt, TransitionSource::SyncResponse, null);
            }

            return new FundingAttemptResult($attempt->id, $attempt->state, null);
        }

        if ($attempt->provider_session_or_intent_reference === null) {
            return new FundingAttemptResult($attempt->id, $attempt->state, null);
        }

        // RFC-005 Funding Provider-Flow Correction Contract §12 — purpose-
        // aware retrieval: a Checkout-backed attempt (ManualTopUp/
        // AddonPurchase) is never a PaymentIntent, and vice versa.
        if ($attempt->purpose === FundingAttemptPurpose::ManualTopUp || $attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
            $session = $this->gateway->retrieveCheckoutSession($attempt->provider_session_or_intent_reference);

            if (! $this->fundingAttemptCheckoutVerified($attempt, $session)) {
                return new FundingAttemptResult($attempt->id, $attempt->state, null);
            }

            $this->confirmSucceeded($attempt, TransitionSource::SyncResponse, null, null, $this->resolveVerifiedPaymentMethodDisplay($attempt, $session));

            return new FundingAttemptResult($attempt->id, FundingAttemptState::Succeeded, null);
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
     *
     * M4 contract §21 — the already-Succeeded early return now also
     * idempotently re-finalizes a pending AddonPurchase (the replay-hole
     * fix); every other purpose's early return is unchanged.
     */
    public function confirmAttemptFromWebhook(BusinessFundingAttempt $attempt, PaymentProviderEvent $event): void
    {
        if ($attempt->state === FundingAttemptState::Succeeded) {
            if ($attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
                $this->finalizeAddonPurchaseIfPending($attempt, TransitionSource::WebhookEvent, $event->id);
            }

            return;
        }

        // RFC-005 Funding Provider-Flow Correction Contract §12 — for a
        // Checkout-backed purpose, ProcessPaymentProviderEvent's own
        // field-level webhook validation is never trusted alone: this
        // independently re-fetches and re-verifies the authoritative
        // Checkout Session before ever mutating, mirroring
        // confirmSlotAgreementFromWebhook()'s own already-shipped design.
        // AutoRecharge is completely unchanged — no new provider call.
        if ($attempt->purpose === FundingAttemptPurpose::ManualTopUp || $attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
            if ($attempt->provider_session_or_intent_reference === null) {
                return;
            }

            $session = $this->gateway->retrieveCheckoutSession($attempt->provider_session_or_intent_reference);

            if (! $this->fundingAttemptCheckoutVerified($attempt, $session)) {
                // CASE 1 (contract §12) — the provider call itself
                // succeeded but re-verification failed: no mutation, no
                // exception; the caller (ProcessPaymentProviderEvent) still
                // marks the event processed under its own existing flow,
                // and ReconcileProviderPendingState may recover later.
                return;
            }

            $this->confirmSucceeded($attempt, TransitionSource::WebhookEvent, $event->id, null, $this->resolveVerifiedPaymentMethodDisplay($attempt, $session));

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
     * payer-authorized one that is stuck. M4 contract §3 item 7k — M4's
     * own retry methods use a genuinely different, real-retry mechanism
     * instead of mirroring this one.
     *
     * RFC-005 Admin Usage Billing Surface Contract §2.1.2 — corrected to
     * genuinely enforce platform-administrator authorization and a
     * mandatory reason, both before any provider-gateway call, and to
     * persist the normalized reason on the resulting transition row.
     */
    public function retryFundingAttemptAsAdministrator(BusinessFundingAttempt $attempt, int $actorUserId, string $reason): FundingAttemptResult
    {
        $this->assertPlatformAdministrator($actorUserId);

        $normalizedReason = trim($reason);
        if ($normalizedReason === '') {
            throw new UnauthorizedSlotAgreementActionException($actorUserId, null, 'retry a funding attempt without a reason');
        }

        if (! in_array($attempt->state, [FundingAttemptState::ProviderPending, FundingAttemptState::RequiresAction, FundingAttemptState::Failed], true)) {
            throw new FundingAttemptNotResumableException($attempt->id, $attempt->state->value);
        }

        if ($attempt->provider_session_or_intent_reference === null) {
            throw new FundingAttemptNotResumableException($attempt->id, $attempt->state->value);
        }

        // RFC-005 Funding Provider-Flow Correction Contract §12 — the
        // identical purpose-based branch: a stuck ManualTopUp/AddonPurchase
        // attempt is Checkout-Session-backed, never PaymentIntent-backed.
        // No new capability — still resume-only, never origination.
        if ($attempt->purpose === FundingAttemptPurpose::ManualTopUp || $attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
            $session = $this->gateway->retrieveCheckoutSession($attempt->provider_session_or_intent_reference);

            if ($this->fundingAttemptCheckoutVerified($attempt, $session)) {
                $this->confirmSucceeded($attempt, TransitionSource::AdminAction, null, $actorUserId, $this->resolveVerifiedPaymentMethodDisplay($attempt, $session), $normalizedReason);

                return new FundingAttemptResult($attempt->id, FundingAttemptState::Succeeded, null);
            }

            return new FundingAttemptResult($attempt->id, $attempt->state, null);
        }

        $paymentIntent = $this->gateway->retrievePaymentIntent($attempt->provider_session_or_intent_reference);

        if ($paymentIntent->status === 'succeeded') {
            $this->confirmSucceeded($attempt, TransitionSource::AdminAction, null, $actorUserId, null, $normalizedReason);

            return new FundingAttemptResult($attempt->id, FundingAttemptState::Succeeded, null);
        }

        return new FundingAttemptResult($attempt->id, $attempt->state, null);
    }

    /**
     * M4 contract §21 — becomes purpose-aware, replacing the prior
     * unconditional "anything that isn't AutoRecharge is PaidTopUp"
     * ternary. ManualTopUp/AutoRecharge's own observable behavior — same
     * entry type, same amount, same idempotency suffix — is byte-for-byte
     * unchanged; only AddonPurchase (never set by any M3 code path) gains
     * new behavior where none existed before.
     */
    /**
     * RFC-005 Funding Confirmation Concurrency Correction Contract §2.1 —
     * credit (or add-on fulfillment) happens before the funding attempt's
     * own state is ever finalized, and finalization itself always routes
     * through the one shared, row-locked, idempotent finalizer below — no
     * separate unlocked fast path for any purpose or any caller. This is
     * what closes the reconciliation contract's own residual two-
     * transition side effect at its shared root, and what makes a crash
     * between crediting and finalizing recoverable on replay: a caller
     * whose own credit collides with an already-committed one simply
     * falls through to the same finalizer, which decides — under lock,
     * never by branching on the credit outcome — whether this caller must
     * still perform the attempt's own terminal transition.
     */
    /**
     * RFC-005 Funding Confirmation Concurrency Correction Contract's own
     * exceptional post-merge implementation correction — the
     * credit-or-add-on-fulfillment step and the shared finalizer call are
     * wrapped in a single outer DB::transaction() so that
     * finalizeFundingAttemptState()'s own write can never be observed as
     * committed independently of (and therefore ahead of) the credit or
     * add-on fulfillment it accompanies. This closes the window in which
     * SendReceiptNotification's own ->afterCommit() dispatch (queued from
     * inside creditFromFunding()'s own nested transaction) could fire
     * before the attempt's own state ever reached Succeeded.
     */
    private function confirmSucceeded(BusinessFundingAttempt $attempt, TransitionSource $source, ?int $providerEventId, ?int $actorUserId = null, ?string $verifiedPaymentMethodDisplay = null, ?string $reason = null): void
    {
        $didFinalize = DB::transaction(function () use ($attempt, $source, $providerEventId, $actorUserId, $verifiedPaymentMethodDisplay, $reason) {
            if ($attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
                $this->finalizeAddonPurchaseIfPending($attempt, $source, $providerEventId);
            } else {
                $entryType = $attempt->purpose === FundingAttemptPurpose::AutoRecharge
                    ? UsageLedgerEntryType::AutoRecharge
                    : UsageLedgerEntryType::PaidTopUp;

                try {
                    $this->walletManager->creditFromFunding(
                        (int) $attempt->business_id,
                        $entryType,
                        (int) $attempt->expected_amount_micro,
                        (int) $attempt->id,
                        $attempt->local_idempotency_key.':credit',
                    );
                } catch (UniqueConstraintViolationException) {
                    // A concurrent/replayed caller already committed this
                    // exact credit. Fall through to the same shared finalizer
                    // below — it alone determines, under lock, whether this
                    // caller must still perform the attempt's own terminal
                    // transition.
                }
            }

            return $this->finalizeFundingAttemptState($attempt, $source, $providerEventId, $actorUserId, $verifiedPaymentMethodDisplay, $reason);
        });

        // RFC-005 Job/Event Dispatch Completion Correction Contract §7.1 —
        // dispatched only after the outer transaction above has genuinely
        // committed, and only by whichever single caller actually
        // performed the terminal transition.
        if ($didFinalize) {
            \App\Events\Usage\BusinessFundingAttemptSucceeded::dispatch(
                (int) $attempt->id,
                (int) $attempt->business_id,
                $attempt->purpose->value,
                (int) $attempt->expected_amount_micro,
            );
        }
    }

    /**
     * RFC-005 Funding Confirmation Concurrency Correction Contract §2.1 —
     * the one shared funding-attempt finalizer, invoked identically by
     * every path in confirmSucceeded(). Always acquires a row lock before
     * re-checking persisted state, so at most one caller, ever, for a
     * given attempt, performs the terminal transition — regardless of
     * whether that caller's own credit won or lost, and regardless of
     * when its own finalization call happens to run relative to another
     * caller's.
     */
    private function finalizeFundingAttemptState(BusinessFundingAttempt $attempt, TransitionSource $source, ?int $providerEventId, ?int $actorUserId, ?string $verifiedPaymentMethodDisplay, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($attempt, $source, $providerEventId, $actorUserId, $verifiedPaymentMethodDisplay, $reason) {
            $locked = $this->attemptRepository->findForUpdateById((int) $attempt->id);

            if ($locked === null || $locked->state === FundingAttemptState::Succeeded) {
                return false;
            }

            $updateAttributes = ['state' => FundingAttemptState::Succeeded->value];

            if ($verifiedPaymentMethodDisplay !== null) {
                $updateAttributes['payment_method_display_snapshot'] = $verifiedPaymentMethodDisplay;
            }

            $fromState = $locked->state;
            $this->attemptRepository->update($locked, $updateAttributes);
            $this->recordTransition($locked, $fromState, FundingAttemptState::Succeeded, $source, $providerEventId, $actorUserId, $reason);

            return true;
        });
    }

    /**
     * M4 contract §21 — idempotent add-on purchase finalization: a no-op
     * if the purchase is already completed. Otherwise applies the
     * catalog row's own fulfillment_mode — a wallet credit only for
     * wallet_credit, a pure state-machine completion (no wallet mutation,
     * no invented delivery mechanism) for direct_deliverable.
     *
     * M4 contract §21 (Correction Round 2 §C) — for wallet_credit, the
     * idempotent ledger credit is established *before* the purchase is
     * ever allowed to become completed: crash before credit → replay
     * credits and then completes; crash after credit but before
     * completion → replay's own credit attempt hits the ledger's own
     * correlation_key unique constraint (creditFromFunding() is
     * deliberately not self-idempotent, per its own documented contract),
     * proving fulfillment already happened, and proceeds straight to
     * completion; crash after completion → the top-of-method guard above
     * no-ops immediately. direct_deliverable is unchanged — state-machine-
     * only completion, no wallet mutation, no invented delivery mechanism.
     */
    private function finalizeAddonPurchaseIfPending(BusinessFundingAttempt $attempt, TransitionSource $source, ?int $providerEventId): void
    {
        $purchase = $this->addonPurchaseRepository->findByFundingAttemptId((int) $attempt->id);

        if ($purchase === null || $purchase->status === AddonPurchaseStatus::Completed) {
            return;
        }

        $catalogRow = $this->addonCatalogRepository->findByAddonKey($purchase->addon_key);

        if ($catalogRow !== null && $catalogRow->fulfillment_mode->value === 'wallet_credit') {
            try {
                $this->walletManager->creditFromFunding(
                    (int) $purchase->business_id,
                    UsageLedgerEntryType::PaidTopUp,
                    (int) $purchase->price_micro,
                    (int) $attempt->id,
                    $attempt->local_idempotency_key.':credit',
                );
            } catch (UniqueConstraintViolationException $exception) {
                // Falls through to the same lock-protected purchase-level
                // completion below, exactly like the winner path — the
                // purchase's own fulfillment record must still be
                // finalized even when this caller lost the credit race.
            }
        }

        $this->completeAddonPurchaseUnderLock($purchase, $source, $providerEventId);
    }

    /**
     * RFC-005 Funding Confirmation Concurrency Correction Contract §2.2 —
     * the purchase-level completion mutation, applied uniformly to every
     * fulfillment mode (including direct_deliverable, which reaches this
     * with no credit-based first-pass filter at all — the lock is the
     * only available serialization point for that mode). Always acquires
     * a row lock before re-checking persisted status, mirroring
     * finalizeFundingAttemptState()'s own design exactly.
     */
    private function completeAddonPurchaseUnderLock(BusinessUsageAddonPurchase $purchase, TransitionSource $source, ?int $providerEventId): void
    {
        DB::transaction(function () use ($purchase, $source, $providerEventId) {
            $locked = $this->addonPurchaseRepository->findForUpdateByFundingAttemptId((int) $purchase->funding_attempt_id);

            if ($locked === null || $locked->status === AddonPurchaseStatus::Completed) {
                return;
            }

            $fromStatus = $locked->status;
            $locked = $this->addonPurchaseRepository->update($locked, [
                'status' => AddonPurchaseStatus::Completed->value,
                'completed_at' => now(),
            ]);

            $this->addonPurchaseTransitionRepository->create([
                'purchase_id' => $locked->id,
                'from_status' => $fromStatus->value,
                'to_status' => AddonPurchaseStatus::Completed->value,
                'source' => $source->value,
                'provider_event_id' => $providerEventId,
                'actor_user_id' => null,
                'failure_reason' => null,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Receipt Boundary Correction Contract §6 — the sole provider-facing
     * boundary for receipt evidence. Never writes
     * business_billing_receipts directly; every write goes through
     * UsageWalletManager::attachFundingReceipt(). Returns the existing
     * receipt (never null) if one already exists — no provider call, no
     * write, in that case. Returns null only when evidence is genuinely
     * unavailable (object-id/status mismatch, or an absent/empty
     * receiptUrl/receiptChargeId) — never conflated with "already
     * exists."
     */
    public function ensureFundingReceipt(BusinessFundingAttempt $attempt, int $ledgerEntryId): ?\App\Models\BusinessBillingReceipt
    {
        $existing = $this->walletManager->findFundingReceipt($ledgerEntryId);

        if ($existing !== null) {
            return $existing;
        }

        if ($attempt->purpose === FundingAttemptPurpose::ManualTopUp || $attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
            $session = $this->gateway->retrieveCheckoutSession($attempt->provider_session_or_intent_reference);

            if ($session->providerCheckoutSessionId !== $attempt->provider_session_or_intent_reference
                || $session->status !== 'complete'
                || $session->paymentStatus !== 'paid'
            ) {
                return null;
            }

            $receiptUrl = $session->receiptUrl;
            $receiptChargeId = $session->receiptChargeId;
        } else {
            $paymentIntent = $this->gateway->retrievePaymentIntent($attempt->provider_session_or_intent_reference);

            if ($paymentIntent->providerPaymentIntentId !== $attempt->provider_session_or_intent_reference
                || $paymentIntent->status !== 'succeeded'
            ) {
                return null;
            }

            $receiptUrl = $paymentIntent->receiptUrl;
            $receiptChargeId = $paymentIntent->receiptChargeId;
        }

        if (blank($receiptUrl) || blank($receiptChargeId)) {
            return null;
        }

        return $this->walletManager->attachFundingReceipt(
            $ledgerEntryId,
            (int) $attempt->id,
            (int) $attempt->business_id,
            $receiptUrl,
            $receiptChargeId,
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

        \App\Events\Usage\BusinessFundingAttemptFailed::dispatch(
            (int) $attempt->id,
            (int) $attempt->business_id,
            $attempt->purpose->value,
        );
    }

    private function recordTransition(BusinessFundingAttempt $attempt, ?FundingAttemptState $from, FundingAttemptState $to, TransitionSource $source, ?int $providerEventId, ?int $actorUserId, ?string $reason = null): void
    {
        $this->transitionRepository->create([
            'funding_attempt_id' => $attempt->id,
            'from_state' => ($from ?? $to)->value,
            'to_state' => $to->value,
            'source' => $source->value,
            'provider_event_id' => $providerEventId,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function formatInstrumentDisplay($instrument): string
    {
        return sprintf('%s •••• %s, exp %02d/%d', $instrument->brand ?? $instrument->type->value, $instrument->last_four ?? '????', $instrument->expiry_month ?? 0, $instrument->expiry_year ?? 0);
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §5.A — the
     * PaymentMethodResult-shaped sibling of formatInstrumentDisplay(),
     * producing the byte-identical display shape from a gateway-retrieved
     * PaymentMethodResult's own scalars instead of a saved
     * BusinessPaymentInstrument model.
     */
    private function formatPaymentMethodDisplay(PaymentMethodResult $method): string
    {
        return sprintf('%s •••• %s, exp %02d/%d', $method->brand ?? $method->type, $method->lastFour ?? '????', $method->expiryMonth ?? 0, $method->expiryYear ?? 0);
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §12 — the
     * BusinessFundingAttempt-shaped sibling of slotAgreementCheckoutVerified(),
     * the identical eight conditions: Session complete, payment_status
     * paid, matching session id/amount/currency/provider customer,
     * non-null PaymentIntent and PaymentMethod references.
     */
    private function fundingAttemptCheckoutVerified(BusinessFundingAttempt $attempt, CheckoutSessionResult $session): bool
    {
        if ($session->status !== 'complete' || $session->paymentStatus !== 'paid') {
            return false;
        }

        if ($session->providerCheckoutSessionId !== $attempt->provider_session_or_intent_reference) {
            return false;
        }

        if ($session->amountMinorUnits !== $this->expectedMinorUnitsFor($attempt) || $session->currencyCode !== $this->expectedCurrencyCodeFor($attempt)) {
            return false;
        }

        if ($session->providerCustomerId !== $attempt->provider_customer_external_id_snapshot) {
            return false;
        }

        if ($session->providerPaymentIntentId === null || $session->providerPaymentMethodId === null) {
            return false;
        }

        return true;
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §5.C — a one-time
     * Checkout Session created without setup_future_usage may legitimately
     * produce a PaymentMethod Stripe never attaches to any Customer at
     * all. The authoritative ownership/amount/currency evidence remains
     * the already-verified Checkout Session itself
     * (fundingAttemptCheckoutVerified(), called before this); this method
     * is used only to obtain safe display metadata, never as an
     * additional ownership gate. An empty providerCustomerId is expected
     * and accepted; a present-but-contradictory one skips display
     * finalization only (returns null) — it never denies the already-
     * independently-verified successful payment.
     */
    private function resolveVerifiedPaymentMethodDisplay(BusinessFundingAttempt $attempt, CheckoutSessionResult $session): ?string
    {
        $paymentMethod = $this->gateway->retrievePaymentMethod((string) $session->providerPaymentMethodId);

        if ($paymentMethod->providerCustomerId !== '' && $paymentMethod->providerCustomerId !== $attempt->provider_customer_external_id_snapshot) {
            return null;
        }

        return $this->formatPaymentMethodDisplay($paymentMethod);
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

    // ------------------------------------------------------------------
    // M4 contract §21 — additional-slot agreement: quote/checkout/confirm
    // ------------------------------------------------------------------

    /**
     * M4 contract §9/§21 — owner-only. Reads the Workspace's current tier
     * and already-allocated slot count via EntitlementManager's two
     * read-only seams, computes the price × ratio snapshot, creates the
     * agreement row in state: quote_created.
     */
    public function quoteAdditionalSlotAgreement(Workspace $workspace, int $targetAllocationCount, int $actorUserId): SlotAgreementQuoteResult
    {
        $this->assertWorkspaceOwner($workspace, $actorUserId, 'quote an additional-slot agreement');

        $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($workspace);
        $currentAllocationCount = $summary->capacity->additionalSlotsAllocated;

        if ($targetAllocationCount <= $currentAllocationCount) {
            throw new UnauthorizedSlotAgreementActionException($actorUserId, null, 'quote a non-increasing additional-slot target');
        }

        [$catalogRow, $pricePerSlotMicro] = $this->resolveCurrentCatalogPricing($workspace);

        $paidDelta = $targetAllocationCount - $currentAllocationCount;
        $totalAmountMicro = (int) bcmul((string) $pricePerSlotMicro, (string) $paidDelta);

        $providerCustomer = $this->providerCustomerRepository->findActiveByWorkspaceId((int) $workspace->id);

        if ($providerCustomer === null) {
            $idempotencyKey = 'provider-customer-workspace-'.$workspace->id;
            $result = $this->gateway->createOrRetrieveCustomer(null, $idempotencyKey);

            $providerCustomer = $this->providerCustomerRepository->create([
                'provider' => 'stripe',
                'workspace_id' => $workspace->id,
                'business_id' => null,
                'provider_customer_id' => $result->providerCustomerId,
                'status' => 'active',
            ]);
        }

        $ownerEmail = (string) (User::query()->find($actorUserId)?->email ?? '');
        $localIdempotencyKey = hash('sha256', 'slot-agreement:'.$workspace->id.':'.Str::uuid());

        $agreement = $this->agreementRepository->create([
            'workspace_id' => $workspace->id,
            'current_allocation_count' => $currentAllocationCount,
            'target_allocation_count' => $targetAllocationCount,
            'paid_delta' => $paidDelta,
            'price_per_slot_micro_snapshot' => $pricePerSlotMicro,
            'total_amount_micro_snapshot' => $totalAmountMicro,
            'currency_id_snapshot' => $catalogRow->currencyId,
            'ratio_snapshot' => $catalogRow->additionalBusinessSlotPriceRatio,
            'plan_catalog_id_snapshot' => $catalogRow->id,
            'plan_tier_snapshot' => $catalogRow->tier->value,
            'requesting_customer_user_id' => $actorUserId,
            'requesting_customer_email_snapshot' => $ownerEmail,
            'provider_customer_id' => $providerCustomer->id,
            'payment_method_display_snapshot' => 'Pending Checkout',
            'local_idempotency_key' => $localIdempotencyKey,
            'billing_cadence' => SlotAgreementBillingCadence::Monthly->value,
            'state' => SlotAgreementState::QuoteCreated->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->recordAgreementTransition($agreement, null, SlotAgreementState::QuoteCreated, TransitionSource::SyncResponse, null, $actorUserId);

        return new SlotAgreementQuoteResult($agreement->id, $agreement->state, $pricePerSlotMicro, $totalAmountMicro, $paidDelta);
    }

    /**
     * M4 contract §15a/§21 — creates the initial Checkout Session, never
     * an off-session PaymentIntent, preserving RFC-005's own
     * customer-present design. Transitions to checkout_pending.
     */
    public function initiateSlotAgreementCheckout(AdditionalBusinessSlotAgreement $agreement, int $actorUserId): SlotAgreementCheckoutResult
    {
        $agreement->loadMissing('workspace', 'providerCustomer');
        $this->assertWorkspaceOwner($agreement->workspace, $actorUserId, 'initiate an additional-slot checkout');

        if ($agreement->state !== SlotAgreementState::QuoteCreated) {
            return new SlotAgreementCheckoutResult($agreement->id, $agreement->state, null, 'not_eligible_for_checkout');
        }

        $currencyCode = $this->currencyCodeById((int) $agreement->currency_id_snapshot);
        $minorUnits = $this->microToMinorUnits((int) $agreement->total_amount_micro_snapshot, $currencyCode);
        $this->assertWithinStripeAmountBounds($minorUnits);

        $successUrl = route('customer.workspaces.additional-business-slots.confirm', [
            'workspaceUid' => $agreement->workspace->uid,
            'agreement' => $agreement->id,
        ]).'?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('customer.workspaces.additional-business-slots.show', [
            'workspaceUid' => $agreement->workspace->uid,
        ]);

        $session = $this->gateway->createCheckoutSession(
            $agreement->providerCustomer->provider_customer_id,
            $minorUnits,
            $currencyCode,
            'Additional Business slots',
            $successUrl,
            $cancelUrl,
            $agreement->local_idempotency_key,
            [
                'app_subject_kind' => 'slot_agreement',
                'app_subject_id' => (string) $agreement->id,
                'app_operation_id' => $agreement->local_idempotency_key,
            ],
            true,
        );

        $fromState = $agreement->state;
        $agreement = $this->agreementRepository->update($agreement, [
            'provider_session_or_intent_reference' => $session->providerCheckoutSessionId,
            'state' => SlotAgreementState::CheckoutPending->value,
        ]);
        $this->recordAgreementTransition($agreement, $fromState, SlotAgreementState::CheckoutPending, TransitionSource::SyncResponse, null, $actorUserId);

        return new SlotAgreementCheckoutResult($agreement->id, $agreement->state, $session->redirectUrl, null);
    }

    /**
     * M4 contract §15a/§21 — mirrors confirmAttemptFromReturn() exactly,
     * via retrieveCheckoutSession(); never trusts the redirect alone.
     */
    public function confirmSlotAgreementFromReturn(AdditionalBusinessSlotAgreement $agreement): SlotAgreementCheckoutResult
    {
        if ($agreement->state !== SlotAgreementState::CheckoutPending || $agreement->provider_session_or_intent_reference === null) {
            return new SlotAgreementCheckoutResult($agreement->id, $agreement->state, null, null);
        }

        $session = $this->gateway->retrieveCheckoutSession($agreement->provider_session_or_intent_reference);

        return $this->confirmSlotAgreementChecked($agreement, $session, TransitionSource::SyncResponse, null);
    }

    /**
     * M4 contract §15/§15a/§21 — identical eight-condition verification
     * and payment-instrument sync as confirmSlotAgreementFromReturn().
     */
    public function confirmSlotAgreementFromWebhook(AdditionalBusinessSlotAgreement $agreement, PaymentProviderEvent $event): void
    {
        if ($agreement->state !== SlotAgreementState::CheckoutPending || $agreement->provider_session_or_intent_reference === null) {
            return;
        }

        $session = $this->gateway->retrieveCheckoutSession($agreement->provider_session_or_intent_reference);

        $this->confirmSlotAgreementChecked($agreement, $session, TransitionSource::WebhookEvent, $event->id);
    }

    /**
     * M4 contract §15a — the shared convergence point both
     * confirmSlotAgreementFromReturn()/confirmSlotAgreementFromWebhook()
     * call; verifies all eight required conditions, syncs the actual
     * paid PaymentMethod (§15c) before performVerifiedAllocation().
     */
    private function confirmSlotAgreementChecked(AdditionalBusinessSlotAgreement $agreement, CheckoutSessionResult $session, TransitionSource $source, ?int $providerEventId): SlotAgreementCheckoutResult
    {
        if (! $this->slotAgreementCheckoutVerified($agreement, $session)) {
            return new SlotAgreementCheckoutResult($agreement->id, $agreement->state, null, 'not_yet_verified');
        }

        $agreement->loadMissing('workspace');
        $instrument = $this->paymentInstrumentManager->syncWorkspaceCheckoutPaymentMethod(
            $agreement->workspace,
            (int) $agreement->requesting_customer_user_id,
            (string) $session->providerPaymentMethodId,
        );

        $fromState = $agreement->state;
        $agreement = $this->agreementRepository->update($agreement, [
            'state' => SlotAgreementState::PaymentSucceeded->value,
            'payment_method_display_snapshot' => $this->formatInstrumentDisplay($instrument),
        ]);
        $this->recordAgreementTransition($agreement, $fromState, SlotAgreementState::PaymentSucceeded, $source, $providerEventId, null);

        $this->performVerifiedAllocation($agreement);

        $refreshed = $this->agreementRepository->findById((int) $agreement->id);

        return new SlotAgreementCheckoutResult($refreshed->id, $refreshed->state, null, null);
    }

    /**
     * M4 contract §15a — the exact eight required conditions: Session
     * complete, payment_status paid, matching session id/amount/currency/
     * provider customer, non-null PaymentIntent and PaymentMethod
     * references.
     */
    private function slotAgreementCheckoutVerified(AdditionalBusinessSlotAgreement $agreement, CheckoutSessionResult $session): bool
    {
        if ($session->status !== 'complete' || $session->paymentStatus !== 'paid') {
            return false;
        }

        if ($session->providerCheckoutSessionId !== $agreement->provider_session_or_intent_reference) {
            return false;
        }

        $currencyCode = $this->currencyCodeById((int) $agreement->currency_id_snapshot);
        $expectedMinorUnits = $this->microToMinorUnits((int) $agreement->total_amount_micro_snapshot, $currencyCode);

        if ($session->amountMinorUnits !== $expectedMinorUnits || $session->currencyCode !== $currencyCode) {
            return false;
        }

        $agreement->loadMissing('providerCustomer');

        if ($agreement->providerCustomer === null || $session->providerCustomerId !== $agreement->providerCustomer->provider_customer_id) {
            return false;
        }

        if ($session->providerPaymentIntentId === null || $session->providerPaymentMethodId === null) {
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // M4 contract §21 (Correction Round 1 §A/§C) — renewal charges
    // ------------------------------------------------------------------

    /**
     * M4 contract §11/§21 — the method InitiateSlotAgreementRenewal calls
     * once per due agreement; the job itself creates no row. Re-snapshots
     * fresh catalog pricing (M4 contract §9 item 5).
     */
    public function createScheduledRenewalCharge(AdditionalBusinessSlotAgreement $agreement): SlotRenewalChargeResult
    {
        $periodStart = Carbon::instance($agreement->next_renewal_at)->toImmutable();
        $periodEnd = $periodStart->addMonthNoOverflow();

        $localIdempotencyKey = hash('sha256', $agreement->id.':scheduled:'.$periodStart->toIso8601String());

        $existing = $this->renewalChargeRepository->findByLocalIdempotencyKey($localIdempotencyKey);

        if ($existing !== null) {
            // M4 contract §21 (Correction Round 2 §B item 5) — attempt 1
            // never reached a definitive outcome (a hard decline with no
            // preserved reference, or a non-definitive/transient error) —
            // re-drive it against the identical idempotency key rather
            // than returning a permanently stale result.
            if ($existing->state === FundingAttemptState::Created) {
                $providerCustomer = $this->providerCustomerRepository->findById((int) $agreement->provider_customer_id);
                $instrument = $this->instrumentRepository->findDefaultForProviderCustomer((int) $agreement->provider_customer_id);

                return $this->driveRenewalChargeAttempt1($existing, $providerCustomer, $instrument);
            }

            return new SlotRenewalChargeResult($existing->id, $existing->state, null);
        }

        $agreement->loadMissing('workspace');
        [$catalogRow, $pricePerSlotMicro] = $this->resolveCurrentCatalogPricing($agreement->workspace);

        $slotCount = (int) $agreement->current_allocation_count;
        $amountMicro = (int) bcmul((string) $pricePerSlotMicro, (string) $slotCount);

        $providerCustomer = $this->providerCustomerRepository->findById((int) $agreement->provider_customer_id);
        $instrument = $this->instrumentRepository->findDefaultForProviderCustomer((int) $agreement->provider_customer_id);

        $charge = $this->renewalChargeRepository->create([
            'agreement_id' => $agreement->id,
            'charge_kind' => SlotRenewalChargeKind::ScheduledRenewal->value,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'amount_micro_snapshot' => $amountMicro,
            'requesting_customer_email_snapshot' => $agreement->requesting_customer_email_snapshot,
            'payer_type_snapshot' => PayerType::Workspace->value,
            'provider_customer_external_id_snapshot' => $providerCustomer?->provider_customer_id ?? '',
            'payment_method_display_snapshot' => $instrument !== null ? $this->formatInstrumentDisplay($instrument) : '',
            'currency_id_snapshot' => $catalogRow->currencyId,
            'plan_catalog_id_snapshot' => $catalogRow->id,
            'plan_tier_snapshot' => $catalogRow->tier->value,
            'ratio_snapshot' => $catalogRow->additionalBusinessSlotPriceRatio,
            'initiated_by' => SlotRenewalChargeInitiatedBy::ScheduledJob->value,
            'actor_user_id' => null,
            'change_operation_id' => null,
            'local_idempotency_key' => $localIdempotencyKey,
            'allocation_delta' => null,
            'state' => FundingAttemptState::Created->value,
            'created_at' => now(),
        ]);

        $this->recordRenewalChargeTransition($charge, null, FundingAttemptState::Created, TransitionSource::SyncResponse, null, null);

        // M4 contract §22 (Correction Round 2 §E.4) — compares against the
        // actual previous scheduled_renewal charge; falls back to the
        // agreement's own original checkout amount only for the genuinely
        // first-ever scheduled renewal, where no previous charge exists.
        $previousCharge = $this->renewalChargeRepository->findPreviousScheduledRenewalCharge((int) $agreement->id, (int) $charge->id);
        $priorAmount = $previousCharge?->amount_micro_snapshot ?? (int) $agreement->total_amount_micro_snapshot;

        if ($priorAmount !== $amountMicro) {
            SendSlotAgreementPriceChangeNotice::dispatch($agreement->id, $charge->id);
        }

        return $this->driveRenewalChargeAttempt1($charge, $providerCustomer, $instrument);
    }

    /**
     * M4 contract §11/§21 (Correction Round 1 §C) — locks the agreement
     * before deriving the paid delta, reserved against the locked
     * target_allocation_count, never the stale current_allocation_count.
     * A repeated identical change_operation_id returns the existing
     * logical charge unchanged.
     */
    public function requestSlotAgreementIncrease(AdditionalBusinessSlotAgreement $agreement, int $targetAllocationCount, string $changeOperationId, int $actorUserId): SlotRenewalChargeResult
    {
        $agreement->loadMissing('workspace');
        $this->assertWorkspaceOwner($agreement->workspace, $actorUserId, 'request an additional-slot increase');

        $isNew = false;

        $charge = DB::transaction(function () use ($agreement, $targetAllocationCount, $changeOperationId, $actorUserId, &$isNew) {
            $locked = $this->agreementRepository->findForUpdateById((int) $agreement->id);

            $existingCharge = $this->renewalChargeRepository->findByChangeOperationId($changeOperationId);

            if ($existingCharge !== null) {
                return $existingCharge;
            }

            if ($targetAllocationCount <= $locked->target_allocation_count) {
                throw new UnauthorizedSlotAgreementActionException($actorUserId, (int) $locked->id, 'request a non-increasing additional-slot target');
            }

            $allocationDelta = $targetAllocationCount - $locked->target_allocation_count;

            $locked->loadMissing('workspace');
            [$catalogRow, $pricePerSlotMicro] = $this->resolveCurrentCatalogPricing($locked->workspace);

            $periodEnd = Carbon::instance($locked->next_renewal_at)->toImmutable();
            $periodStart = $periodEnd->subMonthNoOverflow();
            $now = Carbon::now()->toImmutable();
            $remainingSeconds = max(0, $periodEnd->getTimestamp() - $now->getTimestamp());
            $totalSeconds = max(1, $periodEnd->getTimestamp() - $periodStart->getTimestamp());

            $fullAmount = bcmul((string) $pricePerSlotMicro, (string) $allocationDelta);
            $proratedAmount = (int) UsageWalletManager::bcRoundHalfUp(bcmul($fullAmount, (string) $remainingSeconds, 0), (string) $totalSeconds);

            $localIdempotencyKey = hash('sha256', $locked->id.':increase:'.$changeOperationId);
            $providerCustomer = $this->providerCustomerRepository->findById((int) $locked->provider_customer_id);
            $instrument = $this->instrumentRepository->findDefaultForProviderCustomer((int) $locked->provider_customer_id);

            $newCharge = $this->renewalChargeRepository->create([
                'agreement_id' => $locked->id,
                'charge_kind' => SlotRenewalChargeKind::MidPeriodIncrease->value,
                'period_start' => $now,
                'period_end' => $periodEnd,
                'amount_micro_snapshot' => $proratedAmount,
                'requesting_customer_email_snapshot' => $locked->requesting_customer_email_snapshot,
                'payer_type_snapshot' => PayerType::Workspace->value,
                'provider_customer_external_id_snapshot' => $providerCustomer?->provider_customer_id ?? '',
                'payment_method_display_snapshot' => $instrument !== null ? $this->formatInstrumentDisplay($instrument) : '',
                'currency_id_snapshot' => $catalogRow->currencyId,
                'plan_catalog_id_snapshot' => $catalogRow->id,
                'plan_tier_snapshot' => $catalogRow->tier->value,
                'ratio_snapshot' => $catalogRow->additionalBusinessSlotPriceRatio,
                'initiated_by' => SlotRenewalChargeInitiatedBy::OwnerInitiated->value,
                'actor_user_id' => $actorUserId,
                'change_operation_id' => $changeOperationId,
                'local_idempotency_key' => $localIdempotencyKey,
                'allocation_delta' => $allocationDelta,
                'state' => FundingAttemptState::Created->value,
                'created_at' => now(),
            ]);

            $this->recordRenewalChargeTransition($newCharge, null, FundingAttemptState::Created, TransitionSource::SyncResponse, null, $actorUserId);
            $this->agreementRepository->update($locked, ['target_allocation_count' => $targetAllocationCount]);

            $isNew = true;

            return $newCharge;
        });

        // M4 contract §21 (Correction Round 2 §B item 5) — a found existing
        // charge (fresh or a repeated change_operation_id) whose attempt 1
        // never reached a definitive outcome is re-driven against the
        // identical idempotency key rather than returned stale.
        if (! $isNew && $charge->state !== FundingAttemptState::Created) {
            return new SlotRenewalChargeResult($charge->id, $charge->state, null);
        }

        $providerCustomer = $this->providerCustomerRepository->findById((int) $agreement->provider_customer_id);
        $instrument = $this->instrumentRepository->findDefaultForProviderCustomer((int) $agreement->provider_customer_id);

        return $this->driveRenewalChargeAttempt1($charge, $providerCustomer, $instrument);
    }

    /**
     * M4 contract §11/§21/§23 — attempt 1 for a freshly created renewal
     * charge (either kind); the outbound provider call happens strictly
     * outside any open transaction/lock.
     */
    private function driveRenewalChargeAttempt1(AdditionalBusinessSlotRenewalCharge $charge, $providerCustomer, $instrument): SlotRenewalChargeResult
    {
        if ($providerCustomer === null) {
            return $this->applyRenewalChargeFailure((int) $charge->id, 'no_provider_customer', TransitionSource::SyncResponse, null, null, 1);
        }

        if ($instrument === null) {
            return $this->applyRenewalChargeFailure((int) $charge->id, 'no_payment_instrument', TransitionSource::SyncResponse, null, null, 1);
        }

        $currencyCode = $this->currencyCodeById((int) $charge->currency_id_snapshot);
        $minorUnits = $this->microToMinorUnits((int) $charge->amount_micro_snapshot, $currencyCode);
        $idempotencyKey = hash('sha256', $charge->local_idempotency_key.':attempt:1');

        try {
            $this->assertWithinStripeAmountBounds($minorUnits);

            $paymentIntent = $this->gateway->createOffSessionPaymentIntent(
                $providerCustomer->provider_customer_id,
                $instrument->provider_payment_method_id,
                $minorUnits,
                $currencyCode,
                $idempotencyKey,
                [
                    'app_subject_kind' => 'slot_renewal_charge',
                    'app_subject_id' => (string) $charge->id,
                    'app_operation_id' => $charge->local_idempotency_key,
                ],
            );
        } catch (ProviderCardDeclinedException $e) {
            // M4 contract §21 (Correction Round 2 §B) — a definitive card
            // decline persists its real PaymentIntent reference before the
            // failed transition is recorded, so ordinals 2-3 can confirm
            // against it. Fail-closed when that evidence is unexpectedly
            // absent: treated identically to a non-definitive outcome
            // (no transition, no ordinal consumed, charge stays Created) —
            // never a fabricated reference, never an unretryable charge.
            if ($e->providerPaymentIntentId === null) {
                Log::warning('Definitive card decline on renewal-charge attempt 1 carried no PaymentIntent reference.', [
                    'renewal_charge_id' => $charge->id,
                    'decline_code' => $e->declineCode,
                ]);

                return new SlotRenewalChargeResult($charge->id, $charge->state, $e->getMessage());
            }

            $charge = $this->renewalChargeRepository->update($charge, [
                'provider_session_or_intent_reference' => $e->providerPaymentIntentId,
            ]);

            return $this->applyRenewalChargeFailure((int) $charge->id, $e->getMessage(), TransitionSource::SyncResponse, null, null, 1);
        } catch (ProviderApiUnavailableException|ProviderInvalidRequestException $e) {
            // Non-definitive (§B) — no transition, no ordinal consumed;
            // charge stays Created and is safely re-driven on the next
            // invocation under the identical idempotency key.
            return new SlotRenewalChargeResult($charge->id, $charge->state, $e->getMessage());
        }

        $fromState = $charge->state;
        $newState = $paymentIntent->status === 'requires_action' ? FundingAttemptState::RequiresAction : FundingAttemptState::ProviderPending;
        $charge = $this->renewalChargeRepository->update($charge, [
            'provider_session_or_intent_reference' => $paymentIntent->providerPaymentIntentId,
            'state' => $newState->value,
        ]);
        $this->recordRenewalChargeTransition($charge, $fromState, $newState, TransitionSource::SyncResponse, null, null);

        if ($paymentIntent->status === 'succeeded') {
            return $this->applyRenewalChargeSuccess((int) $charge->id, TransitionSource::SyncResponse, null);
        }

        return new SlotRenewalChargeResult($charge->id, $charge->state, null);
    }

    /**
     * M4 contract §13/§14/§21 (Correction Round 1) — mandatory reason,
     * explicit assertPlatformAdministrator() check before any mutation or
     * provider call. Ordinal permission identical to the owner method.
     */
    public function retrySlotRenewalAsAdministrator(AdditionalBusinessSlotRenewalCharge $charge, int $actorUserId, string $reason): SlotRenewalChargeResult
    {
        $this->assertPlatformAdministrator($actorUserId);

        if (trim($reason) === '') {
            throw new UnauthorizedSlotAgreementActionException($actorUserId, (int) $charge->agreement_id, 'retry a renewal charge without a reason');
        }

        $agreement = $this->agreementRepository->findById((int) $charge->agreement_id);

        if ($agreement === null) {
            throw new SlotRenewalChargeNotResumableException($charge->id, $charge->state->value);
        }

        return $this->driveRenewalChargeRetry($charge, $agreement, TransitionSource::AdminAction, $actorUserId);
    }

    /**
     * M4 contract §13/§21 — the Workspace owner's own lapse/failure
     * recovery path after correcting their payment method. Calls
     * confirmPaymentIntent() against the Workspace's current default
     * instrument — the identical real-retry mechanism the administrator
     * method uses, minus the admin check/mandatory reason.
     */
    public function retrySlotRenewalAsOwner(AdditionalBusinessSlotRenewalCharge $charge, int $actorUserId): SlotRenewalChargeResult
    {
        $agreement = $this->agreementRepository->findById((int) $charge->agreement_id);

        if ($agreement === null) {
            throw new SlotRenewalChargeNotResumableException($charge->id, $charge->state->value);
        }

        $agreement->loadMissing('workspace');
        $this->assertWorkspaceOwner($agreement->workspace, $actorUserId, 'retry a renewal charge');

        return $this->driveRenewalChargeRetry($charge, $agreement, TransitionSource::SyncResponse, $actorUserId);
    }

    /**
     * M4 contract §13/§21/§23 (Correction Round 2 §E.2) — ordinals 2–3
     * pre-lapse; ordinal 4+ is this method's own sole post-lapse role once
     * payment_lapsed is true. A mid_period_increase charge never sets
     * payment_lapsed (§13/§21 scope it to the agreement's own recurring
     * renewal cadence), so an increase charge exhausting 3 attempts stays
     * permanently Failed — a fresh increase (new change_operation_id) is
     * required to try again, never an unbounded retry loop.
     *
     * No persisted pre-provider-call state mutation of any kind — Phase A
     * locks/validates/computes the attempted ordinal N and commits without
     * changing the charge, so a process dying between here and the Stripe
     * call leaves the charge exactly as resumable as it was. Concurrency is
     * closed entirely by result-application dedup (applyRenewalChargeFailure()/
     * applyRenewalChargeSuccess()/applyRenewalChargeRequiresAction()), never
     * by an in-flight claim.
     */
    private function driveRenewalChargeRetry(AdditionalBusinessSlotRenewalCharge $charge, AdditionalBusinessSlotAgreement $agreement, TransitionSource $source, int $actorUserId): SlotRenewalChargeResult
    {
        $chargeId = (int) $charge->id;
        $agreementId = (int) $agreement->id;

        [$providerReference, $ordinal, $providerPaymentMethodId, $localIdempotencyKey] = DB::transaction(function () use ($chargeId, $agreementId) {
            $lockedCharge = $this->renewalChargeRepository->findForUpdateById($chargeId);

            if ($lockedCharge === null) {
                throw new SlotRenewalChargeNotResumableException($chargeId, 'not_found');
            }

            if (! in_array($lockedCharge->state, [FundingAttemptState::Failed, FundingAttemptState::RequiresAction], true)) {
                throw new SlotRenewalChargeNotResumableException($lockedCharge->id, $lockedCharge->state->value);
            }

            if ($lockedCharge->provider_session_or_intent_reference === null) {
                throw new SlotRenewalChargeNotResumableException($lockedCharge->id, $lockedCharge->state->value);
            }

            $lockedAgreement = $this->agreementRepository->findForUpdateById($agreementId);

            if ($lockedAgreement === null) {
                throw new SlotRenewalChargeNotResumableException($lockedCharge->id, $lockedCharge->state->value);
            }

            $ordinal = 1 + $this->renewalChargeTransitionRepository->countFailedForCharge($lockedCharge->id);

            if ($ordinal >= 4 && ! $lockedAgreement->payment_lapsed) {
                throw new SlotRenewalChargeNotResumableException($lockedCharge->id, $lockedCharge->state->value);
            }

            $providerCustomer = $this->providerCustomerRepository->findById((int) $lockedAgreement->provider_customer_id);
            $instrument = $providerCustomer !== null ? $this->instrumentRepository->findDefaultForProviderCustomer((int) $providerCustomer->id) : null;

            if ($instrument === null) {
                throw new SlotRenewalChargeNotResumableException($lockedCharge->id, $lockedCharge->state->value);
            }

            return [$lockedCharge->provider_session_or_intent_reference, $ordinal, $instrument->provider_payment_method_id, $lockedCharge->local_idempotency_key];
        });

        $idempotencyKey = hash('sha256', $localIdempotencyKey.':attempt:'.$ordinal);

        try {
            $paymentIntent = $this->gateway->confirmPaymentIntent($providerReference, $providerPaymentMethodId, $idempotencyKey);
        } catch (ProviderCardDeclinedException $e) {
            return $this->applyRenewalChargeFailure($chargeId, $e->getMessage(), $source, null, $actorUserId, $ordinal);
        } catch (ProviderApiUnavailableException|ProviderInvalidRequestException $e) {
            // Non-definitive (§B) — write nothing; the charge is left
            // exactly in its pre-call state, and the next invocation
            // recomputes the identical ordinal/idempotency key.
            $current = $this->renewalChargeRepository->findById($chargeId);

            return new SlotRenewalChargeResult($chargeId, $current?->state ?? FundingAttemptState::Failed, $e->getMessage());
        }

        if ($paymentIntent->status === 'succeeded') {
            return $this->applyRenewalChargeSuccess($chargeId, $source, null, $actorUserId);
        }

        if ($paymentIntent->status === 'requires_action') {
            return $this->applyRenewalChargeRequiresAction($chargeId, $source, $actorUserId);
        }

        return $this->applyRenewalChargeFailure($chargeId, 'provider_reported_failure', $source, null, $actorUserId, $ordinal);
    }

    /**
     * M4 contract §15/§21 (Correction Round 1 §A) — called by
     * ProcessPaymentProviderEvent once a slot_renewal_charge webhook
     * event has been fully validated. The job itself never mutates a
     * renewal-charge or agreement row.
     */
    public function confirmSlotRenewalChargeFromWebhook(AdditionalBusinessSlotRenewalCharge $charge, PaymentProviderEvent $event): void
    {
        $this->applyRenewalChargeSuccess((int) $charge->id, TransitionSource::WebhookEvent, $event->id);
    }

    public function markSlotRenewalChargeFailedFromWebhook(AdditionalBusinessSlotRenewalCharge $charge, string $failureReason, PaymentProviderEvent $event): void
    {
        $this->applyRenewalChargeFailure((int) $charge->id, $failureReason, TransitionSource::WebhookEvent, $event->id);
    }

    /**
     * M4 contract §21/§23 (Correction Round 1 §A/§E; Correction Round 2
     * §E.2/§E.3) — the single shared renewal-result-application routine
     * for a verified provider success, reused by every synchronous caller
     * and both webhook methods above. Re-locks the charge by id — never
     * trusts a caller-supplied model's own state — so two racing callers
     * applying the identical Stripe-idempotent success converge on exactly
     * one Succeeded transition. scheduled_renewal success unconditionally
     * (idempotently) repairs its downstream agreement bookkeeping;
     * mid_period_increase success unconditionally (idempotently) invokes
     * the verified allocation seam — both run every time, even on a replay
     * where the charge is already Succeeded (the crash-recovery
     * requirement), since each of those two downstream operations is
     * independently idempotent.
     */
    private function applyRenewalChargeSuccess(int $chargeId, TransitionSource $source, ?int $providerEventId, ?int $actorUserId = null): SlotRenewalChargeResult
    {
        $charge = DB::transaction(function () use ($chargeId, $source, $providerEventId, $actorUserId) {
            $locked = $this->renewalChargeRepository->findForUpdateById($chargeId);

            if ($locked === null) {
                throw new SlotRenewalChargeNotResumableException($chargeId, 'not_found');
            }

            if ($locked->state !== FundingAttemptState::Succeeded) {
                $fromState = $locked->state;
                $locked = $this->renewalChargeRepository->update($locked, ['state' => FundingAttemptState::Succeeded->value]);
                $this->recordRenewalChargeTransition($locked, $fromState, FundingAttemptState::Succeeded, $source, $providerEventId, $actorUserId);
            }

            return $locked;
        });

        if ($charge->charge_kind === SlotRenewalChargeKind::ScheduledRenewal) {
            $this->applyScheduledRenewalSuccess($charge);
        }

        if ($charge->charge_kind === SlotRenewalChargeKind::MidPeriodIncrease) {
            $this->performVerifiedRenewalChargeAllocation($charge);
        }

        return new SlotRenewalChargeResult($charge->id, $charge->state, null);
    }

    /**
     * M4 contract §21/§23 (Correction Round 2 §E.2) — applies a `requires_action`
     * provider outcome exactly once: re-locks by id, and a racing caller
     * that finds the charge already RequiresAction no-ops rather than
     * recording a duplicate transition.
     */
    private function applyRenewalChargeRequiresAction(int $chargeId, TransitionSource $source, ?int $actorUserId): SlotRenewalChargeResult
    {
        return DB::transaction(function () use ($chargeId, $source, $actorUserId) {
            $locked = $this->renewalChargeRepository->findForUpdateById($chargeId);

            if ($locked === null || $locked->state === FundingAttemptState::RequiresAction) {
                return new SlotRenewalChargeResult($chargeId, $locked?->state ?? FundingAttemptState::RequiresAction, null);
            }

            $fromState = $locked->state;
            $updated = $this->renewalChargeRepository->update($locked, ['state' => FundingAttemptState::RequiresAction->value]);
            $this->recordRenewalChargeTransition($updated, $fromState, FundingAttemptState::RequiresAction, $source, null, $actorUserId);

            return new SlotRenewalChargeResult($chargeId, $updated->state, null);
        });
    }

    /**
     * M4 contract §21/§23 (Correction Round 2 §E.2/§D) — the single shared
     * renewal-result-application routine for a verified provider failure.
     * Re-locks the charge by id and deduplicates against the durable
     * failed-transition count: when $expectedOrdinal is given (a genuine
     * retry attempt, §E.2), a durable count already `>= $expectedOrdinal`
     * means this exact Stripe-idempotent result was already applied by a
     * racing caller — a pure no-op, never a second transition. Only the
     * branch that actually inserts a new failed transition runs
     * maybeSetPaymentLapsed()/maybeReleaseTerminalIncreaseReservation()
     * (§D) — inside this same transaction, so the terminal-increase
     * release is atomically tied to the unique transition insert, never to
     * an assumption about how many times this method is entered.
     */
    private function applyRenewalChargeFailure(int $chargeId, string $reason, TransitionSource $source, ?int $providerEventId, ?int $actorUserId = null, ?int $expectedOrdinal = null): SlotRenewalChargeResult
    {
        return DB::transaction(function () use ($chargeId, $reason, $source, $providerEventId, $actorUserId, $expectedOrdinal) {
            $locked = $this->renewalChargeRepository->findForUpdateById($chargeId);

            if ($locked === null) {
                throw new SlotRenewalChargeNotResumableException($chargeId, 'not_found');
            }

            if (in_array($locked->state, [FundingAttemptState::Succeeded, FundingAttemptState::Canceled], true)) {
                return new SlotRenewalChargeResult($chargeId, $locked->state, null);
            }

            $durableFailedCount = $this->renewalChargeTransitionRepository->countFailedForCharge($chargeId);

            if ($expectedOrdinal !== null && $durableFailedCount >= $expectedOrdinal) {
                // This exact attempt's result was already applied by a
                // racing caller — no-op.
                return new SlotRenewalChargeResult($chargeId, $locked->state, $reason);
            }

            $fromState = $locked->state;
            $updated = $this->renewalChargeRepository->update($locked, [
                'state' => FundingAttemptState::Failed->value,
                'failure_reason' => $reason,
            ]);
            $this->recordRenewalChargeTransition($updated, $fromState, FundingAttemptState::Failed, $source, $providerEventId, $actorUserId);

            $this->maybeSetPaymentLapsed($updated);
            $this->maybeReleaseTerminalIncreaseReservation($updated);

            return new SlotRenewalChargeResult($chargeId, $updated->state, $reason);
        });
    }

    /**
     * M4 contract §13/§21 (Correction Round 2 §E.1/§E.3) — scheduled_renewal-
     * only advance of next_renewal_at. Runs inside its own transaction so
     * the agreement lock is genuinely effective. Forward-only/idempotent:
     * writes max(computed target, the currently-persisted next_renewal_at) —
     * never regresses an already-advanced date backward on a replay where
     * this same success is applied again (§E.3), which is why
     * applyRenewalChargeSuccess() now calls this unconditionally rather
     * than only on the charge's own first Succeeded transition.
     */
    private function applyScheduledRenewalSuccess(AdditionalBusinessSlotRenewalCharge $charge): void
    {
        $agreementId = (int) $charge->agreement_id;

        $wasLapsed = DB::transaction(function () use ($charge, $agreementId) {
            $agreement = $this->agreementRepository->findForUpdateById($agreementId);

            if ($agreement === null) {
                return null;
            }

            $wasLapsed = (bool) $agreement->payment_lapsed;

            $computedTarget = $wasLapsed
                ? Carbon::now()->toImmutable()->addMonthNoOverflow()
                : $charge->period_end;

            $targetNextRenewalAt = $agreement->next_renewal_at !== null && $agreement->next_renewal_at->greaterThan($computedTarget)
                ? $agreement->next_renewal_at
                : $computedTarget;

            $updates = ['next_renewal_at' => $targetNextRenewalAt];

            if ($wasLapsed) {
                $updates['payment_lapsed'] = false;
                $updates['payment_lapsed_cleared_at'] = now();
            }

            $this->agreementRepository->update($agreement, $updates);

            return $wasLapsed;
        });

        if ($wasLapsed) {
            AdditionalBusinessSlotAgreementPaymentRecovered::dispatch($agreementId);
        }
    }

    /**
     * M4 contract §13 (Correction Round 1) — the pre-lapse dunning cap:
     * only a scheduled_renewal charge's 3rd failed attempt lapses the
     * agreement; a mid_period_increase failure never does (§13/§21's own
     * lapse concept is scoped to the agreement's recurring renewal
     * cadence, not a one-off increase request). Called from inside
     * applyRenewalChargeFailure()'s own transaction — findForUpdateById()
     * here is therefore a genuinely effective lock (§E.1), not a nested,
     * separately-committed one.
     */
    private function maybeSetPaymentLapsed(AdditionalBusinessSlotRenewalCharge $charge): void
    {
        if ($charge->charge_kind !== SlotRenewalChargeKind::ScheduledRenewal) {
            return;
        }

        $failedCount = $this->renewalChargeTransitionRepository->countFailedForCharge((int) $charge->id);

        if ($failedCount < self::PRE_LAPSE_MAX_ATTEMPTS) {
            return;
        }

        $agreement = $this->agreementRepository->findForUpdateById((int) $charge->agreement_id);

        if ($agreement === null || $agreement->payment_lapsed) {
            return;
        }

        $this->agreementRepository->update($agreement, [
            'payment_lapsed' => true,
            'payment_lapsed_at' => now(),
            'next_renewal_at' => null,
        ]);

        AdditionalBusinessSlotAgreementLapsed::dispatch($agreement->id);
    }

    /**
     * M4 contract §11/§21 (Correction Round 2 §D) — releases a terminally-
     * failed mid_period_increase charge's own frozen reservation. Called
     * only from inside applyRenewalChargeFailure()'s own transaction,
     * immediately after that method's own dedup check has confirmed a
     * genuinely new (not a racing duplicate) threshold-reaching failed
     * transition was just inserted — this atomic pairing, not a separate
     * assumption about call count, is what makes the release exactly-once.
     * Never reduces target_allocation_count below current_allocation_count
     * (the agreement's own authoritative allocated floor); other pending
     * reservations remain untouched since this decrements only this
     * charge's own frozen allocation_delta.
     */
    private function maybeReleaseTerminalIncreaseReservation(AdditionalBusinessSlotRenewalCharge $charge): void
    {
        if ($charge->charge_kind !== SlotRenewalChargeKind::MidPeriodIncrease) {
            return;
        }

        $failedCount = $this->renewalChargeTransitionRepository->countFailedForCharge((int) $charge->id);

        if ($failedCount < self::PRE_LAPSE_MAX_ATTEMPTS) {
            return;
        }

        $agreement = $this->agreementRepository->findForUpdateById((int) $charge->agreement_id);

        if ($agreement === null) {
            return;
        }

        $releasedTarget = $agreement->target_allocation_count - (int) $charge->allocation_delta;

        if ($releasedTarget < $agreement->current_allocation_count) {
            throw new \RuntimeException("Terminal mid-period-increase release would reduce target_allocation_count below current_allocation_count for agreement [{$agreement->id}].");
        }

        $this->agreementRepository->update($agreement, ['target_allocation_count' => $releasedTarget]);
    }

    // ------------------------------------------------------------------
    // M4 contract §21 — cancellation
    // ------------------------------------------------------------------

    /**
     * M4 contract §12/§14/§21 (Correction Round 2 §E.1) — $reason mandatory
     * when $actorUserId is an administrator, optional for the owner's own
     * agreement. The administrator branch explicitly verifies real
     * platform-admin identity before accepting the action. The state
     * read-then-write is locked/re-checked against the persisted row, not
     * the caller-supplied model.
     */
    public function requestSlotAgreementCancellation(AdditionalBusinessSlotAgreement $agreement, int $actorUserId, ?string $reason = null): AdditionalBusinessSlotAgreement
    {
        $agreement->loadMissing('workspace');
        $isOwner = (int) $agreement->workspace->owner_user_id === $actorUserId;

        if (! $isOwner) {
            $this->assertPlatformAdministrator($actorUserId);

            if ($reason === null || trim($reason) === '') {
                throw new UnauthorizedSlotAgreementActionException($actorUserId, (int) $agreement->id, 'cancel without a reason');
            }
        }

        $agreementId = (int) $agreement->id;

        return DB::transaction(function () use ($agreementId) {
            $locked = $this->agreementRepository->findForUpdateById($agreementId);

            if ($locked === null) {
                throw new UnauthorizedSlotAgreementActionException(0, $agreementId, 'cancel a nonexistent agreement');
            }

            if ($locked->cancel_at_period_end) {
                return $locked;
            }

            return $this->agreementRepository->update($locked, [
                'cancel_at_period_end' => true,
                'cancellation_requested_at' => now(),
                'cancellation_effective_at' => $locked->next_renewal_at,
            ]);
        });
    }

    /**
     * M4 contract §12/§21 (Correction Round 2 §E.1) — the method
     * FinalizeSlotAgreementCancellation calls once per due agreement; the
     * job itself creates no row. Locked/re-checked against the persisted
     * row so a stale caller-held model never duplicates the transition.
     */
    public function finalizeSlotAgreementCancellation(AdditionalBusinessSlotAgreement $agreement): AdditionalBusinessSlotAgreement
    {
        $agreementId = (int) $agreement->id;

        [$result, $didCancel] = DB::transaction(function () use ($agreementId) {
            $locked = $this->agreementRepository->findForUpdateById($agreementId);

            if ($locked === null || $locked->state === SlotAgreementState::Canceled) {
                return [$locked, false];
            }

            $fromState = $locked->state;
            $updated = $this->agreementRepository->update($locked, [
                'state' => SlotAgreementState::Canceled->value,
                'next_renewal_at' => null,
            ]);
            $this->recordAgreementTransition($updated, $fromState, SlotAgreementState::Canceled, TransitionSource::SyncResponse, null, null);

            return [$updated, true];
        });

        if ($didCancel) {
            AdditionalBusinessSlotAgreementCanceled::dispatch($agreementId);
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // M4 contract §8/§21 — allocation: one shared routine, no synthetic actor
    // ------------------------------------------------------------------

    /**
     * M4 contract §8 items 1–5 (Correction Round 2 §A) — the single place
     * the cross-RFC allocation boundary is implemented. Called by exactly
     * three sites: confirmSlotAgreementChecked() (webhook/return
     * convergence, administratorActorUserId: null),
     * ReconcileSlotAgreementAllocation (directly, administratorActorUserId:
     * null — no synthetic actor), and allocateSlotAgreementAsAdministrator()
     * (the real administrator's own id and mandatory reason).
     *
     * Three explicit crash-safe phases — persisted state, never the
     * caller-supplied model's own state, is authoritative:
     *   Phase 1 (local): reload/lock the agreement; already-completed
     *     writes nothing (Phase 2 still runs, as an RFC-004-idempotent
     *     replay); payment_succeeded/allocation_pending/allocation_failed
     *     otherwise required; transitions to allocation_pending only when
     *     coming from payment_succeeded.
     *   Phase 2 (no open transaction): the one authorized EntitlementManager
     *     seam, with the agreement's own frozen evidence — identical for a
     *     genuine allocation and a replay.
     *   Phase 3 (local): on success, re-lock and complete exactly once,
     *     synchronizing current_allocation_count from the *returned*
     *     assignment; on an RFC-004 exception, re-lock and durably commit
     *     allocation_failed *before* the exception is rethrown, so the
     *     failure state survives even though the caller still observes the
     *     exception.
     *
     * No direct RFC-004 repository/table access anywhere in this method —
     * EntitlementCatalogSourceBoundaryTest enforces this.
     */
    public function performVerifiedAllocation(AdditionalBusinessSlotAgreement $agreement, ?int $administratorActorUserId = null, ?string $reason = null): WorkspacePlanAssignment
    {
        $agreementId = (int) $agreement->id;

        $locked = DB::transaction(function () use ($agreementId, $administratorActorUserId) {
            $locked = $this->agreementRepository->findForUpdateById($agreementId);

            if ($locked === null) {
                throw new UnauthorizedSlotAgreementActionException($administratorActorUserId ?? 0, $agreementId, 'allocate a nonexistent agreement');
            }

            if ($locked->state === SlotAgreementState::Completed) {
                return $locked;
            }

            if (! in_array($locked->state, [SlotAgreementState::PaymentSucceeded, SlotAgreementState::AllocationPending, SlotAgreementState::AllocationFailed], true)) {
                throw new UnauthorizedSlotAgreementActionException($administratorActorUserId ?? (int) $locked->requesting_customer_user_id, $agreementId, 'allocate from an unverified state');
            }

            if (blank($locked->provider_session_or_intent_reference)) {
                throw new UnauthorizedSlotAgreementActionException($administratorActorUserId ?? (int) $locked->requesting_customer_user_id, $agreementId, 'allocate without a verified payment reference');
            }

            if ($locked->state === SlotAgreementState::PaymentSucceeded) {
                $fromState = $locked->state;
                $locked = $this->agreementRepository->update($locked, ['state' => SlotAgreementState::AllocationPending->value]);
                $this->recordAgreementTransition($locked, $fromState, SlotAgreementState::AllocationPending, TransitionSource::SyncResponse, null, $administratorActorUserId);
            }

            return $locked;
        });

        $locked->load('workspace');
        $idempotencyKey = 'slot-agreement-allocation-'.$locked->local_idempotency_key;

        try {
            $assignment = $this->entitlementManager->allocateAdditionalBusinessSlotsFromVerifiedPayment(
                $locked->workspace,
                (int) $locked->paid_delta,
                (int) $locked->requesting_customer_user_id,
                (int) $locked->workspace_id,
                $idempotencyKey,
                (string) $locked->provider_session_or_intent_reference,
                $reason,
            );
        } catch (\Throwable $e) {
            DB::transaction(function () use ($agreementId, $administratorActorUserId) {
                $relocked = $this->agreementRepository->findForUpdateById($agreementId);

                if ($relocked !== null && $relocked->state !== SlotAgreementState::Completed) {
                    $fromState = $relocked->state;
                    $relocked = $this->agreementRepository->update($relocked, ['state' => SlotAgreementState::AllocationFailed->value]);
                    $this->recordAgreementTransition($relocked, $fromState, SlotAgreementState::AllocationFailed, TransitionSource::SyncResponse, null, $administratorActorUserId);
                    AdditionalBusinessSlotAllocationFailed::dispatch($relocked->id);
                }
            });

            throw $e;
        }

        return DB::transaction(function () use ($agreementId, $assignment, $administratorActorUserId) {
            $relocked = $this->agreementRepository->findForUpdateById($agreementId);

            if ($relocked === null || $relocked->state === SlotAgreementState::Completed) {
                return $assignment;
            }

            $fromState = $relocked->state;
            $updated = $this->agreementRepository->update($relocked, [
                'state' => SlotAgreementState::Completed->value,
                'current_allocation_count' => $assignment->additional_business_slots,
                // First-ever completion only (this branch is unreachable
                // once already Completed) — anchors the recurring monthly
                // schedule applyScheduledRenewalSuccess()/
                // createScheduledRenewalCharge() both depend on
                // next_renewal_at already being non-null.
                'next_renewal_at' => $relocked->next_renewal_at ?? Carbon::now()->toImmutable()->addMonthNoOverflow(),
            ]);
            $this->recordAgreementTransition($updated, $fromState, SlotAgreementState::Completed, TransitionSource::SyncResponse, null, $administratorActorUserId);
            AdditionalBusinessSlotAgreementCompleted::dispatch($agreementId);

            return $assignment;
        });
    }

    /**
     * M4 contract §8/§14/§21 — the manual allocation action, a thin
     * wrapper around performVerifiedAllocation(), never a fresh charge.
     * Explicitly verifies real platform-administrator identity before
     * $reason is even checked for non-emptiness.
     */
    public function allocateSlotAgreementAsAdministrator(AdditionalBusinessSlotAgreement $agreement, int $actorUserId, string $reason): WorkspacePlanAssignment
    {
        $this->assertPlatformAdministrator($actorUserId);

        if (trim($reason) === '') {
            throw new UnauthorizedSlotAgreementActionException($actorUserId, (int) $agreement->id, 'allocate without a reason');
        }

        return $this->performVerifiedAllocation($agreement, $actorUserId, $reason);
    }

    /**
     * M4 contract §21 (Correction Round 1 §D; Correction Round 2 §E.1) —
     * used only for charge_kind === mid_period_increase, only after that
     * charge's own payment has been durably, provider-verified successful.
     * Uses the parent agreement's own real workspace_id and original
     * requesting_customer_user_id — never the charge's own actor_user_id.
     * Synchronizes current_allocation_count from RFC-004's own returned
     * assignment; never increments blindly. Three explicit phases, mirroring
     * performVerifiedAllocation() (§A): local prepare (locked), the RFC-004
     * call outside any open transaction, local result apply (re-locked).
     */
    private function performVerifiedRenewalChargeAllocation(AdditionalBusinessSlotRenewalCharge $charge): WorkspacePlanAssignment
    {
        $agreementId = (int) $charge->agreement_id;

        $prepared = DB::transaction(function () use ($charge, $agreementId) {
            $agreement = $this->agreementRepository->findForUpdateById($agreementId);

            if ($agreement === null) {
                throw new UnauthorizedSlotAgreementActionException(0, $agreementId, 'allocate a renewal charge with no parent agreement');
            }

            if ((int) $charge->allocation_delta <= 0) {
                throw new UnauthorizedSlotAgreementActionException((int) $agreement->requesting_customer_user_id, (int) $agreement->id, 'allocate a renewal charge with no positive allocation_delta');
            }

            if (blank($charge->provider_session_or_intent_reference)) {
                throw new UnauthorizedSlotAgreementActionException((int) $agreement->requesting_customer_user_id, (int) $agreement->id, 'allocate a renewal charge with no verified payment reference');
            }

            return $agreement;
        });

        $prepared->load('workspace');
        $idempotencyKey = hash('sha256', 'slot-renewal-allocation:'.$charge->local_idempotency_key);

        $assignment = $this->entitlementManager->allocateAdditionalBusinessSlotsFromVerifiedPayment(
            $prepared->workspace,
            (int) $charge->allocation_delta,
            (int) $prepared->requesting_customer_user_id,
            (int) $prepared->workspace_id,
            $idempotencyKey,
            (string) $charge->provider_session_or_intent_reference,
            null,
        );

        return DB::transaction(function () use ($agreementId, $assignment) {
            $relocked = $this->agreementRepository->findForUpdateById($agreementId);

            if ($relocked !== null) {
                $this->agreementRepository->update($relocked, [
                    'current_allocation_count' => $assignment->additional_business_slots,
                ]);
            }

            return $assignment;
        });
    }

    /**
     * M4 contract §14/§21 — mirrors UsageWalletManager::assertPlatformAdministrator()'s
     * exact shape (a direct users.is_admin read), but throws M4's own
     * UnauthorizedSlotAgreementActionException.
     */
    private function assertPlatformAdministrator(int $actorUserId): void
    {
        $isAdmin = (bool) DB::table('users')->where('id', $actorUserId)->value('is_admin');

        if (! $isAdmin) {
            throw new UnauthorizedSlotAgreementActionException($actorUserId, null, 'act as a platform administrator');
        }
    }

    private function assertWorkspaceOwner(Workspace $workspace, int $actorUserId, string $action): void
    {
        if ((int) $workspace->owner_user_id !== $actorUserId) {
            throw new UnauthorizedSlotAgreementActionException($actorUserId, null, $action);
        }
    }

    private function recordAgreementTransition(AdditionalBusinessSlotAgreement $agreement, ?SlotAgreementState $from, SlotAgreementState $to, TransitionSource $source, ?int $providerEventId, ?int $actorUserId): void
    {
        $this->agreementTransitionRepository->create([
            'agreement_id' => $agreement->id,
            'from_state' => ($from ?? $to)->value,
            'to_state' => $to->value,
            'source' => $source->value,
            'provider_event_id' => $providerEventId,
            'actor_user_id' => $actorUserId,
            'created_at' => now(),
        ]);
    }

    private function recordRenewalChargeTransition(AdditionalBusinessSlotRenewalCharge $charge, ?FundingAttemptState $from, FundingAttemptState $to, TransitionSource $source, ?int $providerEventId, ?int $actorUserId): void
    {
        $this->renewalChargeTransitionRepository->create([
            'renewal_charge_id' => $charge->id,
            'from_state' => ($from ?? $to)->value,
            'to_state' => $to->value,
            'source' => $source->value,
            'provider_event_id' => $providerEventId,
            'actor_user_id' => $actorUserId,
            'created_at' => now(),
        ]);
    }

    /**
     * M4 contract §9 item 2 — reads the Workspace's current tier and the
     * matching catalog row, computes price_per_slot_micro as the base
     * plan price multiplied by the ratio (never the base price alone),
     * via exact bcmath arithmetic, reusing UsageWalletManager's own
     * bcRoundHalfUp() helper rather than re-implementing it. Fails closed
     * on undefined pricing before any agreement/charge row is written.
     *
     * @return array{0: \App\Library\Entitlement\WorkspacePlanCatalogSummary, 1: int}
     */
    private function resolveCurrentCatalogPricing(Workspace $workspace): array
    {
        $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($workspace);
        $tier = $summary->tier;

        if ($tier === null) {
            throw new UnauthorizedSlotAgreementActionException(0, null, 'price an additional slot for a Workspace with no assigned plan tier');
        }

        $catalogRow = null;

        foreach ($this->entitlementManager->listPlanCatalogSummaries() as $row) {
            if ($row->tier === $tier) {
                $catalogRow = $row;

                break;
            }
        }

        if ($catalogRow === null || $catalogRow->price === null || $catalogRow->currencyId === null || $catalogRow->additionalBusinessSlotPriceRatio === null) {
            throw new UnauthorizedSlotAgreementActionException(0, null, 'price an additional slot with undefined catalog pricing');
        }

        $basePlanPriceMicro = $this->decimalPriceToMicroUnits($catalogRow->price);
        $pricePerSlotMicro = (int) UsageWalletManager::bcRoundHalfUp(bcmul((string) $basePlanPriceMicro, $catalogRow->additionalBusinessSlotPriceRatio, 10), '1');

        return [$catalogRow, $pricePerSlotMicro];
    }

    /**
     * Converts workspace_plan_catalog.price (a decimal(16,2) string
     * always expressed in major currency units) into exact micro-units —
     * distinct from microToMinorUnits(), which converts internal
     * micro-units to a provider minor-unit integer.
     */
    private function decimalPriceToMicroUnits(string $price): int
    {
        return (int) bcmul($price, '1000000', 0);
    }

    private function currencyCodeById(int $currencyId): string
    {
        return (string) DB::table('currencies')->where('id', $currencyId)->value('code');
    }

    /**
     * M4 contract §15 — the exact minor-unit amount a verified
     * slot_agreement webhook event must carry to match this agreement's
     * own frozen expectation. Mirrors expectedMinorUnitsFor()'s existing
     * M3 role exactly, used only by ProcessPaymentProviderEvent's own
     * pre-mutation validation, never by any code path that itself calls
     * the provider.
     */
    public function expectedMinorUnitsForAgreement(AdditionalBusinessSlotAgreement $agreement): int
    {
        return $this->microToMinorUnits((int) $agreement->total_amount_micro_snapshot, $this->currencyCodeById((int) $agreement->currency_id_snapshot));
    }

    public function expectedCurrencyCodeForAgreement(AdditionalBusinessSlotAgreement $agreement): string
    {
        return $this->currencyCodeById((int) $agreement->currency_id_snapshot);
    }

    /**
     * M4 contract §15 — the exact minor-unit amount a verified
     * slot_renewal_charge webhook event must carry to match this charge's
     * own frozen expectation. Mirrors expectedMinorUnitsFor()'s existing
     * M3 role exactly.
     */
    public function expectedMinorUnitsForRenewalCharge(AdditionalBusinessSlotRenewalCharge $charge): int
    {
        return $this->microToMinorUnits((int) $charge->amount_micro_snapshot, $this->currencyCodeById((int) $charge->currency_id_snapshot));
    }

    public function expectedCurrencyCodeForRenewalCharge(AdditionalBusinessSlotRenewalCharge $charge): string
    {
        return $this->currencyCodeById((int) $charge->currency_id_snapshot);
    }
}
