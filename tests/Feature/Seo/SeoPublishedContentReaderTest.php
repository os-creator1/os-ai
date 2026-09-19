<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Website\WebsiteStatus;
use App\Library\Seo\SeoPublishedContentReader;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * Contract 18 §9.1 — the single reader of the PUBLISHED Website snapshot.
 * It reads the immutable revision (never the mutable draft pages), writes
 * nothing, and costs a constant number of queries.
 */
class SeoPublishedContentReaderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    private function reader(): SeoPublishedContentReader
    {
        return app(SeoPublishedContentReader::class);
    }

    public function test_a_business_with_no_website_has_no_published_content(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);

        $this->assertNull($this->reader()->forBusiness($business));
    }

    public function test_a_draft_archived_or_unrevisioned_website_is_not_published(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $website = $this->publishWebsite($business, [$this->snapshotPage('p1', 'Home', [], [], true)]);

        $this->assertNotNull($this->reader()->forBusiness($business));

        $website->update(['status' => WebsiteStatus::Draft]);
        $this->assertNull($this->reader()->forBusiness($business), 'A draft Website is not published.');

        $website->update(['status' => WebsiteStatus::Archived]);
        $this->assertNull($this->reader()->forBusiness($business), 'An archived Website is not published.');

        $website->update(['status' => WebsiteStatus::Published, 'published_revision_id' => null]);
        $this->assertNull($this->reader()->forBusiness($business), 'Published status without a published revision is not published.');
    }

    public function test_another_business_never_reads_this_websites_content(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        [, $other] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->publishWebsite($business, [$this->snapshotPage('p1', 'Home', [], [], true)]);

        $this->assertNotNull($this->reader()->forBusiness($business));
        $this->assertNull($this->reader()->forBusiness($other));
    }

    public function test_page_level_seo_counts_come_from_the_snapshot(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->publishWebsite($business, [
            $this->snapshotPage('a', 'Home', ['seo_title' => 'Best Bakery', 'meta_description' => 'Fresh bread daily.'], [], true),
            $this->snapshotPage('b', 'About', ['meta_description' => '   ']),
            $this->snapshotPage('c', 'Hidden', ['seo_title' => 'Hidden', 'noindex' => true]),
        ]);

        $content = $this->reader()->forBusiness($business);

        $this->assertSame(3, $content->pageCount());
        $this->assertSame(1, $content->pagesWithMetaDescription(), 'A whitespace-only description does not count.');
        $this->assertSame(2, $content->pagesWithSeoTitle());
        $this->assertSame(1, $content->pagesMarkedNoindex());
    }

    public function test_it_reports_the_published_revision_never_the_mutable_draft_pages(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $website = $this->publishWebsite($business, [
            $this->snapshotPage('a', 'Home', ['meta_description' => 'Published description.'], [], true),
        ]);

        // The owner has since edited the draft: a different description, and
        // a second draft page. Neither is published, so neither may show.
        WebsitePage::create([
            'website_id' => $website->id, 'title' => 'Draft Home', 'slug' => null, 'is_home' => true,
            'sections' => [], 'meta_description' => null, 'seo_title' => null, 'noindex' => true,
        ]);
        WebsitePage::create([
            'website_id' => $website->id, 'title' => 'Draft Only', 'slug' => 'draft-only',
            'sections' => [], 'meta_description' => 'Draft-only description.',
        ]);

        $content = $this->reader()->forBusiness($business);

        $this->assertSame(1, $content->pageCount());
        $this->assertSame(1, $content->pagesWithMetaDescription());
        $this->assertSame(0, $content->pagesMarkedNoindex());
    }

    public function test_text_surfaces_use_only_the_allowlisted_human_visible_fields(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->publishWebsite($business, [
            $this->snapshotPage('a', 'Home Page Title', ['seo_title' => 'Search Title', 'meta_description' => 'The meta words.'], [
                ['type' => 'hero', 'data' => [
                    'heading' => 'HERO-HEADING', 'subheading' => 'HERO-SUB',
                    'background_image' => 'ASSET-UID-1',
                    'primary_cta' => ['label' => 'CTA-LABEL', 'url' => 'https://cta.example/'],
                ]],
                ['type' => 'text', 'data' => ['heading' => 'TEXT-HEADING', 'body' => 'TEXT-BODY']],
                ['type' => 'image_text', 'data' => ['heading' => 'IMG-HEADING', 'body' => 'IMG-BODY', 'image' => 'ASSET-UID-2', 'image_position' => 'left']],
                ['type' => 'services', 'data' => ['heading' => 'SVC-HEADING', 'items' => [
                    ['name' => 'SVC-NAME', 'description' => 'SVC-DESC', 'price_label' => 'PRICE-LABEL', 'image' => 'ASSET-UID-3'],
                ]]],
                ['type' => 'testimonials', 'data' => ['heading' => 'TST-HEADING', 'items' => [
                    ['quote' => 'TST-QUOTE', 'author_name' => 'AUTHOR-NAME', 'author_title' => 'AUTHOR-TITLE'],
                ]]],
                ['type' => 'faq', 'data' => ['heading' => 'FAQ-HEADING', 'items' => [
                    ['question' => 'FAQ-Q', 'answer' => 'FAQ-A'],
                ]]],
                ['type' => 'cta', 'data' => ['heading' => 'CTA-HEADING', 'body' => 'CTA-BODY', 'buttons' => [['label' => 'BTN-LABEL', 'url' => 'https://btn.example/']]]],
                ['type' => 'contact_details', 'data' => ['show_phone' => true, 'show_email' => true, 'show_address' => true, 'resolved' => ['phone' => '555-CONTACT', 'email' => 'c@x.test', 'address' => 'CONTACT-ADDR']]],
                ['type' => 'not_a_real_section', 'data' => ['heading' => 'UNKNOWN-HEADING', 'body' => 'UNKNOWN-BODY']],
            ], true),
        ]);

        $surfaces = $this->reader()->forBusiness($business)->pages[0]->textSurfaces();

        $this->assertSame('Search Title', $surfaces['title'], 'The SEO title wins over the page title.');
        $this->assertSame('The meta words.', $surfaces['meta_description']);

        foreach ([
            'HERO-HEADING', 'HERO-SUB', 'TEXT-HEADING', 'TEXT-BODY', 'IMG-HEADING', 'IMG-BODY',
            'SVC-HEADING', 'SVC-NAME', 'SVC-DESC', 'TST-HEADING', 'TST-QUOTE', 'FAQ-HEADING', 'FAQ-Q', 'FAQ-A',
            'CTA-HEADING', 'CTA-BODY',
        ] as $included) {
            $this->assertStringContainsString($included, $surfaces['body'], "{$included} is human-visible prose and must be searchable.");
        }

        foreach ([
            'ASSET-UID-1', 'ASSET-UID-2', 'ASSET-UID-3', 'CTA-LABEL', 'BTN-LABEL', 'https://', 'PRICE-LABEL',
            'AUTHOR-NAME', 'AUTHOR-TITLE', '555-CONTACT', 'CONTACT-ADDR', 'UNKNOWN-HEADING', 'UNKNOWN-BODY',
        ] as $excluded) {
            $this->assertStringNotContainsString($excluded, $surfaces['body'], "{$excluded} is a URL, uid, label, contact value or unknown section and must never be searchable.");
        }
    }

    public function test_the_title_surface_falls_back_to_the_page_title(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->publishWebsite($business, [$this->snapshotPage('a', 'Plain Page Title', [], [], true)]);

        $surfaces = $this->reader()->forBusiness($business)->pages[0]->textSurfaces();

        $this->assertSame('Plain Page Title', $surfaces['title']);
        $this->assertSame('', $surfaces['meta_description']);
        $this->assertSame('', $surfaces['body']);
    }

    public function test_assets_without_alt_text_are_reported(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', [], [], true)], [
            ['uid' => 'with-alt', 'url' => 'https://x.test/a.png', 'alt_text' => 'A loaf of bread'],
            ['uid' => 'null-alt', 'url' => 'https://x.test/b.png', 'alt_text' => null],
            ['uid' => 'blank-alt', 'url' => 'https://x.test/c.png', 'alt_text' => '   '],
        ]);

        $this->assertSame(['null-alt', 'blank-alt'], $this->reader()->forBusiness($business)->assetsMissingAltText());
    }

    public function test_a_malformed_snapshot_yields_nothing_rather_than_an_error(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $website = $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', [], [], true)]);

        \DB::table('website_revisions')->where('id', $website->published_revision_id)->update(['snapshot' => json_encode(['pages' => 'nonsense', 'assets' => 7])]);

        $content = $this->reader()->forBusiness($business);

        $this->assertNotNull($content);
        $this->assertSame(0, $content->pageCount());
        $this->assertSame([], $content->assetsMissingAltText());
    }

    public function test_reading_is_read_only_and_costs_a_constant_two_queries(): void
    {
        [, $small] = $this->entitledTenant(WorkspacePlanTier::Core);
        [, $large] = $this->entitledTenant(WorkspacePlanTier::Core);

        $this->publishWebsite($small, [$this->snapshotPage('a', 'Home', [], [], true)]);
        $this->publishWebsite($large, array_map(
            fn (int $i) => $this->snapshotPage("p{$i}", "Page {$i}", ['meta_description' => "desc {$i}"], [
                ['type' => 'text', 'data' => ['heading' => "H{$i}", 'body' => "B{$i}"]],
            ], $i === 0),
            range(0, 19),
        ));

        $before = $this->dbFingerprint($this->seoProtectedTables());

        $smallQueries = $this->capturedQueries(fn () => $this->reader()->forBusiness($small));
        $largeQueries = $this->capturedQueries(fn () => $this->reader()->forBusiness($large));

        $this->assertSame(2, count($smallQueries));
        $this->assertSame(count($smallQueries), count($largeQueries), 'Query count must not grow with page count.');
        $this->assertSame($before, $this->dbFingerprint($this->seoProtectedTables()), 'Reading must write nothing.');
    }
}
