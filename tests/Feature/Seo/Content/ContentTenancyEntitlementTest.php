<?php

namespace Tests\Feature\Seo\Content;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Website\WebsiteStatus;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Seo\Content\ArticleManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Website;
use App\Models\WebsiteArticle;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * SEO Content Engine V1 — who may see and change what: plan packaging through the canonical entitlement model
 * (never a plan-name check), capabilities, Business isolation, and Agency View As (client A only; client B and the
 * agency's own Business stay separate).
 */
class ContentTenancyEntitlementTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private FakeAiCompletionClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $this->fake);
        Http::preventStrayRequests();
    }

    private function article(Business $business, string $title): WebsiteArticle
    {
        Website::query()->firstOrCreate(['business_id' => $business->id], ['name' => $business->name . ' Site', 'status' => WebsiteStatus::Draft]);

        return app(ArticleManager::class)->create((int) $business->customer_id, $business, [
            'title' => $title,
            'body' => 'A short draft body.',
        ]);
    }

    private function nextRequest(): void
    {
        app(\App\Library\Support\RequestScopedCache::class)->flush();
    }

    private function content(Workspace $workspace, Business $business, string $name, array $extra = []): string
    {
        return rtrim((string) config('app.url'), '/') . route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$workspace->uid, $business->uid], $extra), false);
    }

    private function tenantAt(WorkspacePlanTier $tier): array
    {
        [$customer, $business, $workspace] = $this->tenant($tier, 'Jazmin Photo Booth Co.', 'Jazmin Workspace');
        $this->authenticateAs($customer);
        Website::create(['business_id' => $business->id, 'name' => 'Site', 'status' => WebsiteStatus::Draft]);

        return [$customer, $business, $workspace];
    }

    public function test_core_gets_articles_and_the_editor_but_not_opportunities_or_ai_drafting(): void
    {
        [, $business, $workspace] = $this->tenantAt(WorkspacePlanTier::Core);

        $articles = $this->get($this->content($workspace, $business, 'articles.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="growth-note"', $articles);
        $this->assertStringNotContainsString('data-tab="opportunities"', $articles);
        $this->get($this->content($workspace, $business, 'plan'))->assertOk()->assertSee('data-role="growth-note"', false);
        $this->get($this->content($workspace, $business, 'articles.create'))->assertOk();

        $this->get($this->content($workspace, $business, 'opportunities'))->assertNotFound();
        $this->post($this->content($workspace, $business, 'opportunities.draft'), ['key' => 'x'])->assertNotFound();
        $this->post($this->content($workspace, $business, 'opportunities.start'), ['key' => 'x'])->assertNotFound();

        // Manual publishing is part of the Core package.
        $this->post($this->content($workspace, $business, 'articles.store'), ['title' => 'My first article'])->assertRedirect();
        $this->assertSame(1, WebsiteArticle::count());
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_growth_and_agency_get_the_full_content_package(): void
    {
        foreach ([WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [, $business, $workspace] = $this->tenantAt($tier);

            foreach (['plan', 'articles.index', 'opportunities'] as $name) {
                $this->get($this->content($workspace, $business, $name))->assertOk();
            }

            $this->assertStringContainsString('data-tab="opportunities"', $this->get($this->content($workspace, $business, 'articles.index'))->getContent(), $tier->value);
        }
    }

    public function test_capabilities_view_seo_reads_and_manage_seo_writes(): void
    {
        [$customer, $business, $workspace] = $this->tenantAt(WorkspacePlanTier::Growth);
        $article = $this->article($business, 'A draft to protect');

        $this->authenticateAs($customer, ['view_seo']);

        $this->get($this->content($workspace, $business, 'articles.index'))->assertOk();
        $this->get($this->content($workspace, $business, 'articles.preview', [$article->uid]))->assertOk();
        // The platform answers a missing capability with 401 (unauthorized) or 403; either way nothing is written.
        $denied = [401, 403];
        $this->assertContains($this->get($this->content($workspace, $business, 'articles.create'))->getStatusCode(), $denied);
        $this->assertContains($this->post($this->content($workspace, $business, 'articles.store'), ['title' => 'x'])->getStatusCode(), $denied);
        $this->assertContains($this->post($this->content($workspace, $business, 'articles.archive', [$article->uid]))->getStatusCode(), $denied);
        $this->assertSame(1, WebsiteArticle::count());
        $this->assertSame('draft', $article->fresh()->status->value);

        $this->authenticateAs($customer, []);
        $this->assertContains($this->get($this->content($workspace, $business, 'articles.index'))->getStatusCode(), [401, 403], 'no capability, no content');
    }

    public function test_another_business_content_is_invisible_and_untouchable(): void
    {
        [$ownerA, $a, $workspaceA] = $this->tenantAt(WorkspacePlanTier::Growth);
        $mine = $this->article($a, 'Mine only');

        $other = $this->createIndependentWorkspaceBusiness(businessName: 'Other Co', workspaceName: 'Other Workspace');
        $this->assignTier($other['workspace'], WorkspacePlanTier::Growth);
        $theirs = $this->article($other['business'], 'Theirs only');

        // The listing is scoped to the Business.
        $list = $this->get($this->content($workspaceA, $a, 'articles.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Mine only', $list);
        $this->assertStringNotContainsString('Theirs only', $list);

        // Their article through MY Business is not found, however the uid is used.
        foreach (['articles.edit' => 'get', 'articles.preview' => 'get', 'articles.analysis' => 'get'] as $name => $verb) {
            $this->{$verb}($this->content($workspaceA, $a, $name, [$theirs->uid]))->assertNotFound();
        }
        foreach (['articles.publish', 'articles.archive', 'articles.restore', 'articles.draft', 'articles.reviewed', 'articles.track'] as $name) {
            $this->post($this->content($workspaceA, $a, $name, [$theirs->uid]))->assertNotFound();
        }
        $this->post($this->content($workspaceA, $a, 'articles.update', [$theirs->uid]), ['title' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Theirs only', $theirs->fresh()->title);

        // Their Business/Workspace URLs are not mine to open at all.
        foreach (['plan', 'articles.index', 'opportunities', 'articles.create'] as $name) {
            $this->get($this->content($other['workspace'], $other['business'], $name))->assertNotFound();
        }
        $this->get($this->content($other['workspace'], $other['business'], 'articles.edit', [$theirs->uid]))->assertNotFound();

        // Mixing a Workspace with someone else's Business is not found either.
        $this->get($this->content($workspaceA, $other['business'], 'articles.index'))->assertNotFound();

        $this->assertSame('Mine only', $mine->fresh()->title);
    }

    public function test_agency_view_as_client_a_sees_and_manages_client_a_only(): void
    {
        $pair = $this->createAgencyManagedClient(clientBusinessName: 'Client A Booths', clientWorkspaceName: 'Client A', agencyBusinessName: 'Northwind House', agencyWorkspaceName: 'Northwind Agency');
        $this->assignTier($pair['clientWorkspace'], WorkspacePlanTier::Growth);
        $b = $this->createAgencyManagedClient($pair['agencyWorkspace'], 'Client B Booths', 'Client B');
        $this->assignTier($b['clientWorkspace'], WorkspacePlanTier::Growth);

        $articleA = $this->article($pair['clientBusiness'], 'Client A article');
        $articleB = $this->article($b['clientBusiness'], 'Client B article');
        $agencyOwn = $this->article($pair['agencyBusiness'], 'Agency own article');

        $this->nextRequest();
        $this->authenticateAs($pair['agencyOwner']);

        // Not viewing: the agency's own Business blog is its own.
        $own = $this->get($this->content($pair['agencyWorkspace'], $pair['agencyBusiness'], 'articles.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Agency own article', $own);
        $this->assertStringNotContainsString('Client A article', $own);

        $this->post(route('customer.workspaces.clients.view-as', [$pair['agencyWorkspace']->uid, $pair['clientWorkspace']->uid]))->assertRedirect(route('user.home'));

        // Viewing Client A: Client A's content, and only that.
        $list = $this->get($this->content($pair['clientWorkspace'], $pair['clientBusiness'], 'articles.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Client A article', $list);
        $this->assertStringNotContainsString('Client B article', $list);
        $this->assertStringNotContainsString('Agency own article', $list);

        $this->get($this->content($pair['clientWorkspace'], $pair['clientBusiness'], 'articles.edit', [$articleA->uid]))->assertOk();
        $this->post($this->content($pair['clientWorkspace'], $pair['clientBusiness'], 'articles.store'), ['title' => 'Written while viewing A'])->assertRedirect();
        $created = WebsiteArticle::query()->where('title', 'Written while viewing A')->sole();
        $this->assertSame((int) $pair['clientBusiness']->id, (int) $created->business_id, 'the article belongs to the viewed client, never the agency');

        // Client B and the agency's own Business are closed while viewing Client A.
        $this->get($this->content($b['clientWorkspace'], $b['clientBusiness'], 'articles.index'))->assertNotFound();
        $this->get($this->content($b['clientWorkspace'], $b['clientBusiness'], 'articles.edit', [$articleB->uid]))->assertNotFound();
        $this->get($this->content($pair['agencyWorkspace'], $pair['agencyBusiness'], 'articles.index'))->assertNotFound();
        $this->get($this->content($pair['clientWorkspace'], $pair['clientBusiness'], 'articles.edit', [$articleB->uid]))->assertNotFound();
        $this->get($this->content($pair['clientWorkspace'], $pair['clientBusiness'], 'articles.edit', [$agencyOwn->uid]))->assertNotFound();
        $this->post($this->content($pair['clientWorkspace'], $pair['clientBusiness'], 'articles.archive', [$articleB->uid]))->assertNotFound();
        $this->assertSame('draft', $articleB->fresh()->status->value);
    }
}
