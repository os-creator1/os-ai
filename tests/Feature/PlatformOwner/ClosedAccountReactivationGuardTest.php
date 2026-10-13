<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformOwner\AccountClosureState;
use App\Events\Account\UserAccountStatusChanged;
use App\Exceptions\GeneralException;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformOwner\AccountDeletion\AccountClosureRefused;
use App\Library\PlatformOwner\AccountDeletion\PlatformAccountAssessor;
use App\Library\PlatformOwner\AccountDeletion\PlatformAccountClosureManager;
use App\Models\User;
use App\Repositories\Eloquent\EloquentCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * A closed or closing account can never be switched back on: not through the
 * legacy toggle, not through bulk enable, not through any per-model save. A
 * refused request changes nothing and fires no reactivation event.
 */
class ClosedAccountReactivationGuardTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
        $this->admin = $this->actingAsPlatformOwner(array_merge($this->ownerPermissions, ['view customer', 'delete customer']));
    }

    private function closeAccount(bool $providerFails): User
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Billed Studio');
        $user = $fixture['customer']->user;

        $this->stripe->failWith = $providerFails ? PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED) : null;
        $fingerprint = app(PlatformAccountAssessor::class)->assess($user)['fingerprint'];
        $closure = app(PlatformAccountClosureManager::class)->request($this->admin->id, $user->uid, $user->email, 'Customer request', $fingerprint);
        $this->stripe->failWith = null;

        $this->assertSame($providerFails ? AccountClosureState::Closing : AccountClosureState::Closed, $closure->state);
        $this->assertFalse((bool) User::find($user->id)->status);

        return $user->fresh();
    }

    /** A suspended, ordinary customer with no closure. */
    private function suspendedCustomer(): User
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core, 'Plain Studio ' . uniqid(), 'Plain ' . uniqid());
        $customer->user->forceFill(['status' => false])->save();

        return $customer->user->fresh();
    }

    public function test_a_closing_account_cannot_be_toggled_on(): void
    {
        $user = $this->closeAccount(providerFails: true);

        $this->postJson(route('admin.customers.active', $user->uid))
            ->assertOk()->assertJsonPath('status', 'error');

        $this->assertFalse((bool) User::find($user->id)->status);
    }

    public function test_a_closed_retained_account_cannot_be_toggled_on(): void
    {
        $user = $this->closeAccount(providerFails: false);

        $this->postJson(route('admin.customers.active', $user->uid))
            ->assertOk()->assertJsonPath('status', 'error');

        $this->assertFalse((bool) User::find($user->id)->status);
    }

    public function test_bulk_enable_of_a_closing_or_closed_account_changes_nothing_and_fires_no_event(): void
    {
        foreach ([true, false] as $providerFails) {
            $user = $this->closeAccount($providerFails);
            Event::fake([UserAccountStatusChanged::class]);

            $this->postJson(route('admin.customers.batch_action'), ['action' => 'enable', 'ids' => [$user->uid]])
                ->assertOk()->assertJsonPath('status', 'error');

            $this->assertFalse((bool) User::find($user->id)->status);
            Event::assertNotDispatched(UserAccountStatusChanged::class);
        }
    }

    public function test_a_mixed_bulk_selection_is_rejected_entirely(): void
    {
        $closed = $this->closeAccount(providerFails: false);
        $ordinary = $this->suspendedCustomer();
        $otherOrdinary = $this->suspendedCustomer();
        Event::fake([UserAccountStatusChanged::class]);

        $this->postJson(route('admin.customers.batch_action'), ['action' => 'enable', 'ids' => [$ordinary->uid, $closed->uid, $otherOrdinary->uid]])
            ->assertOk()->assertJsonPath('status', 'error');

        // Not even the ordinary accounts in the batch were switched on.
        $this->assertFalse((bool) User::find($ordinary->id)->status);
        $this->assertFalse((bool) User::find($otherOrdinary->id)->status);
        $this->assertFalse((bool) User::find($closed->id)->status);
        Event::assertNotDispatched(UserAccountStatusChanged::class);
    }

    public function test_the_repository_cannot_be_used_to_bypass_the_controller(): void
    {
        $closed = $this->closeAccount(providerFails: false);
        $ordinary = $this->suspendedCustomer();
        Event::fake([UserAccountStatusChanged::class]);

        try {
            app(EloquentCustomerRepository::class)->batchEnable([$ordinary->uid, $closed->uid]);
            $this->fail('The repository must refuse a batch containing a closed account.');
        } catch (GeneralException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse((bool) User::find($ordinary->id)->status);
        Event::assertNotDispatched(UserAccountStatusChanged::class);

        // And a plain model save cannot switch it on either.
        $this->expectException(AccountClosureRefused::class);
        $closed->forceFill(['status' => true])->save();
    }

    public function test_ordinary_activation_still_works(): void
    {
        $viaToggle = $this->suspendedCustomer();
        $viaBulk = $this->suspendedCustomer();

        $this->postJson(route('admin.customers.active', $viaToggle->uid))
            ->assertOk()->assertJsonPath('status', 'success');
        $this->assertTrue((bool) User::find($viaToggle->id)->status);

        Event::fake([UserAccountStatusChanged::class]);
        $this->postJson(route('admin.customers.batch_action'), ['action' => 'enable', 'ids' => [$viaBulk->uid]])
            ->assertOk()->assertJsonPath('status', 'success');

        $this->assertTrue((bool) User::find($viaBulk->id)->status);
        Event::assertDispatched(UserAccountStatusChanged::class, fn ($e) => $e->userId === $viaBulk->id);

        // Turning an ordinary account OFF is unaffected.
        $this->postJson(route('admin.customers.active', $viaToggle->uid))->assertJsonPath('status', 'success');
        $this->assertFalse((bool) User::find($viaToggle->id)->status);
    }
}
