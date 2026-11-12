<?php

namespace Tests\Feature\Conversations;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\BusinessEmailMessage;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The unified inbox under Agency View As. Product decision (see ConversationsViewAsSendTest): View As is impersonation of the
 * viewed customer — it may do what that customer could, through the same checks, and nothing beyond them. So: without a View-As
 * session the agency actor has no access at all; with one, exactly the viewed Business's inbox (and no other client's), and an
 * email reply goes through the existing sender as the viewed Business's own mailbox.
 */
class UnifiedConversationsViewAsTest extends TestCase
{
    use CreatesBusinessEmailFixtures;
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    public function test_view_as_reads_and_replies_in_the_viewed_inbox_only(): void
    {
        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();

        $pair = $this->createAgencyManagedClient(clientBusinessName: 'Harbor Lane Studios', agencyBusinessName: 'Primary', agencyWorkspaceName: 'Northwind Agency');
        $agency = $pair['agencyOwner'];
        $agencyWorkspace = $pair['agencyWorkspace'];
        $workspace = $pair['clientWorkspace'];
        $business = $pair['clientBusiness'];
        $this->assignTier($workspace, WorkspacePlanTier::Growth);
        $this->activeAccount($business);

        $other = $this->createAgencyManagedClient($agencyWorkspace, 'Other Client', 'Other Client Workspace');
        $this->assignTier($other['clientWorkspace'], WorkspacePlanTier::Growth);

        $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $inbound = BusinessEmailMessage::create([
            'business_id' => $business->id, 'location_id' => $location->id, 'direction' => 'inbound', 'operation_key' => 'seed-' . Str::uuid(),
            'provider' => 'google', 'provider_message_id' => 'pm-1', 'provider_thread_id' => 't-1', 'internet_message_id' => '<one@example.com>',
            'from_email' => 'stranger@example.com', 'to_email' => 'owner@business.test', 'subject' => 'Viewed inbox', 'body_text' => 'Hello', 'status' => 'received', 'received_at' => now(),
        ]);

        $load = fn ($ws, $biz) => route('customer.workspaces.businesses.conversations.load', [$ws->uid, $biz->uid]);
        $thread = fn ($ws, $biz) => route('customer.workspaces.businesses.conversations.email_thread', [$ws->uid, $biz->uid]);

        // No View-As session: the agency actor has no ordinary tenancy of the client's inbox.
        $this->authenticateAs($agency);
        $this->post($load($workspace, $business), ['channel' => 'email'])->assertNotFound();
        $this->post($thread($workspace, $business), ['thread' => 't-1'])->assertNotFound();

        $this->post(route('customer.workspaces.clients.view-as', [$agencyWorkspace->uid, $workspace->uid]))->assertRedirect(route('user.home'));

        // Viewing: the viewed Business's inbox, as that customer sees it.
        $this->assertStringContainsString('Viewed inbox', $this->post($load($workspace, $business), ['channel' => 'email'])->assertOk()->getContent());
        $composer = $this->post($thread($workspace, $business), ['thread' => 't-1'])->assertOk()->json('composer');
        $this->assertTrue($composer['email']);

        // A different client of the same agency is NOT reachable through this session.
        $this->post($load($other['clientWorkspace'], $other['clientBusiness']), ['channel' => 'email'])->assertNotFound();

        // The reply is the viewed customer's own authorised send, once, through the existing sender.
        $this->postJson($composer['url'], ['subject' => 'Re: Viewed inbox', 'body' => 'Thanks', 'send_token' => (string) Str::uuid()])
            ->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame('t-1', $this->fakeGoogle->sent[0]->threadId);
        $this->assertSame($inbound->internet_message_id, $this->fakeGoogle->sent[0]->inReplyTo);
    }
}
