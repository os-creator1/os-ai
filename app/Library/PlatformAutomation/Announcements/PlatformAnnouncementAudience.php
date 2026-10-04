<?php

namespace App\Library\PlatformAutomation\Announcements;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns an audience definition into the set of USER ids that should receive an
 * announcement. Recipients are always real customer accounts that own a Workspace or
 * a Business; Platform Owners and staff are never swept in by "everyone".
 *
 * Kinds: everyone | workspace_owners | business_owners | tier (core|growth|agency) |
 * trial | workspaces (ids/uids) | businesses (ids/uids) | users (ids; automation use).
 */
final class PlatformAnnouncementAudience
{
    public const KINDS = [
        'everyone' => 'Everyone',
        'workspace_owners' => 'Workspace owners',
        'business_owners' => 'Business owners',
        'tier' => 'A plan tier (Core / Growth / Agency)',
        'trial' => 'Workspaces on a trial',
        'workspaces' => 'Specific Workspaces',
        'businesses' => 'Specific Businesses',
        'users' => 'Specific users',
    ];

    public const TIERS = ['core', 'growth', 'agency'];

    /**
     * @param  array<string, mixed>  $audience
     * @return list<string> validation errors
     */
    public static function errors(array $audience): array
    {
        $kind = (string) ($audience['kind'] ?? '');

        if (! isset(self::KINDS[$kind])) {
            return ['Choose who the announcement is for.'];
        }
        if ($kind === 'tier' && ! in_array((string) ($audience['tier'] ?? ''), self::TIERS, true)) {
            return ['Choose a plan tier.'];
        }
        if (in_array($kind, ['workspaces', 'businesses', 'users'], true) && self::refs($audience) === []) {
            return ['List at least one ' . rtrim($kind, 's') . '.'];
        }

        return [];
    }

    /** @param array<string, mixed> $audience */
    public static function query(array $audience): Builder
    {
        $kind = (string) ($audience['kind'] ?? '');
        $refs = self::refs($audience);

        $workspaceOwners = fn () => DB::table('workspaces as w')->where('w.is_active', true)->select('w.owner_user_id as id');
        $businessOwners = fn () => DB::table('businesses as b')->select('b.customer_id as id');

        $ids = match ($kind) {
            'workspace_owners' => $workspaceOwners(),
            'business_owners' => $businessOwners(),
            'tier' => $workspaceOwners()
                ->join('workspace_plan_assignments as a', 'a.workspace_id', '=', 'w.id')
                ->join('workspace_plan_catalog as c', 'c.id', '=', 'a.workspace_plan_catalog_id')
                ->where('c.tier', (string) ($audience['tier'] ?? '')),
            'trial' => $workspaceOwners()
                ->join('platform_subscriptions as s', 's.workspace_id', '=', 'w.id')
                ->where('s.status', 'trialing'),
            'workspaces' => DB::table('workspaces as w')->where(fn ($q) => $q->whereIn('w.id', self::numeric($refs))->orWhereIn('w.uid', $refs))->select('w.owner_user_id as id'),
            'businesses' => DB::table('businesses as b')->where(fn ($q) => $q->whereIn('b.id', self::numeric($refs))->orWhereIn('b.uid', $refs))->select('b.customer_id as id'),
            'users' => DB::table('users as uu')->whereIn('uu.id', self::numeric($refs))->select('uu.id as id'),
            default => DB::table('users as e')->where('e.is_customer', true)->select('e.id as id'),
        };

        // Always a real, enabled, non-admin customer account, each at most once.
        return DB::table('users')
            ->whereIn('users.id', $ids)
            ->where('users.is_admin', false)
            ->where('users.status', true)
            ->select('users.id');
    }

    /** @return list<string> */
    private static function refs(array $audience): array
    {
        $raw = $audience['refs'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_unique(array_filter(array_map(fn ($r) => trim((string) $r), (array) $raw), fn ($r) => $r !== '')));
    }

    /** @param list<string> $refs @return list<int> */
    private static function numeric(array $refs): array
    {
        return array_values(array_map('intval', array_filter($refs, 'ctype_digit')));
    }
}
