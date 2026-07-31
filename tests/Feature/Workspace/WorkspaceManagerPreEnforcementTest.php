<?php

namespace Tests\Feature\Workspace;

use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Workspace\Support\HistoricalWorkspaceTestCase;

/**
 * Split out of WorkspaceManagerTest (RFC-003 M1B Slice 4B correction): the
 * three resolveLegacyOnboardingWorkspace() naming-tier behaviors below
 * genuinely depend on a Business existing with zero Workspace candidates —
 * i.e. workspace_id = null — which businesses.workspace_id's NOT NULL
 * constraint makes unconstructible under the final schema
 * WorkspaceManagerTest now runs against. These remain fully in force
 * pre-enforcement (§13.1's naming policy itself is unchanged by migration
 * 6), so they run here against the isolated, disposable historical
 * database instead of being deleted or weakened.
 */
#[Group('workspace-pre-enforcement')]
class WorkspaceManagerPreEnforcementTest extends HistoricalWorkspaceTestCase
{
    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Legacy',
            'last_name' => 'Owner',
            'email' => 'legacy-' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ], $overrides));
    }

    private function createCustomerRecord(int $userId, ?string $company = null): Customer
    {
        return Customer::create(['user_id' => $userId, 'company' => $company]);
    }

    /**
     * is_primary and workspace_id are not mass-assignable (by design —
     * neither Business::$fillable nor the repository allow it), so both
     * are set as direct properties after creation, defaulting to
     * false/null when not supplied. This class runs exclusively under the
     * verified, isolated pre-enforcement schema (businesses.workspace_id
     * still nullable), so the null default here is safe.
     */
    private function createBusiness(int $customerId, array $attributes = []): Business
    {
        $isPrimary = $attributes['is_primary'] ?? false;
        $workspaceId = $attributes['workspace_id'] ?? null;
        unset($attributes['is_primary'], $attributes['workspace_id']);

        $business = Business::create(array_merge([
            'customer_id' => $customerId,
            'name' => 'Legacy Business',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ], $attributes));

        $business->is_primary = $isPrimary;
        $business->workspace_id = $workspaceId;
        $business->save();

        return $business;
    }

    // 8. one primary Business name is naming tier 2.
    public function test_one_primary_business_name_is_naming_tier_two(): void
    {
        $owner = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $this->createCustomerRecord($owner->id, null);
        $this->createBusiness($owner->id, ['name' => 'Primary Biz', 'is_primary' => true]);
        $this->createBusiness($owner->id, ['name' => 'Other Biz', 'is_primary' => false]);
        $manager = app(WorkspaceManager::class);

        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame('Primary Biz', $result->name);
    }

    // 9. first Business by ID is naming tier 3.
    public function test_first_business_by_id_is_naming_tier_three(): void
    {
        $owner = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $this->createCustomerRecord($owner->id, null);
        $this->createBusiness($owner->id, ['name' => 'First Biz', 'is_primary' => false]);
        $this->createBusiness($owner->id, ['name' => 'Second Biz', 'is_primary' => false]);
        $manager = app(WorkspaceManager::class);

        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame('First Biz', $result->name);
    }

    // 12. multiple primary Businesses skip naming tier 2 and use the deterministic first-Business tier.
    public function test_multiple_primary_businesses_skip_tier_two_naming(): void
    {
        $owner = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $this->createCustomerRecord($owner->id, null);
        $first = $this->createBusiness($owner->id, ['name' => 'First Primary', 'is_primary' => true]);
        // Force a second primary directly — nothing in the schema prevents it.
        $second = $this->createBusiness($owner->id, ['name' => 'Second Primary', 'is_primary' => true]);
        // Both primaries here must have null workspace_id — this test is
        // purely about naming once Workspace creation is reached with zero
        // preferred candidates, not about candidate resolution itself.
        $second->workspace_id = null;
        $second->save();

        $manager = app(WorkspaceManager::class);
        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame('First Primary', $result->name);
    }
}
