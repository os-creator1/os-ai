<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Seo\SeoAuditRunner;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * A blank page title / description finding links to the EXISTING Website draft page editor for that exact page. The audit page itself
 * writes nothing and never publishes; the link exists only for someone who may edit the website, and only for this Business's own draft.
 */
class SeoAuditDraftFixLinkTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: \App\Models\Website, 4: WebsitePage} */
    private function audited(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->snapshotPage('placeholder', 'Home', ['seo_title' => null, 'meta_description' => null], [], true)]);
        $page = WebsitePage::create(['website_id' => $website->id, 'title' => 'Home', 'slug' => null, 'is_home' => true, 'sections' => [], 'seo_title' => null, 'meta_description' => null]);

        $revision = DB::table('website_revisions')->where('id', $website->published_revision_id)->first();
        $snapshot = json_decode($revision->snapshot, true);
        $snapshot['pages'][0]['uid'] = $page->uid;
        DB::table('website_revisions')->where('id', $revision->id)->update(['snapshot' => json_encode($snapshot)]);

        app(SeoAuditRunner::class)->runForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id);

        return [$customer, $business, $workspace, $website, $page];
    }

    private function auditUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.seo.audit.index', [$workspace->uid, $business->uid]);
    }

    public function test_a_blank_description_finding_links_to_the_exact_draft_page_and_publishes_nothing(): void
    {
        [$customer, $business, $workspace, $website, $page] = $this->audited();
        $publishedBefore = $website->published_revision_id;
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();

        $editUrl = route('customer.workspaces.businesses.website.pages.edit', [$workspace->uid, $business->uid, $page->uid]);
        $this->assertStringContainsString($editUrl . '#meta_description', $html);
        $this->assertStringNotContainsString('Updated in your draft', $html, 'Nothing has been fixed yet.');
        $this->assertSame($publishedBefore, $website->fresh()->published_revision_id, 'Viewing the audit never publishes.');
        $this->assertNull($page->fresh()->meta_description, 'Viewing the audit never writes the draft.');
    }

    public function test_a_draft_that_already_has_the_value_says_so_without_claiming_it_is_live(): void
    {
        [$customer, $business, $workspace, , $page] = $this->audited();
        $page->update(['meta_description' => 'A clear description of the home page for searchers.']);
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Updated in your draft. Publish to make it live.', $html);
    }

    public function test_someone_who_cannot_edit_the_website_gets_no_edit_link(): void
    {
        [$customer, $business, $workspace] = $this->audited();
        $this->authenticateAsSeoCustomer($customer, ['view_seo']);

        $html = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="audit-draft-fix-link"', $html);
    }

    public function test_a_page_that_is_not_in_this_businesss_draft_gets_no_link(): void
    {
        [$customer, $business, $workspace, , $page] = $this->audited();
        $page->delete();
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="audit-draft-fix-link"', $html);
    }

    public function test_the_finding_offers_prepare_fix_to_the_exact_page_and_generates_nothing(): void
    {
        [$customer, $business, $workspace, , $page] = $this->audited();
        $this->authenticateAsSeoCustomer($customer);
        $this->app->instance(\App\Library\Website\WebsiteAiGenerationClient::class, \Mockery::mock(\App\Library\Website\WebsiteAiGenerationClient::class));

        $html = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a href="[^"]*' . preg_quote($page->uid, '#') . '[^"]*\#meta_description" data-role="audit-draft-fix-link">Prepare fix</a>#', $html);
        $this->assertStringNotContainsString('data-role="audit-fix-live"', $html);
        $this->assertNull($page->fresh()->meta_description);
    }

    public function test_a_drafted_fix_is_drafted_and_only_a_published_version_with_the_value_is_reported_live(): void
    {
        [$customer, $business, $workspace, $website, $page] = $this->audited();
        $this->authenticateAsSeoCustomer($customer);
        $page->update(['meta_description' => 'A clear description of the home page for searchers.']);

        $drafted = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="audit-fix-drafted"', $drafted);
        $this->assertStringNotContainsString('data-role="audit-fix-live"', $drafted, 'A failed or missing publication never reports a live fix.');

        // Publication verified from the persisted published revision (what the existing publish workflow writes).
        $revision = DB::table('website_revisions')->where('id', $website->fresh()->published_revision_id)->first();
        $snapshot = json_decode($revision->snapshot, true);
        $snapshot['pages'][0]['seo']['meta_description'] = 'A clear description of the home page for searchers.'; // the real snapshot shape (WebsiteSnapshotBuilder)
        DB::table('website_revisions')->where('id', $revision->id)->update(['snapshot' => json_encode($snapshot)]);

        $live = $this->get($this->auditUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="audit-fix-live"', $live);
        $this->assertStringNotContainsString('rankings improved', $live);
        $this->assertStringNotContainsString('SEO improved', $live);
    }
}
