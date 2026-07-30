<?php

declare(strict_types=1);

namespace App\Library\Workspace\Migration;

use App\Exceptions\Workspace\WorkspaceBackfillConflictException;
use App\Exceptions\Workspace\WorkspaceBackfillIncompleteException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Query-builder-only, versioned backfill action (RFC-003 §10.3-§10.4) that
 * assigns every pre-existing null-workspace_id Business to a Workspace,
 * grouped by the legacy businesses.customer_id — a one-time migration-
 * compatibility policy, not a permanent domain rule (§10.7). Treated as
 * immutable once migration 5 ships: a future correction is a new
 * WorkspaceBackfillV2, never an edit here, so replaying migration history
 * always reproduces the same result.
 *
 * Must never depend on Eloquent models, model events, or
 * User::displayName() (§10.3) — every read/write goes through DB's query
 * builder, so this class stays safe to invoke directly from a historical
 * migration (Slice 5) without any framework console dependency.
 */
class WorkspaceBackfillV1
{
    /**
     * Bounded page size for the distinct-customer_id cursor below, so
     * memory usage stays flat regardless of table size (§10.3's "bounded
     * chunks" requirement) without chunkById() over a DISTINCT read, which
     * chunkById() cannot paginate correctly. Protected only so a test
     * subclass can shrink it to exercise a page boundary without inserting
     * hundreds of rows; production always uses this default.
     */
    protected const CHUNK_SIZE = 500;

    public function run(): WorkspaceBackfillResult
    {
        $groupsProcessed = 0;
        $workspacesCreated = 0;
        $workspacesReused = 0;
        $businessesAssigned = 0;
        $lastSeenCustomerId = 0;

        while (true) {
            $customerIds = $this->nextCustomerIdPage($lastSeenCustomerId);

            if ($customerIds->isEmpty()) {
                break;
            }

            foreach ($customerIds as $customerId) {
                $outcome = $this->processCustomerGroup((int) $customerId);

                $groupsProcessed++;
                $businessesAssigned += $outcome['assignedCount'];

                if ($outcome['created']) {
                    $workspacesCreated++;
                } else {
                    $workspacesReused++;
                }
            }

            $lastSeenCustomerId = (int) $customerIds->last();
        }

        $remainingNullCount = DB::table('businesses')->whereNull('workspace_id')->count();

        if ($remainingNullCount > 0) {
            throw new WorkspaceBackfillIncompleteException($remainingNullCount);
        }

        return new WorkspaceBackfillResult(
            groupsProcessed: $groupsProcessed,
            workspacesCreated: $workspacesCreated,
            workspacesReused: $workspacesReused,
            businessesAssigned: $businessesAssigned,
            remainingNullCount: 0,
        );
    }

    /**
     * Deterministic ascending cursor: every customer_id greater than the
     * last one seen that still owns at least one null-workspace_id
     * Business. Strictly increasing and bounded by the finite customer_id
     * space, so the outer loop always terminates; a customer_id already
     * committed in this run is never reselected, since its Businesses are
     * no longer null and it is behind the cursor either way.
     */
    private function nextCustomerIdPage(int $afterCustomerId): Collection
    {
        return DB::table('businesses')
            ->whereNull('workspace_id')
            ->where('customer_id', '>', $afterCustomerId)
            ->orderBy('customer_id')
            ->distinct()
            ->limit(static::CHUNK_SIZE)
            ->pluck('customer_id');
    }

    /**
     * Protected so a test can hold this lock open for a controlled
     * duration after acquiring it, to prove a second concurrent attempt
     * genuinely blocks rather than merely completing sequentially by
     * coincidence. The lock itself — SELECT ... FOR UPDATE on the stable
     * users row (§10.4 step 2, §18) — is the serialization point; there is
     * no Workspace row to lock when none exists yet.
     */
    protected function lockOwnerRow(int $customerId): ?object
    {
        return DB::table('users')->where('id', $customerId)->lockForUpdate()->first();
    }

    /**
     * @return array{created: bool, assignedCount: int}
     */
    protected function processCustomerGroup(int $customerId): array
    {
        return DB::transaction(function () use ($customerId) {
            $userRow = $this->lockOwnerRow($customerId);

            if ($userRow === null) {
                throw new RuntimeException(
                    "Business backfill found no users row for customer_id [{$customerId}]."
                );
            }

            // Re-read every Business for this customer_id only after
            // acquiring the lock above — this is what detects a prior
            // partial run or a concurrent attempt that already committed.
            $businesses = DB::table('businesses')->where('customer_id', $customerId)->get();

            $nonNullWorkspaceIds = $businesses
                ->filter(fn ($business) => $business->workspace_id !== null)
                ->map(fn ($business) => (int) $business->workspace_id)
                ->unique()
                ->values();

            if ($nonNullWorkspaceIds->count() > 1) {
                throw new WorkspaceBackfillConflictException($customerId, $nonNullWorkspaceIds->all());
            }

            $nullBusinessIds = $businesses
                ->filter(fn ($business) => $business->workspace_id === null)
                ->pluck('id');

            if ($nonNullWorkspaceIds->count() === 1) {
                $workspaceId = $nonNullWorkspaceIds->first();

                if (! DB::table('workspaces')->where('id', $workspaceId)->exists()) {
                    throw new RuntimeException(
                        "Business backfill found workspace_id [{$workspaceId}] referenced by customer_id " .
                        "[{$customerId}] with no matching workspaces row."
                    );
                }

                $created = false;
            } else {
                $workspaceId = $this->createWorkspace($customerId, $userRow, $businesses);
                $created = true;
            }

            $assignedCount = $nullBusinessIds->count();

            if ($assignedCount > 0) {
                DB::table('businesses')
                    ->whereIn('id', $nullBusinessIds)
                    ->update([
                        'workspace_id' => $workspaceId,
                        'updated_at' => now(),
                    ]);
            }

            return ['created' => $created, 'assignedCount' => $assignedCount];
        });
    }

    /**
     * Protected — not for production polymorphism, but so tests can inject
     * a controlled failure between the Workspace insert (this method) and
     * the Business-assignment update that follows it in
     * processCustomerGroup(), both inside the same transaction, by
     * subclassing and calling parent::createWorkspace() before throwing.
     * This is the natural seam between "resolve/create the Workspace" and
     * "assign it," decomposed for readability regardless of testing; no
     * production behavior changes.
     */
    protected function createWorkspace(int $customerId, object $userRow, Collection $businesses): int
    {
        $name = $this->resolveWorkspaceName($customerId, $userRow, $businesses);
        $now = now();

        return DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(),
            'name' => $name,
            'owner_user_id' => $customerId,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Deterministic name priority (§10.5), first non-blank value wins:
     * customers.company, then the primary Business's name, then the first
     * Business by id, then "{first_name} {last_name}'s Workspace" built
     * from raw users columns (reproducing User::displayName() without
     * calling it, §10.3). If first_name and last_name are both blank too,
     * falls back to "Customer #{id}'s Workspace" — deterministic and always
     * non-blank, since customer_id itself can never be blank.
     */
    private function resolveWorkspaceName(int $customerId, object $userRow, Collection $businesses): string
    {
        $company = DB::table('customers')->where('user_id', $customerId)->value('company');

        if ($this->isNonBlank($company)) {
            return trim($company);
        }

        $primaryBusiness = $businesses->first(fn ($business) => (bool) $business->is_primary);

        if ($primaryBusiness !== null && $this->isNonBlank($primaryBusiness->name)) {
            return trim($primaryBusiness->name);
        }

        $firstBusiness = $businesses->sortBy('id')->first();

        if ($firstBusiness !== null && $this->isNonBlank($firstBusiness->name)) {
            return trim($firstBusiness->name);
        }

        $fullName = trim(($userRow->first_name ?? '') . ' ' . ($userRow->last_name ?? ''));

        if ($this->isNonBlank($fullName)) {
            return "{$fullName}'s Workspace";
        }

        return "Customer #{$customerId}'s Workspace";
    }

    private function isNonBlank(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
