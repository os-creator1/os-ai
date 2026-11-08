<?php

namespace Tests\Feature\Website;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Seo\SeoAuditRunner;
use App\Models\SeoAuditRun;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Round 8 — a blank SEO title, from a real completed audit to a verified resolution, through the real publish action:
 * proposal (read-only) → explicit approval → exact text in the DRAFT → separate publish → fresh audit. Descriptions and other findings
 * are out of scope and must never be reported as fixed.
 */
class SeoTitleFixApprovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private const APPROVED = 'Photo Booth Rentals for Events | Acme';

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: \App\Models\Website, 4: \App\Models\WebsitePage} */
    private function publishedWithBlankTitle(array $pageOverrides = []): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Title fix test.');
        $website = $this->createWebsite($business);
        $page = $this->homePage($website, array_merge(['title' => 'Photo Booth Rentals'], $pageOverrides));
        // Publishing needs `website`; the SEO audit page needs `view_seo`. The authorized customer has both.
        $this->authenticateAsCustomer($customer, ['website', 'view_seo']);
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))->assertRedirect();
        $website = $website->fresh();
        app(SeoAuditRunner::class)->runForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id);

        return [$customer, $business->fresh(), $workspace->fresh(), $website, $page->fresh()];
    }

    private function auditHtml($workspace, $business): string
    {
        return $this->get(route('customer.workspaces.businesses.seo.audit.index', [$workspace->uid, $business->uid]))->assertOk()->getContent();
    }

    private function runUid($business): string
    {
        return SeoAuditRun::query()->where('business_id', $business->id)->latest('id')->value('uid');
    }

    private function approve($workspace, $business, $page, array $data = [])
    {
        return $this->post(route('customer.workspaces.businesses.seo.audit.title.apply', [$workspace->uid, $business->uid, $page->uid]), array_merge([
            'seo_title' => self::APPROVED,
            'audit_run' => $this->runUid($business),
            'draft_token' => $page->fresh()->draftToken(),
        ], $data));
    }

    public function test_blank_title_to_approved_draft_to_published_to_verified_resolution(): void
    {
        [, $business, $workspace, $website, $page] = $this->publishedWithBlankTitle();
        $firstRevision = (int) $website->published_revision_id;

        // The finding shows the page and an editable proposal; showing it changes nothing.
        $html = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('data-role="audit-title-proposal"', $html);
        $this->assertStringContainsString('Proposed title for &quot;Photo Booth Rentals&quot;', $html);
        $this->assertNull($page->fresh()->seo_title, 'No mutation before approval.');

        // Explicit approval of the (edited) text saves exactly that text to the draft — and nothing goes live.
        $this->approve($workspace, $business, $page)->assertSessionHas('status', 'success');
        $this->assertSame(self::APPROVED, $page->fresh()->seo_title);
        $this->assertSame($firstRevision, (int) $website->fresh()->published_revision_id, 'No automatic publication.');
        $this->assertNull(WebsiteRevision::find($firstRevision)->snapshot['pages'][0]['seo']['seo_title']);
        $drafted = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('data-role="audit-fix-drafted"', $drafted);
        $this->assertStringNotContainsString('data-role="audit-fix-live"', $drafted);
        $this->assertStringNotContainsString('data-role="audit-title-proposal"', $drafted);

        // The owner publishes through the real action: live, but not yet verified.
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))->assertRedirect();
        $second = WebsiteRevision::find((int) $website->fresh()->published_revision_id);
        $this->assertSame(self::APPROVED, $second->snapshot['pages'][0]['seo']['seo_title']);
        $awaiting = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('data-role="audit-fix-live"', $awaiting);
        $this->assertStringNotContainsString('data-role="audit-resolved"', $awaiting);

        // A fresh completed audit is what verifies it.
        app(SeoAuditRunner::class)->runForRevision((int) $business->id, (int) $website->id, (int) $second->id);
        $verified = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('Verified resolved by a fresh audit', $verified);
        $this->assertStringContainsString('page title', $verified);
        $this->assertDoesNotMatchRegularExpression('/rankings? (improved|increase)|more (traffic|bookings)/i', $verified);
    }

    public function test_other_findings_remain_so_the_audit_is_never_called_clean(): void
    {
        [, $business, $workspace, $website, $page] = $this->publishedWithBlankTitle(); // the description is blank too
        $this->approve($workspace, $business, $page);
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]));
        app(SeoAuditRunner::class)->runForRevision((int) $business->id, (int) $website->id, (int) $website->fresh()->published_revision_id);

        $html = $this->auditHtml($workspace, $business);

        $this->assertStringContainsString('Verified resolved by a fresh audit', $html, 'The title is resolved...');
        $this->assertStringNotContainsString('We found nothing to fix', $html, '...but the description finding keeps the audit open.');
        $this->assertSame(1, substr_count($html, 'data-role="audit-draft-fix-link"'));
    }

    public function test_a_stale_draft_or_a_moved_on_audit_saves_nothing(): void
    {
        [, $business, $workspace, , $page] = $this->publishedWithBlankTitle();

        $this->approve($workspace, $business, $page, ['draft_token' => str_repeat('0', 64)])->assertSessionHas('status', 'error');
        $this->approve($workspace, $business, $page, ['audit_run' => '00000000-0000-0000-0000-000000000000'])->assertSessionHas('status', 'error');
        $this->approve($workspace, $business, $page, ['seo_title' => '   '])->assertSessionHasErrors();

        $this->assertNull($page->fresh()->seo_title);
    }

    public function test_a_same_second_edit_after_the_proposal_was_shown_cannot_be_overwritten(): void
    {
        [, $business, $workspace, , $page] = $this->publishedWithBlankTitle();
        $token = $page->fresh()->draftToken(); // what the form carried when it was shown
        $before = $page->fresh()->updated_at->getTimestamp();

        // Another edit lands in the very same second: only a state fingerprint can tell (updated_at is deliberately left untouched).
        \Illuminate\Support\Facades\DB::table('website_pages')->where('id', $page->id)->update(['meta_description' => 'Written by someone else a moment ago.']);
        $this->assertSame($before, $page->fresh()->updated_at->getTimestamp());
        $this->assertNotSame($token, $page->fresh()->draftToken());

        $this->approve($workspace, $business, $page, ['draft_token' => $token])->assertSessionHas('status', 'error');

        $this->assertNull($page->fresh()->seo_title, 'The stale approval saved nothing.');
        $this->assertSame('Written by someone else a moment ago.', $page->fresh()->meta_description, 'The newer edit survived.');
    }

    public function test_an_existing_title_is_never_overwritten_and_a_repeat_is_a_no_op(): void
    {
        [, $business, $workspace, , $page] = $this->publishedWithBlankTitle();

        $this->approve($workspace, $business, $page)->assertSessionHas('status', 'success');
        $savedAt = $page->fresh()->updated_at->getTimestamp();

        $this->approve($workspace, $business, $page)->assertSessionHas('status', 'success'); // same text again
        $this->assertSame(self::APPROVED, $page->fresh()->seo_title);
        $this->assertSame($savedAt, $page->fresh()->updated_at->getTimestamp(), 'A repeat writes nothing.');

        $this->approve($workspace, $business, $page, ['seo_title' => 'A different title'])->assertSessionHas('status', 'error');
        $this->assertSame(self::APPROVED, $page->fresh()->seo_title, 'Nothing was overwritten.');
    }

    public function test_another_business_and_a_user_without_website_permission_are_refused(): void
    {
        [$customer, $business, $workspace, , $page] = $this->publishedWithBlankTitle();
        $url = route('customer.workspaces.businesses.seo.audit.title.apply', [$workspace->uid, $business->uid, $page->uid]);
        $payload = ['seo_title' => 'Hijack', 'audit_run' => $this->runUid($business), 'draft_token' => $page->draftToken()];

        $this->authenticateAsCustomer($customer, ['view_seo']);
        $this->assertContains($this->post($url, $payload)->getStatusCode(), [401, 403], 'No website permission: refused.');

        [$intruder, , $otherWorkspace] = $this->entitledTenant();
        app(EntitlementManager::class)->changePlan($otherWorkspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Intruder.');
        $this->authenticateAsCustomer($intruder);
        $this->post($url, $payload)->assertNotFound();

        $this->assertNull($page->fresh()->seo_title);
    }
}
