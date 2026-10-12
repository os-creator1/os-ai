<?php

namespace Tests\Feature\PlatformBilling;

use App\Library\PlatformBilling\Offers\EmailIdentity;
use App\Library\PlatformBilling\Offers\OfferTermsCalculator;
use App\Library\PlatformBilling\Offers\PlatformMoney;
use App\Models\PlatformPrivateOffer;
use App\Models\WorkspacePlanCatalog;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The exact arithmetic of a Private Offer: what is charged today, when the
 * first payment is, when a promotion ends and what the price is afterwards.
 * Pure — no database, no Stripe.
 */
class PrivateOfferTermsTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2027-01-10 12:00:00', 'UTC');
    }

    private function plan(string $price = '100.00', string $cycle = 'every_28_days'): WorkspacePlanCatalog
    {
        $catalog = new WorkspacePlanCatalog();
        $catalog->forceFill([
            'id' => 7, 'tier' => 'growth', 'display_name' => 'Growth', 'price' => $price, 'billing_cycle' => $cycle,
        ]);

        return $catalog;
    }

    /** @param array<string, mixed> $attributes */
    private function offer(array $attributes = []): PlatformPrivateOffer
    {
        $offer = new PlatformPrivateOffer();
        $offer->forceFill(array_merge([
            'kind' => PlatformPrivateOffer::KIND_NEW_SUBSCRIPTION,
            'currency_id' => 1,
            'trial_days' => null,
            'custom_price' => null,
            'discount_type' => null,
            'discount_value' => null,
            'discount_duration' => null,
            'discount_periods' => null,
        ], $attributes));

        return $offer;
    }

    private function terms(PlatformPrivateOffer $offer, bool $trialEligible = true, ?WorkspacePlanCatalog $plan = null, ?array $existing = null)
    {
        return (new OfferTermsCalculator())->calculate($offer, $plan ?? $this->plan(), 'USD', $trialEligible, $this->now, $existing);
    }

    public function test_the_plain_plan_price_is_charged_today_and_every_28_days(): void
    {
        $terms = $this->terms($this->offer());

        $this->assertSame(10000, $terms->todayChargeMinor());
        $this->assertSame(10000, $terms->standardPriceMinor());
        $this->assertSame('Every 28 days', (string) $terms->get('cycle_label'));
        $this->assertSame('day', $terms->get('interval'));
        $this->assertSame(28, $terms->get('interval_count'));
        $this->assertSame($this->now->toIso8601String(), $terms->firstPaymentAt()->toIso8601String());
        $this->assertFalse($terms->hasPromotionEnd());
        $this->assertNull($terms->trialDays());
    }

    public function test_a_trial_of_any_length_defers_the_first_payment_and_charges_nothing_today(): void
    {
        foreach ([7, 14, 28, 45] as $days) {
            $terms = $this->terms($this->offer(['trial_days' => $days]));

            $this->assertSame($days, $terms->trialDays());
            $this->assertSame(0, $terms->todayChargeMinor(), "{$days}-day trial charges nothing today");
            $this->assertSame($this->now->addDays($days)->toIso8601String(), $terms->firstPaymentAt()->toIso8601String());
            $this->assertSame(10000, $terms->get('first_payment_minor'));
        }
    }

    public function test_a_trial_that_is_not_available_is_removed_and_the_first_charge_is_today(): void
    {
        $terms = $this->terms($this->offer(['trial_days' => 14]), trialEligible: false);

        $this->assertNull($terms->trialDays());
        $this->assertTrue($terms->get('trial_blocked'));
        $this->assertSame(10000, $terms->todayChargeMinor());
    }

    public function test_a_custom_recurring_price_replaces_the_plan_price(): void
    {
        $terms = $this->terms($this->offer(['custom_price' => '79.50']));

        $this->assertSame(7950, $terms->standardPriceMinor());
        $this->assertSame(7950, $terms->todayChargeMinor());
        $this->assertSame('negotiated', $terms->get('standard_source'));
        $this->assertSame(10000, $terms->get('plan_price_minor'), 'the list price is still recorded');
        $this->assertSame([7950], $terms->requiredAmountsMinor());
    }

    public function test_a_fixed_discount_for_n_periods_ends_after_exactly_n_28_day_periods_then_reverts(): void
    {
        $terms = $this->terms($this->offer([
            'discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'periods', 'discount_periods' => 3,
        ]));

        $paid = $terms->paidPhases();
        $this->assertCount(2, $paid);
        $this->assertSame(7000, $paid[0]['price_minor']);
        $this->assertSame(3, $paid[0]['periods']);
        $this->assertSame(10000, $paid[1]['price_minor']);
        $this->assertSame(7000, $terms->todayChargeMinor());

        // 3 periods x 28 days = 84 days — not "3 months".
        $this->assertSame($this->now->addDays(84)->toIso8601String(), $paid[0]['ends_at']);
        $this->assertSame($paid[0]['ends_at'], $paid[1]['starts_at']);
        $this->assertSame(10000, $terms->get('recurring_after_promotion_minor'));
        $this->assertTrue($terms->hasPromotionEnd());
        $this->assertSame([7000, 10000], $terms->requiredAmountsMinor());
    }

    public function test_a_percentage_discount_for_one_period(): void
    {
        $terms = $this->terms($this->offer(['discount_type' => 'percent', 'discount_value' => '25', 'discount_duration' => 'once']));

        $paid = $terms->paidPhases();
        $this->assertSame(7500, $paid[0]['price_minor']);
        $this->assertSame(1, $paid[0]['periods']);
        $this->assertSame($this->now->addDays(28)->toIso8601String(), $paid[0]['ends_at']);
        $this->assertSame(10000, $paid[1]['price_minor']);
    }

    public function test_a_forever_discount_is_just_the_recurring_price(): void
    {
        $terms = $this->terms($this->offer(['discount_type' => 'percent', 'discount_value' => '20', 'discount_duration' => 'forever']));

        $this->assertFalse($terms->hasPromotionEnd());
        $this->assertCount(1, $terms->paidPhases());
        $this->assertSame(8000, $terms->standardPriceMinor() - 2000);
        $this->assertSame(8000, $terms->get('recurring_after_promotion_minor'));
        $this->assertSame(8000, $terms->todayChargeMinor());
        $this->assertNull($terms->promotion()['ends_at']);
    }

    public function test_a_discount_applies_to_the_custom_price_and_reverts_to_it_not_to_the_plan_price(): void
    {
        $terms = $this->terms($this->offer([
            'custom_price' => '80.00', 'discount_type' => 'fixed', 'discount_value' => '20.00',
            'discount_duration' => 'periods', 'discount_periods' => 2,
        ]));

        $paid = $terms->paidPhases();
        $this->assertSame(6000, $paid[0]['price_minor']);
        $this->assertSame(8000, $paid[1]['price_minor']);
        $this->assertSame(8000, $terms->get('recurring_after_promotion_minor'));
        $this->assertSame('negotiated', $terms->get('standard_source'));
        $this->assertSame('Your agreed ongoing price', $terms->get('ongoing_label'));
    }

    public function test_a_promotion_without_a_negotiated_price_reverts_to_the_catalog_price(): void
    {
        $terms = $this->terms($this->offer(['discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'once']));

        $this->assertSame('catalog', $terms->get('standard_source'));
        $this->assertSame('The standard plan price', $terms->get('ongoing_label'));
        $this->assertSame(10000, $terms->paidPhases()[1]['price_minor']);
    }

    public function test_a_trial_and_a_promotion_together_start_the_promotion_at_the_first_payment(): void
    {
        $terms = $this->terms($this->offer([
            'trial_days' => 14, 'discount_type' => 'fixed', 'discount_value' => '40.00',
            'discount_duration' => 'periods', 'discount_periods' => 2,
        ]));

        $first = $this->now->addDays(14);
        $paid = $terms->paidPhases();

        $this->assertSame(0, $terms->todayChargeMinor());
        $this->assertSame($first->toIso8601String(), $paid[0]['starts_at']);
        $this->assertSame($first->addDays(56)->toIso8601String(), $paid[0]['ends_at']);
        $this->assertSame('trial', $terms->phases()[0]['kind']);
    }

    public function test_yearly_plans_count_periods_in_years(): void
    {
        $terms = $this->terms(
            $this->offer(['discount_type' => 'percent', 'discount_value' => '10', 'discount_duration' => 'periods', 'discount_periods' => 2]),
            plan: $this->plan('1000.00', 'yearly'),
        );

        $this->assertSame($this->now->addYears(2)->toIso8601String(), $terms->paidPhases()[0]['ends_at']);
        $this->assertSame('year', $terms->get('interval'));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidOffers(): array
    {
        return [
            'discount equal to the price' => [['discount_type' => 'fixed', 'discount_value' => '100.00', 'discount_duration' => 'forever']],
            'discount above the price' => [['discount_type' => 'fixed', 'discount_value' => '150.00', 'discount_duration' => 'forever']],
            'discount that leaves nothing chargeable' => [['discount_type' => 'fixed', 'discount_value' => '99.80', 'discount_duration' => 'forever']],
            'zero percent' => [['discount_type' => 'percent', 'discount_value' => '0', 'discount_duration' => 'forever']],
            'no duration' => [['discount_type' => 'fixed', 'discount_value' => '10', 'discount_duration' => null]],
            'one "periods" period' => [['discount_type' => 'fixed', 'discount_value' => '10', 'discount_duration' => 'periods', 'discount_periods' => 1]],
            'absurd period count' => [['discount_type' => 'fixed', 'discount_value' => '10', 'discount_duration' => 'periods', 'discount_periods' => 500]],
            'custom price below the minimum charge' => [['custom_price' => '0.10']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOffers')]
    public function test_offers_that_cannot_be_charged_are_refused(array $attributes): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->terms($this->offer($attributes));
    }

    public function test_the_acceptance_hash_ignores_dates_but_not_money(): void
    {
        $offer = $this->offer(['trial_days' => 14, 'discount_type' => 'fixed', 'discount_value' => '10.00', 'discount_duration' => 'periods', 'discount_periods' => 2]);

        $today = $this->terms($offer);
        $tomorrow = (new OfferTermsCalculator())->calculate($offer, $this->plan(), 'USD', true, $this->now->addDay(), null);

        $this->assertSame($today->hash(), $tomorrow->hash(), 'a midnight rollover must not invalidate a form the customer was shown');
        $this->assertNotSame($today->hash(), $this->terms($offer, trialEligible: false)->hash(), 'losing the trial changes what they pay');
        $this->assertNotSame($today->hash(), $this->terms($this->offer(['trial_days' => 14, 'discount_type' => 'fixed', 'discount_value' => '11.00', 'discount_duration' => 'periods', 'discount_periods' => 2]))->hash());
    }

    public function test_a_billing_change_starts_at_the_next_renewal_and_needs_acceptance_only_if_it_could_cost_more(): void
    {
        $calculator = new OfferTermsCalculator();
        $renewal = $this->now->addDays(10);
        $existing = ['amount_minor' => 10000, 'effective_at' => $renewal];

        $cheaper = $this->terms($this->offer(['kind' => PlatformPrivateOffer::KIND_BILLING_CHANGE, 'custom_price' => '80.00']), existing: $existing);
        $this->assertSame(0, $cheaper->todayChargeMinor(), 'nothing is charged today');
        $this->assertSame($renewal->toIso8601String(), $cheaper->firstPaymentAt()->toIso8601String());
        $this->assertFalse($calculator->requiresAcceptance($cheaper));

        $dearer = $this->terms($this->offer(['kind' => PlatformPrivateOffer::KIND_BILLING_CHANGE, 'custom_price' => '120.00']), existing: $existing);
        $this->assertTrue($calculator->requiresAcceptance($dearer));

        // A cheaper promotion that REVERTS to a higher price still needs a yes.
        $reverting = $this->terms($this->offer([
            'kind' => PlatformPrivateOffer::KIND_BILLING_CHANGE, 'custom_price' => '120.00',
            'discount_type' => 'fixed', 'discount_value' => '50.00', 'discount_duration' => 'once',
        ]), existing: $existing);
        $this->assertTrue($calculator->requiresAcceptance($reverting));
    }

    public function test_a_billing_change_cannot_carry_a_trial(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->terms(
            $this->offer(['kind' => PlatformPrivateOffer::KIND_BILLING_CHANGE, 'custom_price' => '80.00', 'trial_days' => 7]),
            existing: ['amount_minor' => 10000, 'effective_at' => $this->now->addDay()],
        );
    }

    public function test_money_is_integer_minor_units(): void
    {
        $this->assertSame(7500, PlatformMoney::applyPercentOff(10000, '25'));
        $this->assertSame(3333, PlatformMoney::applyPercentOff(4999, '33.33'));
        $this->assertSame(2, PlatformMoney::applyPercentOff(3, '50'), '1.5 rounds half up');
        $this->assertSame('79.05', PlatformMoney::toDecimal(7905, 'USD'));
        $this->assertSame('5000', PlatformMoney::toDecimal(5000, 'JPY'));
        $this->assertSame('79.50 USD', PlatformMoney::display(7950, 'USD'));
        $this->assertSame('$79.50', PlatformMoney::display(7950, 'USD', '${PRICE}'));
    }

    public function test_email_identity_separates_strict_matching_from_the_trial_mailbox(): void
    {
        $this->assertSame('jane@x.com', EmailIdentity::normalize('  Jane@X.com '));
        $this->assertNotSame(EmailIdentity::normalize('jane@x.com'), EmailIdentity::normalize('jane+a@x.com'), 'an offer is NOT redeemable by an alias');

        $this->assertSame('janedoe@gmail.com', EmailIdentity::key('Jane.Doe+promo@googlemail.com'));
        $this->assertSame('jane.doe@corp.com', EmailIdentity::key('jane.doe+x@corp.com'), 'dots are only ignored at Gmail');
    }
}
