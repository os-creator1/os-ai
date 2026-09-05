<?php

namespace Tests\Feature\Business;

use App\Library\Business\LegacyBusinessResolver;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Business Data Tenancy Foundation, Pass 1 — LegacyBusinessResolver never
 * guesses. Every rule from the audit is proven explicitly, including both
 * ambiguous states (zero primary among multiple, multiple primary among
 * multiple) resolving to null rather than an arbitrary pick.
 */
class LegacyBusinessResolverTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function resolver(): LegacyBusinessResolver
    {
        return new LegacyBusinessResolver();
    }

    public function test_exactly_one_business_resolves_to_it(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $resolved = $this->resolver()->resolveForCustomer($customer->user_id);

        $this->assertNotNull($resolved);
        $this->assertSame($business->id, $resolved->id);
    }

    public function test_multiple_businesses_with_exactly_one_primary_resolves_to_the_primary(): void
    {
        $customer = $this->createCustomer();
        $first = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'First']));
        $second = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Second']));

        // createForCustomerInWorkspace() marks only the customer's first
        // Business primary; assert that invariant holds before resolving.
        $this->assertTrue((bool) $first->fresh()->is_primary);
        $this->assertFalse((bool) $second->fresh()->is_primary);

        $resolved = $this->resolver()->resolveForCustomer($customer->user_id);

        $this->assertNotNull($resolved);
        $this->assertSame($first->id, $resolved->id);
    }

    public function test_zero_businesses_resolves_to_null(): void
    {
        $customer = $this->createCustomer();

        $this->assertNull($this->resolver()->resolveForCustomer($customer->user_id));
    }

    public function test_multiple_businesses_with_zero_primary_resolves_to_null(): void
    {
        $customer = $this->createCustomer();
        $first = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'First']));
        $second = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Second']));

        DB::table('businesses')->whereIn('id', [$first->id, $second->id])->update(['is_primary' => false]);

        $this->assertNull($this->resolver()->resolveForCustomer($customer->user_id));
    }

    public function test_multiple_businesses_with_multiple_primary_resolves_to_null(): void
    {
        $customer = $this->createCustomer();
        $first = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'First']));
        $second = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Second']));

        // Force both primary — the schema places no unique constraint on
        // (customer_id, is_primary), matching BusinessRepositoryTest's own
        // established proof that this state is possible and must never be
        // silently resolved by picking one.
        DB::table('businesses')->whereIn('id', [$first->id, $second->id])->update(['is_primary' => true]);

        $this->assertNull($this->resolver()->resolveForCustomer($customer->user_id));
    }

    public function test_never_picks_first_or_lowest_id_when_ambiguous(): void
    {
        $customer = $this->createCustomer();
        $lower = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Lower Id']));
        $higher = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Higher Id']));
        $this->assertLessThan($higher->id, $lower->id);

        DB::table('businesses')->whereIn('id', [$lower->id, $higher->id])->update(['is_primary' => false]);

        $resolved = $this->resolver()->resolveForCustomer($customer->user_id);

        $this->assertNull($resolved, 'Ambiguous state must resolve to null, never to the lowest id.');
    }

    public function test_resolves_a_different_customers_business_independently(): void
    {
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customerA, $this->businessAttributes(['name' => 'A']));
        $businessB = $this->createBusinessWithWorkspace($customerB, $this->businessAttributes(['name' => 'B']));

        $this->assertSame($businessA->id, $this->resolver()->resolveForCustomer($customerA->user_id)->id);
        $this->assertSame($businessB->id, $this->resolver()->resolveForCustomer($customerB->user_id)->id);
    }
}
