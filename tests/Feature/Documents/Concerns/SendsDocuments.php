<?php

namespace Tests\Feature\Documents\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Documents\DocumentManager;
use App\Library\Documents\PublicDocumentGuard;
use App\Library\Entitlement\CustomerAccountAccessGuard;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;

/**
 * Contract 17 Sub-slice C fixtures — a real, sendable document built through
 * the PRODUCTION manager (create -> line -> schedule -> recipient), never by
 * raw inserts, so every test exercises the same path a customer does.
 */
trait SendsDocuments
{
    use CreatesBusinessTestData;
    use CreatesCustomerContextFixtures;

    /**
     * Payments & Contracts is `Planned` until Sub-slice G, so EntitlementManager
     * denies it for every tier and the public surface is unreachable by design.
     * This replaces EXACTLY that one step of the guard — the token check, the
     * account-lifecycle check, the Location check and every document-lifecycle
     * check still run the unmodified production code.
     */
    protected function allowPublicEntitlement(): void
    {
        $this->app->bind(PublicDocumentGuard::class, fn ($app) => new class(
            $app->make(CustomerAccountAccessGuard::class),
            $app->make(EntitlementManager::class),
        ) extends PublicDocumentGuard {
            protected function entitlementAllows(Workspace $workspace, Business $business): bool
            {
                return true;
            }
        });
    }

    /**
     * @return array{customer: Customer, business: Business, workspace: Workspace, location: BusinessLocation, contact: Contacts}
     */
    protected function sendableTenant(string $businessName = 'Harbor Lane Studios'): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, $businessName, $businessName . ' WS');
        $business->status = BusinessStatus::Active;
        $business->currency_code = 'USD';
        $business->save();

        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'name' => 'Main',
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);

        $group = \App\Models\ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => 'Clients ' . uniqid(),
            'status' => true,
        ]);

        $contact = Contacts::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => '1415555' . random_int(1000, 9999),
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location->id]);

        return [
            'customer' => $customer,
            'business' => $business->fresh(),
            'workspace' => $workspace->fresh(),
            'location' => $location,
            'contact' => $contact->fresh(),
        ];
    }

    /**
     * A draft document with one line, a valid schedule and a frozen-able
     * recipient — everything send() requires and nothing more.
     */
    protected function draftDocument(array $tenant, array $overrides = []): BusinessDocument
    {
        $manager = app(DocumentManager::class);

        $document = $manager->create(
            $tenant['business'],
            $tenant['location'],
            $tenant['contact'],
            null,
            $overrides['kind'] ?? 'proposal',
            $overrides['title'] ?? 'Kitchen renovation proposal',
            $tenant['customer']->user,
        );

        $manager->addCustomLine($document, 'Design work', 'Initial drawings', 2, 25000);
        $manager->setSchedule($document, [
            ['kind' => 'full', 'amount_minor' => 50000, 'currency_code' => 'USD'],
        ]);
        $manager->edit($document, ['recipient_email_snapshot' => $overrides['recipient'] ?? 'client@example.test', 'recipient_name_snapshot' => 'Pat Rivera']);

        return $document->refresh();
    }

    /**
     * Sends the document and returns the ONE plaintext token that was
     * delivered, captured off the notification — the only place it
     * legitimately exists after send(). It is deliberately NOT read out of
     * any table: that is precisely what the design forbids.
     *
     * @return array{0: BusinessDocument, 1: string}
     */
    protected function sendAndCaptureToken(BusinessDocument $document): array
    {
        $token = null;

        \Illuminate\Support\Facades\Notification::fake();
        $document = app(DocumentManager::class)->send($document);

        \Illuminate\Support\Facades\Notification::assertSentOnDemand(
            \App\Notifications\Documents\DocumentIssuedNotification::class,
            function ($notification) use (&$token) {
                $token = (new \ReflectionProperty($notification, 'plaintextToken'))->getValue($notification);

                return true;
            }
        );

        return [$document->refresh(), (string) $token];
    }

    protected function publicUrl(BusinessDocument $document, string $token): string
    {
        return route('public.documents.show', ['uid' => $document->uid, 'token' => $token]);
    }

    protected function signUrl(BusinessDocument $document, string $token): string
    {
        return route('public.documents.sign', ['uid' => $document->uid, 'token' => $token]);
    }

    /** A content hash of every row of the given tables — equal before/after proves nothing was written. */
    protected function fingerprint(array $tables): string
    {
        $parts = [];

        foreach ($tables as $table) {
            $parts[] = $table . ':' . md5(DB::table($table)->orderBy('id')->get()->toJson());
        }

        return implode('|', $parts);
    }

    protected function randomToken(): string
    {
        return Str::random(64);
    }
}
