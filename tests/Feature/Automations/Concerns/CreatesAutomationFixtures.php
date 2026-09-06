<?php

namespace Tests\Feature\Automations\Concerns;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\Automation;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerBasedSendingServer;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;

/**
 * B4 Business Automations — shared fixtures. Builds an ENTITLED,
 * Business-scoped tenant (Workspace with a Core plan — the tier that
 * includes `automations` — plus an active Business), Business-scoped CRM
 * data, a Business-assigned messaging channel, and the B1 send-core
 * double. The provider is never called for real: CampaignRepository is
 * bound as a Mockery double exactly as the B1 suite does, and counting its
 * quickSend() invocations is how at-most-once is proven.
 */
trait CreatesAutomationFixtures
{
    use CreatesBusinessTestData;

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function entitledTenant(): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        $workspace = Workspace::query()->findOrFail($business->workspace_id);

        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'B4 fixture assignment.', true, 0);

        return [$customer, $business->fresh(), $workspace->fresh()];
    }

    protected function platformAdminId(): int
    {
        return User::create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'email' => 'platform-admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
    }

    protected function addMember(Workspace $workspace, User $user, WorkspaceMembershipRole $role, bool $isActive = true): WorkspaceMembership
    {
        return WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'business_access_scope' => WorkspaceBusinessAccessScope::All->value,
            'is_active' => $isActive,
        ]);
    }

    protected function contactGroup(Business $business, string $name = 'Clients'): ContactGroups
    {
        return ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => $name,
            'status' => true,
        ]);
    }

    protected function dateField(ContactGroups $group, string $tag = 'BIRTH_DATE'): ContactGroupFields
    {
        return ContactGroupFields::create([
            'contact_group_id' => $group->id,
            'label' => 'Birth date',
            'type' => ContactGroupFields::TYPE_DATE,
            'tag' => $tag,
            'visible' => true,
            'required' => false,
            'is_phone' => false,
        ]);
    }

    protected function textField(ContactGroups $group, string $tag = 'STATUS_NOTE'): ContactGroupFields
    {
        return ContactGroupFields::create([
            'contact_group_id' => $group->id,
            'label' => 'Status note',
            'type' => 'text',
            'tag' => $tag,
            'visible' => true,
            'required' => false,
            'is_phone' => false,
        ]);
    }

    protected function contact(Business $business, ContactGroups $group, string $phone = '12025551000', ?string $dateValue = null, ?ContactGroupFields $dateField = null): Contacts
    {
        $contact = Contacts::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => $phone,
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);

        if ($dateValue !== null && $dateField !== null) {
            ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $dateField->id, 'value' => $dateValue]);
        }

        return $contact;
    }

    /**
     * A Business-assigned, active messaging channel (B2 shape) and a
     * Business-owned active SenderID, plus the legacy Customer-level
     * Subscription/Plan/coverage the B1 send core requires.
     *
     * @return array{server: SendingServer, sender: string}
     */
    protected function sendableChannel(Business $business): array
    {
        $country = Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);

        $plan = Plan::create([
            'currency_id' => $currency->id,
            'name' => 'Automation Test Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode([]),
            'status' => true,
        ]);

        PlansCoverageCountries::create([
            'plan_id' => $plan->id,
            'country_id' => $country->id,
            'status' => true,
            'options' => json_encode(['plain' => true, 'mms' => true, 'plain_sms' => 0.05, 'mms_sms' => 0.10]),
        ]);

        Subscription::create([
            'user_id' => $business->customer_id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);

        $server = SendingServer::create([
            'name' => 'Twilio',
            'settings' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'plain' => true,
            'mms' => true,
            'user_id' => $business->customer_id,
            'account_sid' => 'AC_TEST',
            'auth_token' => 'token_test',
        ]);

        CustomerBasedSendingServer::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'sending_server' => $server->id,
            'status' => 1,
        ]);

        Senderid::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'sender_id' => 'AUTOSENDER',
            'status' => Senderid::STATUS_ACTIVE,
            'price' => 0,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'currency_id' => $currency->id,
        ]);

        return ['server' => $server, 'sender' => 'AUTOSENDER'];
    }

    protected function sendMessageAutomation(Business $business, SendingServer $server, string $sender, array $overrides = []): Automation
    {
        return Automation::create(array_merge([
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => 'Welcome text',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => AutomationTriggerType::ContactCreated->value,
            'trigger_config' => ['contact_group_id' => null],
            'action_type' => AutomationActionType::SendMessage->value,
            'action_config' => [
                'sms_type' => 'plain',
                'message' => 'Hi {FIRST_NAME}, welcome!',
                'sender_id' => $sender,
                'sending_server' => $server->id,
            ],
        ], $overrides));
    }

    protected function dateReachedAutomation(Business $business, ContactGroups $group, ContactGroupFields $field, SendingServer $server, string $sender, array $overrides = []): Automation
    {
        return Automation::create(array_merge([
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => 'Birthday text',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => AutomationTriggerType::ContactDateReached->value,
            'trigger_config' => [
                'contact_group_id' => $group->id,
                'date_field_id' => $field->id,
                'offset' => '2 days',
                'send_at' => '09:00',
            ],
            'action_type' => AutomationActionType::SendMessage->value,
            'action_config' => [
                'sms_type' => 'plain',
                'message' => 'Happy birthday!',
                'sender_id' => $sender,
                'sending_server' => $server->id,
            ],
        ], $overrides));
    }

    /**
     * The B1 send-core double. quickSend() is "the provider call" for
     * every at-most-once assertion.
     */
    protected function mockSendCore(int $expectedSends, bool $succeed = true): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(CampaignRepository::class);

        $mock->shouldReceive('checkQuickSendValidation')
            ->andReturnUsing(fn (array $input) => response()->json([
                'status' => 'success',
                'sender_id' => $input['sender_id'] ?? null,
                'sms_type' => $input['sms_type'] ?? 'plain',
                'user_id' => $input['user_id'] ?? null,
            ]));

        $expectation = $mock->shouldReceive('quickSend');

        if ($expectedSends === 0) {
            $expectation->never();
        } else {
            $expectation->times($expectedSends);
        }

        $expectation->andReturn(response()->json($succeed
            ? ['status' => 'success', 'message' => 'sent']
            : ['status' => 'error', 'message' => 'provider rejected']));

        $this->app->instance(CampaignRepository::class, $mock);

        return $mock;
    }

    protected function authenticateAsCustomer(Customer $customer, array $permissions = ['automations']): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    protected function authenticateAsUser(User $user, array $permissions = ['automations']): void
    {
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($user);
    }

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
}
