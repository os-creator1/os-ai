<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoIndexabilityState;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Growth\GrowthFactSnapshotBuilder;
use App\Library\Seo\SeoAuditPageReader;
use App\Library\Seo\SeoIndexability;
use App\Library\Seo\SeoOverviewReader;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Website;
use App\Models\WebsiteDomain;
use App\Models\WebsiteRevision;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * SEO V1 final (A1/A3) — "can search engines find my website?" is TRUE.
 *
 * The old overview said every published site "is not indexed yet" because custom
 * domains did not exist. They do now: a published site served from its Active
 * primary domain is `index, follow` page by page, unless the owner hid a page.
 * These tests pin the four states, the exact page counts (from the PUBLISHED
 * snapshot only), the plain-word wording and the actions, on both screens that
 * show it, plus the honest Search Console card (no numbers, no provider).
 */
class SeoIndexabilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    /**
     * @param  int  $total  published pages
     * @param  int  $hidden  how many of them are marked noindex
     * @return array<int, array<string, mixed>>
     */
    private function pages(int $total, int $hidden = 0): array
    {
        $pages = [];

        for ($i = 0; $i < $total; $i++) {
            $pages[] = $this->snapshotPage('p' . $i, 'Page ' . $i, ['noindex' => $i < $hidden], [], $i === 0);
        }

        return $pages;
    }

    private function domain(Website $website, string $host = 'booths.example.test', bool $primary = true, WebsiteDomainStatus $status = WebsiteDomainStatus::Active): WebsiteDomain
    {
        return WebsiteDomain::create([
            'uid' => (string) Str::uuid(),
            'website_id' => $website->id,
            'domain' => $host,
            'is_primary' => $primary,
            'status' => $status,
        ]);
    }

    /** @return array{0: Customer, 1: Business, 2: Workspace} */
    private function tenant(): array
    {
        return $this->entitledTenant(WorkspacePlanTier::Growth);
    }

    private function overviewIndexability(Workspace $workspace, Business $business, Customer $customer): SeoIndexability
    {
        return app(SeoOverviewReader::class)->read($workspace, $business, $customer->user)->indexability;
    }

    public function test_no_published_website_is_its_own_state_on_both_readers(): void
    {
        [$customer, $business, $workspace] = $this->tenant();

        $this->assertSame(SeoIndexabilityState::NoPublishedWebsite, $this->overviewIndexability($workspace, $business, $customer)->state);
        $this->assertSame(SeoIndexabilityState::NoPublishedWebsite, app(SeoAuditPageReader::class)->read($business)->indexability->state);
    }

    public function test_a_published_site_with_no_domain_is_platform_path_only_and_points_to_connecting_a_domain(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->publishWebsite($business, $this->pages(3));

        $indexability = $this->overviewIndexability($workspace, $business, $customer);

        $this->assertSame(SeoIndexabilityState::PlatformPathNotIndexable, $indexability->state);
        $this->assertSame(SeoIndexability::ACTION_CONNECT_DOMAIN, $indexability->action());
        $this->assertSame('Action', $indexability->toneWord());
        $this->assertSame(SeoIndexabilityState::PlatformPathNotIndexable, app(SeoAuditPageReader::class)->read($business)->indexability->state);
    }

    public function test_only_an_active_primary_domain_makes_the_site_findable(): void
    {
        foreach ([
            'pending verification' => [true, WebsiteDomainStatus::PendingVerification],
            'provisioning' => [true, WebsiteDomainStatus::Provisioning],
            'an active alias that is not primary' => [false, WebsiteDomainStatus::Active],
        ] as $label => [$primary, $status]) {
            [$customer, $business, $workspace] = $this->tenant();
            $website = $this->publishWebsite($business, $this->pages(3));
            $this->domain($website, 'alias-' . Str::random(6) . '.example.test', $primary, $status);

            $this->assertSame(
                SeoIndexabilityState::PlatformPathNotIndexable,
                $this->overviewIndexability($workspace, $business, $customer)->state,
                "A domain that is {$label} is served as a platform-path site."
            );
        }

        [$customer, $business, $workspace] = $this->tenant();
        $website = $this->publishWebsite($business, $this->pages(3));
        $this->domain($website);

        $this->assertSame(SeoIndexabilityState::Indexable, $this->overviewIndexability($workspace, $business, $customer)->state);
    }

    public function test_the_counts_are_exact_14_pages_5_hidden_means_9_of_14(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $website = $this->publishWebsite($business, $this->pages(14, 5));
        $this->domain($website);

        foreach ([$this->overviewIndexability($workspace, $business, $customer), app(SeoAuditPageReader::class)->read($business)->indexability] as $indexability) {
            $this->assertSame(SeoIndexabilityState::Indexable, $indexability->state);
            $this->assertSame(9, $indexability->indexablePages);
            $this->assertSame(14, $indexability->totalPages);
            $this->assertSame(5, $indexability->hiddenPages());
            $this->assertSame('Search engines can find 9 of 14 pages', $indexability->label());
            $this->assertSame('Needs attention', $indexability->toneWord());
            $this->assertSame(SeoIndexability::ACTION_ALLOW_INDEXING, $indexability->action());
            $this->assertStringContainsString('5 pages are marked as hidden from search', $indexability->detail());
        }
    }

    public function test_every_page_findable_is_good_with_no_action(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $website = $this->publishWebsite($business, $this->pages(3));
        $this->domain($website);

        $indexability = $this->overviewIndexability($workspace, $business, $customer);

        $this->assertSame('Search engines can find 3 of 3 pages', $indexability->label());
        $this->assertSame('Good', $indexability->toneWord());
        $this->assertNull($indexability->action());
    }

    public function test_a_single_page_site_reads_naturally(): void
    {
        $indexability = new SeoIndexability(SeoIndexabilityState::Indexable, 1, 1);
        $this->assertSame('Search engines can find 1 of 1 page', $indexability->label());

        $hidden = new SeoIndexability(SeoIndexabilityState::HiddenFromSearch, 0, 1);
        $this->assertStringContainsString('Your only page is marked as hidden from search', $hidden->detail());
    }

    public function test_every_page_hidden_is_live_but_hidden_from_search(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $website = $this->publishWebsite($business, $this->pages(4, 4));
        $this->domain($website);

        $indexability = $this->overviewIndexability($workspace, $business, $customer);

        $this->assertSame(SeoIndexabilityState::HiddenFromSearch, $indexability->state);
        $this->assertSame('Your site is live but hidden from search', $indexability->label());
        $this->assertSame(SeoIndexability::ACTION_ALLOW_INDEXING, $indexability->action());
        $this->assertSame('Action', $indexability->toneWord());
        $this->assertStringContainsString('All 4 pages are marked as hidden from search', $indexability->detail());
    }

    public function test_the_counts_come_from_the_published_revision_not_a_newer_unpublished_one(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $website = $this->publishWebsite($business, $this->pages(3, 1));
        $this->domain($website);

        // A newer revision exists, but is not what the website serves.
        WebsiteRevision::create([
            'website_id' => $website->id,
            'version_number' => 2,
            'snapshot' => ['schema_version' => 1, 'website' => ['name' => 'Test Website'], 'pages' => $this->pages(10, 10), 'assets' => []],
            'schema_version' => 1,
            'created_by' => $business->customer_id,
        ]);

        $indexability = $this->overviewIndexability($workspace, $business, $customer);

        $this->assertSame([2, 3], [$indexability->indexablePages, $indexability->totalPages]);
        $this->assertSame(SeoIndexabilityState::Indexable, $indexability->state);
    }

    public function test_the_overview_and_the_website_check_show_the_truthful_line_with_the_right_action(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $website = $this->publishWebsite($business, $this->pages(14, 5));
        $this->domain($website);
        $this->authenticateAsSeoCustomer($customer);

        $pagesUrl = route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]);

        foreach ([
            $this->get($this->seoUrl($workspace, $business))->assertOk()->getContent(),
            $this->get(route('customer.workspaces.businesses.seo.audit.index', [$workspace->uid, $business->uid]))->assertOk()->getContent(),
        ] as $html) {
            $this->assertStringContainsString('Search engines can find 9 of 14 pages', $html);
            $this->assertMatchesRegularExpression('/data-role="indexability-tone"[^>]*>\s*Needs attention\s*</', $html);
            $this->assertStringContainsString('data-action="allow_indexing"', $html);
            $this->assertStringContainsString($pagesUrl, $html);
            $this->assertStringNotContainsString('not indexed', $html);
        }
    }

    public function test_a_hidden_and_a_no_domain_site_show_their_own_wording_and_links(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $website = $this->publishWebsite($business, $this->pages(2, 2));
        $this->domain($website);
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->seoUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('Your site is live but hidden from search', $html);
        $this->assertStringContainsString('data-action="allow_indexing"', $html);

        [$other, $otherBusiness, $otherWorkspace] = $this->tenant();
        $this->publishWebsite($otherBusiness, $this->pages(2));
        $this->authenticateAsSeoCustomer($other);

        $html = $this->get($this->seoUrl($otherWorkspace, $otherBusiness))->assertOk()->getContent();
        $this->assertStringContainsString('Your website is live, but search engines cannot find it yet', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.website.domains.index', [$otherWorkspace->uid, $otherBusiness->uid]), $html);
    }

    public function test_the_action_link_is_withheld_from_someone_who_cannot_open_the_website_screens(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->publishWebsite($business, $this->pages(2));
        $this->authenticateAsSeoCustomer($customer, ['view_seo', 'manage_seo']);

        $html = $this->get($this->seoUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Your website is live, but search engines cannot find it yet', $html);
        $this->assertStringNotContainsString('data-role="indexability-action"', $html);
    }

    public function test_the_search_console_card_is_honest_and_shows_no_figures(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->seoUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-section="search-console"', $html);
        $this->assertStringContainsString('Not connected', $html);
        $this->assertStringContainsString('Not available yet', $html);
        // No fake zeros and no metric of any kind.
        $this->assertDoesNotMatchRegularExpression('/\b0\s+(clicks|impressions)\b/i', $html);
        $this->assertStringNotContainsString('data-role="search-console-clicks"', $html);

        // Growth keeps Search Console neutral-unavailable: no reader, no fact, no provider call.
        $this->assertFalse(app(GrowthFactSnapshotBuilder::class)->build($business->fresh())->set('search_console')->isAvailable());
    }
}
