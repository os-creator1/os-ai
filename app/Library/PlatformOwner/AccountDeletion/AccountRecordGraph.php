<?php

namespace App\Library\PlatformOwner\AccountDeletion;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Finds — and can remove — exactly the rows that belong to one customer
 * account, in an order the database itself proves safe.
 *
 * THE ORDER IS NOT HAND-WRITTEN. This repository has hundreds of tables and a
 * foreign-key graph that keeps growing; a hard-coded delete list would be
 * wrong the first time someone adds a table. The authoritative dependency
 * graph is the database's own (information_schema), so the graph is read from
 * there, and the schema-drift test pins that every foreign key into a user
 * has been consciously classified.
 *
 * WHAT IS "OWNED". Starting from the account's user row and the Workspaces it
 * owns, every row that references an owned row through a foreign key that
 * would otherwise block (RESTRICT / NO ACTION) or cascade is owned too, and so
 * are that row's own dependants, transitively. Foreign keys that SET NULL are
 * never followed: the database detaches them and the referencing row (an
 * audit "created by", say) survives.
 *
 * WHAT IS DELIBERATELY NOT FOLLOWED — and therefore blocks hard deletion
 * instead of being silently destroyed:
 *  - any foreign key out of `users` that is not a known user-owned table
 *    (an appointment assigned to the user in somebody else's Business, a
 *    website revision they authored there, a shared currency they created);
 *  - `businesses.customer_id`, which names who created a Business, not whose
 *    Workspace it lives in. A Business is owned because of its Workspace.
 * If such rows exist they are reported as blockers; the caller closes the
 * account instead of deleting it.
 *
 * Nothing here is a transaction boundary: {@see purge()} must be called inside
 * one by its single caller, PlatformAccountClosureManager.
 */
final class AccountRecordGraph
{
    private const CHUNK = 500;

    private const TUPLE_CHUNK = 200;

    /**
     * `table.column` foreign keys into `users` that are the user's OWN rows.
     * Everything else that points at a user with a non-SET-NULL rule is a
     * blocker when rows exist.
     *
     * @var array<int, string>
     */
    public const USER_OWNED = [
        'announcements_user.user_id',
        'automations.user_id',
        'blacklists.user_id',
        'booking_type_staff.staff_user_id',
        'campaigns.user_id',
        'chat_boxes.user_id',
        'contact_groups.customer_id',
        'contacts.customer_id',
        'customer_based_pricing_plans.user_id',
        'customer_based_sending_servers.user_id',
        'customer_onboardings.customer_id',
        'customers.user_id',
        'external_calendar_connections.user_id',
        'failed_contact_imports.user_id',
        'file_campaign_data.user_id',
        'invoices.user_id',
        'keywords.user_id',
        'notifications.user_id',
        'phone_numbers.user_id',
        'plans.user_id',
        'platform_announcement_receipts.user_id',
        'reports.user_id',
        'role_user.user_id',
        'schedule_messages.user_id',
        'senderid.user_id',
        'staff_availability_rules.staff_user_id',
        'staff_booking_locks.staff_user_id',
        'staff_time_off.staff_user_id',
        'subscriptions.user_id',
        'templates.user_id',
        'tracking_logs.customer_id',
        'view_as_sessions.actor_user_id',
        'workspace_memberships.user_id',
        'workspaces.owner_user_id',
    ];

    /**
     * Foreign keys into `users` that are knowingly NOT followed (see class
     * docblock). Listed so the schema-drift test can prove that every
     * non-SET-NULL key into users is either USER_OWNED or here.
     *
     * @var array<int, string>
     */
    public const USER_REFERENCES_THAT_BLOCK = [
        'announcements.user_id',
        'appointments.staff_user_id',
        'business_billing_contacts.contact_user_id',
        'business_home_visits.user_id',
        'business_knowledge_profile_changes.actor_user_id',
        'businesses.customer_id',
        'currencies.user_id',
        'opportunity_action_executions.initiated_by_user_id',
        'platform_admin_actions.actor_user_id',
        'platform_private_offer_redemptions.actor_user_id',
        'platform_private_offers.created_by_user_id',
        'platform_private_offers.revoked_by_user_id',
        'subscriptions.end_by',
        'website_guided_generation_attempts.created_by_user_id',
        'website_revisions.created_by',
        'workspace_plan_catalog_pricing_changes.actor_user_id',
    ];

    /** Files whose rows are removed with the account: table => [disk column, path column]. */
    private const FILE_TABLES = [
        'website_assets' => ['disk', 'path'],
        'business_backdrop_images' => ['disk', 'path'],
        'catalog_item_images' => ['disk', 'path'],
    ];

    /** @var array<int, array{child: string, columns: array<int, string>, parent: string, parent_columns: array<int, string>, rule: string}>|null */
    private ?array $foreignKeys = null;

    /** @var array<string, array<string, bool>>|null table => column => nullable */
    private ?array $columns = null;

    /**
     * @param  array<int, int>  $workspaceIds  the Workspaces this account OWNS
     */
    public function discover(int $userId, array $workspaceIds): AccountRecordSelection
    {
        $selected = ['users' => [$userId => true]];
        $pending = ['users' => [$userId]];

        if ($workspaceIds !== []) {
            $selected['workspaces'] = array_fill_keys(array_map('intval', $workspaceIds), true);
            $pending['workspaces'] = array_map('intval', $workspaceIds);
        }

        $unsupported = [];
        $specs = [];

        while ($pending !== []) {
            $table = array_key_first($pending);
            $ids = array_values(array_unique($pending[$table]));
            unset($pending[$table]);

            foreach ($this->foreignKeys() as $fk) {
                if ($fk['parent'] !== $table || $fk['rule'] === 'SET NULL') {
                    continue;
                }

                if (! $this->follows($fk)) {
                    continue;
                }

                $tuples = $this->parentTuples($table, $fk['parent_columns'], $ids);

                if ($tuples === []) {
                    continue;
                }

                $child = $fk['child'];

                if (! $this->hasColumn($child, 'id')) {
                    // A pivot-style table with no id: selected by value, and it
                    // may not itself be a parent (checked below).
                    if (count($fk['columns']) !== 1) {
                        $unsupported[] = $child;

                        continue;
                    }

                    $specs[$child][] = [$fk['columns'][0], array_column($tuples, 0)];

                    continue;
                }

                $found = [];

                foreach (array_chunk($tuples, self::TUPLE_CHUNK) as $chunk) {
                    foreach ($this->childQuery($fk, $chunk)->pluck('id') as $id) {
                        $found[] = (int) $id;
                    }
                }

                $new = array_values(array_filter($found, fn (int $id) => ! isset($selected[$child][$id])));

                if ($new === []) {
                    continue;
                }

                foreach ($new as $id) {
                    $selected[$child][$id] = true;
                }

                $pending[$child] = array_merge($pending[$child] ?? [], $new);
            }
        }

        // A value-selected (id-less) table must not be a parent of anything we
        // would then have to follow: we could not enumerate its rows. Fail closed.
        foreach (array_keys($specs) as $table) {
            foreach ($this->foreignKeys() as $fk) {
                if ($fk['parent'] === $table && $fk['rule'] !== 'SET NULL') {
                    $unsupported[] = $table;
                    break;
                }
            }
        }

        return new AccountRecordSelection(
            ids: array_map(fn (array $ids) => array_map('intval', array_keys($ids)), $selected),
            specs: $specs,
            blockers: $this->blockers($selected),
            unsupported: array_values(array_unique($unsupported)),
        );
    }

    /**
     * Tables a deletion COULD reach, from the schema alone (no data): the
     * static closure of the followed foreign keys starting at `users` and
     * `workspaces`. Used by the schema-drift test to prove that every table
     * carrying a `workspace_id` / `business_id` is inside the deletion graph.
     *
     * @return array<int, string>
     */
    public function reachableTables(): array
    {
        $seen = ['users' => true, 'workspaces' => true];
        $queue = ['users', 'workspaces'];

        while ($queue !== []) {
            $table = array_shift($queue);

            foreach ($this->foreignKeys() as $fk) {
                if ($fk['parent'] === $table && $fk['rule'] !== 'SET NULL' && $this->follows($fk) && ! isset($seen[$fk['child']])) {
                    $seen[$fk['child']] = true;
                    $queue[] = $fk['child'];
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Delete every selected row, children before parents. Caller supplies the
     * transaction. Throws (so the transaction rolls back) rather than guess.
     */
    public function purge(AccountRecordSelection $selection): void
    {
        if ($selection->blockers !== [] || $selection->unsupported !== []) {
            throw new RuntimeException('Refusing to purge an account whose graph has blockers.');
        }

        foreach (ProtectedAccountRecords::ownedByReference() as $table => $columns) {
            foreach ($columns as $column => $root) {
                foreach (array_chunk($selection->ids[$root] ?? [], self::CHUNK) as $chunk) {
                    DB::table($table)->whereIn($column, $chunk)->delete();
                }
            }
        }

        $tables = array_keys($selection->ids);

        $this->detachNullableReferences($selection, $tables);

        foreach ($this->deletionOrder($tables) as $table) {
            foreach (array_chunk($selection->ids[$table] ?? [], self::CHUNK) as $chunk) {
                DB::table($table)->whereIn('id', $chunk)->delete();
            }
        }

        foreach ($selection->specs as $table => $list) {
            foreach ($list as [$column, $values]) {
                foreach (array_chunk($values, self::CHUNK) as $chunk) {
                    DB::table($table)->whereIn($column, $chunk)->delete();
                }
            }
        }
    }

    /**
     * Files that go with the rows, gathered BEFORE the rows are deleted.
     *
     * @return array<int, array{disk: string, path: string}>
     */
    public function files(AccountRecordSelection $selection): array
    {
        $files = [];

        foreach (self::FILE_TABLES as $table => [$diskColumn, $pathColumn]) {
            foreach (array_chunk($selection->ids[$table] ?? [], self::CHUNK) as $chunk) {
                foreach (DB::table($table)->whereIn('id', $chunk)->get([$diskColumn, $pathColumn]) as $row) {
                    if ($row->{$pathColumn} !== null && $row->{$pathColumn} !== '') {
                        $files[] = ['disk' => (string) ($row->{$diskColumn} ?: 'public'), 'path' => (string) $row->{$pathColumn}];
                    }
                }
            }
        }

        return $files;
    }

    /**
     * Rows in each PROTECTED table that belong to the selection.
     *
     * @return array<string, array{label: string, count: int}>
     */
    public function protectedRecords(AccountRecordSelection $selection): array
    {
        $found = [];

        foreach (ProtectedAccountRecords::definitions() as $table => [$label, $predicate]) {
            $ids = $selection->ids[$table] ?? [];

            if ($ids === []) {
                continue;
            }

            $count = 0;

            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $query = DB::table($table)->whereIn('id', $chunk);

                if ($predicate !== null) {
                    $predicate($query);
                }

                $count += $query->count();
            }

            if ($count > 0) {
                $found[$table] = ['label' => $label, 'count' => $count];
            }
        }

        foreach (ProtectedAccountRecords::retainedByReference() as $table => [$label, $columns]) {
            $count = 0;

            foreach ($columns as $column => $root) {
                foreach (array_chunk($selection->ids[$root] ?? [], self::CHUNK) as $chunk) {
                    $count += DB::table($table)->whereIn($column, $chunk)->count();
                }
            }

            if ($count > 0) {
                $found[$table] = ['label' => $label, 'count' => $count];
            }
        }

        return $found;
    }

    /**
     * @return array<int, array{child: string, columns: array<int, string>, parent: string, parent_columns: array<int, string>, rule: string}>
     */
    public function foreignKeys(): array
    {
        if ($this->foreignKeys !== null) {
            return $this->foreignKeys;
        }

        $rows = DB::select(
            'select k.table_name as child, k.constraint_name as name, k.column_name as col, '
            . 'k.referenced_table_name as parent, k.referenced_column_name as pcol, r.delete_rule as rule '
            . 'from information_schema.key_column_usage k '
            . 'join information_schema.referential_constraints r '
            . 'on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name '
            . 'where k.table_schema = ? and k.referenced_table_name is not null '
            . 'order by k.table_name, k.constraint_name, k.ordinal_position',
            [DB::connection()->getDatabaseName()],
        );

        $grouped = [];

        foreach ($rows as $row) {
            $key = $row->child . '|' . $row->name;
            $grouped[$key] ??= [
                'child' => (string) $row->child,
                'columns' => [],
                'parent' => (string) $row->parent,
                'parent_columns' => [],
                'rule' => (string) $row->rule,
            ];
            $grouped[$key]['columns'][] = (string) $row->col;
            $grouped[$key]['parent_columns'][] = (string) $row->pcol;
        }

        return $this->foreignKeys = array_values($grouped);
    }

    private function follows(array $fk): bool
    {
        $column = $fk['child'] . '.' . $fk['columns'][0];

        if ($fk['parent'] === 'users') {
            return in_array($column, self::USER_OWNED, true);
        }

        return $column !== 'businesses.customer_id';
    }

    /**
     * @param  array<string, array<int, bool>>  $selected
     * @return array<int, array{table: string, column: string, count: int, rule: string}>
     */
    private function blockers(array $selected): array
    {
        $blockers = [];

        foreach ($this->foreignKeys() as $fk) {
            if ($fk['rule'] === 'SET NULL' || ! isset($selected[$fk['parent']]) || $this->follows($fk)) {
                continue;
            }

            $tuples = $this->parentTuples($fk['parent'], $fk['parent_columns'], array_keys($selected[$fk['parent']]));

            if ($tuples === []) {
                continue;
            }

            $own = $selected[$fk['child']] ?? [];
            $count = 0;

            foreach (array_chunk($tuples, self::TUPLE_CHUNK) as $chunk) {
                $query = $this->childQuery($fk, $chunk);

                if ($own !== [] && $this->hasColumn($fk['child'], 'id')) {
                    $query->whereNotIn('id', array_keys($own));
                }

                $count += $query->count();
            }

            if ($count > 0) {
                $blockers[] = ['table' => $fk['child'], 'column' => implode(',', $fk['columns']), 'count' => $count, 'rule' => $fk['rule']];
            }
        }

        return $blockers;
    }

    /**
     * The values the foreign key's parent columns hold for the selected parent
     * rows, one tuple per row (composite keys give several values per tuple).
     * Tuples containing NULL can match nothing and are dropped.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, int|string>  $ids
     * @return array<int, array<int, int|string>>
     */
    private function parentTuples(string $table, array $columns, array $ids): array
    {
        if ($columns === ['id']) {
            return array_map(fn ($id) => [$id], array_values($ids));
        }

        $tuples = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (DB::table($table)->whereIn('id', $chunk)->get($columns) as $row) {
                $tuple = array_map(fn (string $c) => $row->{$c}, $columns);

                if (! in_array(null, $tuple, true)) {
                    $tuples[implode("\x1f", $tuple)] = $tuple;
                }
            }
        }

        return array_values($tuples);
    }

    /**
     * Child rows whose foreign-key columns equal one of the given tuples.
     *
     * @param  array{child: string, columns: array<int, string>}  $fk
     * @param  array<int, array<int, int|string>>  $tuples
     */
    private function childQuery(array $fk, array $tuples): \Illuminate\Database\Query\Builder
    {
        $query = DB::table($fk['child']);

        if (count($fk['columns']) === 1) {
            return $query->whereIn($fk['columns'][0], array_column($tuples, 0));
        }

        $columns = implode(',', array_map(fn (string $c) => '`' . $c . '`', $fk['columns']));
        $row = '(' . implode(',', array_fill(0, count($fk['columns']), '?')) . ')';

        return $query->whereRaw('(' . $columns . ') in (' . implode(',', array_fill(0, count($tuples), $row)) . ')', array_merge(...$tuples));
    }

    /**
     * Clear nullable references between selected rows first, so cycles and
     * self-references (a workflow pointing at its own published version, a
     * parent id) cannot make the delete order unsatisfiable.
     *
     * @param  array<int, string>  $tables
     */
    private function detachNullableReferences(AccountRecordSelection $selection, array $tables): void
    {
        foreach ($this->foreignKeys() as $fk) {
            if (! in_array($fk['child'], $tables, true) || ! in_array($fk['parent'], $tables, true) || $fk['rule'] === 'SET NULL') {
                continue;
            }

            $nullable = array_values(array_filter($fk['columns'], fn (string $c) => $this->isNullable($fk['child'], $c)));

            if ($nullable === []) {
                continue;
            }

            foreach (array_chunk($selection->ids[$fk['child']] ?? [], self::CHUNK) as $chunk) {
                DB::table($fk['child'])->whereIn('id', $chunk)->update(array_fill_keys($nullable, null));
            }
        }
    }

    /**
     * The delete order for every table a deletion could ever reach. Throws if
     * the schema contains a dependency cycle that nulling cannot break, which
     * the schema-drift test turns into a failure long before production.
     *
     * @return array<int, string>
     */
    public function orderForAllReachableTables(): array
    {
        return $this->deletionOrder($this->reachableTables());
    }

    /**
     * Children before parents, over the edges nulling could not remove.
     *
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function deletionOrder(array $tables): array
    {
        // $mustFollow[parent] = children that have to be deleted before it.
        $blocking = array_fill_keys($tables, []);

        foreach ($this->foreignKeys() as $fk) {
            if ($fk['child'] === $fk['parent'] || ! isset($blocking[$fk['child']], $blocking[$fk['parent']]) || $fk['rule'] === 'SET NULL') {
                continue;
            }

            $allNullable = count(array_filter($fk['columns'], fn (string $c) => $this->isNullable($fk['child'], $c))) > 0;

            if ($allNullable) {
                continue;
            }

            $blocking[$fk['parent']][$fk['child']] = true;
        }

        $order = [];
        $remaining = $blocking;

        while ($remaining !== []) {
            $ready = array_keys(array_filter($remaining, fn (array $children) => array_diff_key($children, array_flip($order)) === []));

            if ($ready === []) {
                throw new RuntimeException('The account data has a dependency cycle that cannot be ordered safely: ' . implode(', ', array_keys($remaining)));
            }

            foreach ($ready as $table) {
                $order[] = $table;
                unset($remaining[$table]);
            }
        }

        return $order;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return isset($this->columns()[$table][$column]);
    }

    private function isNullable(string $table, string $column): bool
    {
        return $this->columns()[$table][$column] ?? false;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $columns = [];

        foreach (DB::select(
            'select table_name as t, column_name as c, is_nullable as n from information_schema.columns where table_schema = ?',
            [DB::connection()->getDatabaseName()],
        ) as $row) {
            $columns[(string) $row->t][(string) $row->c] = $row->n === 'YES';
        }

        return $this->columns = $columns;
    }
}
