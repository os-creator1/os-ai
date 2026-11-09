<?php

namespace Tests\Feature\Website;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Seo\SeoAuditRunner;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\SeoAuditRun;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Round 12 — three more audit rules corrected from the audit page: a composed SEO title that is too long, an SEO title shared with another
 * page, and a meta description shared with another page. Real publish and real audit runs; AI only through the existing read-only
 * suggestion endpoint, mocked.
 */
class SeoMetadataOptimizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private const LONG_TITLE = 'Photo Booth Rentals for Weddings, Birthdays and Corporate Events'; // 64 chars: over the 60 recommended even on its own
    private const COMPOSED_TOO_LONG = 'Photo Booth Rentals for Weddings and Birthdays'; // 46 chars, but 61 once " | Test Website" is added
    private const SHORT_TITLE = 'Photo Booth Rentals for Events'; // 30 chars, 45 composed
    private const SHARED_TITLE = 'Photo Booth Rentals | Acme';
    private const UNIQUE_TITLE = 'Event Photo Booth Hire in Town';
    private const DESC_ONE = 'Plan a memorable event with our photo booth rentals. See what is included and how to book your date.';
    private const DESC_TWO = 'Weddings, birthdays and corporate parties: photo booth packages with attendants and instant prints.';
    private const DESC_UNIQUE = 'Compare our photo booth packages, see what is included and check dates for your event in minutes.';

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: \App\Models\Website, 4: WebsitePage, 5: WebsitePage} */
    private function site(array $home = [], array $about = []): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Metadata optimisation test.');
        $website = $this->createWebsite($business);
        $good = ['seo_title' => 'Unrelated Page Title Alpha', 'meta_description' => self::DESC_TWO];
        $homePage = $this->homePage($website, array_merge(['title' => 'Photo Booth Rentals'], $good, ['seo_title' => 'Home Page Title Beta', 'meta_description' => self::DESC_ONE], $home));
        $aboutPage = $this->homePage($website, array_merge(['title' => 'About Us', 'slug' => 'about', 'is_home' => false, 'sort_order' => 1], $good, $about));
        $this->authenticateAsCustomer($customer, ['website', 'view_seo']);
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))->assertRedirect();
        $website = $website->fresh();
        app(SeoAuditRunner::class)->runForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id);

        return [$customer, $business->fresh(), $workspace->fresh(), $website, $homePage->fresh(), $aboutPage->fresh()];
    }

    private function auditHtml($workspace, $business): string
    {
        return $this->get(route('customer.workspaces.businesses.seo.audit.index', [$workspace->uid, $business->uid]))->assertOk()->getContent();
    }

    private function runUid($business): string
    {
        return SeoAuditRun::query()->where('business_id', $business->id)->latest('id')->value('uid');
    }

    private function approveTitle($workspace, $business, $page, string $value, array $data = [])
    {
        return $this->post(route('customer.workspaces.businesses.seo.audit.title.apply', [$workspace->uid, $business->uid, $page->uid]), array_merge([
            'seo_title' => $value, 'audit_run' => $this->runUid($business), 'draft_token' => $page->fresh()->draftToken(),
        ], $data));
    }

    private function approveDescription($workspace, $business, $page, string $value, array $data = [])
    {
        return $this->post(route('customer.workspaces.businesses.seo.audit.description.apply', [$workspace->uid, $business->uid, $page->uid]), array_merge([
            'meta_description' => $value, 'audit_run' => $this->runUid($business), 'draft_token' => $page->fresh()->draftToken(),
        ], $data));
    }

    private function publishAndAudit($workspace, $business, $website): void
    {
        Queue::fake([\App\Jobs\Seo\RunSeoAuditForRevision::class]);
        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))->assertRedirect();
        $this->assertStringContainsString('data-role="audit-fix-published-pending"', $this->auditHtml($workspace, $business), 'Published, awaiting a fresh audit.');
        app(SeoAuditRunner::class)->runForRevision((int) $business->id, (int) $website->id, (int) $website->fresh()->published_revision_id);
    }

    // ------------------------------------------------------------------ A. Composed title too long

    public function test_a_title_that_is_too_long_once_composed_is_shown_with_its_real_length_and_fixed_through_to_verification(): void
    {
        [, $business, $workspace, $website, $home] = $this->site(['seo_title' => self::LONG_TITLE]);

        $html = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('data-role="audit-fix-context"', $html);
        $this->assertStringContainsString(self::LONG_TITLE, $html);
        $this->assertStringContainsString('(64 characters as searchers see it, including your business name when it fits; recommended maximum 60)', $html);
        $this->assertStringContainsString('data-role="audit-title-proposal"', $html);
        $this->assertSame(self::LONG_TITLE, $home->fresh()->seo_title, 'Showing the finding changes nothing.');

        // The composed length is what counts: 46 characters alone, 61 with the business name.
        $this->assertSame(46, strlen(self::COMPOSED_TOO_LONG));
        $this->approveTitle($workspace, $business, $home, self::COMPOSED_TOO_LONG)->assertSessionHas('status', 'error');
        $this->assertSame(self::LONG_TITLE, $home->fresh()->seo_title);

        $this->approveTitle($workspace, $business, $home, self::SHORT_TITLE)->assertSessionHas('status', 'success');
        $this->assertSame(self::SHORT_TITLE, $home->fresh()->seo_title);
        $this->assertStringContainsString('data-role="audit-fix-drafted"', $this->auditHtml($workspace, $business));
        $this->assertStringNotContainsString('data-role="audit-resolved"', $this->auditHtml($workspace, $business));

        $this->publishAndAudit($workspace, $business, $website);
        $verified = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('Verified resolved by a fresh audit', $verified);
        $this->assertStringContainsString('shorter page title', $verified);
    }

    // ------------------------------------------------------------------ B. Duplicate title

    public function test_a_duplicate_title_shows_the_conflict_and_rejects_another_pages_title_even_with_different_spacing_and_case(): void
    {
        [, $business, $workspace, $website, $home, $about] = $this->site(['seo_title' => self::SHARED_TITLE], ['seo_title' => '  photo booth   RENTALS | acme ']);

        $html = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('Also used by: About Us.', $html);

        // Another page's title (audit normalisation) is refused; nothing else is touched.
        $this->approveTitle($workspace, $business, $home, 'PHOTO BOOTH rentals | ACME')->assertSessionHas('status', 'error');
        $this->approveTitle($workspace, $business, $home, '  photo booth   RENTALS | acme ')->assertSessionHas('status', 'error');
        $this->assertSame(self::SHARED_TITLE, $home->fresh()->seo_title);
        $this->assertSame('  photo booth   RENTALS | acme ', $about->fresh()->seo_title, 'The other page is never changed.');
        // The unchanged duplicate is not "already done".
        $this->approveTitle($workspace, $business, $home, self::SHARED_TITLE)->assertSessionHas('status', 'error');

        $this->approveTitle($workspace, $business, $home, self::UNIQUE_TITLE)->assertSessionHas('status', 'success');
        $this->assertSame(self::UNIQUE_TITLE, $home->fresh()->seo_title);
        $this->assertSame('  photo booth   RENTALS | acme ', $about->fresh()->seo_title);

        $this->publishAndAudit($workspace, $business, $website);
        $verified = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('Verified resolved by a fresh audit', $verified);
        $this->assertStringContainsString('page title of its own', $verified);
        $this->assertStringNotContainsString('Also used by', $verified);
    }

    // ------------------------------------------------------------------ C. Duplicate description (with the optional suggestion)

    public function test_a_duplicate_description_is_replaced_manually_or_from_a_suggestion_and_verified_after_a_fresh_audit(): void
    {
        [, $business, $workspace, $website, $home, $about] = $this->site(['meta_description' => self::DESC_ONE], ['meta_description' => self::DESC_ONE]);

        $html = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('data-role="audit-description-form"', $html);
        $this->assertStringContainsString('Also used by: About Us.', $html);
        $this->assertStringContainsString('shared with another page', $html);

        // Optional AI: an explicit click on the existing endpoint returns text and saves nothing.
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->once()->andReturn(json_encode(['description' => self::DESC_UNIQUE]));
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
        $this->postJson(route('customer.workspaces.businesses.website.pages.suggestDescription', [$workspace->uid, $business->uid, $home->uid]))->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(self::DESC_ONE, $home->fresh()->meta_description);

        // Not another page's description, not too short, not unchanged.
        $this->approveDescription($workspace, $business, $home, '  PLAN a memorable event with our photo booth rentals.  See what is included and how to book your date.')->assertSessionHas('status', 'error');
        $this->approveDescription($workspace, $business, $home, 'Too short to be a useful description.')->assertSessionHas('status', 'error');
        $this->approveDescription($workspace, $business, $home, self::DESC_ONE)->assertSessionHas('status', 'error');
        $this->assertSame(self::DESC_ONE, $home->fresh()->meta_description);

        $this->approveDescription($workspace, $business, $home, self::DESC_UNIQUE)->assertSessionHas('status', 'success');
        $this->assertSame(self::DESC_UNIQUE, $home->fresh()->meta_description);
        $this->assertSame(self::DESC_ONE, $about->fresh()->meta_description, 'The other page is never changed.');

        $this->publishAndAudit($workspace, $business, $website);
        $this->assertStringContainsString('Verified resolved by a fresh audit', $this->auditHtml($workspace, $business));
    }

    public function test_when_the_suggestion_is_unavailable_the_manual_correction_still_works(): void
    {
        [, $business, $workspace, , $home] = $this->site(['meta_description' => self::DESC_ONE], ['meta_description' => self::DESC_ONE]);
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(true);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->once()->andReturn(null);
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->postJson(route('customer.workspaces.businesses.website.pages.suggestDescription', [$workspace->uid, $business->uid, $home->uid]))->assertStatus(429);
        $this->assertSame(self::DESC_ONE, $home->fresh()->meta_description);

        $this->approveDescription($workspace, $business, $home, self::DESC_UNIQUE)->assertSessionHas('status', 'success');
        $this->assertSame(self::DESC_UNIQUE, $home->fresh()->meta_description);
    }

    // ------------------------------------------------------------------ D. Shared behaviour

    public function test_other_findings_stay_open_and_loading_the_audit_never_calls_the_ai_or_writes(): void
    {
        // The home page has a long title AND a description shared with About; About also has a blank title.
        [, $business, $workspace, $website, $home, $about] = $this->site(
            ['seo_title' => self::LONG_TITLE, 'meta_description' => self::DESC_ONE],
            ['seo_title' => null, 'meta_description' => self::DESC_ONE],
        );
        $this->app->instance(WebsiteAiGenerationClient::class, \Mockery::mock(WebsiteAiGenerationClient::class)); // any call would throw
        $before = [$home->fresh()->draftToken(), $about->fresh()->draftToken()];

        $html = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('data-role="audit-title-proposal"', $html);
        $this->assertStringContainsString('data-role="audit-description-form"', $html);
        $this->assertSame($before, [$home->fresh()->draftToken(), $about->fresh()->draftToken()], 'Nothing was written on load.');

        $this->approveTitle($workspace, $business, $home, self::SHORT_TITLE)->assertSessionHas('status', 'success');
        $this->publishAndAudit($workspace, $business, $website);
        $after = $this->auditHtml($workspace, $business);

        $this->assertStringContainsString('Verified resolved by a fresh audit', $after);
        $this->assertStringContainsString('data-role="audit-description-form"', $after, 'The shared description is still outstanding.');
        $this->assertStringNotContainsString('We found nothing to fix', $after);
    }

    public function test_stale_moved_on_and_repeated_approvals_never_overwrite_newer_work(): void
    {
        [, $business, $workspace, , $home] = $this->site(['seo_title' => self::LONG_TITLE]);
        $token = $home->fresh()->draftToken();

        DB::table('website_pages')->where('id', $home->id)->update(['meta_description' => 'Edited a moment ago by someone else, in the same second.']);
        $this->approveTitle($workspace, $business, $home, self::SHORT_TITLE, ['draft_token' => $token])->assertSessionHas('status', 'error');
        $this->assertSame(self::LONG_TITLE, $home->fresh()->seo_title);
        $this->assertSame('Edited a moment ago by someone else, in the same second.', $home->fresh()->meta_description);

        $this->approveTitle($workspace, $business, $home, self::SHORT_TITLE, ['audit_run' => '00000000-0000-0000-0000-000000000000'])->assertSessionHas('status', 'error');
        $this->assertSame(self::LONG_TITLE, $home->fresh()->seo_title);

        $this->approveTitle($workspace, $business, $home, self::SHORT_TITLE)->assertSessionHas('status', 'success');
        $saved = $home->fresh()->draftToken();
        $this->approveTitle($workspace, $business, $home, self::SHORT_TITLE)->assertSessionHas('status', 'success'); // identical repeat
        $this->assertSame($saved, $home->fresh()->draftToken());

        // The problem is gone from the draft, so a different title is never written over it.
        $this->approveTitle($workspace, $business, $home, 'A Different Valid Title')->assertSessionHas('status', 'error');
        $this->assertSame(self::SHORT_TITLE, $home->fresh()->seo_title);
    }

    public function test_unauthorized_users_and_other_businesses_are_refused_and_a_viewer_gets_manual_guidance(): void
    {
        [$customer, $business, $workspace, , $home] = $this->site(['seo_title' => self::LONG_TITLE]);
        $titleUrl = route('customer.workspaces.businesses.seo.audit.title.apply', [$workspace->uid, $business->uid, $home->uid]);
        $payload = ['seo_title' => self::SHORT_TITLE, 'audit_run' => $this->runUid($business), 'draft_token' => $home->draftToken()];

        $this->authenticateAsCustomer($customer, ['view_seo']);
        $this->assertContains($this->post($titleUrl, $payload)->getStatusCode(), [401, 403]);
        $viewer = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('data-role="audit-manual-guidance"', $viewer);
        $this->assertStringNotContainsString('data-role="audit-title-proposal"', $viewer);

        [$intruder, , $otherWorkspace] = $this->entitledTenant();
        app(EntitlementManager::class)->changePlan($otherWorkspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Intruder.');
        $this->authenticateAsCustomer($intruder, ['website', 'view_seo']);
        $this->post($titleUrl, $payload)->assertNotFound();

        $this->assertSame(self::LONG_TITLE, $home->fresh()->seo_title);
    }

    // ------------------------------------------------------------------ Overlapping findings on one field

    public function test_a_title_that_is_both_too_long_and_a_duplicate_is_corrected_under_the_exact_rule_that_was_submitted(): void
    {
        [, $business, $workspace, , $home, $about] = $this->site(['seo_title' => self::LONG_TITLE], ['seo_title' => self::LONG_TITLE]);

        $html = $this->auditHtml($workspace, $business);
        foreach (['seo_title_over_recommended', 'duplicate_seo_title'] as $rule) {
            $this->assertStringContainsString('id="title-' . $home->uid . '-' . $rule . '"', $html, 'Each form has its own input id.');
            $this->assertStringContainsString('name="rule" value="' . $rule . '"', $html);
        }

        // Unique, but 68 characters: valid under the DUPLICATE rule, and would be refused under the LONG rule. It is judged as submitted.
        $uniqueButLong = self::LONG_TITLE . ' Now';
        $this->approveTitle($workspace, $business, $home, $uniqueButLong, ['rule' => 'seo_title_over_recommended'])->assertSessionHas('status', 'error');
        $this->assertSame(self::LONG_TITLE, $home->fresh()->seo_title, 'Judged under the long-title rule, so refused.');

        $this->approveTitle($workspace, $business, $home, $uniqueButLong, ['rule' => 'duplicate_seo_title'])->assertSessionHas('status', 'success');
        $this->assertSame($uniqueButLong, $home->fresh()->seo_title);
        $this->assertSame(self::LONG_TITLE, $about->fresh()->seo_title, 'The other page is never changed.');

        // The duplicate is fixed in the draft; the long-title problem is NOT: its form is still offered and nothing claims otherwise.
        $after = $this->auditHtml($workspace, $business);
        $this->assertStringContainsString('name="rule" value="seo_title_over_recommended"', $after);
        $this->assertStringNotContainsString('data-role="audit-resolved"', $after);
    }

    public function test_a_description_that_is_both_too_short_and_a_duplicate_keeps_each_rules_own_validation(): void
    {
        $short = 'Photo booths for hire.';
        [, $business, $workspace, , $home, $about] = $this->site(['meta_description' => $short], ['meta_description' => $short]);

        $html = $this->auditHtml($workspace, $business);
        foreach (['meta_description_short', 'duplicate_meta_description'] as $rule) {
            $this->assertStringContainsString('id="desc-' . $home->uid . '-' . $rule . '"', $html);
        }
        $this->assertStringContainsString('characters, too short)', $html);
        $this->assertStringContainsString('characters, shared with another page)', $html);

        // Under the duplicate rule a unique, long-enough description is accepted; a still-too-short one is refused under either rule.
        $this->approveDescription($workspace, $business, $home, self::DESC_ONE, ['rule' => 'duplicate_meta_description'])->assertSessionHas('status', 'success');
        $this->assertSame(self::DESC_ONE, $home->fresh()->meta_description);
        $this->assertSame($short, $about->fresh()->meta_description);
        $this->approveDescription($workspace, $business, $about, 'Still too short.', ['rule' => 'meta_description_short'])->assertSessionHas('status', 'error');
        $this->assertSame($short, $about->fresh()->meta_description);

        // Under the short rule alone, a long-enough value is accepted even if another page already uses it (that is the duplicate rule's job).
        $this->approveDescription($workspace, $business, $about, self::DESC_ONE, ['rule' => 'meta_description_short'])->assertSessionHas('status', 'success');
        $this->assertSame(self::DESC_ONE, $about->fresh()->meta_description);
        $this->assertStringContainsString('name="rule" value="duplicate_meta_description"', $this->auditHtml($workspace, $business), 'The duplicate is still outstanding and visible.');
    }

    public function test_an_ambiguous_missing_invalid_or_unreported_rule_never_updates_a_draft(): void
    {
        [, $business, $workspace, , $home] = $this->site(['seo_title' => self::LONG_TITLE], ['seo_title' => self::LONG_TITLE]);

        // Two findings for this field and no rule: refused, never silently resolved to "the first".
        $this->approveTitle($workspace, $business, $home, self::SHORT_TITLE)->assertSessionHas('status', 'error');
        // A rule that is not one of this endpoint's, or that is invalid.
        $this->approveTitle($workspace, $business, $home, self::SHORT_TITLE, ['rule' => 'meta_description_short'])->assertSessionHasErrors('rule');
        $this->approveTitle($workspace, $business, $home, self::SHORT_TITLE, ['rule' => 'not_a_rule'])->assertSessionHasErrors('rule');
        // A valid rule that the latest audit does NOT report for this page.
        $this->approveTitle($workspace, $business, $home, self::SHORT_TITLE, ['rule' => 'seo_title_blank'])->assertSessionHas('status', 'error');
        $this->assertSame(self::LONG_TITLE, $home->fresh()->seo_title);

        // A single-finding page still accepts a request without a rule (older form), and an explicit correct rule too.
        [, $business2, $workspace2, , $home2] = $this->site(['seo_title' => self::LONG_TITLE]);
        $this->approveTitle($workspace2, $business2, $home2, self::SHORT_TITLE)->assertSessionHas('status', 'success');
        $this->assertSame(self::SHORT_TITLE, $home2->fresh()->seo_title);
    }
}
