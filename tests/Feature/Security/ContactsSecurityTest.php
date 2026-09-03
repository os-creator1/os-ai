<?php

namespace Tests\Feature\Security;

use App\Models\AppConfig;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * CRM Security Remediation Contract §17 item 2 — individual-subscriber
 * (Contacts) actions inside an owned ContactGroups row (§9), the
 * eleven newly-permission-gated actions reachable from this file's own
 * scope (§7), deleteContactField's second bound parameter (§8), and
 * Family B's materially-different batch response contract (§9/§14,
 * Correction Round 2 Correction B) — every composition of owned,
 * foreign, and nonexistent ids produces the identical existing success
 * response, since the controller discards every one of these repository
 * methods' own return values; safety instead comes from the already-owned
 * `group_id` scope excluding foreign/nonexistent rows from ever being
 * matched, mutated, or exposed.
 */
class ContactsSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

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
    // Own contact readable/mutable; foreign parent denied like nonexistent
    // -----------------------------------------------------------------

    public function test_update_contact_status_own_succeeds_foreign_parent_denied_like_nonexistent_parent(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $groupA = $this->createGroup($tenantA, 'Group A');
        $groupB = $this->createGroup($tenantB, 'Group B');
        $contactA = $this->createContact($tenantA->user_id, $groupA->id, '15550001001');

        $this->authenticateAsCustomer($tenantA, ['update_contact']);

        $ownResponse = $this->postJson(route('customer.contact.status', $groupA->uid), ['id' => $contactA->uid]);
        $ownResponse->assertOk();
        $ownResponse->assertJson(['status' => 'success']);
        $this->assertSame('unsubscribe', DB::table('contacts')->where('id', $contactA->id)->value('status'));

        $foreignParentResponse = $this->post(route('customer.contact.status', $groupB->uid), ['id' => $contactA->uid]);
        $nonexistentParentResponse = $this->post(route('customer.contact.status', 'nonexistent-' . uniqid()), ['id' => $contactA->uid]);

        $foreignParentResponse->assertStatus(404);
        $nonexistentParentResponse->assertStatus(404);
        $this->assertSame($foreignParentResponse->getStatusCode(), $nonexistentParentResponse->getStatusCode());
    }

    public function test_delete_contact_denied_when_parent_group_foreign_succeeds_on_own(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $groupA = $this->createGroup($tenantA, 'Group A');
        $groupB = $this->createGroup($tenantB, 'Group B');
        $contactA = $this->createContact($tenantA->user_id, $groupA->id, '15550001002');

        $this->authenticateAsCustomer($tenantA, ['delete_contact']);

        $foreignResponse = $this->post(route('customer.contact.delete', $groupB->uid), ['id' => $contactA->uid]);
        $foreignResponse->assertStatus(404);
        $this->assertSame(1, DB::table('contacts')->where('id', $contactA->id)->count());

        $ownResponse = $this->postJson(route('customer.contact.delete', $groupA->uid), ['id' => $contactA->uid]);
        $ownResponse->assertOk();
        $ownResponse->assertJson(['status' => 'success']);
        $this->assertSame(0, DB::table('contacts')->where('id', $contactA->id)->count());
    }

    public function test_edit_and_update_contact_cannot_expose_or_mutate_foreign_contact(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $groupA = $this->createGroup($tenantA, 'Group A');
        $groupB = $this->createGroup($tenantB, 'Group B');
        $contactB = $this->createContact($tenantB->user_id, $groupB->id, '15550001003');

        $this->authenticateAsCustomer($tenantA, ['update_contact']);

        // groupA is owned by tenant A, but contactB belongs to groupB — the
        // subscriber lookup itself is scoped to the resolved (owned) group,
        // so a foreign contact_id inside an owned group context must not
        // resolve to another tenant's row.
        $editResponse = $this->get(route('customer.contact.edit', $groupA->uid) . '?contact_id=' . $contactB->uid);
        $editResponse->assertRedirect();
        $editResponse->assertSessionHas('status', 'error');

        $updateResponse = $this->post(route('customer.contact.update', $groupA->uid), [
            'contact_id' => $contactB->uid,
            'PHONE' => '15550001003',
        ]);
        $updateResponse->assertRedirect();
        $updateResponse->assertSessionHas('status', 'error');
        $this->assertSame(1, DB::table('contacts')->where('id', $contactB->id)->where('group_id', $groupB->id)->count());
    }

    // -----------------------------------------------------------------
    // create/store cannot target a foreign group
    // -----------------------------------------------------------------

    public function test_create_contact_and_store_contact_cannot_target_foreign_group(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $groupB = $this->createGroup($tenantB, 'Foreign Group');

        $this->authenticateAsCustomer($tenantA, ['create_contact']);

        $createResponse = $this->get(route('customer.contact.create', $groupB->uid));
        $createResponse->assertStatus(404);

        $storeResponse = $this->post(route('customer.contact.store', $groupB->uid), ['PHONE' => '15550002001']);
        $storeResponse->assertStatus(404);
        $this->assertSame(0, DB::table('contacts')->where('phone', '15550002001')->count());
    }

    /**
     * §9 Correction J — storeImportContact()'s existing
     * 'customer_id' => Auth::user()->id line is left completely unchanged;
     * the historical customer_id/group_id mismatch is closed structurally
     * by §8's binding fix alone, since $contact is now guaranteed owned.
     * This asserts the resulting invariant, not a differing-values case
     * (which is now structurally impossible to produce).
     */
    public function test_store_import_contact_produces_customer_id_matching_the_owned_group(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $groupA = $this->createGroup($tenantA, 'Import Group');

        $this->authenticateAsCustomer($tenantA, ['create_contact']);

        $response = $this->post(route('customer.contact.import', $groupA->uid), [
            'recipients' => '12025550101;12025550102',
            'delimiter' => ';',
        ]);

        $response->assertRedirect();

        $imported = DB::table('contacts')->where('group_id', $groupA->id)->get();
        $this->assertGreaterThan(0, $imported->count());
        foreach ($imported as $row) {
            $this->assertSame($groupA->customer_id, $row->customer_id);
            $this->assertSame($tenantA->user_id, $row->customer_id);
        }
    }

    // -----------------------------------------------------------------
    // Eleven previously-ungated actions (§7) reachable from this file
    // -----------------------------------------------------------------

    public function test_import_process_data_denies_actor_missing_permission(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'Import Group');
        $this->authenticateAsCustomer($tenantA, []);

        $response = $this->post(route('customer.contact.import_process', $group->uid), ['fields' => ['phone']]);

        $response->assertStatus(401);
    }

    public function test_opt_in_keyword_denies_actor_missing_permission(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'Group A');
        $this->authenticateAsCustomer($tenantA, []);

        $response = $this->post(route('customer.contacts.optin_keyword', $group->uid), ['keyword_name' => 'HELLO']);

        $response->assertStatus(401);
    }

    public function test_opt_out_keyword_denies_actor_missing_permission(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'Group A');
        $this->authenticateAsCustomer($tenantA, []);

        $response = $this->post(route('customer.contacts.optout_keyword', $group->uid), ['keyword_name' => 'BYE']);

        $response->assertStatus(401);
    }

    public function test_delete_opt_in_keyword_denies_actor_missing_permission(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'Group A');
        $this->authenticateAsCustomer($tenantA, []);

        $response = $this->post(route('customer.contacts.delete_optin_keyword', $group->uid), ['id' => 'whatever']);

        $response->assertStatus(401);
    }

    public function test_delete_opt_out_keyword_denies_actor_missing_permission(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'Group A');
        $this->authenticateAsCustomer($tenantA, []);

        $response = $this->post(route('customer.contacts.delete_optout_keyword', $group->uid), ['id' => 'whatever']);

        $response->assertStatus(401);
    }

    public function test_import_mapping_denies_actor_missing_permission(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'Group A');
        $this->authenticateAsCustomer($tenantA, []);

        $response = $this->post(route('customer.contacts.import-mapping', $group->uid), ['filepath' => 'whatever.csv']);

        $response->assertStatus(401);
    }

    public function test_import_run_denies_actor_missing_permission(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'Group A');
        $this->authenticateAsCustomer($tenantA, []);

        $response = $this->post(route('customer.contact.import-run', $group->uid), ['filepath' => 'whatever.csv']);

        $response->assertStatus(401);
    }

    public function test_import_validate_denies_actor_missing_permission(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'Group A');
        $this->authenticateAsCustomer($tenantA, []);

        $response = $this->post(route('customer.contact.import-validate', $group->uid), ['mapping' => []]);

        $response->assertStatus(401);
    }

    public function test_count_contacts_denies_actor_missing_permission(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $this->authenticateAsCustomer($tenantA, []);

        $response = $this->post(route('customer.contacts.count_contact'), ['contact_group_ids' => [1]]);

        $response->assertStatus(401);
    }

    /**
     * CRM Security count isolation correction. The campaign builder's own
     * page script submits numeric ContactGroups.id values (not uid) as
     * contact_group_ids to this endpoint. Before the correction, the query
     * counting subscribed Contacts had no customer_id boundary, letting any
     * actor holding view_contact submit a foreign tenant's numeric group id
     * and learn that tenant's subscribed-contact count. Own+foreign and
     * own+nonexistent must both silently collapse to the own-only count,
     * and foreign-only/nonexistent-only must be exactly indistinguishable.
     */
    public function test_count_contacts_tenant_isolation(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();

        $groupA = $this->createGroup($tenantA, 'Tenant A Group');
        $groupB = $this->createGroup($tenantB, 'Tenant B Group');

        $this->createContact($tenantA->user_id, $groupA->id, '15550001001');
        $this->createContact($tenantA->user_id, $groupA->id, '15550001002');
        $this->createContact($tenantB->user_id, $groupB->id, '15550002001');

        $nonexistentGroupId = ContactGroups::max('id') + 1000;

        $this->authenticateAsCustomer($tenantA, ['view_contact']);

        $ownOnlyResponse = $this->post(route('customer.contacts.count_contact'), ['contact_group_ids' => [$groupA->id]]);
        $ownOnlyResponse->assertOk();
        $this->assertSame('2', $ownOnlyResponse->getContent());

        $ownForeignResponse = $this->post(route('customer.contacts.count_contact'), ['contact_group_ids' => [$groupA->id, $groupB->id]]);
        $ownForeignResponse->assertOk();
        $this->assertSame($ownOnlyResponse->getContent(), $ownForeignResponse->getContent());

        $ownNonexistentResponse = $this->post(route('customer.contacts.count_contact'), ['contact_group_ids' => [$groupA->id, $nonexistentGroupId]]);
        $ownNonexistentResponse->assertOk();
        $this->assertSame($ownOnlyResponse->getContent(), $ownNonexistentResponse->getContent());

        $foreignOnlyResponse = $this->post(route('customer.contacts.count_contact'), ['contact_group_ids' => [$groupB->id]]);
        $foreignOnlyResponse->assertOk();
        $this->assertSame('0', $foreignOnlyResponse->getContent());

        $nonexistentOnlyResponse = $this->post(route('customer.contacts.count_contact'), ['contact_group_ids' => [$nonexistentGroupId]]);
        $nonexistentOnlyResponse->assertOk();
        $this->assertSame('0', $nonexistentOnlyResponse->getContent());

        $this->assertSame($foreignOnlyResponse->getStatusCode(), $nonexistentOnlyResponse->getStatusCode());
        $this->assertSame($foreignOnlyResponse->getContent(), $nonexistentOnlyResponse->getContent());

        $this->assertSame(1, DB::table('contacts')->where('group_id', $groupB->id)->where('status', 'subscribe')->count());
    }

    public function test_download_failed_contacts_denies_actor_missing_permission(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $group = $this->createGroup($tenantA, 'Group A');
        $this->authenticateAsCustomer($tenantA, []);

        $response = $this->get(route('customer.contacts.download_failed', ['contact' => $group->uid, 'job_id' => 1]));

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // deleteContactField's second bound parameter (§8)
    // -----------------------------------------------------------------

    public function test_delete_contact_field_foreign_and_nonexistent_field_denied_identically_own_succeeds(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $groupA = $this->createGroup($tenantA, 'Group A');
        $groupB = $this->createGroup($tenantB, 'Group B');

        $ownField = ContactGroupFields::create([
            'contact_group_id' => $groupA->id,
            'label' => 'Own Custom Field',
            'type' => 'text',
            'tag' => 'OWN_FIELD',
            'required' => false,
            'visible' => true,
        ]);
        $foreignField = ContactGroupFields::where('contact_group_id', $groupB->id)->where('tag', 'FIRST_NAME')->firstOrFail();

        $this->authenticateAsCustomer($tenantA, ['view_contact']);

        $foreignFieldResponse = $this->post(route('customer.contact.delete-contact-field', ['contact' => $groupA->uid, 'field_id' => $foreignField->uid]));
        $nonexistentFieldResponse = $this->post(route('customer.contact.delete-contact-field', ['contact' => $groupA->uid, 'field_id' => 'nonexistent-' . uniqid()]));

        $foreignFieldResponse->assertStatus(404);
        $nonexistentFieldResponse->assertStatus(404);
        $this->assertSame(1, DB::table('contact_group_fields')->where('id', $foreignField->id)->count());

        $ownFieldResponse = $this->postJson(route('customer.contact.delete-contact-field', ['contact' => $groupA->uid, 'field_id' => $ownField->uid]));
        $ownFieldResponse->assertOk();
        $ownFieldResponse->assertJson(['status' => 'success']);
        $this->assertSame(0, DB::table('contact_group_fields')->where('id', $ownField->id)->count());
    }

    // -----------------------------------------------------------------
    // Family B batch response contract (§9/§14, Correction Round 2 B)
    // -----------------------------------------------------------------

    public function test_family_b_batch_destroy_always_succeeds_and_never_mutates_a_victim_row(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $groupA = $this->createGroup($tenantA, 'Group A');
        $groupB = $this->createGroup($tenantB, 'Group B');
        $ownedContact = $this->createContact($tenantA->user_id, $groupA->id, '15550004001');
        $foreignContact = $this->createContact($tenantB->user_id, $groupB->id, '15550004002');

        $this->authenticateAsCustomer($tenantA, ['delete_contact']);

        // owned + foreign: identical existing success response, foreign row untouched.
        $response = $this->postJson(route('customer.contact.batch_action', $groupA->uid), [
            'action' => 'destroy',
            'ids' => [$ownedContact->uid, $foreignContact->uid],
        ]);
        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        $this->assertSame(0, DB::table('contacts')->where('id', $ownedContact->id)->count());
        $this->assertSame(1, DB::table('contacts')->where('id', $foreignContact->id)->count());

        // foreign-only: identical existing success response, zero mutation.
        $foreignOnlyResponse = $this->postJson(route('customer.contact.batch_action', $groupA->uid), [
            'action' => 'destroy',
            'ids' => [$foreignContact->uid],
        ]);
        $foreignOnlyResponse->assertOk();
        $foreignOnlyResponse->assertJson(['status' => 'success']);
        $this->assertSame(1, DB::table('contacts')->where('id', $foreignContact->id)->count());

        // nonexistent-only: identical existing success response.
        $nonexistentOnlyResponse = $this->postJson(route('customer.contact.batch_action', $groupA->uid), [
            'action' => 'destroy',
            'ids' => ['nonexistent-' . uniqid()],
        ]);
        $nonexistentOnlyResponse->assertOk();
        $nonexistentOnlyResponse->assertJson(['status' => 'success']);
        $this->assertSame($foreignOnlyResponse->getContent(), $nonexistentOnlyResponse->getContent());
    }

    public function test_family_b_batch_subscribe_unsubscribe_and_move_never_mutate_a_victim_row(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $groupA = $this->createGroup($tenantA, 'Group A');
        $groupB = $this->createGroup($tenantB, 'Group B');
        $foreignContact = $this->createContact($tenantB->user_id, $groupB->id, '15550004101');

        $this->authenticateAsCustomer($tenantA, ['update_contact']);

        $subscribeResponse = $this->postJson(route('customer.contact.batch_action', $groupA->uid), [
            'action' => 'subscribe',
            'ids' => [$foreignContact->uid],
        ]);
        $subscribeResponse->assertOk();
        $subscribeResponse->assertJson(['status' => 'success']);

        $unsubscribeResponse = $this->postJson(route('customer.contact.batch_action', $groupA->uid), [
            'action' => 'unsubscribe',
            'ids' => [$foreignContact->uid],
        ]);
        $unsubscribeResponse->assertOk();
        $unsubscribeResponse->assertJson(['status' => 'success']);

        $this->assertSame($groupB->id, DB::table('contacts')->where('id', $foreignContact->id)->value('group_id'));
        $this->assertSame('subscribe', DB::table('contacts')->where('id', $foreignContact->id)->value('status'));

        $ownTargetGroup = $this->createGroup($tenantA, 'Own Target');
        $moveResponse = $this->postJson(route('customer.contact.batch_action', $groupA->uid), [
            'action' => 'move',
            'ids' => [$foreignContact->uid],
            'target_group' => $ownTargetGroup->uid,
        ]);
        $moveResponse->assertOk();
        $moveResponse->assertJson(['status' => 'success']);
        $this->assertSame($groupB->id, DB::table('contacts')->where('id', $foreignContact->id)->value('group_id'));
        $this->assertSame(0, DB::table('contacts')->where('group_id', $ownTargetGroup->id)->count());
    }

    public function test_family_b_batch_copy_owned_source_with_foreign_and_nonexistent_ids_copies_zero_rows_owned_still_works(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $sourceGroup = $this->createGroup($tenantA, 'Source Group');
        $targetGroup = $this->createGroup($tenantA, 'Target Group');
        $foreignGroup = $this->createGroup($tenantB, 'Foreign Group');
        $ownedContact = $this->createContact($tenantA->user_id, $sourceGroup->id, '15550004201');
        $foreignContact = $this->createContact($tenantB->user_id, $foreignGroup->id, '15550004202');

        $this->authenticateAsCustomer($tenantA, ['update_contact']);

        $response = $this->postJson(route('customer.contact.batch_action', $sourceGroup->uid), [
            'action' => 'copy',
            'ids' => [$foreignContact->uid, 'nonexistent-' . uniqid()],
            'target_group' => $targetGroup->uid,
        ]);
        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        $this->assertSame(0, DB::table('contacts')->where('group_id', $targetGroup->id)->count());

        $ownedResponse = $this->postJson(route('customer.contact.batch_action', $sourceGroup->uid), [
            'action' => 'copy',
            'ids' => [$ownedContact->uid],
            'target_group' => $targetGroup->uid,
        ]);
        $ownedResponse->assertOk();
        $ownedResponse->assertJson(['status' => 'success']);
        $this->assertSame(1, DB::table('contacts')->where('group_id', $targetGroup->id)->count());
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
