<?php

namespace App\Library\PlatformOwner;

use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;

/**
 * Platform Owner V1 final — one search box for support: finds customer
 * accounts, Workspaces and Businesses by name, email or uid. Read-only and
 * bounded (LIMIT per kind); returns only display fields and route keys.
 */
class PlatformSupportSearch
{
    public const LIMIT = 10;

    /**
     * @return array{users: \Illuminate\Support\Collection, workspaces: \Illuminate\Support\Collection, businesses: \Illuminate\Support\Collection}
     */
    public function search(string $term): array
    {
        $term = trim($term);
        $like = '%' . addcslashes($term, '%_\\') . '%';

        $users = User::query()->where('is_admin', false)
            ->where(fn ($q) => $q->where('email', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('uid', $term))
            ->orderBy('email')->limit(self::LIMIT)
            ->get(['id', 'uid', 'first_name', 'last_name', 'email', 'status', 'email_verified_at']);

        $workspaces = Workspace::query()
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('uid', $term))
            ->orderBy('name')->limit(self::LIMIT)->get(['id', 'uid', 'name', 'is_active']);

        $businesses = Business::query()
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('uid', $term))
            ->orderBy('name')->limit(self::LIMIT)->get(['id', 'uid', 'name', 'email', 'status']);

        return compact('users', 'workspaces', 'businesses');
    }
}
