<?php

namespace Tests\Feature\Website;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Seo\SeoAuditRunner;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\SeoAuditRun;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Round 10 — a TOO SHORT meta description (META_DESCRIPTION_SHORT): the owner sees the existing text, edits it or asks for the existing
 * read-only suggestion, approves the replacement, and it is saved to the DRAFT only. Resolution is reported only after publishing and
 * a fresh completed audit.
 */
class SeoShortDescriptionFixApprovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private const SHORT = 'Photo booths for hire.';
    private const APPROVED = 'Photo booth rentals for weddings, birthdays and corporate events, with on-site attendants and instant prints. Check dates and book online.';

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: \App\Models\Website, 4: \App\Models\WebsitePage} */
    private function publishedWithShortDescription(array $pageOverrides = ['seo_title' => 'Photo Booth Rentals | Acme']): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Short description test.');
        $website = $this->createWebsite($business);
        $page = $this->homePage($website, array_merge(['title' => 'Photo Booth Rentals', 'meta_description' => self::SHORT], $pageOverrides));
        $this->authenticateAsCustomer($customer, ['website', 'view_seo']);
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))->assertRedirect();
        $website = $website->fresh();
        app(SeoAuditRunner::class)->runForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id);

        return [$customer, $business->fresh(), $workspace->fresh(), $website, $page->fresh()];
    }

    private function ai(?string $json, bool $budget = false, int $calls = 1): void
    {
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn($budget);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->times($calls)->andReturn($json);
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
    }

    private function auditHtml($workspace, $business): string
    {
        return $this->get(route('customer.workspaces.businesses.seo.audit.index', [$workspace->uid, $business->uid]))->assertOk()->getContent();
    }

    private function approve($workspace, $business, $page, array $data = [])
    {
        return $this->post(route('customer.workspaces.businesses.seo.audit.description.apply', [$workspace->uid, $business->uid, $page->uid]), array_merge([
            'meta_description' => self::APPROVED,
            'audit_run' => SeoAuditRun::query()->where('business_id', $business->id)->latest('id')->value('uid'),
            'draft_token' => $page->fresh()->draftToken(),
        ], $data));
    }

    public function test_the_existing_short_text_is_shown_for_review_and_loading_changes_nothing(): void
    {
        [, $business, $workspace, , $page] = $this->publishedWithShortDescription();
        $this->ai(null, false, 0);

        $html = $this->auditHtml($workspace, $business);

        $this->assertStringContainsString('data-role="audit-description-form"', $html);
        $this->assertStringContainsString('data-role="audit-current-description"', $html);
        $this->assertStringContainsString(self::SHORT, $html);
        $this->assertSame(self::SHORT, $page->fresh()->meta_description);
    }

    public function test_suggest_approve_replaces_the_draft_only_and_a_fresh_audit_verifies_it(): void
    {
        [, $business, $workspace, $website, $page] = $this->publishedWithShortDescription();
        $firstRevision = (int) $website->published_revision_id;
        $this->ai(json_encode(['description' => self::APPROVED]));

        $this->postJson(route('customer.workspaces.businesses.website.pages.suggestDescription', [$workspace->uid, $business->uid, $page->uid]))
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(self::SHORT, $page->fresh()->meta_description, 'A suggestion alone writes nothing.');

        $this->approve($workspace, $business, $page)->assertSessionHas('status', 'success');
        $this->assertSame(self::APPROVED, $page->fresh()->meta_description);
        $this->assertSame($firstRevision, (int) $website->fresh()->published_revision_id, 'Not published.');
        $drafted = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('data-role="audit-fix-drafted"', $drafted);
        $this->assertStringNotContainsString('data-role="audit-resolved"', $drafted);

        // The real publish runs; only the background audit job is held back so "published, awaiting a fresh audit" can be observed.
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\Seo\RunSeoAuditForRevision::class]);
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))->assertRedirect();
        $second = WebsiteRevision::find((int) $website->fresh()->published_revision_id);
        $this->assertSame(self::APPROVED, $second->snapshot['pages'][0]['seo']['meta_description']);
        $awaiting = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('data-role="audit-fix-published-pending"', $awaiting);
        $this->assertStringNotContainsString('data-role="audit-resolved"', $awaiting, 'Published is not yet verified.');

        app(SeoAuditRunner::class)->runForRevision((int) $business->id, (int) $website->id, (int) $second->id);
        $verified = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('Verified resolved by a fresh audit', $verified);
        $this->assertStringContainsString('fuller description', $verified);
    }

    public function test_a_replacement_that_is_still_too_short_or_too_long_or_unchanged_is_refused(): void
    {
        [, $business, $workspace, , $page] = $this->publishedWithShortDescription();

        $this->approve($workspace, $business, $page, ['meta_description' => 'Still far too short.'])->assertSessionHas('status', 'error');
        $this->approve($workspace, $business, $page, ['meta_description' => str_repeat('a', 161)])->assertSessionHas('status', 'error');
        $this->approve($workspace, $business, $page, ['meta_description' => self::SHORT])->assertSessionHas('status', 'error');

        $this->assertSame(self::SHORT, $page->fresh()->meta_description);
    }

    public function test_other_findings_stay_open_and_a_budget_exhaustion_leaves_manual_entry(): void
    {
        [, $business, $workspace, $website, $page] = $this->publishedWithShortDescription([]); // the SEO title is blank too
        $this->ai(null, true);
        $this->postJson(route('customer.workspaces.businesses.website.pages.suggestDescription', [$workspace->uid, $business->uid, $page->uid]))->assertStatus(429);
        $this->assertSame(self::SHORT, $page->fresh()->meta_description);

        $this->approve($workspace, $business, $page)->assertSessionHas('status', 'success');
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]));
        app(SeoAuditRunner::class)->runForRevision((int) $business->id, (int) $website->id, (int) $website->fresh()->published_revision_id);

        $html = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('Verified resolved by a fresh audit', $html);
        $this->assertStringNotContainsString('We found nothing to fix', $html);
        $this->assertStringContainsString('data-role="audit-title-proposal"', $html);
    }

    public function test_stale_moved_on_and_repeated_approvals_never_overwrite_newer_work(): void
    {
        [, $business, $workspace, , $page] = $this->publishedWithShortDescription();
        $token = $page->fresh()->draftToken();

        // Same-second edit to a different attribute: the old fingerprint must lose and the newer edit must survive.
        DB::table('website_pages')->where('id', $page->id)->update(['seo_title' => 'Changed a moment ago']);
        $this->approve($workspace, $business, $page, ['draft_token' => $token])->assertSessionHas('status', 'error');
        $this->assertSame(self::SHORT, $page->fresh()->meta_description);
        $this->assertSame('Changed a moment ago', $page->fresh()->seo_title);

        $this->approve($workspace, $business, $page, ['audit_run' => '00000000-0000-0000-0000-000000000000'])->assertSessionHas('status', 'error');
        $this->assertSame(self::SHORT, $page->fresh()->meta_description);

        $this->approve($workspace, $business, $page)->assertSessionHas('status', 'success');
        $savedToken = $page->fresh()->draftToken();
        $this->approve($workspace, $business, $page)->assertSessionHas('status', 'success'); // identical repeat: no-op
        $this->assertSame($savedToken, $page->fresh()->draftToken());

        $other = str_repeat('A different replacement that is long enough to pass the check. ', 2);
        $this->approve($workspace, $business, $page, ['meta_description' => $other])->assertSessionHas('status', 'error');
        $this->assertSame(self::APPROVED, $page->fresh()->meta_description, 'An adequate description is never replaced again.');
    }

    public function test_unauthorized_users_and_other_businesses_are_refused(): void
    {
        [$customer, $business, $workspace, , $page] = $this->publishedWithShortDescription();
        $url = route('customer.workspaces.businesses.seo.audit.description.apply', [$workspace->uid, $business->uid, $page->uid]);
        $payload = ['meta_description' => self::APPROVED, 'audit_run' => SeoAuditRun::query()->where('business_id', $business->id)->latest('id')->value('uid'), 'draft_token' => $page->draftToken()];

        $this->authenticateAsCustomer($customer, ['view_seo']);
        $this->assertContains($this->post($url, $payload)->getStatusCode(), [401, 403]);

        [$intruder, , $otherWorkspace] = $this->entitledTenant();
        app(EntitlementManager::class)->changePlan($otherWorkspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Intruder.');
        $this->authenticateAsCustomer($intruder, ['website', 'view_seo']);
        $this->post($url, $payload)->assertNotFound();

        $this->assertSame(self::SHORT, $page->fresh()->meta_description);
    }
}
