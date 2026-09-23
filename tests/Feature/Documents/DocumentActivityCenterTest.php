<?php

namespace Tests\Feature\Documents;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\DocumentExpired;
use App\Events\DocumentFullyPaid;
use App\Events\DocumentPaymentSucceeded;
use App\Events\DocumentRefunded;
use App\Events\DocumentSent;
use App\Events\DocumentSigned;
use App\Events\DocumentVoided;
use App\Library\Documents\DocumentActivityCenterReader;
use App\Library\Entitlement\EntitlementManager;
use App\Listeners\Documents\SurfaceDocumentActivityInActivityCenter;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\Notifications;
use App\Models\PlatformDatabaseNotification;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §12.G, Blueprint §24 — payment/document events
 * reach the Activity Center through the EXISTING `database` notification
 * substrate (`platform_database_notifications`, already used by Automations
 * V2's "Notify the team"), never the legacy `notifications` table. THE READ
 * IS AUTHORITATIVE: DocumentActivityCenterReader re-derives tenancy, the
 * `payments_contracts` capability, the entitlement, document existence and
 * LocationAccessGuard fresh on every read, so nothing here is ever trusted
 * from the state that existed when the event was written.
 */
class DocumentActivityCenterTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    private function reader(): DocumentActivityCenterReader
    {
        return app(DocumentActivityCenterReader::class);
    }

    private function otherLocation(array $tenant): BusinessLocation
    {
        return BusinessLocation::create([
            'business_id' => $tenant['business']->id,
            'name' => 'Other Location',
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
    }

    private function staffGrantedOnly(array $tenant, BusinessLocation $location): Customer
    {
        $staff = $this->createCustomer();

        $membership = $this->createMembership($tenant['workspace'], $staff->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);

        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);

        return $staff;
    }

    /**
     * DocumentActivityCenterReader::authorize() checks the `payments_contracts`
     * Gate for the given $user via AccountRepository::hasPermission(), which
     * reads the CURRENT TEST SESSION regardless of which User object was
     * passed — exactly as it does in production, where the reader only ever
     * runs for Auth::user(). Every direct reader call below therefore
     * authenticates as that same actor first, mirroring how the navbar
     * actually invokes it.
     */
    public function test_the_owner_sees_a_document_event(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        DocumentSent::dispatch($document->id, 1, 1);

        $this->authenticateAs($tenant['customer']);
        $items = $this->reader()->unreadFor($tenant['customer']->user);

        $this->assertCount(1, $items);
        $this->assertSame('Your proposal was sent.', $items->first()->message);
    }

    public function test_staff_with_the_capability_business_reach_and_that_locations_grant_sees_it(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        $staff = $this->staffGrantedOnly($tenant, $tenant['location']);
        DocumentSent::dispatch($document->id, 1, 1);

        $this->authenticateAs($staff);
        $items = $this->reader()->unreadFor($staff->user);

        $this->assertCount(1, $items);
    }

    public function test_staff_without_that_locations_grant_sees_nothing(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        $other = $this->otherLocation($tenant);
        $staff = $this->staffGrantedOnly($tenant, $other);
        DocumentSent::dispatch($document->id, 1, 1);

        $this->authenticateAs($staff);
        $this->assertCount(0, $this->reader()->unreadFor($staff->user));
    }

    public function test_a_locations_grant_revoked_after_the_event_was_created_removes_it(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        $staff = $this->staffGrantedOnly($tenant, $tenant['location']);
        DocumentSent::dispatch($document->id, 1, 1);

        $this->authenticateAs($staff);
        $this->assertCount(1, $this->reader()->unreadFor($staff->user), 'Sanity check: visible before the grant is revoked.');

        $membership = \App\Models\WorkspaceMembership::query()
            ->where('workspace_id', $tenant['workspace']->id)
            ->where('user_id', $staff->user_id)
            ->firstOrFail();
        app(WorkspaceMembershipLocationRepository::class)->unassign($membership, $tenant['location']->id);

        $this->assertCount(0, $this->reader()->unreadFor($staff->user),
            'The write-time state is never trusted; a grant revoked after the fact removes the item on the next read.');
    }

    public function test_staff_without_the_payments_contracts_capability_sees_nothing(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        $staff = $this->staffGrantedOnly($tenant, $tenant['location']);
        DocumentSent::dispatch($document->id, 1, 1);

        $this->authenticateAs($staff, array_values(array_diff($this->allCustomerPermissions(), ['payments_contracts'])));

        $this->assertCount(0, $this->reader()->unreadFor($staff->user));
    }

    public function test_entitlement_denial_hides_it(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        DocumentSent::dispatch($document->id, 1, 1);

        app(EntitlementManager::class)->createOrChangeOverride(
            $tenant['workspace'],
            PlatformFeature::PaymentsContracts,
            WorkspaceEntitlementOverrideState::Deny,
            $this->platformAdminId(),
            'Activity Center test: deny the feature.'
        );

        $this->authenticateAs($tenant['customer']);
        $this->assertCount(0, $this->reader()->unreadFor($tenant['customer']->user));
    }

    public function test_a_foreign_business_event_never_appears(): void
    {
        $mine = $this->sendableTenant('Mine');
        $theirs = $this->sendableTenant('Theirs');
        $document = $this->draftDocument($theirs);
        DocumentSent::dispatch($document->id, 1, 1);

        $this->authenticateAs($mine['customer']);
        $this->assertCount(0, $this->reader()->unreadFor($mine['customer']->user));

        $this->authenticateAs($theirs['customer']);
        $this->assertCount(1, $this->reader()->unreadFor($theirs['customer']->user));
    }

    public function test_a_hidden_event_does_not_contribute_to_the_visible_notification_count(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        $other = $this->otherLocation($tenant);
        $staff = $this->staffGrantedOnly($tenant, $other);
        DocumentSent::dispatch($document->id, 1, 1);

        $this->authenticateAs($staff);
        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('badge-up">1<', $html);
    }

    public function test_the_owner_sees_the_count_and_item_rendered_in_the_navbar(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        DocumentSent::dispatch($document->id, 1, 1);

        $this->authenticateAs($tenant['customer']);
        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringContainsString('Your proposal was sent.', $html);
    }

    public function test_existing_legacy_notification_behavior_remains_intact(): void
    {
        $tenant = $this->sendableTenant();

        Notifications::create([
            'user_id' => $tenant['customer']->user_id,
            'notification_for' => 'customer',
            'notification_type' => 'chatbox',
            'message' => 'New chat message arrived',
        ]);

        $this->authenticateAs($tenant['customer']);
        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringContainsString('New Inbox Message', $html);
        $this->assertStringContainsString('badge-up">1<', $html);
    }

    public function test_written_through_platform_database_notifications_not_the_legacy_table(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        DocumentSent::dispatch($document->id, 1, 1);

        $this->assertSame(0, Notifications::where('notification_type', 'document_sent')->count());
        $this->assertSame(1, PlatformDatabaseNotification::query()->count());
    }

    public function test_an_event_naming_a_document_that_no_longer_resolves_writes_nothing(): void
    {
        DocumentSent::dispatch(999999999, 1, 1);
        DocumentFullyPaid::dispatch(999999999);

        $this->assertSame(0, PlatformDatabaseNotification::query()->count());
    }

    public function test_every_document_lifecycle_event_is_registered_to_the_listener(): void
    {
        Event::fake();

        foreach ([
            DocumentSent::class => 'handleSent',
            DocumentSigned::class => 'handleSigned',
            DocumentPaymentSucceeded::class => 'handlePaymentSucceeded',
            DocumentFullyPaid::class => 'handleFullyPaid',
            DocumentExpired::class => 'handleExpired',
            DocumentVoided::class => 'handleVoided',
            DocumentRefunded::class => 'handleRefunded',
        ] as $event => $method) {
            Event::assertListening($event, SurfaceDocumentActivityInActivityCenter::class . '@' . $method);
        }
    }
}
