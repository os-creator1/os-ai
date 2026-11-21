<?php

namespace Tests\Feature\Website\Acceptance;

use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Forms\FormManager;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\Form;
use App\Models\User;
use App\Models\Website;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\Feature\Website\Concerns\PublishesGeneratedSites;
use Tests\TestCase;

/**
 * Slice 7 — the generator is not only a photo booth generator. A TUTORING business (real seeded niche blueprint, owner-activated
 * student inquiry form, owner-confirmed fact, fake AI) goes through generation, the owner's Preview, a meaningful edit, publish, a
 * reload, and a look change across all four templates — over the same owner HTTP routes the Photo Booth acceptance bots use.
 * Copy comes from the fake AI, so this proves structure, URLs, forms, SEO and the draft/published boundary, never copy quality.
 */
class NonPhotoBoothNicheAcceptanceTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;
    use PublishesGeneratedSites;
    use RefreshDatabase;

    private const DOMAIN = 'petal-tutoring.test';

    private const AREAS = [
        ['name' => 'Hattiesburg, MS', 'details' => ['venues' => ['The Hattiesburg Public Library'], 'logistics' => 'About 20 minutes from Petal; sessions are held at the library or online.']],
    ];

    private const TEMPLATES = ['photo_booth_modern', 'photo_booth_editorial', 'photo_booth_luxury', 'photo_booth_conversion'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
        config(['ai.website_content_quality' => true]);
    }

    private function pageForm($page, callable $change): array
    {
        return [
            'title' => $page->title, 'slug' => $page->is_home ? '' : $page->slug, 'is_home' => $page->is_home ? '1' : '0',
            'sections' => json_encode($change($page->sections ?? [])), 'seo_title' => $page->seo_title,
            'meta_description' => $page->meta_description, 'noindex' => $page->noindex ? '1' : '0',
        ];
    }

    /** @param array{publish: string, studio: string} $urls built BEFORE any custom-domain request (the URL generator keeps the last host) */
    private function publishAsOwner(array $urls): void
    {
        $this->post($urls['publish'])->assertRedirect();
        $this->get($urls['studio'])->assertOk();
        app('cache')->flush();
    }

    public function test_a_tutoring_owner_generates_previews_edits_publishes_and_reloads_a_complete_site_in_every_template(): void
    {
        // The real shipped Tutoring blueprint, installed into this business; the owner (not the generator) switches its student form on.
        $admin = $this->platformAdminId();
        User::query()->whereKey($admin)->update(['is_admin' => true]);
        $this->artisan('blueprint:seed-niche', ['niche' => 'tutoring_exam_prep', '--actor' => $admin])->assertExitCode(0);

        $form = null;
        $deployment = null;
        [$website, $business] = $this->publishedSite('tutoring', true, true, false, ['One-on-one sessions with the same tutor each week'], function ($website, $business) use (&$form, &$deployment) {
            DB::table('business_knowledge_profiles')->where('business_id', $business->id)->exists()
                || app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->getOrCreate($business);
            DB::table('business_knowledge_profiles')->where('business_id', $business->id)->update(['vertical_key' => 'tutoring_exam_prep']);
            app(NicheBlueprintInstaller::class)->installForBusiness($business->fresh());

            $id = \App\Models\BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->where('component_key', 'tutoring_form_student_inquiry')->where('state', 'installed')->value('installed_record_id');
            $this->assertNotNull($id, 'the Tutoring blueprint installed its student inquiry form');
            $form = Form::query()->where('business_id', $business->id)->findOrFail($id);
            $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
            $manager = app(FormManager::class);
            $manager->activate($business->fresh(), $form);
            $deployment = $manager->setDeployment($business->fresh(), $form->fresh(), $location, true, FormDeploymentSource::Website);
        });

        $customer = Customer::query()->where("user_id", $business->customer_id)->firstOrFail();
        $workspace = Workspace::query()->findOrFail($business->workspace_id);
        $this->authenticateAsCustomer($customer);

        $home = $website->pages()->where('is_home', true)->sole();
        $urls = [
            'publish' => $this->wizardUrl($workspace, $business, 'publish'),
            'studio' => $this->wizardUrl($workspace, $business, 'studio.show'),
            'rebuild' => $this->wizardUrl($workspace, $business, 'rebuild'),
            'edit' => $this->wizardUrl($workspace, $business, 'pages.update', [$home->uid]),
        ];

        // ---- generated page plan and niche-safe addresses
        $pages = $website->pages()->orderBy('sort_order')->orderBy('id')->get();
        $slugs = $pages->pluck('slug')->filter()->all();
        $this->assertGreaterThanOrEqual(8, $pages->count());
        $this->assertSame([], array_values(array_filter($slugs, fn ($s) => str_contains((string) $s, 'photo-booth'))), 'no photo-booth address on a tutoring site');
        $this->assertContains('contact', $slugs);
        $this->assertContains('service-sat-preparation', $slugs);
        $this->assertSame(count($slugs), count(array_unique($slugs)));

        // ---- Preview: every page renders for the owner, once, noindex, and never claims what nobody confirmed
        foreach ($pages as $page) {
            $html = $this->get($this->wizardUrl($workspace, $business, 'preview', [$page->uid]))->assertOk()->getContent();
            $this->assertSame(1, substr_count(strtolower($html), '<h1'), "{$page->title}: one main heading");
            $this->assertStringContainsString('name="viewport"', $html);
            $this->assertMatchesRegularExpression('/<meta name="robots" content="noindex/i', $html, 'a Preview is never indexable');
            foreach (['lorem ipsum', 'award-winning', 'five-star', '5-star', 'years of experience', 'licensed and insured', 'as seen on', 'guarantee'] as $invented) {
                $this->assertStringNotContainsStringIgnoringCase($invented, $html, "{$page->title}: no invented claim ({$invented})");
            }
        }

        // ---- the niche's own form on the contact page (never the photo-booth quote form), beside the real phone/email
        $contact = $website->pages()->where('slug', 'contact')->sole();
        $types = array_column($contact->sections, 'type');
        $this->assertContains('forms_module_form', $types);
        $this->assertNotContains('form', $types, 'never the legacy photo-booth quote form');
        $this->assertContains('contact_details', $types, 'phone and email stay available');
        $this->assertSame($deployment->uid, collect($contact->sections)->firstWhere('type', 'forms_module_form')['data']['forms_module_deployment_uid']);
        $this->assertSame(0, \App\Models\WebsiteForm::query()->where('website_id', $website->id)->count(), 'no photo-booth quote form row was created');

        // ---- the owner's meaningful edit (text + a CTA), made on the draft
        $headline = 'Petal tutoring, one student at a time';
        $this->put($urls['edit'], $this->pageForm($home, function (array $sections) use ($headline) {
            $sections[0]['data']['heading'] = $headline;
            $sections[0]['data']['primary_cta'] = ['label' => 'Ask about sessions', 'url' => '/contact'];

            return $sections;
        }))->assertRedirect()->assertSessionHas('status', 'success');
        $this->assertSame($headline, $home->fresh()->sections[0]['data']['heading']);

        // ---- before publishing nothing is public; after it, the public site is exactly the published snapshot
        $website->fresh()->domains()->create(['domain' => self::DOMAIN, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 'x', 'verified_at' => now(), 'activated_at' => now()]);
        $this->get($this->url('/'))->assertNotFound();
        $this->publishAsOwner($urls);

        $site = $this->render($website->fresh());
        $this->assertStringContainsString($headline, strip_tags($site['/']));
        $this->assertContains('/contact', $this->links($site['/']), 'the owner CTA reaches the real contact address');
        $this->assertCount($pages->count(), $site);

        // An edit made after publishing stays a draft; a reload of the public site still shows the published text.
        $this->put($urls['edit'], $this->pageForm($home->fresh(), function (array $sections) {
            $sections[0]['data']['heading'] = 'An unpublished later headline';

            return $sections;
        }))->assertRedirect();
        app('cache')->flush();
        $reload = strip_tags($this->get($this->url('/'))->assertOk()->getContent());
        $this->assertStringContainsString($headline, $reload);
        $this->assertStringNotContainsString('An unpublished later headline', $reload);
        $this->put($urls['edit'], $this->pageForm($home->fresh(), function (array $sections) use ($headline) {
            $sections[0]['data']['heading'] = $headline;

            return $sections;
        }))->assertRedirect();

        // ---- rendered SEO on the published site
        foreach ($site as $path => $html) {
            $this->assertMatchesRegularExpression('#<title>[^<]{5,}</title>#', $html, "{$path}: a title");
            $this->assertMatchesRegularExpression('#<meta name="description" content="[^"]{10,}"#', $html, "{$path}: a description");
            $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . ($path === '/' ? '/' : $path) . '">', $html);
            $this->assertMatchesRegularExpression('#<meta name="robots" content="index#i', $html, "{$path}: indexable once the owner released indexing");
            $path === '/' || $this->assertStringContainsString('BreadcrumbList', $html, "{$path}: breadcrumb data");
        }
        $this->assertStringContainsString('LocalBusiness', $site['/']);
        $sitemap = $this->get($this->url('/sitemap.xml'))->assertOk()->getContent();
        foreach (array_keys($site) as $path) {
            $this->assertStringContainsString('https://' . self::DOMAIN . ($path === '/' ? '/' : $path) . '</loc>', $sitemap, "{$path} is in the sitemap");
        }
        $this->assertStringNotContainsString('photo-booth', $sitemap);

        // ---- the four templates: a look-only change keeps pages, the owner's edit and the niche form; publishing shows the new design
        foreach (self::TEMPLATES as $template) {
            $this->post($urls['rebuild'], ['template_key' => $template, 'mode' => 'look_only', 'confirm_rebuild' => '1'])->assertRedirect();
            $this->assertSame($pages->count(), $website->pages()->count(), "{$template}: every page kept");
            $this->assertSame($headline, $home->fresh()->sections[0]['data']['heading'], "{$template}: the owner's edit survives");
            $this->publishAsOwner($urls);

            $contactHtml = $this->get($this->url('/contact'))->assertOk()->getContent();
            $this->assertStringContainsString('wd wd-' . str_replace('photo_booth_', '', $template), $contactHtml, "{$template}: the template's design is live");
            $this->assertStringContainsString('data-deployment-uid="' . $deployment->uid . '"', $contactHtml, "{$template}: the tutoring student form is embedded");
            $this->assertStringContainsString('data-mg-lead-form="1"', $contactHtml);
            $this->assertStringContainsString('tel:', $contactHtml, "{$template}: a phone path");
            $this->assertStringContainsString('mailto:', $contactHtml, "{$template}: an email path");
            $homeHtml = $this->get($this->url('/'))->assertOk()->getContent();
            $this->assertStringContainsString($headline, strip_tags($homeHtml));

            // Evidence runs only: ACCEPTANCE_REPORT_DIR=/tmp/x dumps the rendered pages for the browser check.
            if ($dir = getenv('ACCEPTANCE_REPORT_DIR')) {
                @mkdir($dir . '/' . $template, 0777, true);
                file_put_contents($dir . '/' . $template . '/index.html', $homeHtml);
                file_put_contents($dir . '/' . $template . '/contact.html', $contactHtml);
            }
        }
    }
}
