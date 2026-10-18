<?php

namespace App\Library\PlatformOwner\AccountDeletion;

/**
 * The result of {@see AccountRecordGraph::discover()}: the rows that belong to
 * one customer account, plus anything that stops them from being erased.
 */
final class AccountRecordSelection
{
    /**
     * @param  array<string, array<int, int>>  $ids  table => selected primary keys
     * @param  array<string, array<int, array{0: string, 1: array<int, int|string>}>>  $specs  id-less tables, selected by column value
     * @param  array<int, array{table: string, column: string, count: int, rule: string}>  $blockers  rows elsewhere that point at the account
     * @param  array<int, string>  $unsupported  tables the graph could not enumerate
     */
    public function __construct(
        public readonly array $ids,
        public readonly array $specs,
        public readonly array $blockers,
        public readonly array $unsupported,
    ) {
    }

    public function count(string $table): int
    {
        return count($this->ids[$table] ?? []);
    }

    /**
     * @return array<int, int>
     */
    public function idsOf(string $table): array
    {
        return $this->ids[$table] ?? [];
    }

    /**
     * @return array<string, int> table => number of rows, for display
     */
    public function tableCounts(): array
    {
        $counts = array_map('count', $this->ids);
        ksort($counts);

        return $counts;
    }
}
