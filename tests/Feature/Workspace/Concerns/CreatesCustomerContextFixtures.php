<?php

namespace Tests\Feature\Workspace\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;

/**
 * Customer Experience Slice 1B fixtures — tenants per plan tier, extra
 * Businesses, memberships and assignments, plus small parsers for the
 * rendered customer shell (sidebar + navbar) so the T-CTX / T-NAV / T-VIEW
 * tests can assert on navigation keys, links and active state.
 */
trait CreatesCustomerContextFixtures
{
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private ?int $slice1bPlatformAdminId = null;

    protected function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }

    /**
     * User id 1 short-circuits EloquentAccountRepository::hasPermission()
     * as the super admin; burning it on a platform admin keeps every
     * permission assertion below meaningful, and gives assignFirstPlan()
     * its acting platform administrator.
     */
    protected function platformAdminId(): int
    {
        if ($this->slice1bPlatformAdminId !== null) {
            return $this->slice1bPlatformAdminId;
        }

        $admin = User::create([
            'first_name' => 'Platform',
            'last_name' => 'Owner',
            'email' => 'platform-owner-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        return $this->slice1bPlatformAdminId = (int) $admin->id;
    }

    /**
     * A customer who owns one Workspace holding one active Business on the
     * given tier.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function tenant(
        WorkspacePlanTier $tier = WorkspacePlanTier::Growth,
        string $businessName = 'Harbor Lane Studios',
        string $workspaceName = 'Harbor Lane',
    ): array {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();

        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $workspaceName]);
        $business = $this->addBusiness($customer, $workspace, $businessName);

        $this->assignTier($workspace, $tier);

        return [$customer, $business->fresh(), $workspace->fresh()];
    }

    protected function assignTier(Workspace $workspace, WorkspacePlanTier $tier): void
    {
        app(EntitlementManager::class)->assignFirstPlan($workspace, $tier, $this->platformAdminId(), 'Slice 1B fixture assignment.', true, 0);
    }

    /**
     * Persists a Business directly through the repository seam (fixture
     * only — bypasses the customer-facing capacity path on purpose).
     */
    protected function addBusiness(Customer $customer, Workspace $workspace, string $name, BusinessStatus $status = BusinessStatus::Active): Business
    {
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => $name]));

        DB::table('businesses')->where('id', $business->id)->update(['status' => $status->value]);

        return $business->fresh();
    }

    protected function member(
        Workspace $workspace,
        User $user,
        WorkspaceMembershipRole $role,
        WorkspaceBusinessAccessScope $scope = WorkspaceBusinessAccessScope::All,
        bool $active = true,
    ): WorkspaceMembership {
        return $this->createMembership($workspace, $user, [
            'role' => $role,
            'business_access_scope' => $scope,
            'is_active' => $active,
        ]);
    }

    protected function assign(WorkspaceMembership $membership, Business $business): WorkspaceMembershipBusiness
    {
        return WorkspaceMembershipBusiness::create([
            'workspace_membership_id' => $membership->id,
            'business_id' => $business->id,
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function allCustomerPermissions(): array
    {
        return array_keys(config('customer-permissions'));
    }

    /**
     * @param  array<int, string>|null  $permissions  null = every customer permission
     */
    protected function authenticateAs(Customer $customer, ?array $permissions = null): void
    {
        $user = $customer->user;
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions ?? $this->allCustomerPermissions()))]);
        $this->actingAs($user);
    }

    protected function home(): TestResponse
    {
        return $this->get(route('user.home'));
    }

    protected function switchTo(Workspace|string $workspace, Business|string $business): TestResponse
    {
        return $this->post(route('customer.context.business.switch'), [
            'workspace' => $workspace instanceof Workspace ? $workspace->uid : $workspace,
            'business' => $business instanceof Business ? $business->uid : $business,
        ]);
    }

    protected function startViewAs(Workspace|string $workspace, Business|string $business, ?string $reason = null): TestResponse
    {
        return $this->post(route('customer.view-as.start'), array_filter([
            'workspace' => $workspace instanceof Workspace ? $workspace->uid : $workspace,
            'business' => $business instanceof Business ? $business->uid : $business,
            'reason' => $reason,
        ]));
    }

    // -----------------------------------------------------------------
    // Rendered-shell parsers
    // -----------------------------------------------------------------

    /**
     * The navbar + sidebar region of a rendered customer page.
     */
    protected function shellHtml(string $html): string
    {
        $start = strpos($html, '<nav class="header-navbar');
        $end = strpos($html, '<!-- END: Main Menu-->');

        $this->assertNotFalse($start, 'The navbar must be present.');
        $this->assertNotFalse($end, 'The sidebar must be present.');

        return substr($html, $start, $end - $start);
    }

    /**
     * Visible text of the shell with every attribute removed — URLs are
     * allowed to contain /workspaces/ (contract §8.5); the interface is not.
     */
    protected function shellText(string $html): string
    {
        $shell = preg_replace('/\s[a-zA-Z-]+="[^"]*"/', '', $this->shellHtml($html)) ?? '';

        return html_entity_decode(strip_tags($shell));
    }

    /**
     * @return array<int, string>
     */
    protected function menuKeys(string $html): array
    {
        preg_match_all('/data-nav-key="([^"]+)"/', $this->sidebarHtml($html), $matches);

        return $matches[1];
    }

    /**
     * @return array<int, string>
     */
    protected function activeMenuKeys(string $html): array
    {
        preg_match_all('/<li class="([^"]*)" data-nav-key="([^"]+)">/', $this->sidebarHtml($html), $matches, PREG_SET_ORDER);

        $active = [];

        foreach ($matches as $match) {
            if (preg_match('/(^|\s)active(\s|$)/', $match[1]) === 1) {
                $active[] = $match[2];
            }
        }

        return $active;
    }

    /**
     * Every real link rendered in the sidebar (group toggles excluded).
     *
     * @return array<int, string>
     */
    protected function menuLinks(string $html): array
    {
        preg_match_all('/href="([^"]+)"/', $this->sidebarHtml($html), $matches);

        return array_values(array_unique(array_filter($matches[1], fn (string $href) => ! str_starts_with($href, 'javascript:'))));
    }

    protected function sidebarHtml(string $html): string
    {
        $start = strpos($html, 'id="main-menu-navigation"');
        $end = strpos($html, '<!-- END: Main Menu-->');

        $this->assertNotFalse($start, 'The sidebar navigation must be present.');

        return substr($html, $start, $end - $start);
    }

    protected function assertUrlMatchesARegisteredRoute(string $url): void
    {
        try {
            Route::getRoutes()->match(Request::create($url, 'GET'));
        } catch (NotFoundHttpException) {
            $this->fail('Menu target does not resolve to a registered route: ' . $url);
        }
    }
}
