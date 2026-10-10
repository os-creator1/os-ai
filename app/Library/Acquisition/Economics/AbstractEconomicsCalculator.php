<?php

namespace App\Library\Acquisition\Economics;

/**
 * Shared, deliberately small arithmetic helpers for the calculators.
 *
 * Reading an answer is strict: an answer counts only when it is a finite,
 * non-negative number AND the owner did not also mark it "I don't know yet".
 * Anything else reads as null (unknown), so no calculator can compute from a
 * placeholder.
 */
abstract class AbstractEconomicsCalculator implements EconomicsCalculator
{
    /** @param array<string, mixed> $answers @param list<string> $unknown */
    protected function number(array $answers, array $unknown, string $key): ?float
    {
        if (in_array($key, $unknown, true)) {
            return null;
        }

        $value = $answers[$key] ?? null;

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && $number >= 0 ? $number : null;
    }

    protected function micros(?float $major): ?int
    {
        return $major === null ? null : (int) round($major * 1_000_000);
    }

    /** @return ?float 0..1 from a 0..100 percent answer */
    protected function rate(?float $percent): ?float
    {
        return $percent === null ? null : max(0.0, min(100.0, $percent)) / 100;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $unknown
     * @return array{0: list<string>, 1: list<string>} [unknown keys, unanswered keys] restricted to this calculator's inputs
     */
    protected function coverage(array $answers, array $unknown): array
    {
        $unknownKeys = [];
        $unanswered = [];

        foreach (array_keys($this->inputs()) as $key) {
            if (in_array($key, $unknown, true)) {
                $unknownKeys[] = $key;
            } elseif (($answers[$key] ?? null) === null || $answers[$key] === '') {
                $unanswered[] = $key;
            }
        }

        return [$unknownKeys, $unanswered];
    }

    protected function fraction(string $configKey, float $default): float
    {
        return (float) config('ads_decisions.economics.' . $configKey, $default);
    }

    protected function format(int $micros): string
    {
        return number_format($micros / 1_000_000, 2, '.', ',');
    }
}
