<?php

namespace Tests\Feature\Security;

use App\Models\AppConfig;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\ContactGroupsOptinKeywords;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Keywords;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Routing\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * CRM Security Remediation Contract §17 item 1 — Customer\ContactsController's
 * ContactGroups-bound actions (§8/§9's raw-string ownership-resolution
 * architecture) plus the UpdateContactGroup FormRequest compatibility fix
 * (§8 Correction C) and Family A's batch-response contract (§9/§14).
 *
 * Every foreign/nonexistent assertion below exercises the real application
 * rather than assuming a status code — see debug calibration in the
 * contract's own §3.8/§17 discussion: a plain (non-XHR) request to any of
 * these single-record actions renders this app's own errors.404 HTML view
 * (HTTP 404) since app.env=testing (not local) and wantsJson() is false for
 * a request without an Accept: application/json header; a zero-owned batch
 * action instead reaches GeneralException's own unconditional JSON branch
 * in Handler.php (HTTP 200, {"status":"error",...}), independent of
 * wantsJson().
 */
class ContactGroupsSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    /**
     * EloquentAccountRepository::hasPermission() unconditionally grants
     * every permission to the account whose id === 1. Consuming id 1 with a
     * throwaway user before any test's own actor is created keeps every
     * permission assertion in this file meaningful.
     */
    protected function setUp(): void
    {
        parent::setUp();

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

    // -----------------------------------------------------------------
    // show() — own readable, foreign/nonexistent identically denied
    // -----------------------------------------------------------------

    public function test_show_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $groupA = $this->createGroup($tenantA, 'Own Group');
        $groupB = $this->createGroup($tenantB, 'Foreign Group');

        $this->authenticateAsCustomer($tenantA, ['view_contact_group']);

        $ownResponse = $this->get(route('customer.contacts.show', $groupA->uid));
        $ownResponse->assertOk();

        $foreignResponse = $this->get(route('customer.contacts.show', $groupB->uid));
        $nonexistentResponse = $this->get(route('customer.contacts.show', 'nonexistent-uid-' . uniqid()));

        $foreignResponse->assertStatus(404);
        $nonexistentResponse->assertStatus(404);
        $this->assertSame(
            $foreignResponse->getStatusCode(),
            $nonexistentResponse->getStatusCode(),
            'Foreign and nonexistent group uids must produce the identical response (contract §8/§9/§14).'
        );
    }

    // -----------------------------------------------------------------
    // activeToggle() — denied on foreign, victim unmutated, succeeds on own
    // -----------------------------------------------------------------

    public function test_active_toggle_denied_on_foreign_leaves_victim_untouched_succeeds_on_own(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $groupA = $this->createGroup($tenantA, 'Own Group', ['status' => true]);
        $groupB = $this->createGroup($tenantB, 'Foreign Group', ['status' => true]);

        $this->authenticateAsCustomer($tenantA, ['update_contact_group']);

        $foreignResponse = $this->post(route('customer.contacts.active', $groupB->uid));
        $foreignResponse->assertStatus(404);
        $this->assertSame(1, DB::table('contact_groups')->where('id', $groupB->id)->where('status', true)->count());

        $ownResponse = $this->post(route('customer.contacts.active', $groupA->uid));
        $ownResponse->assertOk();
        $this->assertSame(1, DB::table('contact_groups')->where('id', $groupA->id)->where('status', false)->count());
    }

    // -----------------------------------------------------------------
    // copy() — denied on foreign source, succeeds on own
    // -----------------------------------------------------------------

    public function test_copy_denied_on_foreign_source_succeeds_on_own(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $this->giveUnlimitedActiveSubscription($tenantA->user);
        $groupA = $this->createGroup($tenantA, 'Own Group');
        $groupB = $this->createGroup($tenantB, 'Foreign Group');

        $this->authenticateAsCustomer($tenantA, ['create_contact_group']);

        $foreignResponse = $this->post(route('customer.contacts.copy', $groupB->uid), ['group_name' => 'Stolen Copy']);
        $foreignResponse->assertStatus(404);
        $this->assertSame(0, DB::table('contact_groups')->where('name', 'Stolen Copy')->count());

        $ownResponse = $this->post(route('customer.contacts.copy', $groupA->uid), ['group_name' => 'My Copy']);
        $ownResponse->assertOk();
        $this->assertSame(1, DB::table('contact_groups')->where('name', 'My Copy')->where('customer_id', $tenantA->user_id)->count());
    }

    // -----------------------------------------------------------------
    // destroy() — denied on foreign, victim unmutated, succeeds on own
    // -----------------------------------------------------------------

    public function test_destroy_denied_on_foreign_leaves_victim_untouched_succeeds_on_own(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $groupA = $this->createGroup($tenantA, 'Own Group');
        $groupB = $this->createGroup($tenantB, 'Foreign Group');

        $this->authenticateAsCustomer($tenantA, ['delete_contact_group']);

        $foreignResponse = $this->delete(route('customer.contacts.destroy', $groupB->uid));
        $foreignResponse->assertStatus(404);
        $this->assertSame(1, DB::table('contact_groups')->where('id', $groupB->id)->count());

        $ownResponse = $this->delete(route('customer.contacts.destroy', $groupA->uid));
        $ownResponse->assertOk();
        $this->assertSame(0, DB::table('contact_groups')->where('id', $groupA->id)->count());
    }

    // -----------------------------------------------------------------
    // update() + UpdateContactGroup FormRequest compatibility (Correction C)
    // -----------------------------------------------------------------

    public function test_update_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $groupA = $this->createGroup($tenantA, 'Own Group');
        $groupB = $this->createGroup($tenantB, 'Foreign Group');

        $this->authenticateAsCustomer($tenantA, ['update_contact_group']);

        $ownResponse = $this->put(route('customer.contacts.update', $groupA->uid), ['name' => 'Renamed Own Group']);
        $ownResponse->assertRedirect();
        $this->assertSame(1, DB::table('contact_groups')->where('id', $groupA->id)->where('name', 'Renamed Own Group')->count());

        $foreignResponse = $this->put(route('customer.contacts.update', $groupB->uid), ['name' => 'Hijacked Name']);
        $nonexistentResponse = $this->put(route('customer.contacts.update', 'nonexistent-uid-' . uniqid()), ['name' => 'Hijacked Name']);

        $foreignResponse->assertStatus(404);
        $nonexistentResponse->assertStatus(404);
        $this->assertSame($foreignResponse->getStatusCode(), $nonexistentResponse->getStatusCode());
        $this->assertSame(0, DB::table('contact_groups')->where('id', $groupB->id)->where('name', 'Hijacked Name')->count());
    }

    public function test_update_contact_group_uniqueness_validation_still_functions(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $this->createGroup($tenantA, 'Existing Name');
        $groupToRename = $this->createGroup($tenantA, 'Group To Rename');

        $this->authenticateAsCustomer($tenantA, ['update_contact_group']);

        $response = $this->put(route('customer.contacts.update', $groupToRename->uid), ['name' => 'Existing Name']);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, DB::table('contact_groups')->where('id', $groupToRename->id)->where('name', 'Group To Rename')->count());
    }

    /**
     * §8 Correction C — the API route's own model-bound
     * UpdateContactGroup::rules() branch must still work: when
     * $this->route('contact') is already a ContactGroups instance (as it
     * remains on the separate, out-of-scope API route), rules() must not
     * error and must return the same shape it always has. Exercised
     * directly against the FormRequest, not via the deferred API
     * controller itself, since that controller's own body is
     * out-of-scope/unexercised by this remediation.
     */
    public function test_update_contact_group_form_request_model_bound_branch_does_not_error(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'API Bound Group');

        $this->authenticateAsCustomer($tenantA, ['update_contact_group']);

        $request = new \App\Http\Requests\Contacts\UpdateContactGroup();
        $request->setUserResolver(fn () => $tenantA->user);
        $request->merge(['name' => 'Renamed Via API Shape']);

        $route = new Route('PUT', '/fake/{contact}', []);
        $route->bind($request);
        $route->setParameter('contact', $group);
        $request->setRouteResolver(fn () => $route);

        $this->assertTrue($request->authorize(), 'An actor/session holding update_contact_group must authorize this request.');

        $rules = $request->rules();
        $this->assertArrayHasKey('name', $rules);

        $messages = $request->messages();
        $this->assertArrayHasKey('name.unique', $messages);
        $this->assertSame(
            __('locale.contacts.contact_group_available', ['name' => 'Renamed Via API Shape']),
            $messages['name.unique']
        );
    }

    // -----------------------------------------------------------------
    // Family A batch response contract (§9/§14, Correction Round 2 B)
    // -----------------------------------------------------------------

    public function test_batch_destroy_family_a_five_scenario_table(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $this->authenticateAsCustomer($tenantA, ['delete_contact_group']);

        // owned + foreign: owned deleted, foreign untouched, existing success response.
        $owned1 = $this->createGroup($tenantA, 'Owned 1');
        $foreign1 = $this->createGroup($tenantB, 'Foreign 1');
        $response = $this->postJson(route('customer.contacts.batch_action'), ['action' => 'destroy', 'ids' => [$owned1->uid, $foreign1->uid]]);
        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        $this->assertSame(0, DB::table('contact_groups')->where('id', $owned1->id)->count());
        $this->assertSame(1, DB::table('contact_groups')->where('id', $foreign1->id)->count());

        // owned + nonexistent: identical existing success response.
        $owned2 = $this->createGroup($tenantA, 'Owned 2');
        $response = $this->postJson(route('customer.contacts.batch_action'), ['action' => 'destroy', 'ids' => [$owned2->uid, 'nonexistent-' . uniqid()]]);
        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        $this->assertSame(0, DB::table('contact_groups')->where('id', $owned2->id)->count());

        // foreign-only: GeneralException-driven error response.
        $foreign2 = $this->createGroup($tenantB, 'Foreign 2');
        $foreignOnlyResponse = $this->postJson(route('customer.contacts.batch_action'), ['action' => 'destroy', 'ids' => [$foreign2->uid]]);
        $foreignOnlyResponse->assertOk();
        $foreignOnlyResponse->assertJson(['status' => 'error']);
        $this->assertSame(1, DB::table('contact_groups')->where('id', $foreign2->id)->count());

        // nonexistent-only: identical error response to foreign-only.
        $nonexistentOnlyResponse = $this->postJson(route('customer.contacts.batch_action'), ['action' => 'destroy', 'ids' => ['nonexistent-' . uniqid()]]);
        $nonexistentOnlyResponse->assertOk();
        $nonexistentOnlyResponse->assertJson(['status' => 'error']);
        $this->assertSame($foreignOnlyResponse->getContent(), $nonexistentOnlyResponse->getContent());

        // empty submitted list: identical error response.
        $emptyListResponse = $this->postJson(route('customer.contacts.batch_action'), ['action' => 'destroy', 'ids' => []]);
        $emptyListResponse->assertOk();
        $emptyListResponse->assertJson(['status' => 'error']);
    }

    public function test_batch_enable_and_disable_reuse_the_same_family_a_ownership_prefilter(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $this->authenticateAsCustomer($tenantA, ['update_contact_group']);

        $owned = $this->createGroup($tenantA, 'Owned', ['status' => false]);
        $foreign = $this->createGroup($tenantB, 'Foreign', ['status' => false]);

        $response = $this->postJson(route('customer.contacts.batch_action'), ['action' => 'enable', 'ids' => [$owned->uid, $foreign->uid]]);
        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        $this->assertSame(1, DB::table('contact_groups')->where('id', $owned->id)->where('status', true)->count());
        $this->assertSame(1, DB::table('contact_groups')->where('id', $foreign->id)->where('status', false)->count());

        $foreignOnlyResponse = $this->postJson(route('customer.contacts.batch_action'), ['action' => 'disable', 'ids' => [$foreign->uid]]);
        $foreignOnlyResponse->assertOk();
        $foreignOnlyResponse->assertJson(['status' => 'error']);
        $this->assertSame(1, DB::table('contact_groups')->where('id', $foreign->id)->where('status', false)->count());
    }

    // -----------------------------------------------------------------
    // target_group ownership fix (§8) — batchActionContact copy/move
    // -----------------------------------------------------------------

    public function test_batch_action_contact_target_group_foreign_and_nonexistent_denied_identically_own_succeeds(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $sourceGroup = $this->createGroup($tenantA, 'Source Group');
        $ownTargetGroup = $this->createGroup($tenantA, 'Own Target Group');
        $foreignTargetGroup = $this->createGroup($tenantB, 'Foreign Target Group');
        $contact = $this->createContact($tenantA->user_id, $sourceGroup->id, '15550001111');

        $this->authenticateAsCustomer($tenantA, ['update_contact']);

        $foreignResponse = $this->postJson(route('customer.contact.batch_action', $sourceGroup->uid), [
            'action' => 'copy',
            'ids' => [$contact->uid],
            'target_group' => $foreignTargetGroup->uid,
        ]);
        $nonexistentResponse = $this->postJson(route('customer.contact.batch_action', $sourceGroup->uid), [
            'action' => 'copy',
            'ids' => [$contact->uid],
            'target_group' => 'nonexistent-' . uniqid(),
        ]);

        $foreignResponse->assertOk();
        $foreignResponse->assertJson(['status' => 'error']);
        $nonexistentResponse->assertOk();
        $nonexistentResponse->assertJson(['status' => 'error']);
        $this->assertSame($foreignResponse->getContent(), $nonexistentResponse->getContent());
        $this->assertSame(0, DB::table('contacts')->where('group_id', $foreignTargetGroup->id)->count());

        $ownResponse = $this->postJson(route('customer.contact.batch_action', $sourceGroup->uid), [
            'action' => 'copy',
            'ids' => [$contact->uid],
            'target_group' => $ownTargetGroup->uid,
        ]);
        $ownResponse->assertOk();
        $ownResponse->assertJson(['status' => 'success']);
        $this->assertSame(1, DB::table('contacts')->where('group_id', $ownTargetGroup->id)->count());
    }

    // -----------------------------------------------------------------
    // message_form / sms_form dynamic-attribute whitelist (§8)
    // -----------------------------------------------------------------

    public function test_message_form_whitelist_rejects_arbitrary_attribute_accepts_legitimate_values(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'Message Group');

        $this->authenticateAsCustomer($tenantA, ['update_contact_group']);

        $maliciousResponse = $this->post(route('customer.contacts.message', $group->uid), [
            'message_form' => 'customer_id',
            'message' => 'attacker-controlled',
        ]);
        $maliciousResponse->assertRedirect();
        $maliciousResponse->assertSessionHas('status', 'error');
        $this->assertSame($tenantA->user_id, DB::table('contact_groups')->where('id', $group->id)->value('customer_id'));

        foreach (['signup_sms', 'welcome_sms', 'unsubscribe_sms'] as $legitimateField) {
            $legitimateResponse = $this->post(route('customer.contacts.message', $group->uid), [
                'message_form' => $legitimateField,
                'message' => 'Hello from ' . $legitimateField,
            ]);
            $legitimateResponse->assertRedirect();
            $this->assertSame('Hello from ' . $legitimateField, DB::table('contact_groups')->where('id', $group->id)->value($legitimateField));
        }
    }

    public function test_get_message_form_whitelist_rejects_arbitrary_attribute(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'Message Group', ['welcome_sms' => 'Welcome text']);

        $this->authenticateAsCustomer($tenantA, ['update_contact_group']);

        $maliciousResponse = $this->postJson(route('customer.contacts.message_form', $group->uid), ['sms_form' => 'customer_id']);
        $maliciousResponse->assertOk();
        $maliciousResponse->assertJson(['status' => 'error']);

        $legitimateResponse = $this->postJson(route('customer.contacts.message_form', $group->uid), ['sms_form' => 'welcome_sms']);
        $legitimateResponse->assertOk();
        $legitimateResponse->assertJson(['status' => 'success', 'message' => 'Welcome text']);
    }

    /**
     * §7 — getMessageForm() is one of the eleven previously-ungated actions
     * and now requires update_contact_group.
     */
    public function test_get_message_form_denies_actor_missing_permission(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'Message Group', ['welcome_sms' => 'Welcome text']);

        $this->authenticateAsCustomer($tenantA, []);

        $response = $this->post(route('customer.contacts.message_form', $group->uid), ['sms_form' => 'welcome_sms']);

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // XSS #1 regression (§3.6/§12) — Js::from() encoding
    // -----------------------------------------------------------------

    public function test_xss_1_malicious_group_and_keyword_names_are_safely_encoded(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $viewedGroup = $this->createGroup($tenantA, 'Viewed Group');
        $maliciousPayload = '<img src=x onerror=alert(1)>';
        $this->createGroup($tenantA, $maliciousPayload);

        Keywords::create([
            'user_id' => $tenantA->user_id,
            'keyword_name' => $maliciousPayload,
            'status' => 'assigned',
        ]);

        $this->authenticateAsCustomer($tenantA, ['view_contact_group']);

        $response = $this->get(route('customer.contacts.show', $viewedGroup->uid));

        $response->assertOk();
        $response->assertDontSee($maliciousPayload, false);
        $response->assertSee('<img', false);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Customer}
     */
    private function twoTenantCustomers(): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        $tenantA = $this->createCustomer();
        $tenantB = $this->createCustomer();

        return [$tenantA, $tenantB];
    }

    private function authenticateAsCustomer(Customer $customer, array $permissions): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    private function createGroup(Customer $customer, string $name, array $overrides = []): ContactGroups
    {
        return ContactGroups::create(array_merge([
            'customer_id' => $customer->user_id,
            'name' => $name,
            'status' => true,
        ], $overrides));
    }

    private function createContact(int $customerId, int $groupId, string $phone): \App\Models\Contacts
    {
        return \App\Models\Contacts::create([
            'customer_id' => $customerId,
            'group_id' => $groupId,
            'phone' => $phone,
            'status' => 'subscribe',
        ]);
    }

    /**
     * copy()'s own pre-existing (unrelated to this remediation) list/subscriber
     * quota checks require an active Plan/Subscription — without one,
     * Customer::getOptions() returns [] and getOption('list_max') is null,
     * which the controller's own `$list_max != '-1' && $list_max < $totalData`
     * check treats as an exceeded quota. Plan::getOptions() falls back to
     * Plan::defaultOptions() (list_max/subscriber_max/subscriber_per_list_max
     * all '-1', i.e. unlimited) whenever the plan's own `options` column is
     * empty, so a minimal Plan with no `options` payload is sufficient.
     */
    private function giveUnlimitedActiveSubscription(User $user): void
    {
        $currency = Currency::query()->where('code', 'CGT')->first()
            ?? Currency::create(['name' => 'CRM Test Dollar', 'code' => 'CGT', 'format' => '$', 'status' => true]);

        $plan = Plan::create([
            'user_id' => $user->id,
            'name' => 'CRM Test Plan',
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'currency_id' => $currency->id,
            'options' => json_encode([]),
            'status' => true,
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
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
