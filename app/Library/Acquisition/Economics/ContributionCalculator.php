<?php

namespace App\Library\Acquisition\Economics;

/**
 * The formula shared by every purpose that SELLS something repeatedly:
 *
 *   contribution_per_unit = unit_price - variable_cost_per_unit
 *   contribution_ltv      = contribution_per_unit x units_per_customer
 *
 * REVENUE IS NOT PROFIT, and allowed CAC is NOT set equal to LTV. The LTV is
 * computed only when price, cost AND the number of units were all answered
 * (retention unknown => LTV unknown => no profitability claim). From a known
 * LTV the calculator may PROPOSE a conservative CAC (a configured fraction)
 * and the CPL that CAC implies at the owner's qualified->customer rate; those
 * are labelled suggestions and the engine never judges against them — the
 * owner confirms by answering the target questions.
 *
 * Targets the owner DID answer are used as given. A CPL the owner did not
 * answer is derived from their confirmed CAC and their own qualified->customer
 * rate when both exist (CAC x rate), and stays null otherwise.
 */
abstract class ContributionCalculator extends AbstractEconomicsCalculator
{
    /** @return string question key of the price of one unit (lesson, session) */
    abstract protected function priceKey(): string;

    abstract protected function costKey(): string;

    /** @return ?string question key holding units per customer, or null when a customer is exactly one unit */
    abstract protected function unitsKey(): ?string;

    abstract protected function unitNoun(): string;

    /**
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $unknown
     */
    protected function unitsPerCustomer(array $answers, array $unknown): ?float
    {
        $key = $this->unitsKey();

        if ($key === null) {
            return 1.0;
        }

        $units = $this->number($answers, $unknown, $key);

        return $units !== null && $units > 0 ? $units : null;
    }

    public function profile(array $answers, array $unknown): EconomicsProfile
    {
        $price = $this->number($answers, $unknown, $this->priceKey());
        $cost = $this->number($answers, $unknown, $this->costKey());
        $units = $this->unitsPerCustomer($answers, $unknown);
        $rate = $this->rate($this->number($answers, $unknown, 'qualified_to_customer_pct'));
        $noun = $this->unitNoun();

        $targetCac = $this->micros($this->number($answers, $unknown, 'target_cac'));
        $hardCac = $this->micros($this->number($answers, $unknown, 'hard_cac'));
        $targetCpl = $this->micros($this->number($answers, $unknown, 'target_qualified_cpl'));
        $hardCpl = $this->micros($this->number($answers, $unknown, 'hard_cpl'));

        $derived = [];
        $perUnit = null;
        $ltv = null;

        if ($price !== null && $cost !== null) {
            $perUnit = $this->micros($price - $cost);
            $derived[] = [
                'label' => 'Contribution per ' . $noun,
                'value' => ($perUnit < 0 ? '-' : '') . $this->format(abs($perUnit)),
                'note' => 'Price minus the direct cost of delivering one ' . $noun . '. This is contribution, not profit: rent, marketing and overheads are not included.',
            ];

            if ($perUnit > 0 && $units !== null) {
                $ltv = (int) round($perUnit * $units);
                $derived[] = [
                    'label' => 'Contribution per customer over their life',
                    'value' => $this->format($ltv),
                    'note' => $this->unitsKey() === null
                        ? 'One ' . $noun . ' per customer.'
                        : 'Contribution per ' . $noun . ' x ' . rtrim(rtrim(number_format($units, 1, '.', ''), '0'), '.') . ' ' . $noun . 's. Only as reliable as your estimate of how many ' . $noun . 's a customer pays for.',
                ];
            }
        }

        if ($targetCpl === null && $targetCac !== null && $rate !== null && $rate > 0) {
            $targetCpl = (int) round($targetCac * $rate);
            $derived[] = ['label' => 'Target cost per qualified lead', 'value' => $this->format($targetCpl), 'note' => 'Derived: your target cost per customer x your qualified-to-customer rate. Edit it to set your own.'];
        }

        if ($hardCpl === null && $hardCac !== null && $rate !== null && $rate > 0) {
            $hardCpl = (int) round($hardCac * $rate);
            $derived[] = ['label' => 'Highest cost per qualified lead', 'value' => $this->format($hardCpl), 'note' => 'Derived: your hard cost-per-customer ceiling x your qualified-to-customer rate.'];
        }

        $suggestions = [];

        if ($ltv !== null) {
            $suggestedCac = (int) round($ltv * $this->fraction('suggested_target_cac_fraction', 0.3));
            $suggestedHard = (int) round($ltv * $this->fraction('suggested_hard_cac_fraction', 0.5));
            $suggestions['target_cac'] = ['value_micros' => $suggestedCac, 'rate' => null, 'label' => 'Suggested target cost per customer', 'basis' => 'A conservative share of the contribution per customer. An assumption to confirm, not a fact.'];
            $suggestions['hard_cac'] = ['value_micros' => $suggestedHard, 'rate' => null, 'label' => 'Suggested highest cost per customer', 'basis' => 'A cautious ceiling as a share of the contribution per customer. An assumption to confirm.'];

            if ($rate !== null && $rate > 0 && $targetCac === null) {
                $suggestions['target_qualified_cpl'] = ['value_micros' => (int) round($suggestedCac * $rate), 'rate' => $rate, 'label' => 'Suggested target cost per qualified lead', 'basis' => 'The suggested customer cost x your qualified-to-customer rate.'];
            }
        }

        [$unknownKeys, $unanswered] = $this->coverage($answers, $unknown);

        return new EconomicsProfile($targetCpl, $hardCpl, $targetCac, $hardCac, $rate, $perUnit, $ltv, $suggestions, $derived, $unknownKeys, $unanswered);
    }

    /** @return array<string, array{type: string, label: string, help: string}> */
    protected function targetInputs(string $customer): array
    {
        return [
            'qualified_to_customer_pct' => ['type' => 'percent', 'label' => 'Roughly what % of qualified inquiries become a paying ' . $customer . '?', 'help' => 'Leave as "I don\'t know yet" if you have not tracked it.'],
            'target_cac' => ['type' => 'money', 'label' => 'Target cost to win one ' . $customer, 'help' => 'What you would be happy to spend on ads for each new ' . $customer . '. You confirm it; MotionGrove never sets it for you.'],
            'hard_cac' => ['type' => 'money', 'label' => 'Hard maximum cost to win one ' . $customer, 'help' => 'The most you would ever accept.'],
            'target_qualified_cpl' => ['type' => 'money', 'label' => 'Target cost per qualified inquiry', 'help' => 'If you leave this blank it is worked out from your target cost per ' . $customer . ' when that and the % above are known.'],
            'hard_cpl' => ['type' => 'money', 'label' => 'Hard maximum cost per qualified inquiry', 'help' => 'Optional.'],
        ];
    }
}
