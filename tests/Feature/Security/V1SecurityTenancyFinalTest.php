<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\API\Concerns\AuthorizesOwnedContactGroup;
use App\Models\AppConfig;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\Keywords;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\Contracts\ContactsRepository;
use App\Repositories\Eloquent\EloquentCustomerRepository;
use App\Rules\PublicHttpsUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * V1 final security / tenancy / authorization audit — adversarial regression suite.
 *
 * Each test names the defect it proves closed. Attacker is always an authenticated (or
 * unauthenticated) actor of tenant A reaching for tenant B's / a platform account's resource.
 */
class V1SecurityTenancyFinalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        // User id 1 is the platform super admin in this codebase.
        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    // ------------------------------------------------------------------
    // Sub-accounts: any customer could edit / delete / disable ANY user
    // ------------------------------------------------------------------

    public function test_customer_cannot_update_toggle_or_destroy_a_user_they_do_not_own(): void
    {
        [$attacker, $victim] = $this->twoTenantCustomers();
        $this->authenticateAsCustomer($attacker, ['access_backend']);

        $before = User::find($victim->user_id)->only(['email', 'status', 'is_admin']);

        $this->post(route('customer.sub_accounts.update', $victim->user->uid), [
            'first_name' => 'Pwned',
            'email' => 'attacker@example.test',
            'is_admin' => 1,
            'permissions' => ['access_backend' => 1],
        ])->assertStatus(404);

        $this->postJson(route('customer.sub_accounts.active', $victim->user->uid))->assertStatus(404);
        $this->postJson(route('customer.sub_accounts.destroy', $victim->user->uid))->assertStatus(404);
        $this->get(route('customer.sub_accounts.show', $victim->user->uid))->assertStatus(404);

        $this->assertSame($before, User::find($victim->user_id)->only(['email', 'status', 'is_admin']));
    }

    public function test_customer_cannot_target_the_platform_super_admin_through_sub_account_routes(): void
    {
        [$attacker] = $this->twoTenantCustomers();
        $this->authenticateAsCustomer($attacker, ['access_backend']);

        $admin = User::where('is_admin', true)->first();

        $this->postJson(route('customer.sub_accounts.destroy', $admin->uid))->assertStatus(404);
        $this->postJson(route('customer.sub_accounts.active', $admin->uid))->assertStatus(404);

        $this->assertNotNull(User::find($admin->id));
        $this->assertTrue((bool) User::find($admin->id)->status);
    }

    public function test_batch_action_only_touches_the_actors_own_sub_accounts(): void
    {
        [$attacker, $victim] = $this->twoTenantCustomers();
        $this->authenticateAsCustomer($attacker, ['access_backend']);

        $this->postJson(route('customer.sub_accounts.batch_action'), [
            'action' => 'delete',
            'ids' => [$victim->user->uid],
        ]);
        $this->postJson(route('customer.sub_accounts.batch_action'), [
            'action' => 'disable',
            'ids' => [$victim->user->uid],
        ]);

        $this->assertNotNull(User::find($victim->user_id));
        $this->assertTrue((bool) User::find($victim->user_id)->status);
    }

    public function test_sub_account_update_cannot_mass_assign_privilege_columns(): void
    {
        [$parent] = $this->twoTenantCustomers();
        $sub = new User([
            'first_name' => 'Sub',
            'last_name' => 'Account',
            'email' => 'sub' . uniqid() . '@example.test',
        ]);
        $sub->forceFill(['parent_id' => $parent->user_id, 'status' => true, 'is_admin' => false, 'is_customer' => true])->save();
        Customer::create(['user_id' => $sub->id]);

        $this->authenticateAsCustomer($parent, ['access_backend']);

        $this->post(route('customer.sub_accounts.update', $sub->uid), [
            'first_name' => 'Renamed',
            'email' => $sub->email,
            'is_admin' => 1,
            'is_customer' => 0,
            'status' => 0,
            'parent_id' => 1,
            'sms_unit' => 999999,
            'permissions' => ['access_backend' => 'access_backend'],
        ]);

        $fresh = $sub->fresh();
        $this->assertSame('Renamed', $fresh->first_name);
        $this->assertFalse((bool) $fresh->is_admin);
        $this->assertTrue((bool) $fresh->is_customer);
        $this->assertTrue((bool) $fresh->status);
        $this->assertSame((int) $parent->user_id, (int) $fresh->parent_id);
        $this->assertNotEquals(999999, $fresh->sms_unit);
    }

    // ------------------------------------------------------------------
    // Legacy registration-payment return routes deleted arbitrary users
    // ------------------------------------------------------------------

    public function test_guest_cannot_delete_or_upgrade_a_user_through_registration_payment_routes(): void
    {
        [, $victim] = $this->twoTenantCustomers();

        $this->assertContains($this->get(route('user.registers.payment_cancel', $victim->user->uid))->getStatusCode(), [302, 401]);
        $this->get(route('user.registers.payment_success', [
            'user' => $victim->user->uid,
            'plan' => 'nonexistent',
            'payment_method' => 'nonexistent',
        ]));

        $this->assertNotNull(User::find($victim->user_id));
    }

    public function test_signed_in_user_cannot_delete_another_user_through_payment_cancel(): void
    {
        [$attacker, $victim] = $this->twoTenantCustomers();
        $this->authenticateAsCustomer($attacker, ['access_backend']);

        $this->get(route('user.registers.payment_cancel', $victim->user->uid))->assertStatus(404);

        $this->assertNotNull(User::find($victim->user_id));
        $this->assertNotNull(User::find($attacker->user_id));
    }

    // ------------------------------------------------------------------
    // 2FA / login
    // ------------------------------------------------------------------

    public function test_get_request_cannot_switch_two_factor_off(): void
    {
        Notification::fake();

        [$tenant] = $this->twoTenantCustomers();
        $tenant->user->forceFill(['two_factor' => true])->save();
        $this->authenticateAsCustomer($tenant, ['access_backend']);

        $this->get(route('user.account.twofactor.auth', ['status' => 'disabled']));

        $this->assertTrue((bool) $tenant->user->fresh()->two_factor);
    }

    public function test_backup_code_is_single_use(): void
    {
        [$tenant] = $this->twoTenantCustomers();
        $tenant->user->forceFill(['two_factor_backup_code' => json_encode([111111, 222222])])->save();
        $this->authenticateAsCustomer($tenant, ['access_backend']);

        $this->post(route('verify.backup.store'), ['two_factor_code' => 111111]);

        $this->assertSame([222222], json_decode($tenant->user->fresh()->two_factor_backup_code, true));
    }

    public function test_login_is_throttled(): void
    {
        $this->ensureRequiredAppConfigRowsExist();

        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'])->getStatusCode();
        }

        // First attempts are ordinary failed logins; once the 10/min limit is hit the response changes.
        $this->assertNotSame($statuses[0], $statuses[11], 'login was never throttled: ' . implode(',', $statuses));
        $this->assertSame(1, count(array_unique(array_slice($statuses, 0, 10))));
    }

    public function test_a_user_disabled_mid_session_loses_the_session(): void
    {
        $this->ensureRequiredAppConfigRowsExist();

        [$tenant] = $this->twoTenantCustomers();
        $this->authenticateAsCustomer($tenant, ['access_backend']);

        $tenant->user->forceFill(['status' => false])->save();

        $this->get(route('user.home'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ------------------------------------------------------------------
    // Legacy api_token: a missing token resolved "api_token IS NULL"
    // ------------------------------------------------------------------

    public function test_missing_or_blank_api_token_never_resolves_a_user(): void
    {
        [$tenant] = $this->twoTenantCustomers();
        $this->assertNull($tenant->user->api_token);

        $this->assertNull(User::findByApiToken(null));
        $this->assertNull(User::findByApiToken(''));
        $this->assertNull(User::findByApiToken('   '));
        $this->assertNull(User::findByApiToken(['x']));

        $tenant->user->forceFill(['api_token' => 'tok-' . uniqid()])->save();
        $this->assertSame($tenant->user_id, User::findByApiToken($tenant->user->api_token)->id);

        $tenant->user->forceFill(['status' => false])->save();
        $this->assertNull(User::findByApiToken($tenant->user->api_token, true));
    }

    public function test_http_contacts_api_without_token_exposes_nothing(): void
    {
        [$tenant] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenant, 'Group');
        $this->createContact($tenant->user_id, $group->id, '15550009001');

        $response = $this->postJson('/api/http/contacts/' . $group->uid . '/all');

        $this->assertStringNotContainsString('15550009001', $response->getContent());
    }

    public function test_api_ownership_guard_rejects_foreign_group_and_contact(): void
    {
        [$a, $b] = $this->twoTenantCustomers();
        $groupA = $this->createGroup($a, 'A');
        $groupB = $this->createGroup($b, 'B');
        $contactA = $this->createContact($a->user_id, $groupA->id, '15550009101');
        $contactB = $this->createContact($b->user_id, $groupB->id, '15550009102');

        $guard = new class {
            use AuthorizesOwnedContactGroup;

            public function group(ContactGroups $g, User $u)
            {
                return $this->ownedGroupOrAbort($g, $u);
            }

            public function contact(ContactGroups $g, Contacts $c, User $u)
            {
                return $this->ownedContactOrAbort($g, $c, $u);
            }
        };

        $this->assertSame($groupA->id, $guard->group($groupA, $a->user)->id);
        $this->assertSame($contactA->id, $guard->contact($groupA, $contactA, $a->user)->id);

        foreach ([
            fn () => $guard->group($groupB, $a->user),
            fn () => $guard->contact($groupB, $contactB, $a->user),
            fn () => $guard->contact($groupA, $contactB, $a->user), // own group, foreign contact
        ] as $attack) {
            try {
                $attack();
                $this->fail('A foreign group/contact was accepted.');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_sanctum_contacts_api_rejects_a_foreign_group(): void
    {
        [$a, $b] = $this->twoTenantCustomers();
        $groupB = $this->createGroup($b, 'B');
        $this->createContact($b->user_id, $groupB->id, '15550009201');

        Sanctum::actingAs($a->user, ['view_contact', 'view_contact_group', 'delete_contact', 'delete_contact_group']);

        foreach ([
            $this->postJson('/api/v3/contacts/' . $groupB->uid . '/all'),
            $this->postJson('/api/v3/contacts/' . $groupB->uid . '/show'),
            $this->deleteJson('/api/v3/contacts/' . $groupB->uid),
        ] as $response) {
            $this->assertStringNotContainsString('15550009201', $response->getContent());
            $this->assertNotSame(200, $response->getStatusCode());
        }

        $this->assertNotNull(ContactGroups::find($groupB->id));
    }

    // ------------------------------------------------------------------
    // Contact groups: tenant FK injection, staged-file path
    // ------------------------------------------------------------------

    public function test_group_update_cannot_move_a_group_to_another_tenant(): void
    {
        [$a, $b] = $this->twoTenantCustomers();
        $group = $this->createGroup($a, 'Mine');

        app(ContactsRepository::class)->update($group, [
            'name' => 'Renamed',
            'customer_id' => $b->user_id,
            'business_id' => 999999,
            'sending_server' => 7,
            'batch_id' => 'x',
        ]);

        $fresh = $group->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame((int) $a->user_id, (int) $fresh->customer_id);
        $this->assertNull($fresh->business_id);
        $this->assertNull($fresh->sending_server);
    }

    public function test_import_steps_reject_client_supplied_paths(): void
    {
        [$a] = $this->twoTenantCustomers();
        $group = $this->createGroup($a, 'Import');

        foreach ([null, '', '/etc/passwd', 'C:\\Windows\\win.ini', '../../.env', base_path('.env'), 'import-zzz.csv', 'notes.txt'] as $bad) {
            try {
                $group->resolveStagedImportFile($bad);
                $this->fail('Accepted staged path: ' . var_export($bad, true));
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }

        $dir = $group->getImportTempDir();
        $name = 'import-' . uniqid() . '.csv';
        File::put($dir . DIRECTORY_SEPARATOR . $name, "phone\n15550000000\n");

        try {
            $this->assertSame(realpath($dir . DIRECTORY_SEPARATOR . $name), $group->resolveStagedImportFile($dir . DIRECTORY_SEPARATOR . $name));
        } finally {
            File::delete($dir . DIRECTORY_SEPARATOR . $name);
        }
    }

    // ------------------------------------------------------------------
    // Keywords / numbers / subscriptions: bound by uid, no ownership
    // ------------------------------------------------------------------

    public function test_customer_cannot_buy_or_open_another_tenants_keyword(): void
    {
        [$a, $b] = $this->twoTenantCustomers();
        $keyword = Keywords::create([
            'user_id' => $b->user_id,
            'title' => 'Victim',
            'keyword_name' => 'VICTIM' . random_int(100, 999),
            'status' => 'assigned',
            'price' => 0,
        ]);

        $this->authenticateAsCustomer($a, ['buy_keywords', 'update_keywords']);

        $this->get(route('customer.keywords.pay', $keyword->uid))->assertStatus(404);
        $this->get(route('customer.keywords.show', $keyword->uid))->assertStatus(404);

        $fresh = $keyword->fresh();
        $this->assertSame((int) $b->user_id, (int) $fresh->user_id);
        $this->assertSame('assigned', $fresh->status);
    }

    public function test_customer_cannot_cancel_or_read_another_tenants_subscription(): void
    {
        [$a, $b] = $this->twoTenantCustomers();
        // No Plan fixture is needed to prove ownership; the FK is irrelevant to the attack.
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        $subscription = Subscription::query()->forceCreate([
            'user_id' => $b->user_id,
            'plan_id' => 0,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        $this->authenticateAsCustomer($a, ['access_backend']);

        $this->postJson(route('customer.subscriptions.cancel', $subscription->uid))->assertStatus(404);
        $this->get(route('customer.subscriptions.logs', $subscription->uid))->assertStatus(404);

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Admin customer repository: no privilege columns from input
    // ------------------------------------------------------------------

    public function test_admin_customer_update_cannot_mass_assign_privilege_columns(): void
    {
        [$tenant] = $this->twoTenantCustomers();

        app(EloquentCustomerRepository::class)->update($tenant->user, [
            'first_name' => 'Renamed',
            'last_name' => 'Customer',
            'email' => $tenant->user->email,
            'timezone' => 'UTC',
            'locale' => 'en',
            'is_admin' => 1,
            'is_customer' => 0,
            'status' => 0,
            'sms_unit' => 5000,
        ]);

        $fresh = $tenant->user->fresh();
        $this->assertSame('Renamed', $fresh->first_name);
        $this->assertFalse((bool) $fresh->is_admin);
        $this->assertTrue((bool) $fresh->is_customer);
        $this->assertTrue((bool) $fresh->status);
    }

    // ------------------------------------------------------------------
    // Outbound webhook URL (SSRF) and secret serialization
    // ------------------------------------------------------------------

    public function test_webhook_url_must_be_public_https(): void
    {
        foreach ([
            'http://example.com/hook',
            'https://127.0.0.1/hook',
            'https://169.254.169.254/latest/meta-data',
            'https://10.0.0.5/hook',
            'https://192.168.1.10/hook',
            'https://[::1]/hook',
            'https://user:pw@8.8.8.8/hook',
            'ftp://8.8.8.8/hook',
            '',
            'not a url',
        ] as $bad) {
            $this->assertFalse(PublicHttpsUrl::isSafe($bad), 'Accepted: ' . $bad);
        }

        $this->assertTrue(PublicHttpsUrl::isSafe('https://8.8.8.8/hook'));
    }

    public function test_user_serialization_never_exposes_secrets(): void
    {
        [$tenant] = $this->twoTenantCustomers();
        $tenant->user->forceFill([
            'api_token' => 'secret-api-token',
            'two_factor_code' => 123456,
            'two_factor_backup_code' => json_encode([111111]),
        ])->save();

        $json = json_encode($tenant->user->fresh()->toArray());

        foreach (['secret-api-token', '123456', '111111', '"password"', 'remember_token'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    // ------------------------------------------------------------------
    // helpers (same shape as ContactsSecurityTest)
    // ------------------------------------------------------------------

    private function twoTenantCustomers(): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        return [$this->createCustomer(), $this->createCustomer()];
    }

    private function authenticateAsCustomer(Customer $customer, array $permissions): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    private function createGroup(Customer $customer, string $name): ContactGroups
    {
        return ContactGroups::create([
            'customer_id' => $customer->user_id,
            'name' => $name,
            'status' => true,
        ]);
    }

    private function createContact(int $customerId, int $groupId, string $phone): Contacts
    {
        return Contacts::create([
            'customer_id' => $customerId,
            'group_id' => $groupId,
            'phone' => $phone,
            'status' => 'subscribe',
        ]);
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
