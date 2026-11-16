<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Website\GuidedGeneration\DraftPageReconciler;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\WebsitePageStrategy;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * A rebuild of an established website must never silently delete pages or URLs the new plan does not
 * produce (legacy, legal, hand-made pages), while still rewriting the pages it does plan. No AI call.
 */
class DraftPagePreservationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function batch(array $plan): string
    {
        return json_encode(['pages' => collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'], 'title' => $page['title'], 'seo_title' => $page['title'] . ' seo', 'meta_description' => $page['title'] . ' meta description page.',
            'sections' => [['type' => 'hero', 'data' => ['heading' => $page['title']]]],
        ])->values()->all()]);
    }

    private function page(Website $website, string $title, ?string $slug, bool $home = false): void
    {
        $website->pages()->create(['title' => $title, 'slug' => $slug, 'is_home' => $home, 'sections' => [['type' => 'text', 'data' => ['body' => 'OWNER-AUTHORED ' . $title]]]]);
    }

    private function established(Website $website): Website
    {
        $website->forceFill(['indexing_released_at' => now()])->save();

        return $website->fresh();
    }

    private function setup13(): array
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $this->page($website, 'Home', null, true);
        foreach ([
            'Backdrops' => 'backdrops', 'Check availability' => 'check-availability', 'Corporate Photo Booth Rental' => 'corporate-photo-booth-rental',
            'FAQ' => 'faq', 'Gallery' => 'gallery', 'Magic Mirror Photo Booth' => 'magic-mirror-photo-booth', 'Party Photo Booth Rental' => 'party-photo-booth-rental',
            'Privacy Policy' => 'privacy-policy', 'Service Areas' => 'service-areas', 'Terms & Conditions' => 'terms-and-conditions',
            'Terms of Service' => 'terms-of-service', 'Wedding Photo Booth Rental' => 'wedding-photo-booth-rental',
        ] as $title => $slug) {
            $this->page($website, $title, $slug);
        }

        return [$customer, $business, $template, $this->established($website)];
    }

    public function test_an_established_13_page_site_is_regenerated_without_losing_any_url(): void
    {
        [$customer, $business, $template, $website] = $this->setup13();
        $before = $website->pages()->pluck('slug')->map(fn ($s) => (string) $s)->all();
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $this->mockAiClient($this->batch($plan));

        $attempt = app(GuidedGenerationCommitService::class)->rebuild($business, $website, $template, $customer->user_id, 'idem-keep');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);
        $after = $website->pages()->pluck('slug')->map(fn ($s) => (string) $s)->all();
        $this->assertSame([], array_values(array_diff($before, $after, ['faq'])), 'Every previous address is still served (the FAQ is rewritten and redirected).');
        $this->assertSame(1, $website->pages()->where('is_home', true)->count(), 'No duplicate Home.');
        $this->assertSame(count($after), count(array_unique($after)), 'No duplicate slugs.');
        foreach (['privacy-policy', 'terms-and-conditions', 'terms-of-service', 'corporate-photo-booth-rental', 'check-availability'] as $slug) {
            $this->assertStringContainsString('OWNER-AUTHORED', json_encode($website->pages()->where('slug', $slug)->firstOrFail()->sections), "{$slug} content untouched");
        }
    }

    public function test_a_changed_slug_replaces_the_same_logical_page_and_redirects_the_old_address(): void
    {
        [$customer, $business, $template, $website] = $this->setup13();
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $faqSlug = collect($plan)->firstWhere('page_type', 'faq')['slug'];
        $this->assertNotSame('faq', $faqSlug);
        $this->mockAiClient($this->batch($plan));

        app(GuidedGenerationCommitService::class)->rebuild($business, $website, $template, $customer->user_id, 'idem-slug');

        $this->assertSame(1, $website->pages()->whereIn('slug', ['faq', $faqSlug])->count(), 'One FAQ page, never two.');
        $this->assertSame($faqSlug, $website->fresh()->custom_redirects['faq']);
    }

    public function test_reconciliation_reports_exact_counts_for_the_rebuild_screen(): void
    {
        [, $business, $template, $website] = $this->setup13();
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);

        $report = app(DraftPageReconciler::class)->reconcile($website, $business, $plan);

        $this->assertSame(0, count($report['remove']));
        $this->assertSame([], $report['blockers']);
        $this->assertSame(13, count($report['rewrite']) + count($report['preserve']));
        $this->assertSame(count($plan), count($report['rewrite']) + count($report['create']));
        $this->assertContains('Privacy Policy', array_column($report['preserve'], 'title'));
    }

    public function test_legal_pages_survive_even_a_first_generation_but_other_starter_pages_are_replaced(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $this->page($website, 'Home', null, true);
        $this->page($website, 'Old starter extra', 'old-starter-extra');
        $this->page($website, 'Privacy Policy', 'privacy-policy');
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $this->mockAiClient($this->batch($plan));

        app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-first');

        $this->assertTrue($website->pages()->where('slug', 'privacy-policy')->exists());
        $this->assertFalse($website->pages()->where('slug', 'old-starter-extra')->exists());
    }

    public function test_a_failed_generation_leaves_all_thirteen_pages_untouched(): void
    {
        [$customer, $business, $template, $website] = $this->setup13();
        $this->mockAiClient(json_encode(['pages' => [['page_key' => 'invented', 'title' => 'X', 'seo_title' => null, 'meta_description' => null, 'sections' => []]]]));

        $attempt = app(GuidedGenerationCommitService::class)->rebuild($business, $website, $template, $customer->user_id, 'idem-fail');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(13, $website->pages()->count());
        $this->assertStringContainsString('OWNER-AUTHORED', json_encode($website->pages()->where('slug', 'gallery')->firstOrFail()->sections));
    }

    public function test_too_many_preserved_pages_block_the_rebuild_before_anything_is_deleted(): void
    {
        [$customer, $business, $template, $website] = $this->setup13();
        foreach (range(1, 7) as $i) {
            $this->page($website, "Extra {$i}", "extra-{$i}");
        }
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $this->mockAiClient($this->batch($plan));

        $attempt = app(GuidedGenerationCommitService::class)->rebuild($business, $website, $template, $customer->user_id, 'idem-block');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertStringContainsString('at most 20', (string) $attempt->failure_reason);
        $this->assertSame(20, $website->pages()->count());
        $this->assertStringContainsString('OWNER-AUTHORED', json_encode($website->pages()->where('is_home', true)->firstOrFail()->sections));
    }
}
