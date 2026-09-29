<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generator + Local SEO Completion — the task's own deterministic
 * SEO acceptance audit, run against a realistic generated site (real
 * services, one genuinely eligible location, one thin/ineligible
 * location) for every one of the four templates. Proves, from the
 * PUBLISHED snapshot (the thing actually served), the checklist items
 * that are mechanically provable without a browser: no duplicate slugs,
 * no duplicate title/seo_title, exactly one H1 (one hero section) per
 * page, every non-utility content page this lane builds is indexable
 * (the noindex-on-everything defect this lane found and fixed), no
 * thin/ineligible location leaks a page, and no LocalBusiness
 * self-serving rating/review markup is ever emitted.
 */
class WebsiteSeoAcceptanceAuditTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
    }

    public static function templateKeyProvider(): array
    {
        return [
            ['photo_booth_modern'],
            ['photo_booth_editorial'],
            ['photo_booth_luxury'],
            ['photo_booth_conversion'],
        ];
    }

    /**
     * @dataProvider templateKeyProvider
     */
    public function test_generated_site_passes_the_deterministic_seo_audit_for_every_template(string $templateKey): void
    {
        [$customer, $business] = $this->entitledTenant();
        $business->update(['phone' => '+15550001234', 'description' => 'A real local photo booth company.']);

        BusinessService::create(['business_id' => $business->id, 'name' => 'Open-Air Booth', 'slug' => 'open-air-booth', 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 0]);
        BusinessService::create(['business_id' => $business->id, 'name' => 'Mirror Booth', 'slug' => 'mirror-booth', 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 1]);

        BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Naperville',
            'region' => 'IL',
            'service_area_cities' => ['Naperville', 'Aurora', 'Wheaton'],
        ]);
        BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Joliet',
            'region' => 'IL',
        ]);

        $template = WebsiteTemplate::findActiveOrFail($templateKey);
        $website = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);

        $revision = app(WebsitePublisher::class)->publish($website->fresh(), $customer->user_id);
        $pages = collect($revision->fresh()->snapshot['pages']);

        // No thin/ineligible location ever leaks a page.
        $this->assertFalse($pages->pluck('slug')->filter()->contains(fn ($slug) => str_contains($slug, 'joliet')));

        // No duplicate slugs (home's slug is null and excluded).
        $slugs = $pages->pluck('slug')->filter()->all();
        $this->assertSame(count($slugs), count(array_unique($slugs)), "Duplicate slug in template {$templateKey}.");

        // No duplicate seo_title/title (the rendered <title> value) across pages.
        $renderedTitles = $pages->map(fn ($page) => $page['seo']['seo_title'] ?: $page['title'])->all();
        $this->assertSame(count($renderedTitles), count(array_unique($renderedTitles)), "Duplicate rendered title in template {$templateKey}.");

        // No duplicate meta_description among pages that set one.
        $descriptions = $pages->pluck('seo.meta_description')->filter(fn ($value) => $value !== null && trim((string) $value) !== '')->all();
        $this->assertSame(count($descriptions), count(array_unique($descriptions)), "Duplicate meta_description in template {$templateKey}.");

        foreach ($pages as $page) {
            // Every page has a non-empty title.
            $this->assertNotSame('', trim((string) $page['title']), "Empty title on a page in template {$templateKey}.");

            // Exactly one H1 per page: hero is the only component that
            // renders an <h1>, so exactly one hero section per page.
            $heroCount = collect($page['sections'])->where('type', 'hero')->count();
            $this->assertSame(1, $heroCount, "Page '{$page['title']}' in template {$templateKey} does not have exactly one hero/H1.");

            // No lorem-ipsum or other obvious placeholder content.
            $haystack = mb_strtolower(json_encode($page));
            $this->assertStringNotContainsString('lorem ipsum', $haystack);

            // Home is the only page this lane leaves indexable-by-omission
            // among the pre-existing utility pages (About/FAQ/Contact stay
            // noindex, pre-existing and unchanged); every new content page
            // type this lane adds (Services, Packages, a service detail
            // page, a location page) must be indexable — the very defect
            // this lane found and fixed.
            $newContentPageSlugs = ['services', 'packages', 'service-open-air-booth', 'service-mirror-booth', 'serving-naperville-il'];
            if ($page['is_home'] || in_array($page['slug'], $newContentPageSlugs, true)) {
                $this->assertFalse($page['seo']['noindex'], "Page '{$page['title']}' ({$page['slug']}) in template {$templateKey} must be indexable.");
            }
        }

        // LocalBusiness structured data never carries a self-serving
        // rating/review (Google's own review-snippet restriction).
        $home = $pages->firstWhere('is_home', true);
        $localBusinessJson = mb_strtolower(json_encode($revision->snapshot['website']['localBusiness'] ?? []));
        $this->assertStringNotContainsString('aggregaterating', $localBusinessJson);
        $this->assertStringNotContainsString('"review"', $localBusinessJson);
        $this->assertNotNull($home);
    }
}
