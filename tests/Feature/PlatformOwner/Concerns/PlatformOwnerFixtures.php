<?php

namespace Tests\Feature\PlatformOwner\Concerns;

use App\Models\User;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;

/**
 * Platform Owner / Admin V1 fixtures. Builds on the customer-context fixtures
 * (tenants, Agency-managed clients, View As) and the lane-A subscription
 * fixtures, and adds an acting Platform Owner.
 *
 * The user id that `EloquentAccountRepository::hasPermission()` treats as the
 * unconditional super admin (id 1) is burned on a fixture administrator first,
 * so every permission assertion below is meaningful rather than short-circuited.
 */
trait PlatformOwnerFixtures
{
    use CreatesPlatformSubscriptions;

    /** @var array<int, string> */
    protected array $ownerPermissions = [
        'access backend',
        'view workspace',
        'view business',
        'edit business',
        'view workspace plans',
        'manage workspace plans',
        'edit customer',
    ];

    protected function bootPlatformOwnerFixtures(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $this->bindFakeStripe();
    }

    /**
     * An authenticated Platform Owner: `users.is_admin` plus the admin
     * permission strings the existing admin pages check.
     *
     * @param array<int, string>|null $permissions
     */
    protected function actingAsPlatformOwner(?array $permissions = null): User
    {
        $owner = User::create([
            'first_name' => 'Pat',
            'last_name' => 'Owner',
            'email' => 'pat-owner-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions ?? $this->ownerPermissions)]);
        $this->actingAs($owner);

        return $owner;
    }

    /**
     * Every Platform Owner GET route this lane owns or extends.
     *
     * @return array<string, string>
     */
    protected function platformOwnerGetUrls(\App\Models\Workspace $workspace, \App\Models\Business $business): array
    {
        return [
            'overview' => route('admin.platform-owner.overview'),
            'audit' => route('admin.platform-owner.audit'),
            'workspaces' => route('admin.workspaces.index'),
            'workspace' => route('admin.workspaces.show', $workspace),
            'businesses' => route('admin.businesses.index'),
            'business' => route('admin.businesses.show', $business),
        ];
    }
}
