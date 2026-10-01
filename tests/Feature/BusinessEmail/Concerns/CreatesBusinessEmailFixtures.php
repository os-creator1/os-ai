<?php

namespace Tests\Feature\BusinessEmail\Concerns;

use App\Enums\BusinessEmail\BusinessEmailAccountState;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Library\BusinessEmail\BusinessEmailOAuthConfig;
use App\Library\BusinessEmail\GoogleBusinessEmailProvider;
use App\Library\BusinessEmail\MicrosoftBusinessEmailProvider;
use App\Models\Business;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;
use Tests\Feature\BusinessEmail\Support\FakeBusinessEmailProvider;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;

trait CreatesBusinessEmailFixtures
{
    use CreatesCustomerContextFixtures;

    protected FakeBusinessEmailProvider $fakeGoogle;

    protected FakeBusinessEmailProvider $fakeMicrosoft;

    /** Both providers replaced by in-memory fakes; no network is reachable. */
    protected function bindFakeEmailProviders(): void
    {
        $this->fakeGoogle = new FakeBusinessEmailProvider(BusinessEmailProviderType::Google);
        $this->fakeMicrosoft = new FakeBusinessEmailProvider(BusinessEmailProviderType::Microsoft);

        $this->app->instance(GoogleBusinessEmailProvider::class, $this->fakeGoogle);
        $this->app->instance(MicrosoftBusinessEmailProvider::class, $this->fakeMicrosoft);

        $this->configureEmailOAuth();
    }

    protected function configureEmailOAuth(): void
    {
        foreach (BusinessEmailProviderType::cases() as $provider) {
            config([
                "business_email.{$provider->value}.client_id" => 'test-client-' . $provider->value,
                "business_email.{$provider->value}.client_secret" => 'test-secret-' . $provider->value,
                "business_email.{$provider->value}.redirect" => route(BusinessEmailOAuthConfig::CALLBACK_ROUTE, ['provider' => $provider->value]),
            ]);
        }
    }

    /**
     * A tenant whose Business already has its Primary Location, as every V1
     * Business does (the tenant fixture itself creates none).
     *
     * @return array{0: \App\Models\Customer, 1: Business, 2: \App\Models\Workspace}
     */
    protected function emailTenant(string $name = 'Harbor Lane Studios'): array
    {
        $tenant = $this->tenant(businessName: $name, workspaceName: $name . ' Workspace');
        $this->makeLocation($tenant[1], 'Primary Location');

        return $tenant;
    }

    protected function makeLocation(Business $business, string $name = 'Location', bool $archived = false): BusinessLocation
    {
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'name' => $name,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);

        if ($archived) {
            DB::table('business_locations')->where('id', $location->id)->update([
                'lifecycle_state' => \App\Enums\Business\BusinessLocationLifecycleState::Archived->value,
                'archived_at' => now(),
            ]);
        }

        return $location->fresh();
    }

    protected function activeAccount(
        Business $business,
        BusinessEmailProviderType $provider = BusinessEmailProviderType::Google,
        string $mailbox = 'owner@business.test',
        int $actorUserId = null,
    ): BusinessEmailAccount {
        $account = BusinessEmailAccount::create([
            'business_id' => $business->id,
            'provider' => $provider,
            'state' => BusinessEmailAccountState::Pending,
            'connected_by_user_id' => $actorUserId ?? $business->customer_id,
        ]);

        DB::table('business_email_accounts')->where('id', $account->id)->update([
            'state' => BusinessEmailAccountState::Active->value,
            'refresh_token_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('stored-refresh-token'),
            'mailbox_email' => $mailbox,
            'display_name' => 'Owner Name',
            'connected_at' => now(),
            'lock_version' => 1,
        ]);

        return $account->fresh();
    }

    /**
     * A Contact of the Business with the given email custom-field values
     * (none, one, or several), built exactly as ContactDirectory reads them.
     *
     * @param list<string> $emails
     */
    protected function contactWithEmails(Business $business, array $emails, ?int $locationId = null, string $first = ''): Contacts
    {
        static $phone = 15559000000;
        $phone++;

        $groupId = DB::table('contact_groups')->where('business_id', $business->id)->value('id');

        if ($groupId === null) {
            $groupId = DB::table('contact_groups')->insertGetId([
                'uid' => (string) \Illuminate\Support\Str::uuid(),
                'customer_id' => $business->customer_id,
                'business_id' => $business->id,
                'name' => 'Contacts',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $fieldId = $this->groupField($groupId, 'EMAIL', 'Email');

        $contactId = DB::table('contacts')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'location_id' => $locationId,
            'group_id' => $groupId,
            'phone' => (string) $phone,
            'status' => 'subscribe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($emails as $email) {
            DB::table('contacts_custom_field')->insert([
                'contact_id' => $contactId,
                'field_id' => $fieldId,
                'value' => $email,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($first !== '') {
            DB::table('contacts_custom_field')->insert([
                'contact_id' => $contactId,
                'field_id' => $this->groupField($groupId, 'FIRST_NAME', 'First name'),
                'value' => $first,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Contacts::query()->findOrFail($contactId);
    }

    private function groupField(int $groupId, string $tag, string $label): int
    {
        $existing = DB::table('contact_group_fields')->where('contact_group_id', $groupId)->where('tag', $tag)->value('id');

        return $existing !== null ? (int) $existing : (int) DB::table('contact_group_fields')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'contact_group_id' => $groupId,
            'label' => $label,
            'type' => 'text',
            'tag' => $tag,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
