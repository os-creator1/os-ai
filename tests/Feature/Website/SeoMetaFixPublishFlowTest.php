<?php

namespace Tests\Feature\Website;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Seo\SeoAuditRunner;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsitePublisher;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The whole SEO metadata fix, through the REAL actions only: publish (via the website publish endpoint), audit (via SeoAuditRunner and the
 * audit page), suggest and save (via the page editor endpoints). Nothing edits a revision snapshot by hand.
 */
class SeoMetaFixPublishFlowTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private const DESCRIPTION = 'Plan a memorable event with our photo booth rentals. See what is included and how to book your date.';
    private const TITLE = 'Photo Booth Rentals | Acme Events';

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: \App\Models\Website, 4: \App\Models\WebsitePage} */
    private function publishedWithBlankMetadata(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Fix flow test.');
        $website = $this->createWebsite($business);
        $page = $this->homePage($website, ['title' => 'Photo Booth Rentals']);
        $this->authenticateAsCustomer($customer, ['website', 'view_seo', 'manage_seo']);

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))->assertRedirect();

        return [$customer, $business->fresh(), $workspace->fresh(), $website->fresh(), $page->fresh()];
    }

    private function audit($business, $website): void
    {
        app(SeoAuditRunner::class)->runForRevision((int) $business->id, (int) $website->id, (int) $website->fresh()->published_revision_id);
    }

    private function auditPage($workspace, $business): string
    {
        return $this->get(route('customer.workspaces.businesses.seo.audit.index', [$workspace->uid, $business->uid]))->assertOk()->getContent();
    }

    private function save($workspace, $business, $page, array $data)
    {
        return $this->put(route('customer.workspaces.businesses.website.pages.update', [$workspace->uid, $business->uid, $page->uid]), array_merge([
            'title' => $page->title, 'slug' => $page->slug ?? '', 'is_home' => $page->is_home ? '1' : '0',
            'sections' => json_encode($page->sections ?? []), 'seo_title' => $page->seo_title ?? '',
            'noindex' => $page->noindex ? '1' : '0',
            'draft_token' => $page->fresh()->draftToken(),
        ], $data));
    }

    public function test_finding_to_draft_to_publish_to_verified_resolution(): void
    {
        [, $business, $workspace, $website, $page] = $this->publishedWithBlankMetadata();
        $firstRevision = (int) $website->published_revision_id;
        $this->audit($business, $website);

        // 1-2. The existing audit finds both blanks and offers the prepared action.
        $html = $this->auditPage($workspace, $business);
        $this->assertSame(2, substr_count($html, 'data-role="audit-draft-fix-link"'), 'A missing title and a missing description.');
        $this->assertStringNotContainsString('data-role="audit-resolved"', $html);

        // 3. Suggest (read-only), then the owner approves by saving the exact text through the canonical editor.
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->once()->andReturn(json_encode(['description' => self::DESCRIPTION]));
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
        $proposed = $this->postJson(route('customer.workspaces.businesses.website.pages.suggestDescription', [$workspace->uid, $business->uid, $page->uid]))->assertOk()->json('description');
        $this->assertNull($page->fresh()->meta_description, 'A suggestion alone writes nothing.');

        $this->save($workspace, $business, $page, ['meta_description' => $proposed, 'seo_title' => self::TITLE])->assertSessionHas('status', 'success');
        $this->assertSame(self::DESCRIPTION, $page->fresh()->meta_description);
        $this->assertSame(self::TITLE, $page->fresh()->seo_title);

        // 4. Nothing is live yet.
        $this->assertSame($firstRevision, (int) $website->fresh()->published_revision_id);
        $this->assertNull(WebsiteRevision::find($firstRevision)->snapshot['pages'][0]['seo']['meta_description']);
        $drafted = $this->auditPage($workspace, $business);
        $this->assertStringContainsString('data-role="audit-fix-drafted"', $drafted);
        $this->assertStringNotContainsString('data-role="audit-fix-live"', $drafted);

        // 5-6. Publish through the real action: the new revision carries the approved values.
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))->assertRedirect();
        $second = WebsiteRevision::find((int) $website->fresh()->published_revision_id);
        $this->assertNotSame($firstRevision, (int) $second->id);
        $this->assertSame(self::DESCRIPTION, $second->snapshot['pages'][0]['seo']['meta_description']);
        $this->assertSame(self::TITLE, $second->snapshot['pages'][0]['seo']['seo_title']);

        // Published, but not yet verified by an audit: the old audit still lists the findings and nothing is called resolved.
        $awaiting = $this->auditPage($workspace, $business);
        $this->assertStringContainsString('data-role="audit-fix-live"', $awaiting);
        $this->assertStringNotContainsString('data-role="audit-resolved"', $awaiting);

        // 7. A fresh internal audit of the published revision no longer has the findings: only now is it resolved.
        $this->audit($business, $website);
        $verified = $this->auditPage($workspace, $business);
        $this->assertStringContainsString('data-role="audit-resolved"', $verified);
        $this->assertStringContainsString('Verified resolved by a fresh audit', $verified);
        $this->assertSame(0, substr_count($verified, 'data-role="audit-draft-fix-link"'));
        $this->assertStringNotContainsString('rankings improved', $verified);
    }

    public function test_a_failed_publication_never_reports_a_live_or_resolved_fix(): void
    {
        [, $business, $workspace, $website, $page] = $this->publishedWithBlankMetadata();
        $revision = (int) $website->published_revision_id;
        $this->audit($business, $website);
        $this->save($workspace, $business, $page, ['meta_description' => self::DESCRIPTION]);

        $this->mock(WebsitePublisher::class, fn ($m) => $m->shouldReceive('publish')->andThrow(ValidationException::withMessages(['website' => 'Publishing failed.'])));
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]));

        $this->assertSame($revision, (int) $website->fresh()->published_revision_id, 'The live site is unchanged.');
        $html = $this->auditPage($workspace, $business);
        $this->assertStringContainsString('data-role="audit-fix-drafted"', $html);
        $this->assertStringNotContainsString('data-role="audit-fix-live"', $html);
        $this->assertStringNotContainsString('data-role="audit-resolved"', $html);
    }

    public function test_a_rejected_suggestion_changes_nothing_and_a_stale_save_cannot_win(): void
    {
        [, $business, $workspace, $website, $page] = $this->publishedWithBlankMetadata();

        $before = $page->fresh()->only(['meta_description', 'seo_title']);
        // The owner dismisses the suggestion: no request is made. A stale editor then tries to save over newer work.
        $stale = $page->fresh()->draftToken();
        \Illuminate\Support\Facades\DB::table('website_pages')->where('id', $page->id)->update(['meta_description' => 'Newer work from another editor.']);
        $this->save($workspace, $business, $page, ['meta_description' => 'Stale text.', 'draft_token' => $stale])->assertSessionHas('status', 'error');

        $this->assertSame('Newer work from another editor.', $page->fresh()->meta_description, 'Stale editor must preserve the newer draft.');
        $this->assertSame($before['seo_title'], $page->fresh()->seo_title);
        $this->assertSame((int) $website->published_revision_id, (int) $website->fresh()->published_revision_id);
    }

    public function test_another_business_cannot_see_or_change_the_fix(): void
    {
        [, $business, $workspace, , $page] = $this->publishedWithBlankMetadata();
        [$intruder, $otherBusiness, $otherWorkspace] = $this->entitledTenant();
        app(EntitlementManager::class)->changePlan($otherWorkspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Intruder.');
        $this->authenticateAsCustomer($intruder);

        $this->put(route('customer.workspaces.businesses.website.pages.update', [$otherWorkspace->uid, $otherBusiness->uid, $page->uid]), ['title' => 'X', 'meta_description' => 'Hijack'])->assertNotFound();
        $this->put(route('customer.workspaces.businesses.website.pages.update', [$workspace->uid, $business->uid, $page->uid]), ['title' => 'X', 'meta_description' => 'Hijack'])->assertStatus(404);

        $this->assertNotSame('Hijack', $page->fresh()->meta_description);
    }
}
