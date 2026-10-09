<?php

namespace App\Library\Acquisition\Economics;

/**
 * Acquisition Purpose V1 — what the Business's OWN answers add up to.
 *
 * Built by an EconomicsCalculator from the editable answers; it holds only
 * numbers the OWNER confirmed or that follow arithmetically from them. Money
 * is integer MICROS of the Business currency (the same unit the Ads tables
 * use), rates are 0..1 fractions, and an unknown value is `null` — never 0.
 *
 * `targetCplMicros` / `hardCplMicros` are the cost of one QUALIFIED lead the
 * Ads decision engine judges against; `targetCacMicros` / `hardCacMicros` are
 * the cost of one finished outcome (an enrolled student, a hired teacher).
 * A suggestion (see `suggestions`) is NEVER read as a target: the engine uses
 * only what the owner confirmed, so MotionGrove can propose a conservative
 * figure but cannot silently judge an ad against a number nobody agreed to.
 *
 * `contributionLtvMicros` is what one finished outcome contributes AFTER its
 * direct variable cost over its whole expected life. It is the only input to
 * any profitability statement, and it is `null` unless every number it needs
 * was answered — retention unknown means profitability unknown.
 */
final class EconomicsProfile
{
    /**
     * @param  array<string, array{value_micros: ?int, rate: ?float, label: string, basis: string}>  $suggestions  proposals the owner may adopt; never used as targets
     * @param  list<array{label: string, value: string, note: ?string}>  $derived  display lines of the arithmetic that was done
     * @param  list<string>  $unknownKeys  questions the owner answered "I don't know yet"
     * @param  list<string>  $unansweredKeys  questions with no answer at all
     */
    public function __construct(
        public readonly ?int $targetCplMicros,
        public readonly ?int $hardCplMicros,
        public readonly ?int $targetCacMicros,
        public readonly ?int $hardCacMicros,
        public readonly ?float $qualifiedToOutcomeRate,
        public readonly ?int $contributionPerUnitMicros,
        public readonly ?int $contributionLtvMicros,
        public readonly array $suggestions = [],
        public readonly array $derived = [],
        public readonly array $unknownKeys = [],
        public readonly array $unansweredKeys = [],
    ) {
    }

    public function hasAnyTarget(): bool
    {
        return $this->targetCplMicros !== null
            || $this->hardCplMicros !== null
            || $this->targetCacMicros !== null
            || $this->hardCacMicros !== null;
    }

    /**
     * The ONLY gate for the word "profitable". True when a finished outcome's
     * lifetime contribution is known AND what one cost to win is known and is
     * lower. Revenue is never profit, and a CAC below a guessed LTV does not
     * qualify because the LTV is null unless every input was answered.
     */
    public function supportsProfitabilityClaim(?int $actualCacMicros): bool
    {
        return $this->contributionLtvMicros !== null
            && $this->contributionLtvMicros > 0
            && $actualCacMicros !== null
            && $actualCacMicros < $this->contributionLtvMicros;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'target_cpl_micros' => $this->targetCplMicros,
            'hard_cpl_micros' => $this->hardCplMicros,
            'target_cac_micros' => $this->targetCacMicros,
            'hard_cac_micros' => $this->hardCacMicros,
            'qualified_to_outcome_rate' => $this->qualifiedToOutcomeRate,
            'contribution_per_unit_micros' => $this->contributionPerUnitMicros,
            'contribution_ltv_micros' => $this->contributionLtvMicros,
            'suggestions' => $this->suggestions,
            'unknown' => $this->unknownKeys,
            'unanswered' => $this->unansweredKeys,
        ];
    }
}
