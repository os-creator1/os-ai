<?php

namespace App\Library\PlatformBilling\Offers;

use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformBilling\BillingCycle;
use App\Library\PlatformBilling\PlatformPriceVerifier;
use App\Library\PlatformBilling\PlatformStripeGateway;
use App\Models\PlatformStripePrice;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Database\QueryException;

/**
 * MotionGrove creates — and VERIFIES — the Stripe Prices it sells on, so the
 * Platform Owner never has to open Stripe to make a Price for an offer.
 *
 * A Stripe Price is immutable, so an agreed price is represented by a Price
 * that can only ever mean what it meant on the day it was created. This class
 * guarantees three things:
 *
 *   1. ONE Price per commercial identity. (plan, currency, amount, recurrence,
 *      mode) maps to a single Price, reused by every offer at that amount and
 *      protected by a unique key against concurrent duplicates.
 *   2. NOTHING IS TRUSTED UNREAD. A Price is re-read from the provider and
 *      compared — amount, currency, recurrence (day x 28 for the standard
 *      cycle), active, recurring, livemode — both when it is created and when
 *      an existing one is reused. A mismatch fails closed; it is never
 *      "corrected" by quietly using a different Price.
 *   3. THE PLAN'S OWN PRICE IS PREFERRED. An offer at exactly the plan's list
 *      amount sells on the plan's existing Stripe Price, so it is
 *      indistinguishable from a normal purchase there.
 *
 * Provider calls happen with NO transaction open (the gateway fakes police it).
 */
final class PlatformPriceProvisioner
{
    public function __construct(
        private readonly PlatformStripeGateway $gateway,
        private readonly PlatformPriceVerifier $verifier,
    ) {
    }

    /**
     * The verified Stripe Price id that charges `$amountMinor` per billing
     * period of `$catalog`'s cycle.
     *
     * @throws PlatformBillingException
     */
    public function priceIdFor(WorkspacePlanCatalog $catalog, int $amountMinor, string $currencyCode): string
    {
        return $this->resolve($catalog, $amountMinor, $currencyCode, preferCatalogPrice: true);
    }

    private function resolve(WorkspacePlanCatalog $catalog, int $amountMinor, string $currencyCode, bool $preferCatalogPrice): string
    {
        $currencyCode = mb_strtoupper($currencyCode);
        $recurrence = BillingCycle::stripeRecurrence((string) $catalog->billing_cycle)
            ?? throw PlatformBillingException::because(PlatformBillingException::TIER_NOT_PRICED);

        [$interval, $intervalCount] = $recurrence;

        $mode = $this->gateway->configurationStatus()['mode'];

        if ($mode === null) {
            throw PlatformBillingException::because(PlatformBillingException::NOT_CONFIGURED);
        }

        $livemode = $mode === 'live';

        // 1. The plan's own Price, when the amount is the plan's own amount.
        if ($preferCatalogPrice
            && ! blank($catalog->provider_price_id)
            && $catalog->price !== null
            && PlatformMoney::toMinor((string) $catalog->price, $currencyCode) === $amountMinor
            && (int) $catalog->currency_id !== 0
            && $this->matches((string) $catalog->provider_price_id, $amountMinor, $currencyCode, (string) $catalog->billing_cycle)) {
            return (string) $catalog->provider_price_id;
        }

        // 2. A Price MotionGrove already made for exactly this.
        $existing = $this->registryRow($catalog, $currencyCode, $amountMinor, $interval, $intervalCount, $livemode);

        if ($existing !== null) {
            return $this->verifiedOrFail((string) $existing->provider_price_id, $amountMinor, $currencyCode, (string) $catalog->billing_cycle);
        }

        // 3. Create it.
        $productId = $this->productFor($catalog);

        $identity = implode('|', [$catalog->id, $currencyCode, $amountMinor, $interval, $intervalCount, $livemode ? 'live' : 'test']);

        $created = $this->gateway->createRecurringPrice(
            productId: $productId,
            unitAmountMinor: $amountMinor,
            currencyCode: $currencyCode,
            interval: $interval,
            intervalCount: $intervalCount,
            nickname: sprintf('%s - %s %s', $catalog->display_name, PlatformMoney::toDecimal($amountMinor, $currencyCode), BillingCycle::label((string) $catalog->billing_cycle)),
            metadata: ['app_scope' => 'platform_plan', 'app_tier' => $catalog->tier->value],
            idempotencyKey: 'platform-price:' . hash('sha256', $identity),
        );

        // Verify what the provider actually made against what we asked for.
        $this->verifiedOrFail($created->id, $amountMinor, $currencyCode, (string) $catalog->billing_cycle);

        try {
            (new PlatformStripePrice())->forceFill([
                'workspace_plan_catalog_id' => $catalog->id,
                'currency_code' => $currencyCode,
                'amount_minor' => $amountMinor,
                'interval' => $interval,
                'interval_count' => $intervalCount,
                'provider_product_id' => $productId,
                'provider_price_id' => $created->id,
                'purpose' => 'offer',
                'livemode' => $livemode,
                'verified_at' => now(),
            ])->save();
        } catch (QueryException $e) {
            // Lost a race to an identical row: converge on the winner. Anything
            // else (a connection or data error) is NOT a race and must surface,
            // not hand back an untracked Price.
            $winner = $this->registryRow($catalog, $currencyCode, $amountMinor, $interval, $intervalCount, $livemode);

            if ($winner === null) {
                throw $e;
            }

            if ($winner->provider_price_id !== $created->id) {
                return $this->verifiedOrFail((string) $winner->provider_price_id, $amountMinor, $currencyCode, (string) $catalog->billing_cycle);
            }
        }

        return $created->id;
    }

    /**
     * Creates (or reuses) the standard recurring Price for a plan's listed
     * price and returns its id, ready to be written to the catalog through the
     * audited plan administrator. This is what replaces "go and make a Price in
     * Stripe" for the plans themselves.
     *
     * @throws PlatformBillingException
     */
    public function provisionCatalogPrice(WorkspacePlanCatalog $catalog, string $currencyCode): string
    {
        if ($catalog->price === null) {
            throw PlatformBillingException::because(PlatformBillingException::TIER_NOT_PRICED);
        }

        $minor = PlatformMoney::toMinor((string) $catalog->price, $currencyCode)
            ?? throw PlatformBillingException::because(PlatformBillingException::TIER_NOT_PRICED);

        // The catalog's own pointer is exactly what is being (re)established, so
        // it is not consulted: this goes straight to "reuse or create".
        return $this->resolve($catalog, $minor, $currencyCode, preferCatalogPrice: false);
    }

    private function productFor(WorkspacePlanCatalog $catalog): string
    {
        if (! blank($catalog->provider_product_id)) {
            return $this->gateway->ensureProduct(
                (string) $catalog->display_name,
                (string) $catalog->provider_product_id,
                'platform-product:' . $catalog->tier->value,
            );
        }

        // Group under the product of the plan's existing Price when there is one.
        $productId = null;

        // The 28-day migration moves a monthly Price to `legacy_provider_price_id`,
        // which is still on the plan's own Product, so it is the fallback.
        foreach ([$catalog->provider_price_id, $catalog->legacy_provider_price_id] as $existingPrice) {
            if (blank($existingPrice)) {
                continue;
            }

            try {
                $productId = $this->gateway->retrievePrice((string) $existingPrice)->productId;
            } catch (PlatformBillingException) {
                $productId = null;
            }

            if (! blank($productId)) {
                break;
            }
        }

        $productId = $this->gateway->ensureProduct(
            (string) $catalog->display_name,
            $productId,
            'platform-product:' . $catalog->tier->value,
        );

        WorkspacePlanCatalog::query()->whereKey($catalog->id)->update(['provider_product_id' => $productId]);
        $catalog->provider_product_id = $productId;

        return $productId;
    }

    private function registryRow(WorkspacePlanCatalog $catalog, string $currencyCode, int $amountMinor, string $interval, int $intervalCount, bool $livemode): ?PlatformStripePrice
    {
        return PlatformStripePrice::query()
            ->where('workspace_plan_catalog_id', $catalog->id)
            ->where('currency_code', $currencyCode)
            ->where('amount_minor', $amountMinor)
            ->where('interval', $interval)
            ->where('interval_count', $intervalCount)
            ->where('livemode', $livemode)
            ->first();
    }

    private function matches(string $priceId, int $amountMinor, string $currencyCode, string $cycle): bool
    {
        try {
            return $this->verifier->mismatches(
                $priceId,
                PlatformMoney::toDecimal($amountMinor, $currencyCode),
                $currencyCode,
                $cycle,
            ) === [];
        } catch (PlatformBillingException) {
            return false;
        }
    }

    /** @throws PlatformBillingException */
    private function verifiedOrFail(string $priceId, int $amountMinor, string $currencyCode, string $cycle): string
    {
        $mismatches = $this->verifier->mismatches(
            $priceId,
            PlatformMoney::toDecimal($amountMinor, $currencyCode),
            $currencyCode,
            $cycle,
        );

        if ($mismatches !== []) {
            throw PlatformBillingException::because(PlatformBillingException::PRICE_TERMS_MISMATCH);
        }

        return $priceId;
    }
}
