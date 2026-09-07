<?php

namespace App\DTO\Analytics;

/**
 * One daily series over Business-local calendar dates (contract §4.3,
 * §5 M7, §7 K3). `dates` are `Y-m-d` local dates in order; every entry of
 * `series` is a name => list of integers aligned with `dates`.
 */
final class DailySeries
{
    /**
     * @param  array<int, string>  $dates
     * @param  array<string, array<int, int>>  $series
     */
    public function __construct(
        public readonly array $dates,
        public readonly array $series,
    ) {
    }

    public function total(string $name): int
    {
        return (int) array_sum($this->series[$name] ?? []);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['dates' => $this->dates, 'series' => $this->series];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(array_values($data['dates'] ?? []), $data['series'] ?? []);
    }
}
