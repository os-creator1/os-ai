<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\Tracking\WebsiteTrackingSettings;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsitePublisher;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\Form;
use App\Models\User;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\Feature\Website\Concerns\PublishesGeneratedSites;
use Tests\TestCase;

/**
 * Slice 4 — acceptance of the ACTUAL generator output for a realistic photo booth business, structured like the Kaleb Hill v3
 * benchmark (docs/migrations/customer-websites/kaleb-hill/website-content.json): home, a service page per booth, an event page per
 * occasion, qualified service-area pages, About, FAQ and a Contact page that embeds the installed pb3_form_lead Forms-module form.
 *
 * Real seeded Photo Booth blueprints (v1, v2, v3), real generation commit, publish and custom-domain rendering; the only fake is
 * the AI. Set ACCEPTANCE_REPORT_DIR to also write every rendered page and an inventory there (used for the browser screenshots).
 */
class GeneratorAcceptanceTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;
    use PublishesGeneratedSites;
    use RefreshDatabase;

    private const DOMAIN = 'harborbooths.test';

    private const AREAS = [
        ['name' => 'Hattiesburg, MS', 'details' => ['venues' => ['The Hattiesburg Saenger Theater', 'Lake Terrace Convention Center'], 'logistics' => 'About 20 minutes from Petal; we load in two hours before the event.']],
        ['name' => 'Laurel, MS', 'details' => ['venues' => ['The Landrum Center'], 'logistics' => 'About 45 minutes from Petal; parking is behind the hall.']],
        ['name' => 'Biloxi, MS'],   // confirmed service area, but no local evidence: listed, never a page
    ];

    private $business;

    private $website;

    private $customer;

    private $deployment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
        config(['ai.website_content_quality' => true]);
    }

    /** Generates and publishes the whole site once; every test reads the same real result. */
    private function acceptanceSite(): void
    {
        if ($this->website !== null) {
            return;
        }

        // Real, seeded niche blueprints BEFORE the business exists: they install when it gets its first plan.
        $this->ensureRequiredAppConfigRowsExist();
        $admin = $this->platformAdminId();
        User::query()->whereKey($admin)->update(['is_admin' => true]);
        $this->artisan('blueprint:seed-photo-booth', ['--actor' => $admin])->assertExitCode(0);
        $this->assertSame(0, Artisan::call('documents:seed-photo-booth-templates', ['--actor' => $admin]));
        $this->artisan('blueprint:seed-photo-booth-v2', ['--actor' => $admin])->assertExitCode(0);
        $this->artisan('blueprint:seed-photo-booth-v3', ['--actor' => $admin])->assertExitCode(0);

        [$this->customer, $business] = $this->entitledTenant(['name' => 'Harbor Booths', 'industry' => 'photo_booth_service']);
        DB::table('businesses')->where('id', $business->id)->update(['phone' => '6015550142', 'email' => 'hello@harborbooths.example']);
        $this->business = $business->fresh();
        // The installer is what a real first plan triggers (a queued job in production).
        app(\App\Library\NicheBlueprint\NicheBlueprintInstaller::class)->installForBusiness($this->business);

        foreach (['Magic Mirror Photo Booth', 'Open-Air Photo Booth', '360 Video Booth'] as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => Str::slug($name), 'description' => "What {$name} is: a booth for guests with prints, props and an attendant at your venue, set up in about an hour.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $i + 1]);
        }
        foreach (['Weddings', 'Corporate Events', 'Birthday Parties'] as $i => $event) {
            BusinessService::create(['business_id' => $business->id, 'name' => $event, 'slug' => Str::slug($event), 'kind' => BusinessService::KIND_EVENT, 'description' => "How we run {$event} with an attendant, props and instant sharing for every guest.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 100 + $i]);
        }

        $location = new BusinessLocation();
        $location->forceFill(['business_id' => $business->id, 'is_primary' => true, 'service_mode' => 'service_area', 'name' => 'Main', 'city' => 'Petal', 'region' => 'Mississippi', 'country_code' => 'US', 'service_area_cities' => ['Hattiesburg', 'Laurel', 'Biloxi']])->save();

        // The owner's decision, never the generator's: switch the installed lead form on and offer it to Website pages.
        $form = $this->installedLeadForm();
        $manager = app(FormManager::class);
        $manager->activate($this->business, $form);
        $this->deployment = $manager->setDeployment($this->business, $form->fresh(), $location, true, FormDeploymentSource::Website);

        $this->website = $this->createWebsite($this->business, ['name' => 'Harbor', 'indexing_released_at' => now()]);
        $this->fakeProvider();
        $attempt = app(GuidedGenerationCommitService::class)->generateFull($this->business, $this->website, WebsiteTemplate::findActiveOrFail('photo_booth_modern'), $this->customer->user_id, 'idem-accept', null, null, null, null, self::AREAS);
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);

        app(WebsitePublisher::class)->publish($this->website->fresh(), $this->platformAdminId());
        $this->website->fresh()->domains()->create(['domain' => self::DOMAIN, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 'x', 'verified_at' => now(), 'activated_at' => now()]);
        $this->website = $this->website->fresh();
    }

    private function installedLeadForm(): Form
    {
        $id = \App\Models\BusinessBlueprintComponentInstallation::query()->where('business_id', $this->business->id)->where('component_key', 'pb3_form_lead')->where('state', 'installed')->value('installed_record_id');
        $this->assertNotNull($id, 'the Photo Booth Business Pack v3 installed its lead form for this business');

        return Form::query()->where('business_id', $this->business->id)->findOrFail($id);
    }

    /** @return array<string, string> path => html of every page of the published site */
    private function site(): array
    {
        $this->acceptanceSite();

        return $this->render($this->website);
    }

    private function body(string $html): string
    {
        return preg_replace('#<(header|nav|footer|script|style)\b.*?</\1>#s', '', $html) ?? $html;
    }

    public function test_the_generated_site_has_the_benchmarks_page_structure(): void
    {
        $pages = $this->site();
        $paths = array_keys($pages);

        $this->assertContains('/', $paths);
        foreach (['magic-mirror-photo-booth', 'open-air-photo-booth', '360-video-booth'] as $booth) {
            $this->assertContains('/service-' . $booth, $paths, 'a dedicated page per booth');
        }
        $this->assertCount(3, array_filter($paths, fn ($p) => str_ends_with($p, '-photo-booth-rental')), 'a dedicated page per event occasion');
        foreach (['/photo-booth-about', '/photo-booth-faq', '/photo-booth-contact', '/services'] as $fixed) {
            $this->assertContains($fixed, $paths);
        }
        $this->assertContains('/serving-hattiesburg-ms', $paths);
        $this->assertContains('/serving-laurel-ms', $paths);
        $this->assertNotContains('/serving-biloxi-ms', $paths, 'an area with no local evidence is listed, never a page');
        $this->assertLessThanOrEqual(20, count($paths));

        if ($dir = getenv('ACCEPTANCE_REPORT_DIR')) {
            $this->writeReport((string) $dir, $pages);
        }
    }

    public function test_every_internal_link_and_embedded_form_resolves_and_no_important_section_is_empty(): void
    {
        $pages = $this->site();
        $checked = [];

        foreach ($pages as $path => $html) {
            $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html, "{$path}: responsive viewport");
            $informative = $path === '/' || str_starts_with($path, '/service-') || str_ends_with($path, '-photo-booth-rental') || str_starts_with($path, '/serving-');
            $this->assertGreaterThanOrEqual($informative ? 2 : 1, preg_match_all('#<h2\b[^>]*>\s*\S#', $this->body($html)), "{$path}: titled content sections");
            $this->assertDoesNotMatchRegularExpression('#<h[1-3][^>]*>\s*</h[1-3]>#', $html, "{$path}: no empty heading");
            $this->assertDoesNotMatchRegularExpression('#<a\b[^>]*href="\s*(\#|)"#', $html, "{$path}: no link that points nowhere");

            foreach ($this->links($html) as $link) {
                if (isset($checked[$link]) || preg_match('#^/(css|js|images|fonts|storage|vendor)/|\.(css|js|png|jpe?g|webp|svg|ico)$#', $link)) {
                    continue;
                }
                $checked[$link] = true;
                $this->get($this->url($link))->assertOk();
            }

            preg_match_all('#<iframe[^>]+src="([^"]+)"#', $html, $frames);
            foreach ($frames[1] as $src) {
                $this->get($src)->assertOk();
            }
        }

        $this->assertGreaterThanOrEqual(count($pages) - 2, count($checked), 'the crawl actually followed the site');
    }

    public function test_pages_are_distinct_unique_and_make_no_invented_business_claims(): void
    {
        $pages = $this->site();
        $texts = [];

        foreach ($pages as $path => $html) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($this->body($html))));
            $texts[$path] = mb_substr(mb_strtolower($text), 0, 1500);
            $this->assertDoesNotMatchRegularExpression('/\b(award[- ]winning|best in|#1|five[- ]star|top[- ]rated|years of experience|since 20\d\d|licensed and insured|guarantee)/i', $text, "{$path}: no invented credential or claim");
            $this->assertStringNotContainsString('aggregateRating', $html);
            $this->assertStringNotContainsString('data-testid="testimonials"', $html, "{$path}: no invented reviews");
        }

        $keys = array_keys($texts);
        foreach ($keys as $i => $a) {
            foreach (array_slice($keys, $i + 1) as $b) {
                similar_text($texts[$a], $texts[$b], $percent);
                $this->assertLessThan(92.0, $percent, "{$a} and {$b} must not read as the same page");
            }
        }

        $titles = array_map(fn ($html) => preg_replace('#.*<title>(.*?)</title>.*#s', '$1', $html), $pages);
        $this->assertSame(count($titles), count(array_unique($titles)), 'no duplicate titles');
    }

    public function test_navigation_and_contextual_links_connect_home_services_events_areas_and_contact(): void
    {
        $pages = $this->site();
        $homeBody = $this->links($this->body($pages['/']));

        foreach (['/service-magic-mirror-photo-booth', '/service-open-air-photo-booth', '/service-360-video-booth', '/photo-booth-contact'] as $target) {
            $this->assertContains($target, $homeBody, "home links to {$target} in its body");
        }

        $nav = $this->links(preg_replace('#.*?(<header\b.*?</header>).*#s', '$1', $pages['/']));
        $this->assertContains('/services', $nav);
        $this->assertContains('/photo-booth-contact', $nav);

        foreach (array_keys($pages) as $path) {
            if (str_starts_with($path, '/service-') || str_ends_with($path, '-photo-booth-rental') || str_starts_with($path, '/serving-')) {
                $this->assertContains('/photo-booth-contact', $this->links($pages[$path]), "{$path} leads to the inquiry page");
            }
            if (str_starts_with($path, '/serving-')) {
                $this->assertNotEmpty(array_filter($this->links($this->body($pages[$path])), fn ($l) => str_starts_with($l, '/service-')), "{$path} links to services");
            }
        }
    }

    public function test_seo_canonicals_sitemap_robots_and_structured_data_hold_for_the_whole_site(): void
    {
        $pages = $this->site();
        $sitemap = $this->get($this->url('/sitemap.xml'))->assertOk()->getContent();
        preg_match_all('#<loc>([^<]+)</loc>#', $sitemap, $m);

        foreach ($pages as $path => $html) {
            $url = 'https://' . self::DOMAIN . ($path === '/' ? '/' : $path);
            $this->assertStringContainsString('<link rel="canonical" href="' . $url . '">', $html);
            $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
            $this->assertContains($url, $m[1]);

            preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $j);
            $blocks = array_map(fn ($x) => json_decode($x, true), $j[1]);
            $local = collect($blocks)->firstWhere('@type', 'LocalBusiness');
            $this->assertNotNull($local, "{$path}: LocalBusiness");
            $this->assertSame('Harbor Booths', $local['name']);
            $this->assertStringContainsString('6015550142', preg_replace('/\D/', '', (string) ($local['telephone'] ?? '')));
            $this->assertArrayNotHasKey('address', $local, 'no street address was confirmed, so none is published');
            if ($path !== '/') {
                $this->assertContains('BreadcrumbList', array_column($blocks, '@type'));
            }
        }

        $this->assertStringContainsString('Sitemap: https://' . self::DOMAIN . '/sitemap.xml', $this->get($this->url('/robots.txt'))->getContent());
    }

    public function test_the_inquiry_journey_uses_pb3_form_lead_and_a_submission_creates_a_contact_and_an_inquiry_but_no_booking(): void
    {
        $pages = $this->site();
        $contact = $pages['/photo-booth-contact'];

        $this->assertSame($this->installedLeadForm()->id, Form::query()->where('uid', json_decode(json_encode($this->deployment->form), true)['uid'])->value('id'));
        $this->assertSame('pb3_form_lead', \App\Models\BusinessBlueprintComponentInstallation::query()->where('installed_record_id', $this->deployment->form_id)->where('component_type', 'form')->value('component_key'));
        $this->assertMatchesRegularExpression('#<iframe src="[^"]*/forms/' . $this->deployment->uid . '"#', $contact, 'the page embeds the installed form deployment');
        $this->assertStringContainsString('data-mg-lead-form="1"', $contact, 'the lead-form frame reports conversions');
        $this->assertSame(0, \App\Models\WebsiteForm::query()->where('website_id', $this->website->id)->count(), 'no duplicate legacy website form');

        // The form is served from the application's own host (the customer's domain only frames it).
        $app = rtrim((string) config('app.url'), '/');
        $formPage = $this->get($app . '/forms/' . $this->deployment->uid)->assertOk()->getContent();
        foreach (['full_name', 'phone', 'email', 'event_date', 'event_type'] as $field) {
            $this->assertStringContainsString('name="' . $field, $formPage, "the real lead form asks for {$field}");
        }

        // The frame sizes itself to the form (the submit button is never hidden under a nested scrollbar): the page loads the frame
        // script once, and the form reports its height only to the origins allowed to frame it.
        $this->assertSame(1, substr_count($contact, 'js/website-form-frame.js'));
        $this->assertStringContainsString('motiongrove:form_height', $formPage);
        $this->assertStringContainsString($this->deployment->uid, $formPage);
        $this->assertStringContainsString('https:\/\/' . self::DOMAIN, $formPage);
        $this->assertStringNotContainsString("postMessage(message, '*')", $formPage);
        $frameJs = file_get_contents(public_path('js/website-form-frame.js'));
        foreach (['frame.contentWindow !== event.source', 'event.origin !== origin', "data.deployment_uid !== frame.getAttribute('data-deployment-uid')", 'MAX'] as $guard) {
            $this->assertStringContainsString($guard, $frameJs);
        }

        $response = $this->post($app . '/forms/' . $this->deployment->uid, [
            FormSubmissionService::TOKEN_FIELD => FormOperationToken::issue($this->deployment),
            'full_name' => 'Ada Lovelace', 'phone' => '+1 (601) 555-0199', 'email' => 'ada@example.test',
            'event_date' => now()->addMonths(3)->toDateString(), 'event_type' => ['Wedding'], 'notes' => 'Reception for 120 guests.',
        ]);
        $response->assertRedirect();
        $this->assertStringContainsString('/thanks', $response->headers->get('Location'));

        $submission = \App\Models\FormSubmission::query()->where('form_deployment_id', $this->deployment->id)->firstOrFail();
        $this->assertNotNull($submission->contact_id, 'the Forms service resolved a CRM contact');
        $contactRow = Contacts::query()->where('business_id', $this->business->id)->findOrFail($submission->contact_id);
        $inquiries = CrmPipeline::query()->where('business_id', $this->business->id)->where('name', 'Inquiries')->firstOrFail();
        $opportunity = CrmOpportunity::query()->where('contact_id', $contactRow->id)->firstOrFail();
        $this->assertSame((int) $inquiries->id, (int) $opportunity->pipeline_id, 'the inquiry lands in the Inquiries pipeline');
        $this->assertSame('New Lead', $inquiries->stages()->whereKey($opportunity->stage_id)->value('name'));
        $this->assertSame(0, DB::table('appointments')->count(), 'an inquiry is never a confirmed booking');

        // The thank-you announces exactly this stored submission to the site's own origin, with no personal data.
        $thanks = $this->get($response->headers->get('Location'))->assertOk()->getContent();
        $this->assertStringContainsString('motiongrove:lead_submitted', $thanks);
        $this->assertStringContainsString('https:\/\/' . self::DOMAIN, $thanks);
        foreach (['Ada Lovelace', 'ada@example.test', '555-0199'] as $personal) {
            $this->assertStringNotContainsString($personal, $thanks);
        }
    }

    public function test_google_ads_and_meta_lead_conversion_tracking_attach_to_every_published_page_and_the_lead_frame(): void
    {
        $this->site();
        app(WebsiteTrackingSettings::class)->save($this->website, '*', ['google_tag_id' => 'G-ABC123XYZ', 'google_ads_id' => 'AW-123456789', 'google_ads_lead_label' => 'AbCdEfGhIj', 'meta_pixel_id' => '123456789012345']);

        foreach (['/', '/photo-booth-contact', '/service-magic-mirror-photo-booth', '/serving-laurel-ms'] as $path) {
            $html = $this->get($this->url($path))->getContent();
            preg_match('#<script type="application/json" id="mg-tracking-config">(.*?)</script>#s', $html, $m);
            $config = json_decode($m[1] ?? 'null', true);
            $this->assertSame([['id' => 'AW-123456789', 'label' => 'AbCdEfGhIj']], $config['ads_conversions'] ?? null, "{$path}: Google Ads lead conversion");
            $this->assertSame(['123456789012345'], $config['meta_pixel_ids'] ?? null, "{$path}: Meta Pixel");
        }

        $js = file_get_contents(public_path('js/website-tracking.js'));
        $this->assertStringContainsString("fbq('track', 'Lead')", $js);
        $this->assertStringContainsString("event: 'motiongrove_lead'", $js);
    }

    public function test_the_native_blog_uses_the_same_design_links_to_services_and_keeps_the_inquiry_journey(): void
    {
        $pages = $this->site();
        $service = collect($this->website->fresh()->publishedRevision->snapshot['pages'])->firstWhere('slug', 'service-magic-mirror-photo-booth');
        $paragraph = 'Planning a photo booth for your event starts with the guest list, the venue layout and the time you want the booth open. A booth works best when guests can walk up easily, so choose a spot near the action but away from the busiest doorway. Think about how long the line may get during peak moments, and leave enough room for a small group to pose together. ';
        $manager = app(\App\Library\Seo\Content\ArticleManager::class);
        $article = $manager->create((int) $this->business->customer_id, $this->business, [
            'title' => 'How Much Space Does a Magic Mirror Booth Need?',
            'excerpt' => 'A practical look at the floor space, power and layout a magic mirror booth needs at your venue.',
            'body' => "## What to plan first\n\n" . str_repeat($paragraph, 2) . "\n\n## Choosing the right setup\n\n" . str_repeat($paragraph, 2),
            'meta_description' => 'A practical look at the floor space, power and layout a magic mirror booth needs at your venue, with tips for planning.',
            'primary_topic' => 'how much space does a magic mirror booth need',
            'search_intent' => 'planning',
            'supports_page_uid' => $service['uid'],
        ]);
        $manager->publish((int) $this->business->customer_id, $this->business, $article);

        $index = $this->get($this->url('/blog'))->assertOk()->getContent();
        $post = $this->get($this->url('/blog/' . $article->slug))->assertOk()->getContent();
        $home = $this->get($this->url('/'))->getContent();

        $header = fn (string $html) => preg_replace('#.*?(<header\b.*?</header>).*#s', '$1', $html);
        $stylesheets = fn (string $html) => (function () use ($html) { preg_match_all('#<link rel="stylesheet" href="([^"?]+)#', $html, $m); return $m[1]; })();
        foreach ([$index, $post] as $blog) {
            $this->assertStringContainsString('wd wd-', $blog, 'rendered by the template design, not a second renderer');
            $this->assertSame($stylesheets($home), array_slice($stylesheets($blog), 0, count($stylesheets($home))), 'the same stylesheets as every other page (the blog adds only its own)');
            $this->assertSame($this->links($header($home)), $this->links($header($blog)), 'the same header navigation');
        }

        $this->assertContains('/service-magic-mirror-photo-booth', $this->links($this->body($post)), 'the article links to the service it supports');
        $this->assertContains('/photo-booth-contact', $this->links($post), 'the article keeps the inquiry journey');
        $this->assertContains('/blog/' . $article->slug, $this->links($index));
        $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . '/blog/' . $article->slug . '">', $post);
        $this->assertStringContainsString('BlogPosting', $post);
        $this->assertStringContainsString('https://' . self::DOMAIN . '/blog/' . $article->slug, $this->get($this->url('/sitemap.xml'))->getContent());
    }

    /** @param array<string, string> $pages */
    private function writeReport(string $dir, array $pages): void
    {
        @mkdir($dir, 0777, true);
        $inventory = [];

        foreach ($pages as $path => $html) {
            $slug = $path === '/' ? 'index' : trim($path, '/');
            $inventory[] = ['path' => $path, 'title' => preg_replace('#.*<title>(.*?)</title>.*#s', '$1', $html), 'sections' => preg_match_all('#<h2\b#', $this->body($html)), 'links' => count($this->links($html)), 'indexable' => str_contains($html, 'content="index, follow"')];
            @mkdir($dir . '/' . dirname($slug), 0777, true);
            file_put_contents($dir . '/' . $slug . '.html', $html);
        }

        $form = $this->get(rtrim((string) config('app.url'), '/') . '/forms/' . $this->deployment->uid)->getContent();
        file_put_contents($dir . '/lead-form.html', $form);
        file_put_contents($dir . '/inventory.json', json_encode($inventory, JSON_PRETTY_PRINT));
    }
}
