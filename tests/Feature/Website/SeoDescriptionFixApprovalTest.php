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
 * Round 9 — a blank meta description, from a completed audit to a verified resolution: optional suggestion (explicit click, existing
 * read-only endpoint, mocked AI) → explicit approval → exact text in the DRAFT → separate publish → fresh audit.
 */
class SeoDescriptionFixApprovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private const SUGGESTED = 'Plan a memorable event with our photo booth rentals. See what is included and how to book your date.';
    private const APPROVED = 'Photo booth rentals for weddings, birthdays and corporate events, with on-site attendants and instant prints. Check dates and book online.';

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: \App\Models\Website, 4: \App\Models\WebsitePage} */
    private function publishedWithBlankDescription(array $pageOverrides = ['seo_title' => 'Photo Booth Rentals | Acme']): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Description fix test.');
        $website = $this->createWebsite($business);
        $page = $this->homePage($website, array_merge(['title' => 'Photo Booth Rentals'], $pageOverrides));
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

    private function runUid($business): string
    {
        return SeoAuditRun::query()->where('business_id', $business->id)->latest('id')->value('uid');
    }

    private function approve($workspace, $business, $page, array $data = [])
    {
        return $this->post(route('customer.workspaces.businesses.seo.audit.description.apply', [$workspace->uid, $business->uid, $page->uid]), array_merge([
            'meta_description' => self::APPROVED,
            'audit_run' => $this->runUid($business),
            'draft_token' => $page->fresh()->draftToken(),
        ], $data));
    }

    private function suggest($workspace, $business, $page)
    {
        return $this->postJson(route('customer.workspaces.businesses.website.pages.suggestDescription', [$workspace->uid, $business->uid, $page->uid]));
    }

    public function test_the_finding_offers_the_correction_and_loading_the_audit_never_calls_the_ai(): void
    {
        [, $business, $workspace, , $page] = $this->publishedWithBlankDescription();
        $this->ai(null, false, 0);

        $html = $this->auditHtml($workspace, $business);

        $this->assertStringContainsString('data-role="audit-description-form"', $html);
        $this->assertStringContainsString('data-role="audit-suggest-description"', $html);
        $this->assertStringContainsString('Meta description for &quot;Photo Booth Rentals&quot;', $html);
        $this->assertNull($page->fresh()->meta_description, 'No mutation on load.');
    }

    public function test_suggest_then_approve_saves_the_exact_text_publishes_nothing_and_a_fresh_audit_verifies_it(): void
    {
        [, $business, $workspace, $website, $page] = $this->publishedWithBlankDescription();
        $firstRevision = (int) $website->published_revision_id;
        $this->ai(json_encode(['description' => self::SUGGESTED]));

        // Explicit click: the suggestion is returned for review and saves nothing.
        $this->suggest($workspace, $business, $page)->assertOk()->assertJson(['ok' => true, 'description' => self::SUGGESTED]);
        $this->assertNull($page->fresh()->meta_description);

        // The owner edits it and approves: exactly the approved text reaches the draft; nothing goes live.
        $this->approve($workspace, $business, $page)->assertSessionHas('status', 'success');
        $this->assertSame(self::APPROVED, $page->fresh()->meta_description);
        $this->assertSame('Photo Booth Rentals | Acme', $page->fresh()->seo_title, 'Other fields untouched.');
        $this->assertSame($firstRevision, (int) $website->fresh()->published_revision_id);
        $drafted = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('data-role="audit-fix-drafted"', $drafted);
        $this->assertStringNotContainsString('data-role="audit-fix-live"', $drafted);

        // Published through the real action: live, awaiting verification.
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))->assertRedirect();
        $second = WebsiteRevision::find((int) $website->fresh()->published_revision_id);
        $this->assertSame(self::APPROVED, $second->snapshot['pages'][0]['seo']['meta_description']);
        $awaiting = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('data-role="audit-fix-live"', $awaiting);
        $this->assertStringNotContainsString('data-role="audit-resolved"', $awaiting);

        // Only a fresh completed audit verifies it.
        app(SeoAuditRunner::class)->runForRevision((int) $business->id, (int) $website->id, (int) $second->id);
        $verified = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('Verified resolved by a fresh audit', $verified);
        $this->assertStringContainsString('description', $verified);
    }

    public function test_other_outstanding_findings_stay_outstanding(): void
    {
        [, $business, $workspace, $website, $page] = $this->publishedWithBlankDescription([]); // the SEO title is blank too
        $this->approve($workspace, $business, $page);
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]));
        app(SeoAuditRunner::class)->runForRevision((int) $business->id, (int) $website->id, (int) $website->fresh()->published_revision_id);

        $html = $this->auditHtml($workspace, $business);

        $this->assertStringContainsString('Verified resolved by a fresh audit', $html);
        $this->assertStringNotContainsString('We found nothing to fix', $html);
        $this->assertStringContainsString('data-role="audit-title-proposal"', $html, 'The blank title is still offered.');
    }

    public function test_a_description_outside_70_to_160_characters_is_refused(): void
    {
        [, $business, $workspace, , $page] = $this->publishedWithBlankDescription();

        $this->approve($workspace, $business, $page, ['meta_description' => 'Too short to be useful.'])->assertSessionHas('status', 'error');
        $this->approve($workspace, $business, $page, ['meta_description' => str_repeat('a', 161)])->assertSessionHas('status', 'error');
        $this->approve($workspace, $business, $page, ['meta_description' => '   '])->assertSessionHasErrors();

        $this->assertNull($page->fresh()->meta_description);
    }

    public function test_an_exhausted_budget_changes_nothing_and_manual_entry_still_works(): void
    {
        [, $business, $workspace, , $page] = $this->publishedWithBlankDescription();
        $this->ai(null, true);

        $this->suggest($workspace, $business, $page)->assertStatus(429);
        $this->assertNull($page->fresh()->meta_description);

        $this->approve($workspace, $business, $page)->assertSessionHas('status', 'success');
        $this->assertSame(self::APPROVED, $page->fresh()->meta_description);
    }

    public function test_a_stale_draft_a_moved_on_audit_an_existing_value_and_a_repeat_never_overwrite(): void
    {
        [, $business, $workspace, , $page] = $this->publishedWithBlankDescription();
        $token = $page->fresh()->draftToken();

        // A same-second edit to another attribute (updated_at untouched): the old fingerprint must lose.
        DB::table('website_pages')->where('id', $page->id)->update(['seo_title' => 'Changed a moment ago']);
        $this->approve($workspace, $business, $page, ['draft_token' => $token])->assertSessionHas('status', 'error');
        $this->assertNull($page->fresh()->meta_description);
        $this->assertSame('Changed a moment ago', $page->fresh()->seo_title);

        $this->approve($workspace, $business, $page, ['audit_run' => '00000000-0000-0000-0000-000000000000'])->assertSessionHas('status', 'error');
        $this->assertNull($page->fresh()->meta_description);

        $this->approve($workspace, $business, $page)->assertSessionHas('status', 'success');
        $savedAt = $page->fresh()->updated_at->getTimestamp();
        $this->approve($workspace, $business, $page)->assertSessionHas('status', 'success'); // identical repeat
        $this->assertSame($savedAt, $page->fresh()->updated_at->getTimestamp());

        $other = str_repeat('Different text that is long enough to pass the length check. ', 2);
        $this->approve($workspace, $business, $page, ['meta_description' => $other])->assertSessionHas('status', 'error');
        $this->assertSame(self::APPROVED, $page->fresh()->meta_description);
    }

    public function test_unauthorized_users_and_other_businesses_are_refused(): void
    {
        [$customer, $business, $workspace, , $page] = $this->publishedWithBlankDescription();
        $url = route('customer.workspaces.businesses.seo.audit.description.apply', [$workspace->uid, $business->uid, $page->uid]);
        $payload = ['meta_description' => self::APPROVED, 'audit_run' => $this->runUid($business), 'draft_token' => $page->draftToken()];

        $this->authenticateAsCustomer($customer, ['view_seo']);
        $this->assertContains($this->post($url, $payload)->getStatusCode(), [401, 403]);

        [$intruder, , $otherWorkspace] = $this->entitledTenant();
        app(EntitlementManager::class)->changePlan($otherWorkspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Intruder.');
        $this->authenticateAsCustomer($intruder, ['website', 'view_seo']);
        $this->post($url, $payload)->assertNotFound();

        $this->assertNull($page->fresh()->meta_description);
    }
}
