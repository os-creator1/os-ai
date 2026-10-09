<?php

namespace App\Library\Acquisition\Economics;

/**
 * The fallback for a purpose created by hand (or a future niche) that has no
 * specialised formula: only the owner's own limits, no revenue arithmetic, so
 * it can never claim profitability.
 */
final class SimpleOutcomeCalculator extends AbstractEconomicsCalculator
{
    public const KEY = 'simple_outcome';

    public function key(): string
    {
        return self::KEY;
    }

    public function inputs(): array
    {
        return [
            'qualified_to_customer_pct' => ['type' => 'percent', 'label' => 'Roughly what % of qualified inquiries become a customer?', 'help' => 'Leave as "I don\'t know yet" if you have not tracked it.'],
            'target_cac' => ['type' => 'money', 'label' => 'Target cost to win one customer', 'help' => 'What you would be happy to spend on ads for each new customer.'],
            'hard_cac' => ['type' => 'money', 'label' => 'Hard maximum cost to win one customer', 'help' => 'The most you would ever accept.'],
            'target_qualified_cpl' => ['type' => 'money', 'label' => 'Target cost per qualified inquiry', 'help' => 'Worked out from the two answers above when left blank and both are known.'],
            'hard_cpl' => ['type' => 'money', 'label' => 'Hard maximum cost per qualified inquiry', 'help' => 'Optional.'],
        ];
    }

    public function profile(array $answers, array $unknown): EconomicsProfile
    {
        $rate = $this->rate($this->number($answers, $unknown, 'qualified_to_customer_pct'));
        $targetCac = $this->micros($this->number($answers, $unknown, 'target_cac'));
        $hardCac = $this->micros($this->number($answers, $unknown, 'hard_cac'));
        $targetCpl = $this->micros($this->number($answers, $unknown, 'target_qualified_cpl'));
        $hardCpl = $this->micros($this->number($answers, $unknown, 'hard_cpl'));
        $derived = [];

        if ($targetCpl === null && $targetCac !== null && $rate !== null && $rate > 0) {
            $targetCpl = (int) round($targetCac * $rate);
            $derived[] = ['label' => 'Target cost per qualified inquiry', 'value' => $this->format($targetCpl), 'note' => 'Derived: your target cost per customer x your qualified-to-customer rate.'];
        }

        [$unknownKeys, $unanswered] = $this->coverage($answers, $unknown);

        return new EconomicsProfile($targetCpl, $hardCpl, $targetCac, $hardCac, $rate, null, null, [], $derived, $unknownKeys, $unanswered);
    }
}
