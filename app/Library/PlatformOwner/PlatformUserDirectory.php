<?php

namespace App\Library\PlatformOwner;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Platform Owner V1 final — read model for the Users page: customer-side
 * accounts (is_admin = false) with their verification state, status and
 * Workspace memberships. Read-only; nothing here can reveal a password,
 * token, API key or two-factor secret (explicit column list).
 *
 * Memberships are fetched in two batched queries for the whole page, so the
 * query count does not grow with the number of rows.
 */
class PlatformUserDirectory
{
    public const PER_PAGE = 25;

    private const COLUMNS = [
        'id', 'uid', 'first_name', 'last_name', 'email', 'email_verified_at', 'status', 'is_admin',
        'is_customer', 'last_access_at', 'created_at',
    ];

    /**
     * @param  array{q?: ?string, state?: ?string}  $filters  state: active|suspended|unverified
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = User::query()->select(self::COLUMNS)->where('is_admin', false);

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('email', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("concat(first_name, ' ', coalesce(last_name, '')) like ?", [$like]);
            });
        }

        match ($filters['state'] ?? null) {
            'suspended' => $query->where('status', false),
            'active' => $query->where('status', true),
            'unverified' => $query->whereNull('email_verified_at'),
            default => null,
        };

        $page = $query->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString();

        $this->attachMemberships($page->getCollection());

        return $page;
    }

    public function find(string $uid): ?User
    {
        $user = User::query()->select(self::COLUMNS)->where('is_admin', false)->where('uid', $uid)->first();

        if ($user) {
            $this->attachMemberships(collect([$user]));
        }

        return $user;
    }

    /**
     * Sets `workspaces_summary` on each user: list of [name, uid, role].
     *
     * @param  \Illuminate\Support\Collection<int, User>  $users
     */
    private function attachMemberships($users): void
    {
        $ids = $users->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        $memberships = WorkspaceMembership::query()
            ->whereIn('user_id', $ids)
            ->where('is_active', true)
            ->with('workspace:id,uid,name')
            ->get(['id', 'workspace_id', 'user_id', 'role'])
            ->groupBy('user_id');

        $owned = Workspace::query()->whereIn('owner_user_id', $ids)->get(['id', 'uid', 'name', 'owner_user_id'])->groupBy('owner_user_id');

        foreach ($users as $user) {
            $rows = [];

            foreach ($owned->get($user->id, collect()) as $w) {
                $rows[$w->id] = ['name' => $w->name, 'uid' => $w->uid, 'role' => 'owner'];
            }

            foreach ($memberships->get($user->id, collect()) as $m) {
                if ($m->workspace && ! isset($rows[$m->workspace->id])) {
                    $role = $m->role instanceof \BackedEnum ? $m->role->value : (string) $m->role;
                    $rows[$m->workspace->id] = ['name' => $m->workspace->name, 'uid' => $m->workspace->uid, 'role' => $role];
                }
            }

            $user->setRelation('workspaces_summary', array_values($rows));
        }
    }
}
