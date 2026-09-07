<?php

namespace Tests\Feature\Website;

use App\Library\Website\WebsiteAiDraftGenerator;
use App\Library\Website\WebsiteDraftPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §3.4, §16 Slice 1 -- the general
 * 20-page-per-Website ceiling inside WebsiteDraftPageService::
 * createPage(), distinct from WebsiteAiDraftGenerator::MAX_PAGES (which
 * only ever bounds a single AI-generation batch starting from zero
 * pages).
 */
class WebsitePageCountCeilingTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    public function test_the_20th_page_succeeds_and_the_21st_fails(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $service = app(WebsiteDraftPageService::class);

        // Homepage (1) plus 18 sub-pages = 19 pages so far.
        $service->createPage($website, ['title' => 'Home', 'is_home' => true, 'sections' => []]);

        for ($i = 1; $i <= 18; $i++) {
            $service->createPage($website, ['title' => "Page {$i}", 'slug' => "page-{$i}", 'is_home' => false, 'sections' => []]);
        }

        $this->assertSame(19, $website->pages()->count());

        // The 20th page succeeds.
        $service->createPage($website, ['title' => 'Page 19', 'slug' => 'page-19', 'is_home' => false, 'sections' => []]);
        $this->assertSame(20, $website->pages()->count());

        // The 21st fails.
        $this->expectException(ValidationException::class);
        $service->createPage($website, ['title' => 'Page 20', 'slug' => 'page-20', 'is_home' => false, 'sections' => []]);
    }

    public function test_the_21st_page_does_not_persist_after_rejection(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $service = app(WebsiteDraftPageService::class);

        $service->createPage($website, ['title' => 'Home', 'is_home' => true, 'sections' => []]);

        for ($i = 1; $i <= 19; $i++) {
            $service->createPage($website, ['title' => "Page {$i}", 'slug' => "page-{$i}", 'is_home' => false, 'sections' => []]);
        }

        $this->assertSame(20, $website->pages()->count());

        try {
            $service->createPage($website, ['title' => 'One Too Many', 'slug' => 'one-too-many', 'is_home' => false, 'sections' => []]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(20, $website->pages()->count());
        $this->assertDatabaseMissing('website_pages', ['website_id' => $website->id, 'slug' => 'one-too-many']);
    }

    public function test_updating_an_existing_page_at_the_ceiling_is_never_mistaken_for_a_new_page(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $service = app(WebsiteDraftPageService::class);

        $home = $service->createPage($website, ['title' => 'Home', 'is_home' => true, 'sections' => []]);

        for ($i = 1; $i <= 19; $i++) {
            $service->createPage($website, ['title' => "Page {$i}", 'slug' => "page-{$i}", 'is_home' => false, 'sections' => []]);
        }

        $this->assertSame(20, $website->pages()->count());

        // The Website is already at the 20-page ceiling -- updating an
        // existing page must still succeed (updatePage() never counts
        // against the create ceiling).
        $updated = $service->updatePage($website, $home, ['title' => 'Updated Home', 'is_home' => true, 'sections' => []]);

        $this->assertSame('Updated Home', $updated->title);
        $this->assertSame(20, $website->pages()->count());
    }

    /**
     * A genuine two-connection race (two processes both reading a count
     * of 19 before either commits) requires infrastructure RefreshDatabase's
     * single wrapping transaction cannot provide -- a second, separate
     * PDO connection to this same test database would not see this
     * test's own uncommitted rows at all under MySQL's default isolation
     * level, making a real concurrent-write assertion unreliable inside
     * this harness. Instead this proves the actual safety mechanism
     * mechanically: createPage() locks the Website row (serializing any
     * two callers for the same Website) BEFORE reading pages()->count(),
     * so a second caller can only ever observe the count *after* the
     * first caller's transaction has committed -- never the stale
     * pre-commit value both would need to both pass the ceiling check.
     */
    public function test_the_page_count_check_locks_the_website_row_before_counting(): void
    {
        $source = file_get_contents(app_path('Library/Website/WebsiteDraftPageService.php'));

        $lockPosition = strpos($source, "Website::where('id', \$website->id)->lockForUpdate()");
        $countPosition = strpos($source, 'pages()->count() >= self::MAX_PAGES');

        $this->assertNotFalse($lockPosition, 'createPage() must lock the Website row before counting.');
        $this->assertNotFalse($countPosition, 'createPage() must check the page count against MAX_PAGES.');
        $this->assertLessThan($countPosition, $lockPosition, 'The Website row lock must be acquired BEFORE the page count is read, so a concurrent caller for the same Website is serialized and always sees an up-to-date count.');
    }

    public function test_repeated_sequential_creation_at_the_ceiling_never_exceeds_twenty(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $service = app(WebsiteDraftPageService::class);

        $service->createPage($website, ['title' => 'Home', 'is_home' => true, 'sections' => []]);

        for ($i = 1; $i <= 19; $i++) {
            $service->createPage($website, ['title' => "Page {$i}", 'slug' => "page-{$i}", 'is_home' => false, 'sections' => []]);
        }

        $this->assertSame(20, $website->pages()->count());

        $rejected = 0;

        for ($i = 20; $i <= 22; $i++) {
            try {
                $service->createPage($website, ['title' => "Page {$i}", 'slug' => "page-{$i}", 'is_home' => false, 'sections' => []]);
            } catch (ValidationException) {
                $rejected++;
            }
        }

        $this->assertSame(3, $rejected);
        $this->assertSame(20, $website->pages()->count());
    }

    public function test_the_existing_ai_batch_page_limit_is_unaffected(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $pages = array_map(fn ($i) => [
            'title' => "Page {$i}",
            'is_home' => $i === 0,
            'slug' => $i === 0 ? null : "page-{$i}",
            'sections' => [$this->section('text')],
        ], range(0, 19));

        $this->mockAiClient(json_encode(['pages' => $pages]));

        $generator = app(WebsiteAiDraftGenerator::class);
        $result = $generator->generate($website);

        $this->assertTrue($result);
        $this->assertSame(20, $website->pages()->count());
    }
}
