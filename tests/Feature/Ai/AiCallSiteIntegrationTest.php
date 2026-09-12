<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Http\Controllers\Customer\CampaignController;
use App\Http\Requests\Campaigns\GenerateAIMessageRequest;
use App\Library\AgencyProspecting\OpenAiAgencyProspectingClient;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\ContextSource;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerFrame;
use App\Library\Navigation\WorkspaceCandidate;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\AiUsageLedgerEntry;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Unified Business Home and COO Decision Engine Contract §11.4, §13 (slice
 * AI-1) — T-BUD-9 and T-ROUTE-2.
 *
 * T-ROUTE-2: each of the three pre-existing AI call sites
 * (OpenAiAgencyProspectingClient, WebsiteAiGenerationClient,
 * CampaignController::generateAIMessage) now sends its prompt through
 * AiGateway::complete() rather than any direct provider call, and the
 * request the gateway receives carries the route's own configured
 * max_output_tokens rather than an unbounded value.
 *
 * T-BUD-9: on `refused(budget_exhausted)`, each caller's degrade-gracefully
 * behaviour matches the §11.4 table — a caller-shaped fallback, never an
 * exception, and every deterministic surface around it keeps working.
 */
class AiCallSiteIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private FakeAiCompletionClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.active' => true]);
        config(['ai.enforce_budgets_for_existing_categories' => false]);

        $this->fakeClient = new FakeAiCompletionClient();
        $this->app->instance(\App\Library\Ai\Contracts\AiCompletionClient::class, $this->fakeClient);
    }

    /**
     * §11.1/T-BUD-6 — suspending the plan assignment resolves a zero
     * Workspace cap, so the gateway's unconditional zero-cap gate refuses
     * every call deterministically, regardless of
     * `ai.enforce_budgets_for_existing_categories`. This is the cleanest
     * way to force `refused(budget_exhausted)` for these three
     * pre-existing (not-always-hard-enforced) categories without
     * fabricating an over-cap ledger state.
     */
    private function suspend(Workspace $workspace): void
    {
        app(EntitlementManager::class)->changePlanStatus(
            $workspace,
            WorkspacePlanAssignmentStatus::Suspended,
            $this->platformAdminId(),
            'T-BUD-9 fixture: force a refused AI call.',
        );
    }

    // =================================================================
    // Agency prospect AI reply
    // =================================================================

    public function test_agency_prospecting_client_routes_through_the_gateway_with_the_routes_max_output_tokens(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $client = app(OpenAiAgencyProspectingClient::class);
        $reply = $client->complete([['role' => 'user', 'content' => 'hello']], $workspace, null);

        $this->assertNotNull($reply, 'A funded Workspace must receive a reply.');
        $this->assertSame(1, $this->fakeClient->callCount());

        $sentRequest = $this->fakeClient->requests()[0];
        $this->assertSame((int) config('ai.routes.routine.max_output_tokens'), $sentRequest->maxOutputTokens);

        $this->assertSame(
            1,
            AiUsageLedgerEntry::where('workspace_id', $workspace->id)->where('category', 'agency_prospect_reply')->count(),
            'The call must produce a ledger record (T-AI-GATE-1) — there is no side door around the gateway.'
        );
    }

    /**
     * §11.4 — "Falls back to the existing non-AI path: no automatic
     * reply". `OpenAiAgencyProspectingClient::complete()` fails closed to
     * null; AgencyProspectingRespondJob already treats a null AI decision
     * as "do not send anything" (unchanged AI-1 scope) — this test proves
     * the gateway boundary itself never throws and never fabricates a
     * reply once the Workspace's budget is exhausted.
     */
    public function test_agency_prospecting_client_returns_null_when_the_workspace_budget_is_exhausted(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->suspend($workspace);

        $client = app(OpenAiAgencyProspectingClient::class);
        $reply = $client->complete([['role' => 'user', 'content' => 'hello']], $workspace, null);

        $this->assertNull($reply);
        $this->assertSame(0, $this->fakeClient->callCount(), 'A refused call must never reach the provider.');

        // Mirrors AiGatewayTest's own T-BUD-6 assertions exactly: a zero
        // Workspace cap (unassigned/inactive/suspended) is refused by
        // AiGateway's Gate 2, before any reservation is attempted — so,
        // like AiDisabled, it produces no ledger row at all. Only a
        // request that reaches the authoritative reserve() gate (an
        // assigned, active plan that is merely over its own cap) creates
        // a `refused` ledger entry.
        $this->assertSame(
            0,
            AiUsageLedgerEntry::where('workspace_id', $workspace->id)->where('category', 'agency_prospect_reply')->count()
        );
    }

    // =================================================================
    // Website AI draft
    // =================================================================

    public function test_website_generation_client_routes_through_the_gateway_with_the_routes_max_output_tokens(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $client = app(WebsiteAiGenerationClient::class);
        $content = $client->complete([['role' => 'user', 'content' => 'hello']], $business, null);

        $this->assertNotNull($content);
        $this->assertSame(1, $this->fakeClient->callCount());

        $sentRequest = $this->fakeClient->requests()[0];
        $this->assertSame((int) config('ai.routes.routine.max_output_tokens'), $sentRequest->maxOutputTokens);
        $this->assertTrue($sentRequest->jsonMode, 'Website generation must request JSON mode.');

        $this->assertSame(
            1,
            AiUsageLedgerEntry::where('workspace_id', $workspace->id)->where('category', 'website_generation')->count()
        );
    }

    /**
     * §11.4 — "AI drafting is paused ... Manual editing is unaffected."
     * `WebsiteAiDraftGenerator::generate()` treats a null completion as
     * generation failure and returns false, without throwing — deterministic
     * product surfaces (manual editing) never depend on this call
     * succeeding.
     */
    public function test_website_generation_client_returns_null_when_the_workspace_budget_is_exhausted(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->suspend($workspace);

        $client = app(WebsiteAiGenerationClient::class);
        $content = $client->complete([['role' => 'user', 'content' => 'hello']], $business, null);

        $this->assertNull($content);
        $this->assertSame(0, $this->fakeClient->callCount());

        // See the equivalent assertion in
        // test_agency_prospecting_client_returns_null_when_the_workspace_budget_is_exhausted()
        // for why the zero-cap gate produces no ledger row.
        $this->assertSame(
            0,
            AiUsageLedgerEntry::where('workspace_id', $workspace->id)->where('category', 'website_generation')->count()
        );
    }

    // =================================================================
    // Campaign AI message draft
    // =================================================================

    public function test_campaign_controller_routes_through_the_gateway_with_the_routes_max_output_tokens(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $response = $this->invokeGenerateAiMessage($customer, $workspace);
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame(1, $this->fakeClient->callCount());

        $sentRequest = $this->fakeClient->requests()[0];
        $this->assertSame((int) config('ai.routes.routine.max_output_tokens'), $sentRequest->maxOutputTokens);

        $this->assertSame(
            1,
            AiUsageLedgerEntry::where('workspace_id', $workspace->id)->where('category', 'campaign_message_draft')->count()
        );
    }

    /**
     * §11.4 — "The button is disabled with the same sentence" (as
     * Website AI draft's own exhaustion message): a JSON `success: false`
     * response, never a 500, so the deterministic compose-manually path
     * stays available.
     */
    public function test_campaign_controller_degrades_gracefully_when_the_workspace_budget_is_exhausted(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->suspend($workspace);

        $response = $this->invokeGenerateAiMessage($customer, $workspace);
        $payload = $response->getData(true);

        $this->assertFalse($payload['success']);
        $this->assertIsString($payload['message']);
        $this->assertSame(0, $this->fakeClient->callCount());

        // See the equivalent assertion in
        // test_agency_prospecting_client_returns_null_when_the_workspace_budget_is_exhausted()
        // for why the zero-cap gate produces no ledger row.
        $this->assertSame(
            0,
            AiUsageLedgerEntry::where('workspace_id', $workspace->id)->where('category', 'campaign_message_draft')->count()
        );
    }

    /**
     * Invokes the controller action directly (never over HTTP) with a
     * real `customerContext` request attribute — exactly the shape
     * `CampaignController::generateAIMessage()` reads via
     * `request()->attributes->get('customerContext')` — so the test
     * exercises the controller's real Workspace-resolution and
     * AiGateway-calling logic without needing the full customer
     * authentication/middleware stack.
     */
    private function invokeGenerateAiMessage(Customer $customer, Workspace $workspace)
    {
        $workspaceCandidate = new WorkspaceCandidate(
            (int) $workspace->id, (string) $workspace->uid, (string) $workspace->name, true,
            (int) $workspace->owner_user_id, true, null, null, false, null, null, [],
        );

        $context = new CustomerContext(
            userId: (int) $customer->user_id,
            frame: CustomerFrame::Account,
            workspaces: [$workspaceCandidate],
            selectedWorkspace: $workspaceCandidate,
            selectedBusiness: null,
            source: ContextSource::None,
            viewAs: null,
            preferenceCleared: false,
        );

        $httpRequest = Request::create('/customer/campaign/generate-ai-message', 'POST', [
            'goal' => 'Announce a spring sale',
            'tone' => 'friendly',
            'audience' => 'past customers',
        ]);
        $httpRequest->attributes->set('customerContext', $context);
        $this->app->instance('request', $httpRequest);

        $formRequest = GenerateAIMessageRequest::createFrom($httpRequest);
        $formRequest->setContainer($this->app);
        $formRequest->validateResolved();

        $controller = $this->app->make(CampaignController::class);

        return $controller->generateAIMessage($formRequest, $this->app->make(AiGateway::class), $this->app->make(AiModelRouter::class));
    }
}
