<?php

namespace Tests\Feature\Documents;

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
use App\Listeners\Documents\SurfaceDocumentActivityInActivityCenter;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 17 §12.G, Blueprint §24 — payment/document events
 * reach the Activity Center, the existing per-user Notifications inbox (the
 * bell). Reuses the exact existing mechanism
 * (`Notifications::create(['notification_type' => ..., ...])`) rather than
 * building a second activity system, so these tests exercise the listener
 * through the real event bus, not a hand-rolled call.
 */
class DocumentActivityCenterTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDocumentsTestData;
    use CreatesCustomerContextFixtures;

    /** @return array{0: int, 1: int} document id, owner user id */
    private function documentAndOwner(): array
    {
        $bundle = $this->documentsBundle();
        $documentId = $this->insertDocument($bundle);

        return [$documentId, (int) $bundle['business']->customer_id];
    }

    public function test_every_document_lifecycle_event_notifies_the_business_owner_with_a_distinct_type(): void
    {
        [$documentId, $ownerId] = $this->documentAndOwner();

        DocumentSent::dispatch($documentId, 1, 1);
        DocumentSigned::dispatch($documentId, 1, 1);
        DocumentPaymentSucceeded::dispatch($documentId, 1);
        DocumentFullyPaid::dispatch($documentId);
        DocumentExpired::dispatch($documentId);
        DocumentVoided::dispatch($documentId);
        DocumentRefunded::dispatch($documentId, 1, 1);

        $types = Notifications::where('user_id', $ownerId)->pluck('notification_type')->all();

        $this->assertSame([
            'document_sent',
            'document_signed',
            'document_payment_succeeded',
            'document_fully_paid',
            'document_expired',
            'document_voided',
            'document_refunded',
        ], $types);

        $this->assertSame(7, Notifications::where('user_id', $ownerId)->where('notification_for', 'customer')->count());
    }

    public function test_a_workspace_staff_member_is_never_notified_only_the_owner_is(): void
    {
        [$documentId, $ownerId] = $this->documentAndOwner();
        $business = Business::find(BusinessDocument::find($documentId)->business_id);

        $staff = $this->createCustomer();
        $this->createMembership($business->workspace, $staff->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);

        DocumentSent::dispatch($documentId, 1, 1);

        $this->assertSame(1, Notifications::where('user_id', $ownerId)->count());
        $this->assertSame(0, Notifications::where('user_id', $staff->user_id)->count(),
            'A Location-unaware inbox never notifies a staff member — only the owner, who always has full Location reach, is told (§12.G).');
    }

    public function test_an_event_naming_a_document_that_no_longer_resolves_writes_nothing(): void
    {
        DocumentSent::dispatch(999999999, 1, 1);
        DocumentFullyPaid::dispatch(999999999);

        $this->assertSame(0, Notifications::count());
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
