<?php

namespace Tests\Feature\Usage;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §11.5 — exact schema shape for
 * business_billing_contacts: UNIQUE(business_id), nullable
 * contact_user_id/contact_name/contact_email, notification_opt_in
 * defaults true.
 */
class BusinessBillingContactSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_business_id_is_unique(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('business_billing_contacts')->insert([
            'business_id' => $business->id,
            'contact_name' => 'Jane Doe',
            'contact_email' => 'jane@example.test',
            'notification_opt_in' => true,
            'updated_by_user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('business_billing_contacts')->insert([
            'business_id' => $business->id,
            'contact_name' => 'John Doe',
            'contact_email' => 'john@example.test',
            'notification_opt_in' => true,
            'updated_by_user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_contact_user_id_supports_an_independent_contact_without_a_user(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $id = DB::table('business_billing_contacts')->insertGetId([
            'business_id' => $business->id,
            'contact_user_id' => null,
            'contact_name' => 'Jane Doe',
            'contact_email' => 'jane@example.test',
            'notification_opt_in' => true,
            'updated_by_user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('business_billing_contacts')->find($id);
        $this->assertNull($row->contact_user_id);
    }

    public function test_contact_user_id_restricts_deletion_while_referenced(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $user = User::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        DB::table('business_billing_contacts')->insert([
            'business_id' => $business->id,
            'contact_user_id' => $user->id,
            'notification_opt_in' => true,
            'updated_by_user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user->id)->delete();
    }
}
