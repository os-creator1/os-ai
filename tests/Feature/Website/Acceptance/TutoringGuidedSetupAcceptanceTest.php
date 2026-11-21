<?php

namespace Tests\Feature\Website\Acceptance;

use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\Form;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\Feature\Website\Concerns\DrivesTutoringSetup;
use Tests\Feature\Website\Concerns\FakesGuidedWebsiteAi;
use Tests\TestCase;

/**
 * Slice 8 — a TUTORING owner goes through the REAL guided setup (the niche's own seeded questionnaire, answered screen by screen
 * over the owner's HTTP routes), generates, previews every page, edits, publishes, reloads the public site, and a visitor
 * submits the Tutoring blueprint's student inquiry form through the real Forms pipeline. Fake AI only: this proves structure,
 * URLs, facts, forms, SEO and the draft/published boundary, never copy quality.
 */
class TutoringGuidedSetupAcceptanceTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;
    use DrivesTutoringSetup;
    use FakesGuidedWebsiteAi;
    use RefreshDatabase;

    private const DOMAIN = 'petal-tutoring.test';

    private const TEMPLATES = ['tutoring_modern', 'tutoring_editorial', 'tutoring_luxury', 'tutoring_conversion'];

    public function test_the_wizard_serves_the_tutoring_questionnaire_driven_by_the_seeded_definition(): void
    {
        $this->tutoringTenant();

        $session = $this->wizardSession();
        $this->assertSame('tutoring_exam_prep', $session->version->questionnaireDefinition->niche_key);
        $keys = array_column($session->version->steps(), 'key');
        $this->assertContains('lesson_services', $keys);
        foreach (['booth_services', 'backdrops', 'contact_form_fields', 'event_types'] as $photoBoothKey) {
            $this->assertNotContains($photoBoothKey, $keys);
        }
        $this->assertSame('tutoring_modern', \App\Models\Website::where('business_id', $this->business->id)->value('template_key'), 'the niche starts on its own first look');
    }

    public function test_a_tutoring_owner_goes_from_the_questionnaire_to_a_published_site_and_a_real_student_inquiry(): void
    {
        $this->tutoringTenant();

        // The owner's own routes, built before any customer-domain request.
        $urls = [
            'review' => $this->url('review'),
            'preview' => $this->owner('preview'),
            'publish' => $this->owner('publish'),
            'studio' => $this->owner('studio.show'),
            'rebuild' => $this->owner('rebuild'),
            'allow' => $this->owner('pages.allowIndexing'),
        ];

        // ---- the review screen offers THIS niche's looks (never the Photo Booth ones) and the page plan its answers earn
        $this->completeTutoringSetup();
        $review = $this->get($urls['review'])->assertOk()->getContent();
        $this->assertStringContainsString('Bold Scholar', $review);
        foreach (self::TEMPLATES as $look) {
            $this->assertStringContainsString('value="' . $look . '"', $review, "the review offers {$look}");
        }
        $this->assertStringNotContainsString('value="photo_booth_', $review, 'no Photo Booth look is offered to a tutoring business');

        $website = $this->generate();
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $website->guidedGenerationAttempts()->latest('id')->value('status'));

        // ---- confirmed answers became real records and facts
        $this->assertEqualsCanonicalizing(['Mathematics Tutoring', 'Exam Preparation'], $this->business->services()->pluck('name')->all());
        $profile = \App\Models\BusinessKnowledgeProfile::where('business_id', $this->business->id)->firstOrFail();
        $this->assertEqualsCanonicalizing(['Lessons are one-on-one', 'We offer a trial lesson or a free consultation'], array_map(fn ($d) => is_array($d) ? ($d['label'] ?? $d['value'] ?? reset($d)) : $d, (array) $profile->differentiators), 'owner-confirmed differentiators, in their own words');
        $this->assertSame('601-555-0142', $this->business->fresh()->phone);

        // ---- page plan and niche-safe addresses
        $pages = $website->pages()->orderBy('sort_order')->orderBy('id')->get();
        $slugs = $pages->pluck('slug')->filter()->all();
        foreach (['about', 'contact', 'faq', 'services', 'service-mathematics-tutoring', 'service-exam-preparation', 'how-a-first-lesson-works'] as $expected) {
            $this->assertContains($expected, $slugs, "[{$expected}] is planned: " . json_encode($slugs));
        }
        $this->assertSame([], array_values(array_filter($slugs, fn ($s) => str_contains((string) $s, 'photo-booth') || str_contains((string) $s, 'booth'))));
        $this->assertSame(count($slugs), count(array_unique($slugs)));
        $this->assertNotContains('serving-hattiesburg-ms', $slugs, 'areas the owner listed are mentioned, never given a page of their own');

        // ---- Preview: every page, once, noindex, and no invented claim
        foreach ($pages as $page) {
            $html = $this->get($this->owner('preview', [$page->uid]))->assertOk()->getContent();
            $this->assertSame(1, substr_count(strtolower($html), '<h1'), "{$page->title}: one main heading");
            $this->assertStringContainsString('name="viewport"', $html);
            $this->assertMatchesRegularExpression('/<meta name="robots" content="noindex/i', $html);
            foreach (['lorem ipsum', 'award-winning', 'five-star', '5-star', 'years of experience', 'licensed and insured', 'as seen on', 'guarantee'] as $invented) {
                $this->assertStringNotContainsStringIgnoringCase($invented, $html, "{$page->title}: no invented claim ({$invented})");
            }
        }

        // ---- the owner's own words, verbatim: the FAQ answers and the custom "how a lesson works" section
        $faq = $website->pages()->where('slug', 'faq')->sole();
        $faqText = json_encode($faq->sections);
        $this->assertStringContainsString('Yes. Ask about a trial lesson when you send an inquiry.', $faqText);
        $custom = $website->pages()->where('slug', 'how-a-first-lesson-works')->sole();
        $this->assertStringContainsString('We start by talking with the student about what they are studying', json_encode($custom->sections));

        // ---- the contact page carries the Tutoring blueprint's student form beside phone and email
        $contact = $website->pages()->where('slug', 'contact')->sole();
        $types = array_column($contact->sections, 'type');
        $this->assertContains('forms_module_form', $types);
        $this->assertContains('contact_details', $types);
        $this->assertNotContains('form', $types, 'never the legacy photo-booth quote form');
        $this->assertSame($this->deployment->uid, collect($contact->sections)->firstWhere('type', 'forms_module_form')['data']['forms_module_deployment_uid']);

        // ---- an owner edit on the draft, then publish
        $home = $website->pages()->where('is_home', true)->sole();
        $editUrl = $this->owner('pages.update', [$home->uid]);
        $headline = 'Petal tutoring, one student at a time';
        $this->put($editUrl, $this->pageForm($home, function (array $sections) use ($headline) {
            $sections[0]['data']['heading'] = $headline;
            $sections[0]['data']['primary_cta'] = ['label' => 'Ask about lessons', 'url' => '/contact'];

            return $sections;
        }))->assertRedirect()->assertSessionHas('status', 'success');

        $website->fresh()->domains()->create(['domain' => self::DOMAIN, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 'x', 'verified_at' => now(), 'activated_at' => now()]);
        $this->get($this->site('/'))->assertNotFound();
        $this->publishAsOwner($urls);

        // ---- public reload: exactly the published snapshot
        $public = [];
        foreach ($website->fresh()->publishedRevision->snapshot['pages'] as $page) {
            $path = ! empty($page['is_home']) ? '/' : '/' . $page['slug'];
            $public[$path] = $this->get($this->site($path))->assertOk()->getContent();
        }
        $this->assertCount($pages->count(), $public);
        $this->assertStringContainsString($headline, strip_tags($public['/']));
        $links = fn (string $html) => (function () use ($html) { preg_match_all('#href="(?:https://' . preg_quote(self::DOMAIN, '#') . ')?(/[^"\#?]*)"#', $html, $m); return array_values(array_unique($m[1])); })();
        $this->assertContains('/contact', $links($public['/']), 'the owner CTA reaches the real contact address');
        foreach (array_keys($public) as $path) {
            $this->assertContains('/contact', $links($public[$path]), "{$path} leads to /contact");
            foreach ($links($public[$path]) as $internal) {
                $this->assertTrue($internal === '/' || isset($public[$internal]) || in_array($internal, ['/sitemap.xml'], true) || str_starts_with($internal, '/css') || str_starts_with($internal, '/js') || str_starts_with($internal, '/forms/'), "{$path} links to a real page: {$internal}");
            }
        }

        // ---- generated pages are hidden from search until the OWNER releases them; releasing and republishing makes them indexable
        $this->assertMatchesRegularExpression('/<meta name="robots" content="noindex/i', $public['/'], 'a freshly published site is not indexable yet');
        $this->post($urls['allow'])->assertRedirect();
        $this->publishAsOwner($urls);
        foreach (array_keys($public) as $path) {
            $public[$path] = $this->get($this->site($path))->assertOk()->getContent();
        }

        // ---- a later draft edit never reaches the public site until the owner republishes
        $this->put($editUrl, $this->pageForm($home->fresh(), function (array $sections) {
            $sections[0]['data']['heading'] = 'An unpublished later headline';

            return $sections;
        }))->assertRedirect();
        app('cache')->flush();
        $reload = strip_tags($this->get($this->site('/'))->assertOk()->getContent());
        $this->assertStringContainsString($headline, $reload);
        $this->assertStringNotContainsString('An unpublished later headline', $reload);
        $this->assertStringContainsString('An unpublished later headline', strip_tags($this->get($urls['preview'])->getContent()), 'the owner still sees it in Preview');

        // ---- rendered SEO
        foreach ($public as $path => $html) {
            $this->assertMatchesRegularExpression('#<title>[^<]{5,}</title>#', $html, "{$path}: a title");
            $this->assertMatchesRegularExpression('#<meta name="description" content="[^"]{10,}"#', $html, "{$path}: a description");
            $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . ($path === '/' ? '/' : $path) . '">', $html);
            $this->assertMatchesRegularExpression('#<meta name="robots" content="index#i', $html, "{$path}: indexable once released");
            $path === '/' || $this->assertStringContainsString('BreadcrumbList', $html, "{$path}: breadcrumb data");
        }
        $this->assertStringContainsString('LocalBusiness', $public['/']);
        $this->assertStringContainsString('601-555-0142', strip_tags($public['/contact']) . $public['/contact']);
        $this->assertStringContainsString('tel:', $public['/contact']);
        $this->assertStringContainsString('mailto:hello@petaltutoring.test', $public['/contact']);
        $sitemap = $this->get($this->site('/sitemap.xml'))->assertOk()->getContent();
        foreach (array_keys($public) as $path) {
            $this->assertStringContainsString('https://' . self::DOMAIN . ($path === '/' ? '/' : $path) . '</loc>', $sitemap, "{$path} is in the sitemap");
        }
        $this->assertStringNotContainsString('photo-booth', $sitemap);

        // ---- a visitor submits the published student inquiry form through the real Forms pipeline
        $app = rtrim((string) config('app.url'), '/');
        $contactHtml = $public['/contact'];
        $this->assertStringContainsString('data-deployment-uid="' . $this->deployment->uid . '"', $contactHtml);
        $this->assertStringContainsString('data-mg-lead-form="1"', $contactHtml);
        $formPage = $this->get($app . '/forms/' . $this->deployment->uid)->assertOk()->getContent();
        foreach (['full_name', 'phone', 'student_grade', 'subject'] as $field) {
            $this->assertStringContainsString('name="' . $field, $formPage, "the real student form asks for {$field}");
        }

        $input = [
            FormSubmissionService::TOKEN_FIELD => FormOperationToken::issue($this->deployment),
            'full_name' => 'Jordan Parent', 'phone' => '+1 (601) 555-0199', 'email' => 'jordan@example.test',
            'student_grade' => '8', 'subject' => 'Mathematics', 'goal' => 'Fractions and algebra basics.', 'format' => 'Either',
        ];
        $response = $this->post($app . '/forms/' . $this->deployment->uid, $input);
        $response->assertRedirect();
        $this->assertStringContainsString('/thanks', $response->headers->get('Location'));
        $this->post($app . '/forms/' . $this->deployment->uid, $input);   // a double click / replay of the same submission

        $submissions = \App\Models\FormSubmission::query()->where('form_deployment_id', $this->deployment->id)->get();
        $this->assertCount(1, $submissions, 'a replayed submission creates no second submission');
        $contactRow = Contacts::query()->where('business_id', $this->business->id)->findOrFail($submissions->first()->contact_id);
        $this->assertSame(1, Contacts::query()->where('business_id', $this->business->id)->where('phone', $contactRow->phone)->count(), 'no duplicate Contact');
        $pipeline = CrmPipeline::query()->where('business_id', $this->business->id)->where('name', 'Student Enrollment')->firstOrFail();
        $opportunity = CrmOpportunity::query()->where('contact_id', $contactRow->id)->firstOrFail();
        $this->assertSame((int) $pipeline->id, (int) $opportunity->pipeline_id, 'the inquiry lands in the Tutoring Student Enrollment pipeline');
        $this->assertSame('New inquiry', $pipeline->stages()->whereKey($opportunity->stage_id)->value('name'));
        $this->assertSame(1, CrmOpportunity::query()->where('contact_id', $contactRow->id)->count());
        $this->assertSame(0, DB::table('appointments')->count(), 'an inquiry is never a booking');
        $thanks = $this->get($response->headers->get('Location'))->assertOk()->getContent();
        $this->assertStringContainsString('motiongrove:lead_submitted', $thanks);
        foreach (['Jordan Parent', 'jordan@example.test', '555-0199'] as $personal) {
            $this->assertStringNotContainsString($personal, $thanks);
        }

        // ---- the four looks: each keeps every page, the owner's edit and the form, and publishes its own design
        $this->put($editUrl, $this->pageForm($home->fresh(), function (array $sections) use ($headline) {
            $sections[0]['data']['heading'] = $headline;

            return $sections;
        }))->assertRedirect();

        foreach (self::TEMPLATES as $template) {
            $this->post($urls['rebuild'], ['template_key' => $template, 'mode' => 'look_only', 'confirm_rebuild' => '1'])->assertRedirect();
            $this->assertSame($pages->count(), $website->pages()->count(), "{$template}: every page kept");
            $this->assertSame($headline, $home->fresh()->sections[0]['data']['heading'], "{$template}: the owner's edit survives");
            $this->publishAsOwner($urls);

            $contactHtml = $this->get($this->site('/contact'))->assertOk()->getContent();
            $this->assertStringContainsString('wd wd-' . str_replace('tutoring_', '', $template), $contactHtml, "{$template}: its design is live");
            $this->assertStringContainsString('data-deployment-uid="' . $this->deployment->uid . '"', $contactHtml);
            $this->assertStringContainsString('tel:', $contactHtml);
            $this->assertStringContainsString('mailto:', $contactHtml);
            $homeHtml = $this->get($this->site('/'))->assertOk()->getContent();
            $this->assertStringContainsString($headline, strip_tags($homeHtml));

            // Evidence runs only: ACCEPTANCE_REPORT_DIR=/tmp/x dumps the rendered pages for the browser check.
            if ($dir = getenv('ACCEPTANCE_REPORT_DIR')) {
                @mkdir($dir . '/' . $template, 0777, true);
                file_put_contents($dir . '/' . $template . '/index.html', $homeHtml);
                file_put_contents($dir . '/' . $template . '/contact.html', $contactHtml);
                file_put_contents($dir . '/' . $template . '/faq.html', $this->get($this->site('/faq'))->getContent());
            }
        }
    }

    public function test_the_niche_seed_is_idempotent_and_never_touches_another_niches_looks_or_questionnaire(): void
    {
        $this->seed(\Database\Seeders\WebsiteTemplateSeeder::class);
        $photoBooth = \App\Models\WebsiteTemplate::where('niche_key', 'photo_booth_service')->orderBy('key')->get(['key', 'theme', 'display_name'])->toArray();

        $this->ensureRequiredAppConfigRowsExist();
        User::query()->whereKey($this->platformAdminId())->update(['is_admin' => true]);
        foreach ([1, 2] as $run) {
            $this->artisan('blueprint:seed-niche', ['niche' => 'tutoring_exam_prep', '--actor' => $this->platformAdminId()])->assertExitCode(0);
        }

        $this->assertEqualsCanonicalizing(self::TEMPLATES, \App\Models\WebsiteTemplate::where('niche_key', 'tutoring_exam_prep')->pluck('key')->all());
        $this->assertSame($photoBooth, \App\Models\WebsiteTemplate::where('niche_key', 'photo_booth_service')->orderBy('key')->get(['key', 'theme', 'display_name'])->toArray(), 'Photo Booth looks are untouched');
        $this->assertSame(0, \App\Models\WebsiteTemplate::where('niche_key', 'dog_boarding')->count(), 'Dog Boarding looks are not installed by the Tutoring seed');
        $definition = \App\Models\QuestionnaireDefinition::where('key', \Database\Seeders\TutoringWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        $this->assertSame(1, $definition->versions()->count(), 'one published version, however often it is seeded');
        $this->assertSame(0, \App\Models\QuestionnaireDefinition::where('niche_key', 'photo_booth_service')->count(), 'the Tutoring seed does not create or change a Photo Booth questionnaire');
        foreach (self::TEMPLATES as $key) {
            $this->assertNotNull(\App\Library\Website\Design\WebsiteDesigns::forTemplateKey($key), "{$key} has a design");
        }
    }

    public function test_a_photo_booth_business_still_gets_the_photo_booth_questionnaire_and_looks_when_the_tutoring_niche_is_seeded(): void
    {
        $this->seedGuidedWizard();
        $this->ensureRequiredAppConfigRowsExist();
        User::query()->whereKey($this->platformAdminId())->update(['is_admin' => true]);
        $this->artisan('blueprint:seed-niche', ['niche' => 'tutoring_exam_prep', '--actor' => $this->platformAdminId()])->assertExitCode(0);

        $this->tenant();

        $this->assertSame('photo_booth_service', $this->wizardSession()->version->questionnaireDefinition->niche_key);
        $this->assertStringStartsWith('photo_booth_', (string) Website::where('business_id', $this->business->id)->value('template_key'));
    }
}
