<?php

namespace App\Library\Usage;

use App\Enums\Usage\PayerType;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Models\Business;
use App\Models\BusinessPaymentInstrument;
use App\Models\PaymentProviderCustomer;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;
use App\Repositories\Contracts\BusinessPaymentInstrumentRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M3 contract §9/§10 — sole write authority for payment_provider_customers
 * and business_payment_instruments. Every outbound PaymentProviderGateway
 * call happens strictly outside any database transaction/lock (§8/§16).
 * SetupIntent creation and instrument attach/detach are charge-adjacent
 * actions, gated by the identical payer-consent authority M2's
 * BillingProfileManager::assertPayerConsent() already established for
 * payer changes — evaluated against the wallet's CURRENT payer_type
 * (RFC-005 §16's "consent extended to every charge-causing action" rule),
 * never a broader non-payment authority.
 */
class PaymentInstrumentManager
{
    public function __construct(
        private readonly PaymentProviderCustomerRepository $providerCustomerRepository,
        private readonly BusinessPaymentInstrumentRepository $instrumentRepository,
        private readonly BusinessPayerAssignmentRepository $payerAssignmentRepository,
        private readonly PaymentProviderGateway $gateway,
    ) {
    }

    /**
     * Resolves (creating only if genuinely absent) the provider customer
     * that backs the Business's current payer. No migration/backfill ever
     * calls this — it is invoked only from an authorized payment-setup
     * action (M3 contract §22).
     */
    public function resolveProviderCustomer(Business $business, int $actorUserId): PaymentProviderCustomer
    {
        $payerType = $this->assertChargeCausingConsent($business, $actorUserId);

        if ($payerType === PayerType::Workspace) {
            $business->loadMissing('workspace');
            $existing = $this->providerCustomerRepository->findActiveByWorkspaceId((int) $business->workspace->id);

            if ($existing !== null) {
                return $existing;
            }

            $idempotencyKey = 'provider-customer-workspace-'.$business->workspace->id;
            $result = $this->gateway->createOrRetrieveCustomer(null, $idempotencyKey);

            return $this->providerCustomerRepository->create([
                'provider' => 'stripe',
                'workspace_id' => $business->workspace->id,
                'business_id' => null,
                'provider_customer_id' => $result->providerCustomerId,
                'status' => 'active',
            ]);
        }

        $existing = $this->providerCustomerRepository->findActiveByBusinessId((int) $business->id);

        if ($existing !== null) {
            return $existing;
        }

        $idempotencyKey = 'provider-customer-business-'.$business->id;
        $result = $this->gateway->createOrRetrieveCustomer(null, $idempotencyKey);

        return $this->providerCustomerRepository->create([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'workspace_id' => null,
            'provider_customer_id' => $result->providerCustomerId,
            'status' => 'active',
        ]);
    }

    public function createSetupIntent(Business $business, int $actorUserId): SetupIntentResult
    {
        $providerCustomer = $this->resolveProviderCustomer($business, $actorUserId);
        $idempotencyKey = 'setup-intent-'.$providerCustomer->id.'-'.Str::uuid();

        return $this->gateway->createSetupIntent($providerCustomer->provider_customer_id, $idempotencyKey);
    }

    /**
     * Confirms (via authoritative provider retrieval, never a browser
     * redirect alone, M3 contract §10 item 6) and persists the resulting
     * instrument exactly once. Idempotent — a repeat confirmation of an
     * already-attached provider_payment_method_id is a no-op.
     */
    public function confirmSetupIntentAndAttach(Business $business, int $actorUserId, string $providerSetupIntentId): ?BusinessPaymentInstrument
    {
        $providerCustomer = $this->resolveProviderCustomer($business, $actorUserId);

        $setupIntent = $this->gateway->retrieveSetupIntent($providerSetupIntentId);

        if ($setupIntent->status !== 'succeeded' || $setupIntent->providerPaymentMethodId === null) {
            return null;
        }

        $paymentMethod = $this->gateway->retrievePaymentMethod($setupIntent->providerPaymentMethodId);

        if ($paymentMethod->providerCustomerId !== $providerCustomer->provider_customer_id) {
            return null;
        }

        $existing = $this->instrumentRepository->findByProviderPaymentMethodId($paymentMethod->providerPaymentMethodId);

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($providerCustomer, $paymentMethod) {
            $hasDefault = $this->instrumentRepository->findDefaultForProviderCustomer($providerCustomer->id) !== null;

            return $this->instrumentRepository->create([
                'provider_customer_id' => $providerCustomer->id,
                'provider' => 'stripe',
                'provider_payment_method_id' => $paymentMethod->providerPaymentMethodId,
                'type' => $paymentMethod->type,
                'brand' => $paymentMethod->brand,
                'last_four' => $paymentMethod->lastFour,
                'expiry_month' => $paymentMethod->expiryMonth,
                'expiry_year' => $paymentMethod->expiryYear,
                'is_default' => ! $hasDefault,
                'status' => 'active',
                'created_at' => now(),
            ]);
        });
    }

    public function detachInstrument(Business $business, int $actorUserId, BusinessPaymentInstrument $instrument): void
    {
        $this->assertChargeCausingConsent($business, $actorUserId);

        $this->gateway->detachPaymentMethod($instrument->provider_payment_method_id);

        DB::transaction(function () use ($instrument) {
            $this->instrumentRepository->update($instrument, [
                'status' => 'detached',
                'detached_at' => now(),
            ]);
        });
    }

    public function setDefaultInstrument(Business $business, int $actorUserId, BusinessPaymentInstrument $instrument): void
    {
        $this->assertChargeCausingConsent($business, $actorUserId);

        DB::transaction(function () use ($instrument) {
            $this->providerCustomerRepository->findForUpdateById((int) $instrument->provider_customer_id);

            $this->instrumentRepository->clearDefaultForProviderCustomer((int) $instrument->provider_customer_id);
            $this->instrumentRepository->update($instrument, ['is_default' => true]);
        });
    }

    /**
     * M4 contract §15c — reconciles the actual Checkout Session
     * PaymentMethod into business_payment_instruments as the Workspace's
     * own current default usage-billing instrument. Workspace owner only,
     * no platform-admin bypass — narrower than assertChargeCausingConsent()'s
     * dual Business/Workspace-payer split, since M4's additional-slot flow
     * is unconditionally Workspace-scoped. Reuses this class's own existing
     * repository/gateway calls verbatim; no schema change.
     */
    public function syncWorkspaceCheckoutPaymentMethod(
        Workspace $workspace,
        int $actorUserId,
        string $providerPaymentMethodId,
    ): BusinessPaymentInstrument {
        if ((int) $workspace->owner_user_id !== $actorUserId) {
            throw new UnauthorizedPayerAssignmentException($actorUserId, 0, PayerType::Workspace->value);
        }

        $providerCustomer = $this->providerCustomerRepository->findActiveByWorkspaceId((int) $workspace->id);

        if ($providerCustomer === null) {
            throw new UnauthorizedPayerAssignmentException($actorUserId, 0, PayerType::Workspace->value);
        }

        $paymentMethod = $this->gateway->retrievePaymentMethod($providerPaymentMethodId);

        if ($paymentMethod->providerCustomerId !== $providerCustomer->provider_customer_id) {
            throw new UnauthorizedPayerAssignmentException($actorUserId, 0, PayerType::Workspace->value);
        }

        $existing = $this->instrumentRepository->findByProviderPaymentMethodId($paymentMethod->providerPaymentMethodId);

        return DB::transaction(function () use ($providerCustomer, $paymentMethod, $existing) {
            $instrument = $existing ?? $this->instrumentRepository->create([
                'provider_customer_id' => $providerCustomer->id,
                'provider' => 'stripe',
                'provider_payment_method_id' => $paymentMethod->providerPaymentMethodId,
                'type' => $paymentMethod->type,
                'brand' => $paymentMethod->brand,
                'last_four' => $paymentMethod->lastFour,
                'expiry_month' => $paymentMethod->expiryMonth,
                'expiry_year' => $paymentMethod->expiryYear,
                'is_default' => false,
                'status' => 'active',
                'created_at' => now(),
            ]);

            $this->providerCustomerRepository->findForUpdateById((int) $providerCustomer->id);
            $this->instrumentRepository->clearDefaultForProviderCustomer((int) $providerCustomer->id);

            return $this->instrumentRepository->update($instrument, ['is_default' => true]);
        });
    }

    /**
     * RFC-005 §16's "consent extended to every charge-causing action" rule
     * — evaluated against the wallet's CURRENT payer_type, mirroring
     * BillingProfileManager::assertPayerConsent()'s exact authority split.
     * No platform-administrator override exists for origination (§9/§17).
     */
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
