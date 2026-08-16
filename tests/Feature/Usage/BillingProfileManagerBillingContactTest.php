<?php

namespace Tests\Feature\Usage;

use App\Exceptions\Usage\InvalidBillingContactDataException;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Library\Usage\BillingProfileManager;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §6.F — billing-contact field validation,
 * User-reference-vs-independent-data behavior, and authorization.
 */
class BillingProfileManagerBillingContactTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_independent_contact_data_is_stored_when_no_user_reference_is_given(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $contact = app(BillingProfileManager::class)->updateBillingContact(
            $business, null, 'Jane Doe', 'jane@example.test', true, (int) $business->customer_id,
        );

        $this->assertNull($contact->contact_user_id);
        $this->assertSame('Jane Doe', $contact->contact_name);
        $this->assertSame('jane@example.test', $contact->contact_email);
    }

    public function test_user_reference_does_not_require_independent_name_or_email(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $user = User::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        $contact = app(BillingProfileManager::class)->updateBillingContact(
            $business, (int) $user->id, null, null, true, (int) $business->customer_id,
        );

        $this->assertSame((int) $user->id, $contact->contact_user_id);
        $this->assertNull($contact->contact_name);
        $this->assertNull($contact->contact_email);
    }

    public function test_missing_user_reference_and_incomplete_independent_data_throws(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->expectException(InvalidBillingContactDataException::class);
        app(BillingProfileManager::class)->updateBillingContact(
            $business, null, 'Jane Doe', null, true, (int) $business->customer_id,
        );
    }

    public function test_update_replaces_the_existing_row_rather_than_duplicating(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        app(BillingProfileManager::class)->updateBillingContact($business, null, 'Jane Doe', 'jane@example.test', true, (int) $business->customer_id);
        app(BillingProfileManager::class)->updateBillingContact($business, null, 'John Doe', 'john@example.test', false, (int) $business->customer_id);

        $this->assertSame(1, DB::table('business_billing_contacts')->where('business_id', $business->id)->count());
        $this->assertDatabaseHas('business_billing_contacts', ['business_id' => $business->id, 'contact_name' => 'John Doe', 'notification_opt_in' => false]);
    }

    public function test_unrelated_user_may_not_change_billing_contact(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $unrelated = $this->createCustomer();

        $this->expectException(UnauthorizedUsageBillingManagementException::class);
        app(BillingProfileManager::class)->updateBillingContact(
            $business, null, 'Jane Doe', 'jane@example.test', true, (int) $unrelated->user_id,
        );
    }
}
