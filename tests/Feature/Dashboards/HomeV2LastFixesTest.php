<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Dashboard\DashboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/** Regression tests: external websites, the bounded contacts aggregate, and the inbox date banner. */
class HomeV2LastFixesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function websiteState($owner): ?bool
    {
        return $this->dashboardFor($owner->user)->band(DashboardSnapshot::BAND_COMMAND)['websitePublished'];
    }

    // ── 1 · website state: hosted, external, missing, unknown ───────────

    public function test_the_website_state_distinguishes_hosted_external_missing_and_unknown(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Site Venue', 'Site Account');
        $this->contactsAdded($business, 1, '2026-09-02');
        $this->authenticateAs($owner);
        $suggested = fn () => $this->get(route('user.home'))->assertOk()->getContent();

        // Nothing: missing, and a website is suggested.
        $this->assertFalse($this->websiteState($owner));
        $this->assertStringContainsString('Build your website', $suggested());

        // Hosted but not published: still missing.
        $this->website($business, 'draft');
        $this->assertFalse($this->websiteState($owner));
        $this->assertStringContainsString('Build your website', $suggested());

        // Hosted and published: present.
        $this->website($business, 'published');
        $this->assertTrue($this->websiteState($owner));
        $this->assertStringNotContainsString('Build your website', $suggested());
    }

    public function test_a_configured_external_website_is_not_suggested_as_missing(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'External Venue', 'External Account');
        $this->contactsAdded($business, 1, '2026-09-02');
        $this->authenticateAs($owner);

        // External mode but no address and no audit yet: nothing is configured, so it is still missing.
        DB::table('businesses')->where('id', $business->id)->update(['website_mode' => 'external', 'website_url' => null]);
        $this->assertFalse($this->websiteState($owner));
        $this->assertStringContainsString('Build your website', $this->get(route('user.home'))->assertOk()->getContent());

        // A configured external address is the setup policy's own "usable website".
        DB::table('businesses')->where('id', $business->id)->update(['website_url' => 'https://example-external.test']);
        $this->assertTrue($this->websiteState($owner));
        $this->assertStringNotContainsString('Build your website', $this->get(route('user.home'))->assertOk()->getContent());
    }

    public function test_an_unreadable_status_leaves_the_website_state_unknown(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Unknown Venue', 'Unknown Account');
        $this->contactsAdded($business, 1, '2026-09-02');
        DB::table('businesses')->where('id', $business->id)->update(['website_mode' => 'external', 'website_url' => 'https://example-external.test']);
        $this->authenticateAs($owner);

        DB::listen(function ($q) {
            if (str_contains($q->sql, 'business_usage_wallets')) {
                throw new \RuntimeException('status read failed');
            }
        });

        $this->assertNull($this->websiteState($owner));
        $this->assertStringNotContainsString('Build your website', $this->get(route('user.home'))->assertOk()->getContent());
    }

    // ── 2 · the scoped contacts aggregate only scans the requested windows ──

    public function test_the_scoped_contacts_statement_is_bounded_and_still_counts_both_windows(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Bounded Venue', 'Bounded Account');
        // Sep 1–10 (current) and Aug 22–31 (previous); two contacts far outside both.
        $this->contactsAdded($business, 3, '2026-09-04');
        $this->contactsAdded($business, 2, '2026-08-25');
        $this->contactsAdded($business, 4, '2025-01-15');
        $this->contactsAdded($business, 1, '2026-10-05');
        $this->authenticateAs($owner);

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            if (str_contains($query->sql, 'from `contacts`') && str_contains($query->sql, 'AS w0')) {
                $statements[] = $query->sql;
            }
        });

        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertNotEmpty($statements, 'The actor-scoped contacts statement ran.');
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/where `business_id` = \? and `created_at` >= \? and `created_at` < \?/', $sql, 'The scan is bounded by the outer window.');
        }

        $this->assertSame(1, preg_match('#data-kpi="contacts".*?data-role="headline-figure">([^<]+)<.*?(▲ 1)#s', $html, $m));
        $this->assertSame('3', $m[1], 'Current window counted; the old and future contacts are not.');
    }

    public function test_an_empty_window_set_is_safe(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Empty Windows', 'Empty Account');

        $this->assertSame([], app(\App\Library\Analytics\BusinessAnalyticsQueries::class)->newContactsForActor($business, [], []));
    }

    // ── 3 · the inbox banner claims only a range the queries accepted ───

    public function test_the_inbox_date_banner_appears_only_for_an_accepted_range(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Banner Venue', 'Banner Account');
        $this->authenticateAs($owner);
        $index = route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]);
        $says = fn (string $query) => str_contains($this->get($index . '?' . $query)->assertOk()->getContent(), 'conversations started');

        $this->assertTrue($says('created_from=2026-09-01&created_to=2026-09-10'), 'Accepted.');
        $this->assertFalse($says('created_from=2026-09-10&created_to=2026-09-01'), 'Reversed: the queries ignore it, so no claim.');
        $this->assertFalse($says('created_from=2026-01-01&created_to=2026-09-10'), 'Overlong (over the custom-range maximum): ignored.');
        $this->assertFalse($says('created_from=2026-02-30&created_to=2026-03-05'), 'Malformed date.');
        $this->assertFalse($says('created_from=nope&created_to=2026-09-10'), 'Malformed text.');
        $this->assertFalse($says('created_from=2026-09-01'), 'Half a range.');
    }

    public function test_a_valid_waiting_filter_keeps_its_own_indicator_beside_a_rejected_range(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Both Venue', 'Both Account');
        $this->authenticateAs($owner);
        $index = route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]);

        $html = $this->get($index . '?filter=waiting&created_from=2026-09-10&created_to=2026-09-01')->assertOk()->getContent();
        $this->assertStringContainsString('customers waiting for a reply', $html);
        $this->assertStringNotContainsString('conversations started', $html);

        $both = $this->get($index . '?filter=waiting&created_from=2026-09-01&created_to=2026-09-10')->assertOk()->getContent();
        $this->assertStringContainsString('customers waiting for a reply', $both);
        $this->assertStringContainsString('conversations started Sep 1 – Sep 10', $both);
    }
}
