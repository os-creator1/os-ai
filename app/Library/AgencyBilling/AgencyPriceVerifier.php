<?php

namespace App\Library\AgencyBilling;

use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Library\Money\StripeMinorUnits;

/**
 * Lane C §C5.2 — proving that an Agency's plan terms and the Stripe Price it
 * points at are the same thing.
 *
 * WHY THIS IS NOT A FORMAT CHECK. `price_...` looks like a Price id and tells
 * you nothing. The failure that matters is a plan advertising 349.00 USD
 * monthly while the Price actually charges 34.90 EUR yearly — the client is
 * billed something nobody agreed to, and the first person to notice is the
 * client. So the Price is RETRIEVED and every term is compared.
 *
 * THE ACCOUNT IS THE STRONGEST CHECK, AND IT IS FREE. The retrieval goes
 * through this Agency's own `Stripe-Account` header, so a Price belonging to
 * the platform, to lane B, or to a DIFFERENT Agency is not visible at all —
 * cross-account substitution fails as "not retrievable" before any term is
 * even compared. That is structural, not a rule someone has to remember.
 */
final class AgencyPriceVerifier
{
    public function __construct(private readonly AgencyStripeGateway $gateway)
    {
    }

    /**
     * @param  string  $billingCycle  `monthly` or `yearly`
     *
     * @throws AgencyBillingException PRICE_NOT_RETRIEVABLE or PRICE_TERMS_MISMATCH
     */
    public function verify(
        string $connectedAccountId,
        string $providerPriceId,
        string $amount,
        string $currencyCode,
        string $billingCycle,
    ): AgencyPriceSnapshot {
        // Throws PRICE_NOT_RETRIEVABLE for anything this account cannot see.
        $price = $this->gateway->retrievePrice($connectedAccountId, $providerPriceId);

        $expectedMinor = StripeMinorUnits::toMinor($amount, $currencyCode);
        $expectedInterval = self::intervalFor($billingCycle);
        $mode = (string) ($this->gateway->configurationStatus()['mode'] ?? 'test');

        $matches = $price->active
            && $price->recurring
            && mb_strtoupper($price->currency) === mb_strtoupper(trim($currencyCode))
            && $expectedMinor !== null
            && $price->unitAmount === $expectedMinor
            && $price->interval === $expectedInterval
            && (int) $price->intervalCount === 1
            && $price->livemode === ($mode === 'live');

        if (! $matches) {
            throw AgencyBillingException::because(AgencyBillingException::PRICE_TERMS_MISMATCH);
        }

        return $price;
    }

    /**
     * §C5.2 — have the provider create the Price from the terms the Agency
     * typed, so operating lane C needs neither a Stripe dashboard visit nor a
     * database edit. The result is then verified by the same rules, because a
     * Price we asked for is still only a Price the provider actually made.
     *
     * @throws AgencyBillingException
     */
    public function createAndVerify(
        string $connectedAccountId,
        string $productName,
        string $amount,
        string $currencyCode,
        string $billingCycle,
        string $idempotencyKey,
    ): AgencyPriceSnapshot {
        $minor = StripeMinorUnits::toMinor($amount, $currencyCode);

        if ($minor === null) {
            // Not a chargeable amount in this currency — e.g. a fraction of
            // ISK. Refusing here beats letting the provider invent a rounding.
            throw AgencyBillingException::because(AgencyBillingException::PRICE_TERMS_MISMATCH);
        }

        $created = $this->gateway->createPrice(
            $connectedAccountId,
            $productName,
            $minor,
            $currencyCode,
            self::intervalFor($billingCycle),
            $idempotencyKey,
        );

        return $this->verify($connectedAccountId, $created->id, $amount, $currencyCode, $billingCycle);
    }

    /** `monthly`/`yearly` as Stripe's own recurring interval. */
    public static function intervalFor(string $billingCycle): string
    {
        return mb_strtolower(trim($billingCycle)) === 'yearly' ? 'year' : 'month';
    }
}
