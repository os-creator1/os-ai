<?php

namespace App\Library\Business\Migration;

use App\Library\Business\LegacyBusinessResolver;
use Illuminate\Support\Facades\DB;

/**
 * Business Data Tenancy Foundation, Pass 1 — additive-only backfill of the
 * new nullable business_id column across the 11 authorized legacy tables.
 *
 * Treated as immutable once its migration ships (matching this repo's own
 * WorkspaceBackfillV1/UsageWalletBackfillV1 convention): a future
 * correction is a new versioned class (V2), never an edit to this one.
 *
 * Idempotent by construction — every query is scoped to `business_id IS
 * NULL`, so an already-populated row (valid or intentionally left null)
 * is never revisited or overwritten on a re-run.
 *
 * Never fails the run because a historical row cannot be resolved. Rows
 * whose owner cannot be deterministically mapped to exactly one Business
 * (via LegacyBusinessResolver) are simply left NULL; unresolvedCounts()
 * gives an exact, deterministic per-table count for follow-up.
 */
class BusinessDataTenancyBackfillV1
{
    private const CHUNK_SIZE = 500;

    /**
     * Tables whose owner column directly resolves to a Business, with no
     * special-case handling.
     *
     * @var array<string, string> table => owner column
     */
    private const DIRECT_TABLES = [
        'campaigns' => 'user_id',
        'templates' => 'user_id',
        'senderid' => 'user_id',
        'customer_based_sending_servers' => 'user_id',
        'contacts' => 'customer_id',
        'contact_groups' => 'customer_id',
        'blacklists' => 'user_id',
        'keywords' => 'user_id',
    ];

    /**
     * Tables that inherit business_id from their parent Campaign first,
     * falling back to their own owner column only when the campaign_id is
     * null or the parent campaign's own business_id is still unresolved.
     * Must run after `campaigns` has already been backfilled.
     *
     * @var array<string, string> table => owner column
     */
    private const CAMPAIGN_INHERITING_TABLES = [
        'reports' => 'user_id',
        'tracking_logs' => 'customer_id',
    ];

    private const ALL_TABLES = [
        'campaigns', 'reports', 'tracking_logs', 'templates', 'phone_numbers',
        'senderid', 'customer_based_sending_servers', 'contacts', 'contact_groups',
        'blacklists', 'keywords',
    ];

    /** @var array<int, int|null> memoized customerUserId => resolved business id (null = unresolved) */
    private array $resolutionCache = [];

    public function __construct(private readonly LegacyBusinessResolver $resolver = new LegacyBusinessResolver())
    {
    }

    /**
     * @return array<string, array{resolved: int, unresolved: int}>
     */
    public function run(): array
    {
        $summary = [];

        foreach (self::DIRECT_TABLES as $table => $ownerColumn) {
            $summary[$table] = $this->backfillDirect($table, $ownerColumn);
        }

        // phone_numbers is its own special case (pooled/unassigned numbers
        // must stay NULL forever) — not a DIRECT_TABLES entry.
        $summary['phone_numbers'] = $this->backfillPhoneNumbers();

        // Must run after campaigns above so inheritance has something to
        // read from.
        foreach (self::CAMPAIGN_INHERITING_TABLES as $table => $ownerColumn) {
            $summary[$table] = $this->backfillInheritingFromCampaign($table, $ownerColumn);
        }

        return $summary;
    }

    /**
     * @return array<string, int> table => count of rows still NULL
     */
    public function unresolvedCounts(): array
    {
        $counts = [];

        foreach (self::ALL_TABLES as $table) {
            $counts[$table] = DB::table($table)->whereNull('business_id')->count();
        }

        return $counts;
    }

    private function backfillDirect(string $table, string $ownerColumn): array
    {
        $resolved = 0;
        $unresolved = 0;
        $lastId = 0;

        while (true) {
            $rows = DB::table($table)
                ->select('id', $ownerColumn)
                ->whereNull('business_id')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $ownerId = $row->{$ownerColumn};
                $businessId = $ownerId !== null ? $this->resolve((int) $ownerId) : null;

                if ($businessId !== null) {
                    DB::table($table)->where('id', $row->id)->update(['business_id' => $businessId]);
                    $resolved++;
                } else {
                    $unresolved++;
                }
            }

            $lastId = (int) $rows->last()->id;
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved];
    }

    /**
     * Pooled/unassigned numbers use the established sentinel user_id = 1
     * (see EloquentPhoneNumberRepository::update()/release()) — those
     * rows, and any with a null owner, must never resolve a Business and
     * stay NULL permanently, not just "unresolved for now".
     */
    private function backfillPhoneNumbers(): array
    {
        $resolved = 0;
        $unresolved = 0;
        $lastId = 0;

        while (true) {
            $rows = DB::table('phone_numbers')
                ->select('id', 'user_id')
                ->whereNull('business_id')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row->id;

                if ($row->user_id === null || (int) $row->user_id === 1) {
                    $unresolved++;
                    continue;
                }

                $businessId = $this->resolve((int) $row->user_id);

                if ($businessId !== null) {
                    DB::table('phone_numbers')->where('id', $row->id)->update(['business_id' => $businessId]);
                    $resolved++;
                } else {
                    $unresolved++;
                }
            }
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved];
    }

    private function backfillInheritingFromCampaign(string $table, string $ownerColumn): array
    {
        $resolved = 0;
        $unresolved = 0;
        $lastId = 0;

        while (true) {
            $rows = DB::table($table)
                ->select('id', 'campaign_id', $ownerColumn)
                ->whereNull('business_id')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $businessId = null;

                if ($row->campaign_id !== null) {
                    $businessId = DB::table('campaigns')->where('id', $row->campaign_id)->value('business_id');
                }

                if ($businessId === null) {
                    $ownerId = $row->{$ownerColumn};
                    $businessId = $ownerId !== null ? $this->resolve((int) $ownerId) : null;
                }

                if ($businessId !== null) {
                    DB::table($table)->where('id', $row->id)->update(['business_id' => $businessId]);
                    $resolved++;
                } else {
                    $unresolved++;
                }
            }
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved];
    }

    private function resolve(int $customerUserId): ?int
    {
        if (! array_key_exists($customerUserId, $this->resolutionCache)) {
            $this->resolutionCache[$customerUserId] = $this->resolver->resolveForCustomer($customerUserId)?->id;
        }

        return $this->resolutionCache[$customerUserId];
    }
}
