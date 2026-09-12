<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * Account → Members → Add member identifies the person by EMAIL ADDRESS.
 *
 * A customer never types, sees or sends an internal User uid or numeric id.
 * Ordinary form mistakes return to the account page with a useful message;
 * authorization and addressability failures stay 404 and reveal nothing about
 * which addresses have accounts.
 */
class WorkspaceMemberEmailIdentityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private const MEMBER_CANNOT_BE_ADDED = 'We couldn\'t add that person. Check the email address: they need an existing Business OS account, and can\'t already be on this account or be its owner.';

    // --- The customer form -------------------------------------------------

    public function test_the_add_member_form_asks_for_an_email_address_and_never_a_user_uid(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Harbor Lane']);

        $form = $this->membersForm($this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent());

        $this->assertStringContainsString('name="member_email"', $form);
        $this->assertStringContainsString('type="email"', $form);
        $this->assertStringContainsString('>Email address</label>', $form);
        $this->assertStringContainsString('They need an existing Business OS account before you can add them.', $form);
        $this->assertStringNotContainsString('user_uid', $form);
        $this->assertStringNotContainsStringIgnoringCase('User UID', $form);
        $this->assertStringNotContainsString($customer->user->uid, $form, 'No opaque user uid is rendered in the form.');
    }

    public function test_an_unknown_address_is_shown_back_inline_on_the_same_account_page(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $this->from(route('customer.workspaces.show', $workspace->uid))
            ->post(route('customer.workspaces.members.store', $workspace->uid), [
                'member_email' => 'mila@example.test',
                'role' => 'staff',
                'business_access_scope' => 'all',
            ])
            ->assertRedirect(route('customer.workspaces.show', $workspace->uid));

        $page = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();
        $form = $this->membersForm($page);

        $this->assertStringContainsString(e(self::MEMBER_CANNOT_BE_ADDED), $form, 'The message sits on the email field.');
        $this->assertStringContainsString('value="mila@example.test"', $form, 'What the customer typed is kept.');
        $this->assertStringNotContainsString('Page Not Found', $page);
    }

    // --- Adding by email -----------------------------------------------------

    public function test_an_existing_customer_account_is_added_by_email(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $mila = $this->createCustomerAccount('mila.rivera@example.test');

        $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'member_email' => 'mila.rivera@example.test',
            'role' => 'staff',
            'business_access_scope' => 'all',
        ]);

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $response->assertSessionHas('flash_success', 'Member added.');
        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $mila->id)->firstOrFail();
        $this->assertSame(WorkspaceMembershipRole::Staff, $membership->role);
        $this->assertSame(WorkspaceBusinessAccessScope::All, $membership->business_access_scope);
        $this->assertTrue((bool) $membership->is_active);
    }

    /**
     * The address is matched the way sign-in matches it — the same
     * `users.email` equality — so letter case and surrounding spaces don't
     * matter.
     */
    public function test_the_address_matches_regardless_of_letter_case_and_surrounding_spaces(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $mila = $this->createCustomerAccount('Mila.Rivera@Example.test');

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'member_email' => '  MILA.RIVERA@EXAMPLE.TEST  ',
            'role' => 'staff',
            'business_access_scope' => 'all',
        ])->assertSessionHas('flash_success');

        $this->assertNotNull(WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $mila->id)->first());
    }

    public function test_role_and_selected_businesses_are_applied_unchanged(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $businessA = $this->createBusinessForCustomer($customer->user->id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($customer->user->id, $workspace->id);
        $this->createBusinessForCustomer($customer->user->id, $workspace->id);
        $mila = $this->createCustomerAccount('mila@example.test');

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'member_email' => 'mila@example.test',
            'role' => 'admin',
            'business_access_scope' => 'selected',
            'business_uids' => [$businessA->uid, $businessB->uid],
        ])->assertSessionHas('flash_success');

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $mila->id)->firstOrFail();
        $this->assertSame(WorkspaceMembershipRole::Admin, $membership->role);
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $membership->business_access_scope);
        $this->assertEqualsCanonicalizing(
            [$businessA->id, $businessB->id],
            WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->pluck('business_id')->all(),
        );
    }

    // --- Ordinary form mistakes stay on the page ---------------------------

    public function test_a_malformed_address_is_ordinary_inline_validation(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        foreach (['mila', 'mila@', '@example.test', 'mila example.test'] as $malformed) {
            $this->from(route('customer.workspaces.show', $workspace->uid))
                ->post(route('customer.workspaces.members.store', $workspace->uid), [
                    'member_email' => $malformed,
                    'role' => 'staff',
                    'business_access_scope' => 'all',
                ])
                ->assertRedirect(route('customer.workspaces.show', $workspace->uid))
                ->assertSessionHasErrors(['member_email' => 'Enter a valid email address, like name@example.com.']);
        }

        $this->assertSame(0, WorkspaceMembership::where('workspace_id', $workspace->id)->count());
    }

    public function test_the_owner_an_existing_member_and_an_unknown_address_all_read_the_same(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createCustomerAccount('already@example.test');
        $this->createMembership($workspace, $member, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $outcomes = [];
        foreach ([$customer->user->email, 'already@example.test', 'nobody@example.test'] as $address) {
            $response = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
                'member_email' => $address,
                'role' => 'admin',
                'business_access_scope' => 'all',
            ]);
            $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
            $response->assertSessionHasErrors(['member_email' => self::MEMBER_CANNOT_BE_ADDED]);
            $outcomes[] = [$response->status(), $response->headers->get('Location')];
        }

        $this->assertCount(1, array_unique(array_map('serialize', $outcomes)), 'The response never reveals which case it was.');
        $this->assertSame(WorkspaceMembershipRole::Staff, WorkspaceMembership::where('user_id', $member->id)->firstOrFail()->role, 'The existing member is untouched.');
        $this->assertNull(WorkspaceMembership::where('user_id', $customer->user->id)->first(), 'The owner is never made a member.');
    }

    public function test_re_adding_an_identical_member_is_a_harmless_no_op(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $mila = $this->createCustomerAccount('mila@example.test');
        $payload = ['member_email' => 'mila@example.test', 'role' => 'staff', 'business_access_scope' => 'all'];

        $this->post(route('customer.workspaces.members.store', $workspace->uid), $payload)->assertSessionHas('flash_success');
        $this->post(route('customer.workspaces.members.store', $workspace->uid), $payload)->assertSessionHas('flash_success');

        $this->assertSame(1, WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $mila->id)->count());
    }

    public function test_an_address_that_is_not_an_active_customer_account_cannot_be_added(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $disabled = $this->createCustomerAccount('disabled@example.test', ['status' => false]);
        $platformOnly = $this->createCustomerAccount('platform@example.test', ['is_customer' => false, 'is_admin' => true, 'active_portal' => 'admin']);

        foreach (['disabled@example.test', 'platform@example.test'] as $address) {
            $this->post(route('customer.workspaces.members.store', $workspace->uid), [
                'member_email' => $address,
                'role' => 'staff',
                'business_access_scope' => 'all',
            ])->assertSessionHasErrors(['member_email' => self::MEMBER_CANNOT_BE_ADDED]);
        }

        $this->assertSame(0, WorkspaceMembership::whereIn('user_id', [$disabled->id, $platformOnly->id])->count());
    }

    // --- Identity is an email, and only an email ---------------------------

    public function test_a_raw_numeric_id_or_an_internal_uid_is_never_accepted_as_the_member(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $mila = $this->createCustomerAccount('mila@example.test');

        $attempts = [
            ['member_email' => (string) $mila->id],
            ['member_email' => $mila->uid],
            ['user_uid' => $mila->uid],
            ['user_id' => $mila->id],
        ];

        foreach ($attempts as $identity) {
            $this->post(route('customer.workspaces.members.store', $workspace->uid), $identity + [
                'role' => 'staff',
                'business_access_scope' => 'all',
            ])->assertSessionHasErrors('member_email');
        }

        $this->assertSame(0, WorkspaceMembership::where('workspace_id', $workspace->id)->count());
    }

    // --- The security boundary is unchanged --------------------------------

    /**
     * An actor who may not add members gets the same 404 whether or not the
     * address has an account — the address is never looked at for them.
     */
    public function test_an_actor_without_authority_learns_nothing_about_an_address(): void
    {
        $actor = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $known = $this->createCustomerAccount('known@example.test');

        $cases = [
            'staff adding staff' => [WorkspaceMembershipRole::Staff, 'staff'],
            'admin adding an admin' => [WorkspaceMembershipRole::Admin, 'admin'],
        ];

        foreach ($cases as $label => [$actorRole, $requestedRole]) {
            WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $actor->user->id)->delete();
            $this->createMembership($workspace, $actor->user, ['role' => $actorRole, 'is_active' => true]);

            $knownResponse = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
                'member_email' => 'known@example.test', 'role' => $requestedRole, 'business_access_scope' => 'all',
            ]);
            $unknownResponse = $this->post(route('customer.workspaces.members.store', $workspace->uid), [
                'member_email' => 'nobody@example.test', 'role' => $requestedRole, 'business_access_scope' => 'all',
            ]);

            $knownResponse->assertNotFound();
            $unknownResponse->assertNotFound();
            $this->assertSame($knownResponse->status(), $unknownResponse->status(), "{$label}: identical answers.");
            $knownResponse->assertSessionHasNoErrors();
            $unknownResponse->assertSessionHasNoErrors();
            $this->assertStringNotContainsString('known@example.test', (string) $knownResponse->getContent());
            $this->assertStringNotContainsString('nobody@example.test', (string) $unknownResponse->getContent());
        }

        $this->assertNull(WorkspaceMembership::where('user_id', $known->id)->first());
    }

    public function test_a_stranger_or_an_unknown_workspace_is_still_not_found(): void
    {
        $this->actingAsHttpCustomer();
        $someoneElses = $this->createWorkspace($this->createCustomer()->user);
        $this->createCustomerAccount('mila@example.test');

        foreach ([$someoneElses->uid, 'no-such-workspace'] as $workspaceUid) {
            $this->post(route('customer.workspaces.members.store', $workspaceUid), [
                'member_email' => 'mila@example.test',
                'role' => 'staff',
                'business_access_scope' => 'all',
            ])->assertNotFound();
        }

        $this->assertSame(0, WorkspaceMembership::where('workspace_id', $someoneElses->id)->count());
    }

    public function test_no_user_directory_or_search_endpoint_exists(): void
    {
        $memberRoutes = [];
        $lookups = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            $uri = $route->uri();

            if (str_starts_with($name, 'customer.workspaces.members.')) {
                $memberRoutes[] = $name;
            }

            if (str_starts_with($name, 'customer.') && preg_match('#(users?|members?|people|team)/?(search|lookup|directory|autocomplete|find)#i', $uri) === 1) {
                $lookups[] = $uri;
            }
        }

        sort($memberRoutes);
        $this->assertSame([
            'customer.workspaces.members.access',
            'customer.workspaces.members.deactivate',
            'customer.workspaces.members.reactivate',
            'customer.workspaces.members.role',
            'customer.workspaces.members.store',
        ], $memberRoutes);
        $this->assertSame([], $lookups);
        $this->assertFalse(Route::has('customer.workspaces.members.index'));
    }

    // -----------------------------------------------------------------------

    /** @param  array<string, mixed>  $overrides */
    private function createCustomerAccount(string $email, array $overrides = []): User
    {
        $user = User::create(array_merge([
            'first_name' => 'Mila',
            'last_name' => 'Rivera',
            'email' => $email,
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ], $overrides));

        if (array_key_exists('status', $overrides)) {
            DB::table('users')->where('id', $user->id)->update(['status' => $overrides['status']]);
        }

        return $user->fresh();
    }

    /** The Members card's Add member form markup. */
    private function membersForm(string $html): string
    {
        $start = strpos($html, 'data-workspace-action="members"');
        $this->assertNotFalse($start, 'The Add member form must render.');
        $end = strpos($html, '</form>', $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    private function actingAsHttpCustomer(): Customer
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $customer;
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }
}
