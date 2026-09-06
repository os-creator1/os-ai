<?php

namespace Tests\Feature\Automations;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationExecutionStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\Business\BusinessStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Automation;
use App\Models\AutomationExecution;
use App\Models\ContactGroups;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\TestCase;

/**
 * B4 — HTTP tenancy, authorization, IDOR closure, route surface, chooser,
 * demo mode, legacy NULL-business isolation, backfill determinism.
 */
class AutomationsTenancyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;

    private function routeParams($workspace, $business, ?Automation $automation = null): array
    {
        $params = [$workspace->uid, $business->uid];

        if ($automation) {
            $params[] = $automation->uid;
        }

        return $params;
    }

    private function plainAutomation($business, string $name = 'Flag new contacts'): Automation
    {
        $group = $this->contactGroup($business, 'Group ' . uniqid());
        $field = $this->textField($group, 'NOTE_' . strtoupper(uniqid()));

        return Automation::create([
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => $name,
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'trigger_config' => ['contact_group_id' => $group->id],
            'action_type' => AutomationActionType::UpdateContactField->value,
            'action_config' => ['field_id' => $field->id, 'value' => 'new'],
        ]);
    }

    // ---------------------------------------------------------------
    // Authorization: owner / admin / staff
    // ---------------------------------------------------------------

    public function test_owner_can_list_business_automations(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $automation = $this->plainAutomation($business);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.automations.index', $this->routeParams($workspace, $business)))
            ->assertOk()
            ->assertSee($automation->name)
            ->assertSee('Contact created')
            ->assertSee('Update contact field');
    }

    public function test_active_admin_member_can_list_business_automations(): void
    {
        [, $business, $workspace] = $this->entitledTenant();
        $automation = $this->plainAutomation($business);

        $admin = $this->createCustomer()->user;
        $this->addMember($workspace, $admin, WorkspaceMembershipRole::Admin);
        $this->authenticateAsUser($admin);

        $this->get(route('customer.workspaces.businesses.automations.index', $this->routeParams($workspace, $business)))
            ->assertOk()
            ->assertSee($automation->name);
    }

    public function test_staff_member_with_all_scope_can_list_business_automations(): void
    {
        [, $business, $workspace] = $this->entitledTenant();
        $automation = $this->plainAutomation($business);

        $staff = $this->createCustomer()->user;
        $this->addMember($workspace, $staff, WorkspaceMembershipRole::Staff);
        $this->authenticateAsUser($staff);

        $this->get(route('customer.workspaces.businesses.automations.index', $this->routeParams($workspace, $business)))
            ->assertOk()
            ->assertSee($automation->name);
    }

    public function test_inactive_member_is_denied(): void
    {
        [, $business, $workspace] = $this->entitledTenant();
        $this->plainAutomation($business);

        $former = $this->createCustomer()->user;
        $this->addMember($workspace, $former, WorkspaceMembershipRole::Admin, false);
        $this->authenticateAsUser($former);

        $this->get(route('customer.workspaces.businesses.automations.index', $this->routeParams($workspace, $business)))
            ->assertNotFound();
    }

    public function test_missing_automations_permission_is_denied(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer, []);

        // The customer portal maps a Gate denial (AuthorizationException) to
        // 401 — the app's existing convention; what matters is that no page
        // and no data are served.
        $this->get(route('customer.workspaces.businesses.automations.index', $this->routeParams($workspace, $business)))
            ->assertStatus(401)
            ->assertDontSee('Create Automation');
    }

    // ---------------------------------------------------------------
    // Foreign identifiers fail closed
    // ---------------------------------------------------------------

    public function test_foreign_workspace_uid_is_not_found(): void
    {
        [$customer, $business] = $this->entitledTenant();
        [, , $otherWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.automations.index', [$otherWorkspace->uid, $business->uid]))
            ->assertNotFound();
    }

    public function test_foreign_business_uid_inside_own_workspace_is_not_found(): void
    {
        [$customer, , $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $otherBusiness->uid]))
            ->assertNotFound();
    }

    public function test_foreign_automation_uid_inside_own_business_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $foreign = $this->plainAutomation($otherBusiness);
        $this->authenticateAsCustomer($customer);

        $params = $this->routeParams($workspace, $business, $foreign);

        $this->get(route('customer.workspaces.businesses.automations.show', $params))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.automations.edit', $params))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.automations.disable', $params))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.automations.enable', $params))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.automations.destroy', $params))->assertNotFound();

        $this->assertSame(Automation::STATUS_ACTIVE, $foreign->fresh()->status);
        $this->assertDatabaseHas('automations', ['id' => $foreign->id]);
    }

    public function test_single_record_idor_by_outsider_is_closed(): void
    {
        [, $business, $workspace] = $this->entitledTenant();
        $victim = $this->plainAutomation($business);

        $outsider = $this->createCustomer();
        $this->authenticateAsCustomer($outsider);

        $params = $this->routeParams($workspace, $business, $victim);

        $this->post(route('customer.workspaces.businesses.automations.disable', $params))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.automations.destroy', $params))->assertNotFound();
        // A fully valid definition payload, so the request reaches the
        // controller's tenancy resolution rather than stopping at validation.
        $this->post(route('customer.workspaces.businesses.automations.update', $params), [
            'name' => 'pwned',
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'action_type' => AutomationActionType::UpdateContactField->value,
            'field_id' => $victim->action_config['field_id'],
            'value' => 'pwned',
            'enabled' => '1',
        ])->assertNotFound();

        // An invalid payload must not mutate either (validation may answer
        // first, but nothing is written).
        $this->post(route('customer.workspaces.businesses.automations.update', $params), ['name' => 'pwned']);

        $this->assertSame(Automation::STATUS_ACTIVE, $victim->fresh()->status);
        $this->assertSame('Flag new contacts', $victim->fresh()->name);
        $this->assertSame('new', $victim->fresh()->action_config['value']);
    }

    public function test_inactive_workspace_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->plainAutomation($business);
        DB::table('workspaces')->where('id', $workspace->id)->update(['is_active' => false]);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.automations.index', $this->routeParams($workspace, $business)))
            ->assertNotFound();
    }

    public function test_inactive_business_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->plainAutomation($business);
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Inactive->value]);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.automations.index', $this->routeParams($workspace, $business)))
            ->assertNotFound();
    }

    public function test_unentitled_workspace_is_not_found(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);
        $workspace = $business->workspace;
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Legacy NULL-business rows are preserved but never exposed
    // ---------------------------------------------------------------

    public function test_legacy_null_business_automation_is_preserved_but_not_exposed(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $legacy = Automation::create([
            'user_id' => $customer->user_id,
            'business_id' => null,
            'name' => 'Legacy birthday',
            'status' => Automation::STATUS_ACTIVE,
            'contact_list_id' => null,
            'message' => 'Happy birthday from legacy',
        ]);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.automations.index', $this->routeParams($workspace, $business)))
            ->assertOk()
            ->assertDontSee('Legacy birthday');

        $this->get(route('customer.workspaces.businesses.automations.show', $this->routeParams($workspace, $business, $legacy)))
            ->assertNotFound();

        $this->post(route('customer.workspaces.businesses.automations.destroy', $this->routeParams($workspace, $business, $legacy)))
            ->assertNotFound();

        $this->assertDatabaseHas('automations', ['id' => $legacy->id, 'business_id' => null, 'name' => 'Legacy birthday']);
    }

    // ---------------------------------------------------------------
    // Old flat / mutating routes are gone; only the chooser remains
    // ---------------------------------------------------------------

    public function test_old_flat_routes_no_longer_exist(): void
    {
        foreach ([
            'customer.automations.create',
            'customer.automations.store',
            'customer.automations.show',
            'customer.automations.edit',
            'customer.automations.update',
            'customer.automations.enable',
            'customer.automations.disable',
            'customer.automations.destroy',
            'customer.automations.sendNow',
            'customer.automations.batch_action',
            'customer.automations.search',
        ] as $name) {
            $this->assertFalse(Route::has($name), "Route {$name} must not exist.");
        }

        $this->assertTrue(Route::has('customer.automations.index'));
    }

    public function test_old_flat_route_bypass_is_impossible(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $victim = $this->plainAutomation($business);
        $this->authenticateAsCustomer($customer);

        $this->get('/automations/create')->assertNotFound();
        $this->get('/automations/' . $victim->uid)->assertNotFound();
        $this->post('/automations/' . $victim->uid . '/disable')->assertNotFound();
        $this->post('/automations/' . $victim->uid . '/send-now')->assertNotFound();
        $this->post('/automations/batch_action', ['action' => 'destroy', 'ids' => [$victim->uid]])->assertNotFound();
        $this->post('/automations/' . $victim->uid . '/delete')->assertNotFound();

        $this->assertSame(Automation::STATUS_ACTIVE, $victim->fresh()->status);
    }

    /**
     * Lane C finding: the legacy Birthday builder accepted an `mms_file`
     * upload without MIME/size validation and fed it to Tool::uploadImage()
     * under public/mms/. B4 removes the builder, its request class, its
     * controller method and the repository method that performed the
     * upload. This proves the endpoint cannot be invoked any more and that
     * an upload attempt writes nothing.
     */
    public function test_legacy_birthday_upload_endpoint_is_unreachable(): void
    {
        [$customer] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->assertFalse(Route::has('customer.automations.say.happy.birthday'));
        $this->assertFileDoesNotExist(app_path('Http/Requests/Automations/SayBirthdayRequest.php'));
        $this->assertFileDoesNotExist(resource_path('views/customer/Automations/sayHappyBirthday.blade.php'));
        $this->assertFalse(method_exists(\App\Http\Controllers\Customer\AutomationsController::class, 'sayHappyBirthday'));
        $this->assertFalse(method_exists(\App\Http\Controllers\Customer\AutomationsController::class, 'postSayHappyBirthday'));
        $this->assertFalse(method_exists(\App\Repositories\Eloquent\EloquentAutomationsRepository::class, 'automationBuilder'));

        foreach ([
            app_path('Repositories/Eloquent/EloquentAutomationsRepository.php'),
            app_path('Http/Controllers/Customer/AutomationsController.php'),
            app_path('Http/Controllers/Customer/Business/AutomationsController.php'),
        ] as $source) {
            $this->assertStringNotContainsString('uploadImage', file_get_contents($source), basename($source) . ' must not upload files.');
            $this->assertStringNotContainsString('mms_file', file_get_contents($source), basename($source) . ' must not accept mms_file.');
        }

        $mmsDir = public_path('mms');
        $before = is_dir($mmsDir) ? count(scandir($mmsDir)) : 0;

        $this->get('/automations/say-happy-birthday')->assertNotFound();

        $this->post('/automations/say-happy-birthday', [
            'name' => 'legacy',
            'message' => 'hi',
            'mms_file' => \Illuminate\Http\UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
        ])->assertNotFound();

        $after = is_dir($mmsDir) ? count(scandir($mmsDir)) : 0;
        $this->assertSame($before, $after, 'No file may be written under public/mms by the removed endpoint.');
    }

    public function test_chooser_shows_empty_state_without_businesses(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.automations.index'))->assertOk()->assertSee('No Business available yet');
    }

    public function test_chooser_redirects_with_single_business(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.automations.index'))
            ->assertRedirect(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]));
    }

    public function test_chooser_lists_multiple_businesses_without_guessing(): void
    {
        [$customer, $first, $workspace] = $this->entitledTenant();
        $second = app(\App\Repositories\Contracts\BusinessRepository::class)
            ->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => 'Second Venue']));
        DB::table('businesses')->where('id', $second->id)->update(['status' => BusinessStatus::Active->value]);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.automations.index'))
            ->assertOk()
            ->assertSee($first->name)
            ->assertSee('Second Venue');
    }

    // ---------------------------------------------------------------
    // Definition CRUD (Business-scoped) + tampering + demo mode
    // ---------------------------------------------------------------

    private function validSendPayload($business, array $overrides = []): array
    {
        $channel = $this->sendableChannel($business);

        return array_merge([
            'name' => 'Welcome',
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'contact_group_id' => '',
            'action_type' => AutomationActionType::SendMessage->value,
            'sms_type' => 'plain',
            'sender_id' => $channel['sender'],
            'sending_server' => $channel['server']->id,
            'message' => 'Welcome {FIRST_NAME}',
            'enabled' => '1',
        ], $overrides);
    }

    public function test_store_creates_business_scoped_automation(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.automations.store', $this->routeParams($workspace, $business)), $this->validSendPayload($business))
            ->assertRedirect();

        $automation = Automation::where('business_id', $business->id)->firstOrFail();

        $this->assertSame(AutomationTriggerType::ContactCreated, $automation->trigger_type);
        $this->assertSame(AutomationActionType::SendMessage, $automation->action_type);
        $this->assertSame(Automation::STATUS_ACTIVE, $automation->status);
        $this->assertSame('plain', $automation->action_config['sms_type']);
        $this->assertSame((int) $customer->user_id, (int) $automation->user_id);
    }

    public function test_store_rejects_foreign_channel_and_sender(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $foreign = $this->sendableChannel($otherBusiness);
        $this->authenticateAsCustomer($customer);

        $payload = [
            'name' => 'Tampered',
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'action_type' => AutomationActionType::SendMessage->value,
            'sms_type' => 'plain',
            'sender_id' => $foreign['sender'],
            'sending_server' => $foreign['server']->id,
            'message' => 'x',
            'enabled' => '1',
        ];

        $this->post(route('customer.workspaces.businesses.automations.store', $this->routeParams($workspace, $business)), $payload)
            ->assertSessionHasErrors();

        $this->assertSame(0, Automation::where('business_id', $business->id)->count());
    }

    public function test_store_rejects_foreign_group_and_date_field(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $foreignGroup = $this->contactGroup($otherBusiness);
        $foreignField = $this->dateField($foreignGroup);
        $channel = $this->sendableChannel($business);
        $this->authenticateAsCustomer($customer);

        $payload = [
            'name' => 'Tampered',
            'trigger_type' => AutomationTriggerType::ContactDateReached->value,
            'contact_group_id' => $foreignGroup->id,
            'date_field_id' => $foreignField->id,
            'offset' => '1 day',
            'send_at' => '09:00',
            'action_type' => AutomationActionType::SendMessage->value,
            'sms_type' => 'plain',
            'sender_id' => $channel['sender'],
            'sending_server' => $channel['server']->id,
            'message' => 'x',
            'enabled' => '1',
        ];

        $this->post(route('customer.workspaces.businesses.automations.store', $this->routeParams($workspace, $business)), $payload)
            ->assertSessionHasErrors();

        $this->assertSame(0, Automation::where('business_id', $business->id)->count());
    }

    public function test_store_rejects_foreign_custom_field_for_update_action(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $foreignField = $this->textField($this->contactGroup($otherBusiness));
        $this->authenticateAsCustomer($customer);

        $payload = [
            'name' => 'Tampered',
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'action_type' => AutomationActionType::UpdateContactField->value,
            'field_id' => $foreignField->id,
            'value' => 'x',
            'enabled' => '1',
        ];

        $this->post(route('customer.workspaces.businesses.automations.store', $this->routeParams($workspace, $business)), $payload)
            ->assertSessionHasErrors();

        $this->assertSame(0, Automation::where('business_id', $business->id)->count());
    }

    /**
     * Contract §7.B (Correction 1): an UPDATE_CONTACT_FIELD definition needs
     * the exact group its field belongs to.
     */
    private function updateFieldPayload(?int $groupId, int $fieldId): array
    {
        return [
            'name' => 'Tag on create',
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'contact_group_id' => $groupId === null ? '' : (string) $groupId,
            'action_type' => AutomationActionType::UpdateContactField->value,
            'field_id' => $fieldId,
            'value' => 'lead',
            'enabled' => '1',
        ];
    }

    public function test_store_rejects_same_business_field_from_a_different_group(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $audience = $this->contactGroup($business, 'Audience');
        $otherGroup = $this->contactGroup($business, 'Other');
        $otherField = $this->textField($otherGroup, 'OTHER_NOTE');
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.automations.store', $this->routeParams($workspace, $business)), $this->updateFieldPayload($audience->id, $otherField->id))
            ->assertSessionHasErrors(['field_id']);

        $this->assertSame(0, Automation::where('business_id', $business->id)->count());
    }

    public function test_store_rejects_update_field_action_without_a_group(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Audience');
        $field = $this->textField($group);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.automations.store', $this->routeParams($workspace, $business)), $this->updateFieldPayload(null, $field->id))
            ->assertSessionHasErrors(['contact_group_id']);

        $this->assertSame(0, Automation::where('business_id', $business->id)->count());
    }

    public function test_store_accepts_update_field_action_with_its_own_group(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Audience');
        $field = $this->textField($group);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.automations.store', $this->routeParams($workspace, $business)), $this->updateFieldPayload($group->id, $field->id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $automation = Automation::where('business_id', $business->id)->firstOrFail();
        $this->assertSame((int) $group->id, (int) $automation->trigger_config['contact_group_id']);
        $this->assertSame((int) $field->id, (int) $automation->action_config['field_id']);
    }

    public function test_create_form_labels_fields_by_their_group(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Audience');
        $field = $this->textField($group);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.automations.create', $this->routeParams($workspace, $business)))
            ->assertOk()
            ->assertSee('data-group="' . $group->id . '"', false)
            ->assertSee('Audience › ' . $field->label)
            ->assertSee('data-role="group-required-note"', false);
    }

    public function test_update_enable_disable_and_destroy_are_business_scoped(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $automation = $this->plainAutomation($business);
        $field = ContactGroups::find($automation->trigger_config['contact_group_id'])->getFields()->firstWhere('is_phone', false);
        $this->authenticateAsCustomer($customer);

        $params = $this->routeParams($workspace, $business, $automation);

        $this->post(route('customer.workspaces.businesses.automations.disable', $params))->assertRedirect();
        $this->assertSame(Automation::STATUS_INACTIVE, $automation->fresh()->status);

        $this->post(route('customer.workspaces.businesses.automations.enable', $params))->assertRedirect();
        $this->assertSame(Automation::STATUS_ACTIVE, $automation->fresh()->status);

        $this->post(route('customer.workspaces.businesses.automations.update', $params), [
            'name' => 'Renamed',
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'contact_group_id' => $automation->trigger_config['contact_group_id'],
            'action_type' => AutomationActionType::UpdateContactField->value,
            'field_id' => $field->id,
            'value' => 'renamed',
            'enabled' => '0',
        ])->assertRedirect();

        $fresh = $automation->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame(Automation::STATUS_INACTIVE, $fresh->status);
        $this->assertSame('renamed', $fresh->action_config['value']);

        $this->get(route('customer.workspaces.businesses.automations.show', $params))->assertOk()->assertSee('Renamed');
        $this->get(route('customer.workspaces.businesses.automations.edit', $params))->assertOk();

        $this->post(route('customer.workspaces.businesses.automations.destroy', $params))->assertRedirect();
        $this->assertDatabaseMissing('automations', ['id' => $automation->id]);
    }

    public function test_demo_mode_blocks_mutations(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $automation = $this->plainAutomation($business);
        $this->authenticateAsCustomer($customer);
        config(['app.stage' => 'demo']);

        $this->post(route('customer.workspaces.businesses.automations.store', $this->routeParams($workspace, $business)), $this->validSendPayload($business))
            ->assertRedirect();
        $this->assertSame(1, Automation::where('business_id', $business->id)->count());

        $this->post(route('customer.workspaces.businesses.automations.disable', $this->routeParams($workspace, $business, $automation)))->assertRedirect();
        $this->assertSame(Automation::STATUS_ACTIVE, $automation->fresh()->status);

        $this->post(route('customer.workspaces.businesses.automations.destroy', $this->routeParams($workspace, $business, $automation)))->assertRedirect();
        $this->assertDatabaseHas('automations', ['id' => $automation->id]);
    }

    // ---------------------------------------------------------------
    // Execution history shows safe data only
    // ---------------------------------------------------------------

    public function test_history_shows_only_safe_fields(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $automation = $this->plainAutomation($business);
        $contact = $this->contact($business, ContactGroups::find($automation->trigger_config['contact_group_id']), '12025559999');

        AutomationExecution::create([
            'business_id' => $business->id,
            'automation_id' => $automation->id,
            'contact_id' => $contact->id,
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'idempotency_key' => 'contact_created:' . $automation->id . ':' . $contact->id,
            'status' => AutomationExecutionStatus::Failed->value,
            'safe_error_summary' => 'Messaging channel unavailable.',
        ]);

        $this->authenticateAsCustomer($customer);

        $response = $this->get(route('customer.workspaces.businesses.automations.show', $this->routeParams($workspace, $business, $automation)))
            ->assertOk()
            ->assertSee('Failed')
            ->assertSee('Messaging channel unavailable.')
            ->assertSee('…9999');

        $response->assertDontSee('12025559999');
        $response->assertDontSee('idempotency_key');
    }

    // ---------------------------------------------------------------
    // Backfill is deterministic
    // ---------------------------------------------------------------

    public function test_backfill_only_maps_unambiguous_legacy_rows(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $ownGroup = $this->contactGroup($business);

        $otherCustomer = $this->createCustomer();
        $otherBusiness = $this->createBusinessWithWorkspace($otherCustomer, $this->businessAttributes());
        $otherGroup = $this->contactGroup($otherBusiness);

        $nullGroup = ContactGroups::create(['customer_id' => $customer->user_id, 'business_id' => null, 'name' => 'Legacy group', 'status' => true]);

        $mapped = Automation::create(['user_id' => $customer->user_id, 'business_id' => null, 'name' => 'mapped', 'status' => 'active', 'contact_list_id' => $ownGroup->id]);
        $crossCustomer = Automation::create(['user_id' => $customer->user_id, 'business_id' => null, 'name' => 'cross', 'status' => 'active', 'contact_list_id' => $otherGroup->id]);
        $nullBusiness = Automation::create(['user_id' => $customer->user_id, 'business_id' => null, 'name' => 'null', 'status' => 'active', 'contact_list_id' => $nullGroup->id]);
        $noList = Automation::create(['user_id' => $customer->user_id, 'business_id' => null, 'name' => 'nolist', 'status' => 'active', 'contact_list_id' => null]);

        $migration = require base_path('database/migrations/2026_09_07_120002_backfill_business_id_for_automations.php');
        $migration->up();

        $this->assertSame((int) $business->id, (int) $mapped->fresh()->business_id);
        $this->assertNull($crossCustomer->fresh()->business_id);
        $this->assertNull($nullBusiness->fresh()->business_id);
        $this->assertNull($noList->fresh()->business_id);
    }

    public function test_automation_model_no_longer_extends_legacy_sender(): void
    {
        $this->assertNotContains(\App\Library\SendCampaignSMS::class, class_parents(Automation::class));
        $this->assertFalse(method_exists(Automation::class, 'send'));
    }
}
