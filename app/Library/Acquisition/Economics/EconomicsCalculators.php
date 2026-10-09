<?php

namespace App\Library\Acquisition\Economics;

use InvalidArgumentException;

/**
 * The closed registry of economics calculators. A Blueprint component names a
 * calculator by key; an unknown key fails Blueprint publication.
 */
final class EconomicsCalculators
{
    /** @var array<string, EconomicsCalculator> */
    private array $byKey;

    public function __construct()
    {
        foreach ([new RecurringLessonsCalculator, new ClassEnrollmentCalculator, new RecruitmentCalculator, new SimpleOutcomeCalculator] as $calculator) {
            $this->byKey[$calculator->key()] = $calculator;
        }
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->byKey);
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    public function get(string $key): EconomicsCalculator
    {
        return $this->byKey[$key] ?? throw new InvalidArgumentException("Unknown economics calculator [{$key}].");
    }
}
