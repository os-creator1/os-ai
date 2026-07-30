<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessStatus;
use App\Enums\Workspace\WorkspaceContextFailureReason;
use App\Events\Business\BusinessCreated;
use App\Events\Business\BusinessPrimaryLocationUpdated;
use App\Events\Business\BusinessServicesSynced;
use App\Events\Business\BusinessUpdated;
use App\Exceptions\Workspace\WorkspaceContextRequiredException;
use App\Library\Business\BusinessManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class BusinessManagerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_creating_a_business_makes_it_primary_and_draft_and_dispatches_business_created(): void
    {
        Event::fake([BusinessCreated::class, BusinessUpdated::class]);

        $customer = $this->createCustomer();
        $manager = app(BusinessManager::class);

        $business = $manager->createOrUpdateOnboardingBusiness($customer, null, $this->businessAttributes());

        $this->assertTrue($business->is_primary);
        $this->assertSame(BusinessStatus::Draft, $business->status);
        $this->assertSame($customer->user_id, $business->customer_id);
        $this->assertNotNull($business->workspace_id);
        $this->assertSame(
            $business->workspace_id,
            Workspace::where('owner_user_id', $customer->user_id)->value('id')
        );

        Event::assertDispatched(BusinessCreated::class, fn ($event) => $event->businessId === $business->id
            && $event->customerId === $customer->user_id);
        Event::assertNotDispatched(BusinessUpdated::class);
    }

    public function test_business_manager_is_constructed_with_a_workspace_manager_dependency(): void
    {
        $parameters = (new ReflectionClass(BusinessManager::class))->getConstructor()->getParameters();
        $workspaceManagerParameter = null;

        foreach ($parameters as $parameter) {
            if ((string) $parameter->getType() === WorkspaceManager::class) {
                $workspaceManagerParameter = $parameter;
            }
        }

        $this->assertNotNull($workspaceManagerParameter, 'Expected a WorkspaceManager-typed constructor parameter.');

        // Proves the container can actually resolve it end to end, not just
        // that the parameter is declared.
        $this->assertInstanceOf(BusinessManager::class, app(BusinessManager::class));
    }

    public function test_updating_identity_normalizes_website_url_and_derives_canonical_domain(): void
    {
        $customer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());

        Event::fake([BusinessUpdated::class]);

        $manager = app(BusinessManager::class);
        $updated = $manager->updateBusiness($customer, $business, [
            'website_url' => 'WWW.Example.com/Book',
        ]);

        $this->assertSame('https://www.example.com/Book', $updated->website_url);
        $this->assertSame('example.com', $updated->canonical_domain);

        Event::assertDispatched(BusinessUpdated::class, function ($event) use ($business) {
            $changedFields = $event->changedFields;
            sort($changedFields);

            return $event->businessId === $business->id
                && $changedFields === ['canonical_domain', 'website_url'];
        });
    }

    public function test_update_with_no_meaningful_change_does_not_dispatch_business_updated(): void
    {
        $customer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());

        Event::fake([BusinessUpdated::class]);

        $manager = app(BusinessManager::class);
        $manager->updateBusiness($customer, $business, [
            'name' => $business->name,
        ]);

        Event::assertNotDispatched(BusinessUpdated::class);
    }

    public function test_updating_a_business_does_not_resolve_or_create_a_workspace(): void
    {
        $customer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());
        $this->assertNull(DB::table('businesses')->where('id', $business->id)->value('workspace_id'));

        Event::fake([BusinessUpdated::class]);

        $manager = app(BusinessManager::class);
        $updated = $manager->updateBusiness($customer, $business, ['name' => 'Renamed Booth Co']);

        $this->assertNull($updated->workspace_id);
        $this->assertSame(0, Workspace::where('owner_user_id', $customer->user_id)->count());
    }

    public function test_update_business_throws_when_business_does_not_belong_to_customer(): void
    {
        $owner = $this->createCustomer();
        $stranger = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($owner, $this->businessAttributes());

        $manager = app(BusinessManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->updateBusiness($stranger, $business, ['name' => 'Hijacked']);
    }

    public function test_manager_cannot_bypass_protected_fields_through_the_repository(): void
    {
        Event::fake([BusinessUpdated::class]);

        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());

        $manager = app(BusinessManager::class);

        $updated = $manager->updateBusiness($customer, $business, [
            'name' => 'Renamed Booth Co',
            'customer_id' => $otherCustomer->user_id,
            'is_primary' => false,
            'status' => BusinessStatus::Active->value,
            'canonical_domain' => 'attacker.test',
            'activated_at' => now(),
        ]);

        $this->assertSame('Renamed Booth Co', $updated->name);
        $this->assertSame($customer->user_id, $updated->customer_id);
        $this->assertTrue($updated->is_primary);
        $this->assertSame(BusinessStatus::Draft, $updated->status);
        $this->assertNull($updated->canonical_domain);

        Event::assertDispatched(BusinessUpdated::class, fn ($event) => $event->changedFields === ['name']);
    }

    public function test_upsert_primary_location_delegates_invariant_and_dispatches_event(): void
    {
        Event::fake([BusinessPrimaryLocationUpdated::class]);

        $customer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());
        $manager = app(BusinessManager::class);

        $first = $manager->upsertPrimaryLocation($customer, $business, [
            'service_mode' => 'storefront',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
            'public_address' => true,
        ]);

        $second = $manager->upsertPrimaryLocation($customer, $business, [
            'service_mode' => 'storefront',
            'city' => 'Dallas',
            'region' => 'TX',
            'country_code' => 'US',
            'public_address' => true,
        ]);

        $this->assertSame(1, $business->locations()->count());
        $this->assertSame($first->id, $second->id);
        $this->assertSame('Dallas', $second->city);

        Event::assertDispatched(BusinessPrimaryLocationUpdated::class, 2);
    }

    public function test_sync_services_dispatches_event_with_resulting_service_ids(): void
    {
        Event::fake([BusinessServicesSynced::class]);

        $customer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());
        $manager = app(BusinessManager::class);

        $synced = $manager->syncServices($customer, $business, [
            ['name' => 'Digital Photo Booth', 'is_primary' => true],
            ['name' => 'Analog Photo Booth', 'is_primary' => false],
        ]);

        $this->assertSame(1, $business->services()->where('is_primary', true)->count());

        Event::assertDispatched(BusinessServicesSynced::class, fn ($event) => $event->businessId === $business->id
            && $event->serviceIds === $synced->pluck('id')->all());
    }

    public function test_sync_services_rolls_back_and_dispatches_nothing_when_a_child_operation_fails(): void
    {
        Event::fake([BusinessServicesSynced::class]);

        $customer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());

        $otherCustomer = $this->createCustomer();
        $otherBusiness = app(BusinessRepository::class)->createForCustomer($otherCustomer, $this->businessAttributes());
        $foreignService = app(BusinessServiceRepository::class)->syncForBusiness($otherBusiness, [
            ['name' => 'Foreign Service', 'is_primary' => true],
        ])->first();

        $manager = app(BusinessManager::class);

        try {
            $manager->syncServices($customer, $business, [
                ['name' => 'New Service', 'is_primary' => true],
                ['id' => $foreignService->id, 'name' => 'Hijacked', 'is_primary' => false],
            ]);
            $this->fail('Expected an InvalidArgumentException to be thrown.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, $business->services()->count());
        Event::assertNotDispatched(BusinessServicesSynced::class);
    }

    public function test_event_is_not_dispatched_and_changes_are_rolled_back_when_an_outer_transaction_fails(): void
    {
        Event::fake([BusinessCreated::class]);

        $customer = $this->createCustomer();
        $manager = app(BusinessManager::class);

        try {
            DB::transaction(function () use ($manager, $customer) {
                $manager->createOrUpdateOnboardingBusiness($customer, null, $this->businessAttributes());

                // Simulate a later step in the SAME outer transaction failing after
                // BusinessManager's own (inner/nested) transaction already "succeeded".
                throw new RuntimeException('Simulated outer transaction failure.');
            });
            $this->fail('Expected a RuntimeException to be thrown.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, Business::where('customer_id', $customer->user_id)->count());
        $this->assertSame(0, Workspace::where('owner_user_id', $customer->user_id)->count());
        Event::assertNotDispatched(BusinessCreated::class);
    }

    public function test_ambiguous_workspace_candidates_propagate_uncaught_and_roll_back_business_creation(): void
    {
        Event::fake([BusinessCreated::class]);

        $customer = $this->createCustomer();
        $workspaceA = Workspace::create(['name' => 'A', 'owner_user_id' => $customer->user_id, 'is_active' => true]);
        $workspaceB = Workspace::create(['name' => 'B', 'owner_user_id' => $customer->user_id, 'is_active' => true]);

        $businessA = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes(['name' => 'Primary A']));
        $businessA->is_primary = true;
        $businessA->workspace_id = $workspaceA->id;
        $businessA->save();

        $businessB = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes(['name' => 'Primary B']));
        $businessB->is_primary = true;
        $businessB->workspace_id = $workspaceB->id;
        $businessB->save();

        $manager = app(BusinessManager::class);

        try {
            $manager->createOrUpdateOnboardingBusiness($customer, null, $this->businessAttributes(['name' => 'New Business']));
            $this->fail('Expected WorkspaceContextRequiredException was not thrown.');
        } catch (WorkspaceContextRequiredException $e) {
            $this->assertSame(WorkspaceContextFailureReason::MultiplePreferredCandidates, $e->reason);
        }

        $this->assertSame(2, Business::where('customer_id', $customer->user_id)->count());
        $this->assertSame(0, Business::where('customer_id', $customer->user_id)->where('name', 'New Business')->count());
        Event::assertNotDispatched(BusinessCreated::class);
    }

    public function test_missing_owner_user_propagates_model_not_found_uncaught(): void
    {
        Event::fake([BusinessCreated::class]);

        $phantomCustomer = new Customer(['user_id' => 999999]);
        $manager = app(BusinessManager::class);

        $this->expectException(ModelNotFoundException::class);

        try {
            $manager->createOrUpdateOnboardingBusiness($phantomCustomer, null, $this->businessAttributes());
        } finally {
            $this->assertSame(0, Business::where('customer_id', 999999)->count());
            Event::assertNotDispatched(BusinessCreated::class);
        }
    }
}
