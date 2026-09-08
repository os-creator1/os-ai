<?php

namespace App\Library\Entitlement;

use InvalidArgumentException;

/**
 * Customer Experience Slice 1A, correction round 1 — the explicit
 * AUTHORITY/PROVENANCE boundary in front of the paid physical-location
 * allocation seam.
 *
 * A paid 4th/5th location is a subscription amendment. Before this class
 * existed, EntitlementManager::allocateAdditionalLocationSlot() took only
 * an actor user id and mutated additional_location_slots for any caller
 * that invoked it, which made it a general customer-callable free grant:
 * capacity that is nominally paid could be handed out with no price, no
 * checkout, no invoice item and no payment evidence behind it.
 *
 * The seam now demands one of exactly two provenances, and neither can be
 * produced from ordinary customer request input:
 *
 *   verified_billing   — constructed ONLY by a future billing slice that
 *                        has already independently verified a durable,
 *                        successful, idempotent payment/subscription
 *                        amendment for exactly this allocation. It must
 *                        present that evidence here. This class performs
 *                        no provider call and makes no payment decision;
 *                        it records the caller's prior verification,
 *                        exactly as RFC-004 Amendment 1 §5's
 *                        allocateAdditionalBusinessSlotsFromVerifiedPayment()
 *                        already does for BUSINESS slots.
 *
 *   platform_operator  — an authenticated platform administrator acting
 *                        deliberately, with a written reason. The
 *                        administrator identity is re-verified against
 *                        users.is_admin inside EntitlementManager; passing
 *                        an arbitrary id here proves nothing.
 *
 * Slice 1A ships NEITHER caller. There is no Core/Growth price yet, so no
 * verified-billing evidence can exist, and no operator surface for
 * location slots is in scope. The class exists so the seam is closed now
 * and the future billing slice has a real, honest door to knock on.
 */
final readonly class LocationSlotAllocationAuthority
{
    public const PROVENANCE_VERIFIED_BILLING = 'verified_billing';

    public const PROVENANCE_PLATFORM_OPERATOR = 'platform_operator';

    /**
     * Private on purpose: the only ways in are the two named constructors
     * below, each of which demands the evidence its provenance implies.
     */
    private function __construct(
        public string $provenance,
        public ?int $operatorUserId,
        public ?int $requestingCustomerUserId,
        public ?string $billingIdempotencyKey,
        public ?string $billingProviderReference,
        public string $reason,
    ) {
    }

    /**
     * For the future billing slice ONLY, after it has verified payment.
     *
     * @throws InvalidArgumentException when the evidence is not present.
     *         Empty strings are rejected precisely so a caller cannot
     *         manufacture "evidence" it does not have.
     */
    public static function fromVerifiedBilling(
        int $requestingCustomerUserId,
        string $billingIdempotencyKey,
        string $billingProviderReference,
        string $reason,
    ): self {
        if ($requestingCustomerUserId <= 0) {
            throw new InvalidArgumentException('Verified-billing location-slot authority requires the requesting customer user id.');
        }

        if (trim($billingIdempotencyKey) === '' || trim($billingProviderReference) === '') {
            throw new InvalidArgumentException('Verified-billing location-slot authority requires a durable idempotency key and provider reference.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Verified-billing location-slot authority requires a reason for the audit record.');
        }

        return new self(
            self::PROVENANCE_VERIFIED_BILLING,
            null,
            $requestingCustomerUserId,
            trim($billingIdempotencyKey),
            trim($billingProviderReference),
            trim($reason),
        );
    }

    /**
     * For a deliberate platform-administrator action. The id is re-checked
     * against users.is_admin by EntitlementManager before anything is
     * written, so this constructor grants nothing on its own.
     */
    public static function fromPlatformOperator(int $operatorUserId, string $reason): self
    {
        if ($operatorUserId <= 0) {
            throw new InvalidArgumentException('Platform-operator location-slot authority requires an operator user id.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Platform-operator location-slot authority requires a written reason.');
        }

        return new self(
            self::PROVENANCE_PLATFORM_OPERATOR,
            $operatorUserId,
            null,
            null,
            null,
            trim($reason),
        );
    }

    public function isPlatformOperator(): bool
    {
        return $this->provenance === self::PROVENANCE_PLATFORM_OPERATOR;
    }

    public function isVerifiedBilling(): bool
    {
        return $this->provenance === self::PROVENANCE_VERIFIED_BILLING;
    }
}
