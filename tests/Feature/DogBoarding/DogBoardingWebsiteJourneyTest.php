<?php

namespace Tests\Feature\DogBoarding;

use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsiteMediaRequirements;
use App\Library\Website\WebsitePublisher;
use App\Models\BusinessKnowledgeProfile;
use App\Models\CrmOpportunity;
use App\Models\QuestionnaireResponse;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteFormSubmission;
use App\Models\WebsiteGuidedGenerationAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\TestCase;

/**
 * The Dog Boarding journey over HTTP, the way a browser drives it: select the niche, install its Blueprint, answer the
 * niche's own questionnaire, generate (no AI call), read the pages, publish, and send a real inquiry from the public site.
 */
class DogBoardingWebsiteJourneyTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Dog Boarding generation is deterministic: any AI call would be a spend this site does not need.
        $ai = \Mockery::mock(WebsiteAiGenerationClient::class);
        $ai->shouldNotReceive('complete');
        $ai->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $ai->shouldReceive('lastRefusalReason')->andReturn(null);
        $this->app->instance(WebsiteAiGenerationClient::class, $ai);
    }

    // ----------------------------------------------------------------- helpers

    /** Seeds the shipped niche (vertical, templates, questionnaire, Blueprint) and starts a fresh entitled Dog Boarding tenant. */
    protected function dogTenant(array $location = ['city' => 'Spokane', 'region' => 'WA'], array $business = [], bool $install = true): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        User::query()->whereKey($this->platformAdminId())->update(['is_admin' => true]);
        $this->artisan('blueprint:seed-niche', ['niche' => 'dog_boarding', '--actor' => $this->platformAdminId()])->assertExitCode(0);

        $this->tenant($business, $location, start: false);
        app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->getOrCreate($this->business);
        DB::table('business_knowledge_profiles')->where('business_id', $this->business->id)->update(['vertical_key' => 'dog_boarding']);

        if ($install) {
            app(NicheBlueprintInstaller::class)->installForBusiness($this->business->fresh());
        }

        $this->get($this->url('start'));
    }

    protected function group(array $values): TestResponse
    {
        return $this->jpost($this->url('autosave', array_key_first($values)), [
            'answers_revision' => $this->wizardSession()->answers_revision,
            '__present' => array_keys($values),
            's' => collect($values)->map(fn ($v) => ['value' => $v])->all(),
        ]);
    }

    /** @param array<string, mixed> $o per-screen overrides; null skips a screen */
    protected function completeDogSetup(array $o = []): void
    {
        $boarding = $this->uploadPhoto('boarding-room');
        $daycare = $this->uploadPhoto('play-yard');

        $screens = [
            'business_name' => fn () => $this->continueBasics(['business_name' => 'Maple Paws Boarding', 'phone' => '555-0142', 'email' => 'stay@maplepaws.test']),
            'base_location' => fn () => $this->continueStep('base_location', ['value' => ['city' => 'Spokane', 'region' => 'WA', 'country_code' => 'US']]),
            'service_area_cities' => fn () => $this->continueStep('service_area_cities', ['value' => ['Spokane, WA', 'Spokane Valley, WA']]),
            'multiple_locations' => fn () => $this->continueStep('multiple_locations', ['value' => '0']),
            'dropoff_hours' => fn () => $this->group(['dropoff_hours' => 'Mon-Fri 7:00-10:00', 'pickup_hours' => 'Mon-Fri 16:00-18:30']),
            'dog_services' => fn () => $this->continueStep('dog_services', ['items' => [
                ['key' => 'svc-board', 'name' => 'Overnight Dog Boarding', 'description' => 'Your dog stays overnight with meals and outdoor time.', 'preset' => 'overnight_boarding', 'visible' => '1', 'image_path' => $boarding],
                ['key' => 'svc-day', 'name' => 'Dog Daycare', 'description' => 'A day of care and exercise.', 'preset' => 'daycare', 'visible' => '1', 'image_path' => $daycare],
            ]]),
            'service_eligibility' => fn () => $this->continueStep('service_eligibility', ['value' => 'Dogs 6 months and older.']),
            'facility_description' => fn () => $this->group([
                'facility_description' => 'A fenced property with a converted barn.', 'indoor_spaces' => 'Six indoor rooms.', 'outdoor_spaces' => 'A fenced yard.',
                'sleeping_arrangements' => 'Raised beds in private rooms.', 'daily_routine' => 'Morning walk, play, rest, evening meal.', 'supervision_approach' => 'A team member is on site during the day.',
            ]),
            'group_play_policy' => fn () => $this->group(['group_play_policy' => 'Dogs play in small matched groups.', 'separate_care' => '1', 'capacity_note' => '']),
            'pricing_strategy' => fn () => $this->continueStep('pricing_strategy', ['value' => 'starting_from']),
            'packages' => fn () => $this->continueStep('packages', ['items' => [
                ['name' => 'Overnight stay', 'price' => '55', 'currency_code' => 'USD', 'description' => 'Per night, one dog.', 'features' => ['Meals', 'Outdoor time']],
                ['name' => 'Daycare day', 'price' => '32', 'currency_code' => 'USD', 'description' => 'Per day, one dog.'],
            ]]),
            'addons' => fn () => $this->continueStep('addons', ['items' => [['key' => 'x-walk', 'name' => 'Extra walk', 'description' => 'An additional walk.', 'price' => '8', 'currency_code' => 'USD', 'preset' => 'extra_walk']]]),
            'additional_dog_rate' => fn () => $this->group(['additional_dog_rate' => 'Second dog from the same home: 10% off.', 'holiday_rates' => 'Holiday weeks carry a surcharge.', 'deposit_policy' => 'A deposit is required at confirmation.']),
            'vaccination_requirements' => fn () => $this->group(['vaccination_requirements' => 'Proof of current vaccinations is required.', 'age_requirements' => '6 months or older.', 'spay_neuter_requirements' => 'Dogs over 12 months must be fixed.', 'temperament_assessment' => 'A short trial day first.']),
            'feeding_medication' => fn () => $this->group(['feeding_medication' => 'Bring your own food. We can give oral medication.', 'checkin_checkout' => 'Check-in by 10:00.', 'emergency_contact' => 'Two contacts required.', 'cancellation_policy' => '48 hours notice.']),
            'about_story' => fn () => $this->continueStep('about_story', ['value' => $this->jget($this->url('content-default'), ['kind' => 'about', 'variant' => 0])->json('text')]),
            'staff_description' => fn () => $this->continueStep('staff_description', ['value' => 'Run by the owner and two part-time helpers.']),
            'care_philosophy' => fn () => $this->continueStep('care_philosophy', ['value' => 'Calm routines.']),
            'credentials_note' => fn () => $this->continueStep('credentials_note', ['value' => '']),
            'advantages' => fn () => $this->continueStep('advantages', ['value' => ['home_environment', 'meet_greet_first']]),
            'accepted_breeds' => fn () => $this->continueStep('accepted_breeds', ['value' => 'Most breeds; large dogs by assessment.']),
            'testimonials' => fn () => $this->continueStep('testimonials', ['items' => []]),
            'faq_items' => fn () => $this->continueStep('faq_items', ['items' => $this->jget($this->url('content-default'), ['kind' => 'faq'])->json('items')]),
            'gallery' => fn () => $this->continueStep('gallery', ['value' => '3']),
            'photo_rights' => fn () => $this->continueStep('photo_rights', ['value' => ['confirmed']]),
            'brand_personality' => fn () => $this->continueStep('brand_personality', ['value' => 'warm']),
            'primary_cta' => fn () => $this->continueStep('primary_cta', ['value' => 'quote_request']),
            'offers_meet_greet' => fn () => $this->continueStep('offers_meet_greet', ['value' => '1']),
            'preferred_contact_channels' => fn () => $this->continueStep('preferred_contact_channels', ['value' => ['phone', 'email']]),
            'contact_form_fields' => fn () => $this->continueStep('contact_form_fields', ['value' => ['name', 'phone', 'email', 'dog_name', 'dog_breed_size', 'dog_count', 'arrival_date', 'departure_date', 'services_requested', 'message']]),
        ];

        foreach ($screens as $key => $send) {
            $override = array_key_exists($key, $o) ? $o[$key] : '__default__';

            if ($override === null) {
                continue;
            }

            if (is_callable($override)) {
                $send = $override;
            }

            $send()->assertOk();
        }
    }

    protected function generate(): Website
    {
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$this->workspace->uid, $this->business->uid]));

        return Website::where('business_id', $this->business->id)->firstOrFail();
    }

    protected function fullDogSite(): Website
    {
        $this->dogTenant();
        $this->completeDogSetup();
        $this->uploadGalleryPhotos(6);

        return $this->generate();
    }

    // ------------------------------------------------------------------- tests

    public function test_the_wizard_serves_the_dog_boarding_questionnaire_not_the_photo_booth_one(): void
    {
        $this->dogTenant();

        $session = $this->wizardSession();
        $this->assertSame('dog_boarding', $session->version->questionnaireDefinition->niche_key);
        $keys = array_column($session->version->steps(), 'key');
        $this->assertContains('dog_services', $keys);
        $this->assertNotContains('booth_services', $keys);
        $this->assertNotContains('backdrops', $keys);
    }

    public function test_a_photo_booth_business_still_resolves_to_the_photo_booth_questionnaire(): void
    {
        $this->seedGuidedWizard();
        $this->tenant();

        $this->assertSame('photo_booth_service', $this->wizardSession()->version->questionnaireDefinition->niche_key);
    }

    public function test_generation_builds_the_dog_boarding_page_set_with_its_own_addresses_and_no_photo_booth_wording(): void
    {
        $website = $this->fullDogSite();

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $website->guidedGenerationAttempts()->latest('id')->value('status'));

        $slugs = $website->pages()->pluck('slug')->all();
        foreach (['about-us', 'faqs', 'request-a-stay', 'packages', 'gallery', 'overnight-dog-boarding', 'dog-daycare', 'boarding-policies', 'services'] as $slug) {
            $this->assertContains($slug, $slugs, "Dog Boarding page [{$slug}] is planned: ".json_encode($slugs));
        }
        $this->assertSame([], array_values(array_filter($slugs, fn ($s) => str_starts_with((string) $s, 'photo-booth'))), 'No Photo Booth address is ever published.');

        $blob = strtolower(json_encode($website->pages()->get(['title', 'seo_title', 'meta_description', 'sections'])->toArray()));
        foreach (['photo booth', 'booth', 'backdrop', 'wedding'] as $leak) {
            $this->assertStringNotContainsString($leak, $blob, "No [{$leak}] wording on a Dog Boarding site.");
        }
    }

    public function test_the_home_page_has_a_dog_boarding_headline_real_services_rates_faq_and_one_cta(): void
    {
        $website = $this->fullDogSite();
        $home = $website->pages()->where('is_home', true)->firstOrFail();
        $by = [];
        foreach ($home->sections as $section) {
            $by[$section['type']][] = $section['data'];
        }

        $this->assertSame('Comfortable, caring dog boarding and daycare in Spokane', $by['hero'][0]['heading']);
        $this->assertSame('Request a stay', $by['hero'][0]['primary_cta']['label']);
        $this->assertSame('/request-a-stay', $by['hero'][0]['primary_cta']['url']);
        $this->assertNotEmpty($by['hero'][0]['background_image'], 'The hero shows the owner\'s own cover photo.');
        $this->assertCount(1, $by['cta']);
        $this->assertCount(1, $by['hero']);

        $headings = array_map(fn ($d) => $d['heading'] ?? '', $by['services']);
        $this->assertContains('Boarding and care', $headings);
        $this->assertContains('How booking works', $headings);
        $this->assertContains('Rates', $headings);
        $this->assertArrayHasKey('faq', $by);
        $this->assertArrayHasKey('gallery', $by);

        $process = collect($by['services'])->firstWhere('heading', 'How booking works');
        $this->assertStringContainsString('not a booking', json_encode($process));
        $this->assertSame('A day with us', collect($by['text'])->firstWhere('heading', 'A day with us')['heading']);
    }

    public function test_titles_and_descriptions_are_unique_finished_and_use_only_confirmed_facts(): void
    {
        $website = $this->fullDogSite();
        $pages = $website->pages()->get();

        $this->assertSame('Dog Boarding & Daycare in Spokane | Maple Paws Boarding', $pages->firstWhere('is_home', true)->seo_title);
        $this->assertSame($pages->count(), $pages->pluck('seo_title')->unique()->count());
        $this->assertSame($pages->count(), $pages->pluck('meta_description')->unique()->count());

        foreach ($pages as $page) {
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page->seo_title), (string) ($page->slug ?? "home"));
            $this->assertLessThanOrEqual(160, mb_strlen((string) $page->meta_description), (string) ($page->slug ?? "home"));
            $this->assertMatchesRegularExpression('/[.!?]$/', (string) $page->meta_description, (string) ($page->slug ?? "home"));
        }

        $blob = strtolower(json_encode($pages->map->sections->toArray()));
        foreach (['years of experience', 'award', 'certified', 'veterinar', '24/7', 'constant supervision', 'guarantee', 'instant'] as $claim) {
            $this->assertStringNotContainsString($claim, $blob, "No invented claim [{$claim}].");
        }
    }

    public function test_policies_page_shows_only_what_the_owner_wrote_and_the_rates_page_shows_real_prices(): void
    {
        $website = $this->fullDogSite();

        $policies = $website->pages()->where('slug', 'boarding-policies')->firstOrFail();
        $text = json_encode($policies->sections);
        $this->assertStringContainsString('Proof of current vaccinations is required.', $text);
        $this->assertStringContainsString('48 hours notice.', $text);

        $rates = $website->pages()->where('slug', 'packages')->firstOrFail();
        $this->assertSame('Rates & Packages', $rates->title);
        $this->assertStringContainsString('Second dog from the same home: 10% off.', json_encode($rates->sections));
    }

    public function test_missing_pricing_gives_an_honest_pricing_note_and_never_a_made_up_price(): void
    {
        $this->dogTenant();
        $this->completeDogSetup(['pricing_strategy' => fn () => $this->continueStep('pricing_strategy', ['value' => 'custom_quote']), 'packages' => null, 'addons' => null]);
        $this->uploadGalleryPhotos(4);
        $website = $this->generate();

        $this->assertNull($website->pages()->where('slug', 'packages')->first(), 'No rates page without rates.');
        $home = json_encode($website->pages()->where('is_home', true)->firstOrFail()->sections);
        $this->assertStringContainsString('do not publish a single price', $home);
        $this->assertDoesNotMatchRegularExpression('/\$\s?\d/', $home);
    }

    public function test_one_service_only_generates_without_a_daycare_page(): void
    {
        $this->dogTenant();
        $photo = $this->uploadPhoto('room');
        $this->completeDogSetup(['dog_services' => fn () => $this->continueStep('dog_services', ['items' => [
            ['key' => 'svc-board', 'name' => 'Overnight Dog Boarding', 'description' => 'Overnight stays.', 'preset' => 'overnight_boarding', 'visible' => '1', 'image_path' => $photo],
        ]])]);
        $this->uploadGalleryPhotos(3);
        $website = $this->generate();

        $slugs = $website->pages()->pluck('slug')->all();
        $this->assertContains('overnight-dog-boarding', $slugs);
        $this->assertNotContains('dog-daycare', $slugs);
        $this->assertStringNotContainsString('daycare', strtolower((string) $website->pages()->where('is_home', true)->value('seo_title')));
    }

    public function test_no_photos_still_generates_a_draft_but_publishing_is_refused_with_dog_wording(): void
    {
        $this->dogTenant();
        $this->completeDogSetup(['dog_services' => fn () => $this->continueStep('dog_services', ['items' => [
            ['key' => 'svc-board', 'name' => 'Overnight Dog Boarding', 'description' => 'Overnight stays.', 'preset' => 'overnight_boarding', 'visible' => '1'],
        ]])]);
        $website = $this->generate();

        $missing = app(WebsiteMediaRequirements::class)->missing($website->fresh());
        $this->assertNotEmpty($missing);
        $serviceItem = collect($missing)->first(fn (array $m) => str_starts_with($m['key'], 'service:'));
        $this->assertNotNull($serviceItem, 'The service without a photo is listed.');
        $this->assertSame('dog_services', $serviceItem['step'], 'The link goes to the pinned questionnaire\'s own service step.');
        $blob = strtolower(json_encode($missing));
        $this->assertStringNotContainsString('booth', $blob);
    }

    public function test_the_site_publishes_and_a_real_inquiry_creates_one_crm_contact_and_one_stay_request(): void
    {
        $website = $this->fullDogSite();
        app(WebsitePublisher::class)->publish($website->fresh(), (int) $this->customer->user_id);
        $website = $website->fresh();

        $contactPage = $website->pages()->where('slug', 'request-a-stay')->firstOrFail();
        $form = $website->forms()->sole();
        $this->assertSame('Request a Stay', $form->name);
        $this->assertNotNull($form->crm_pipeline_id, 'The form routes into the Blueprint\'s Stay Requests pipeline.');
        $this->assertContains('dog_name', array_column($form->fields, 'key'));
        $this->assertNotContains('medication', array_column($form->fields, 'key'));

        $this->get(route('public.website.page', [$website->public_id, 'request-a-stay']))->assertOk()->assertSee('Request a stay');

        $submitUrl = route('public.website.form.submit', [$website->public_id, $form->uid, $contactPage->uid]);
        $payload = ['name' => 'Jordan Rivera', 'phone' => '5095550101', 'email' => 'jordan@example.test', 'dog_name' => 'Biscuit', 'dog_breed_size' => 'Beagle, medium', 'arrival_date' => '2026-12-20', 'departure_date' => '2026-12-27'];

        $this->post($submitUrl, $payload)->assertRedirect();

        $this->assertSame(1, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
        $opportunity = CrmOpportunity::where('business_id', $this->business->id)->sole();
        $this->assertSame($form->crm_pipeline_id, $opportunity->pipeline_id);
        $this->assertSame('New Inquiry', $opportunity->stage->name);
        $this->assertSame('open', $opportunity->status->value);

        // The same person asking again is recorded, but never opens a second card for the same request.
        $this->post($submitUrl, ['dog_name' => 'Biscuit'] + $payload)->assertRedirect();
        $this->assertSame(2, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
        $this->assertSame(1, CrmOpportunity::where('business_id', $this->business->id)->count());
        $this->assertSame(1, \App\Models\Contacts::query()->where('business_id', $this->business->id)->count());
    }

    public function test_the_stay_request_never_reads_as_a_confirmed_booking_anywhere_on_the_site(): void
    {
        $website = $this->fullDogSite();
        $blob = strtolower(json_encode($website->pages()->get()->map->sections->toArray()));

        foreach (['book now', 'instantly', 'real-time availability', 'spots available', 'we have availability'] as $claim) {
            $this->assertStringNotContainsString($claim, $blob);
        }
        $this->assertStringContainsString('not a confirmed booking', $blob);
    }

    public function test_a_second_tenant_cannot_see_the_first_tenants_website_or_inquiries(): void
    {
        $website = $this->fullDogSite();
        $first = $this->business;

        // A second, independent Dog Boarding tenant.
        $this->tenant(['name' => 'Other Kennels'], ['city' => 'Boise', 'region' => 'ID'], start: false);
        $second = $this->business;
        $this->assertNotSame($first->id, $second->id);

        $response = $this->get(route('customer.workspaces.businesses.website.preview', [$this->workspace->uid, $first->uid]));
        $this->assertContains($response->getStatusCode(), [403, 404], 'Another tenant\'s preview is never served.');
        $this->assertSame(0, Website::where('business_id', $second->id)->count());
        $this->assertSame(0, QuestionnaireResponse::where('business_id', $second->id)->count());
        $this->assertSame(1, Website::where('id', $website->id)->count());
    }

    public function test_the_dog_boarding_business_never_receives_the_photo_booth_blueprint_records(): void
    {
        $this->dogTenant();

        $profile = BusinessKnowledgeProfile::where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame('dog_boarding', $profile->vertical_key);
        $this->assertSame(0, DB::table('crm_pipelines')->where('business_id', $this->business->id)->where('name', 'Inquiries')->count());
        $this->assertSame(1, DB::table('crm_pipelines')->where('business_id', $this->business->id)->where('name', 'Stay Requests')->count());
    }

    public function test_a_stale_questionnaire_revision_cannot_generate_over_newer_answers(): void
    {
        $this->dogTenant();
        $this->completeDogSetup();
        $this->uploadGalleryPhotos(3);
        $stale = $this->wizardSession()->answers_revision;
        $this->continueStep('brand_personality', ['value' => 'fresh'])->assertOk();

        $this->post($this->url('generate'), ['answers_revision' => $stale]);

        $this->assertSame(0, Website::where('business_id', $this->business->id)->whereHas('pages')->count(), 'Nothing was generated from outdated answers.');
    }

    public function test_changing_an_answer_and_regenerating_rebuilds_the_same_page_set_without_duplicates(): void
    {
        $website = $this->fullDogSite();
        $before = $website->pages()->orderBy('slug')->pluck('slug')->all();

        $this->get(route('customer.workspaces.businesses.website.edit-setup', [$this->workspace->uid, $this->business->uid]))->assertRedirect();
        $this->continueStep('pricing_strategy', ['value' => 'fixed_packages'])->assertOk();
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])->assertRedirect();

        $after = $website->fresh()->pages()->orderBy('slug')->pluck('slug')->all();
        $this->assertSame($before, $after, 'Regeneration replaces pages atomically; nothing is duplicated.');
        $this->assertSame(1, $website->fresh()->forms()->count());
        $this->assertSame(1, \App\Models\CrmPipeline::where('business_id', $this->business->id)->where('name', 'Stay Requests')->count());
    }

    public function test_changing_the_look_only_keeps_every_dog_boarding_page(): void
    {
        $website = $this->fullDogSite();
        $before = $website->pages()->orderBy('slug')->pluck('slug')->all();

        $this->post(route('customer.workspaces.businesses.website.rebuild', [$this->workspace->uid, $this->business->uid]), [
            'template_key' => 'dog_boarding_fresh', 'mode' => 'look_only', 'confirm_rebuild' => '1',
        ])->assertRedirect();

        $website = $website->fresh();
        $this->assertSame('dog_boarding_fresh', $website->template_key);
        $this->assertSame($before, $website->pages()->orderBy('slug')->pluck('slug')->all());
    }

    public function test_the_template_picker_offers_only_dog_boarding_looks_to_a_dog_boarding_business(): void
    {
        $this->dogTenant();

        $keys = $this->get($this->url('start'))->getContent();
        $this->assertStringNotContainsString('photo_booth_modern', $keys);
    }

    // --------------------------------------------------- correction round tests

    public function test_the_website_identity_follows_the_business_name_the_owner_confirmed(): void
    {
        $website = $this->fullDogSite();

        $this->assertSame('Maple Paws Boarding', $this->business->fresh()->name);
        $this->assertSame('Maple Paws Boarding', $website->fresh()->name, 'The website no longer keeps the name the business had when the shell was created.');

        app(WebsitePublisher::class)->publish($website->fresh(), (int) $this->customer->user_id);
        $html = $this->get(route('public.website.home', $website->public_id))->getContent();
        $this->assertStringContainsString('Maple Paws Boarding', $html);
        $this->assertStringNotContainsString('Snap Booth Co', $html);
    }

    public function test_an_owner_chosen_website_name_is_never_overwritten_by_a_business_rename(): void
    {
        $website = $this->fullDogSite();
        $website->forceFill(['name' => 'Maple Paws Retreat'])->save();

        $this->business->fresh()->forceFill(['name' => 'Maple Paws LLC'])->save();

        $this->assertSame('Maple Paws Retreat', $website->fresh()->name);
    }

    public function test_renaming_one_business_never_renames_another_tenants_website(): void
    {
        $website = $this->fullDogSite();
        $first = $this->business;
        $this->tenant(['name' => $first->getOriginal('name')], ['city' => 'Boise', 'region' => 'ID'], start: false);
        $other = $this->business;
        $this->assertNotSame($first->id, $other->id);
        $otherSite = $this->createWebsite($other, ['name' => 'Maple Paws Boarding']);

        $first->fresh()->forceFill(['name' => 'Maple Paws Renamed'])->save();

        $this->assertSame('Maple Paws Boarding', $otherSite->fresh()->name, 'The rename is scoped to its own business.');
        $this->assertSame('Maple Paws Renamed', $website->fresh()->name);
    }

    private function publishedFormContext(): array
    {
        $website = $this->fullDogSite();
        app(WebsitePublisher::class)->publish($website->fresh(), (int) $this->customer->user_id);
        $website = $website->fresh();
        $form = $website->forms()->sole();
        $page = $website->pages()->where('slug', 'request-a-stay')->firstOrFail();

        return [$website, route('public.website.form.submit', [$website->public_id, $form->uid, $page->uid])];
    }

    private function customValues(\App\Models\Contacts $contact): array
    {
        return \App\Models\CustomFieldValue::query()->where('contact_id', $contact->id)->with('definition')->get()
            ->mapWithKeys(fn ($v) => [$v->definition->label => $v->value_text ?? $v->value_number ?? $v->value_date ?? json_encode($v->getAttributes())])->all();
    }

    public function test_a_stay_request_fills_the_contacts_dog_custom_fields_and_never_the_private_notes(): void
    {
        [, $url] = $this->publishedFormContext();

        $this->post($url, [
            'name' => 'Jordan Rivera', 'phone' => '5095550101', 'email' => 'jordan@example.test', 'dog_name' => 'Biscuit',
            'dog_breed_size' => 'Beagle, medium', 'dog_count' => '2', 'arrival_date' => '2026-12-20', 'departure_date' => '2026-12-27',
            'services_requested' => 'Overnight boarding, daycare', 'message' => 'Takes thyroid medication twice a day.',
        ])->assertRedirect();

        $contact = \App\Models\Contacts::where('business_id', $this->business->id)->sole();
        $values = $this->customValues($contact);
        $this->assertSame('Biscuit', $values['Dog name']);
        $this->assertSame('Beagle, medium', $values['Dog breed']);
        $this->assertArrayHasKey('Number of dogs', $values);
        $this->assertArrayHasKey('Requested arrival date', $values);
        $this->assertArrayHasKey('Requested departure date', $values);
        $this->assertArrayHasKey('Requested services', $values);
        $this->assertStringNotContainsString('thyroid', json_encode($values), 'Care notes are never copied onto the contact.');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('custom_field_values')->where('business_id', '!=', $this->business->id)->count());
    }

    public function test_a_returning_contact_only_has_blank_dog_fields_filled(): void
    {
        [, $url] = $this->publishedFormContext();
        $payload = ['name' => 'Jordan Rivera', 'phone' => '5095550101', 'dog_name' => 'Biscuit'];

        $this->post($url, $payload)->assertRedirect();
        $this->post($url, ['dog_name' => 'Pepper', 'dog_breed_size' => 'Corgi'] + $payload)->assertRedirect();

        $contact = \App\Models\Contacts::where('business_id', $this->business->id)->sole();
        $values = $this->customValues($contact);
        $this->assertSame('Biscuit', $values['Dog name'], 'A second dog never overwrites what the owner already knows.');
        $this->assertSame('Corgi', $values['Dog breed'], 'A blank field is filled.');
        $this->assertSame(1, \App\Models\CrmOpportunity::where('business_id', $this->business->id)->count());
    }

    public function test_an_invalid_answer_never_blocks_the_inquiry(): void
    {
        [, $url] = $this->publishedFormContext();

        $this->post($url, ['name' => 'Jordan Rivera', 'phone' => '5095550101', 'dog_count' => 'lots', 'services_requested' => 'Skydiving'])->assertRedirect();

        $this->assertSame(1, WebsiteFormSubmission::count());
        $this->assertSame(1, \App\Models\CrmOpportunity::where('business_id', $this->business->id)->count());
        $values = $this->customValues(\App\Models\Contacts::where('business_id', $this->business->id)->sole());
        $this->assertArrayNotHasKey('Number of dogs', $values);
        $this->assertArrayNotHasKey('Requested services', $values);
    }

    public function test_an_interrupted_generation_is_retried_safely_once_the_lease_expires(): void
    {
        $this->dogTenant();
        $this->completeDogSetup();
        $this->uploadGalleryPhotos(3);
        $website = Website::where('business_id', $this->business->id)->firstOrFail();
        $coordinator = app(\App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator::class);

        // Another worker holds a fresh lease (and then dies): the owner's generate is refused and commits nothing.
        $dead = $coordinator->beginLease($website);
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision]);
        $this->assertSame(0, $website->pages()->count());

        // The lease expires; the retry succeeds, replaces nothing twice, and the dead worker's token is useless.
        Website::whereKey($website->id)->update(['generation_lease_started_at' => now()->subSeconds(\App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator::LEASE_SECONDS + 1)]);
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])->assertRedirect();

        $this->assertGreaterThanOrEqual(8, $website->fresh()->pages()->count());
        $this->assertSame(1, $website->fresh()->pages()->where('is_home', true)->count());
        $this->assertNull($website->fresh()->generation_lease_token, 'The lease is released after the retry.');

        $this->assertNotNull($dead);
        $count = $website->fresh()->pages()->count();
        $context = \App\Library\Website\Setup\WizardPresentationAnswers::blueprintContext(QuestionnaireResponse::where('business_id', $this->business->id)->latest('id')->firstOrFail());
        $stale = app(\App\Library\Website\GuidedGeneration\GuidedGenerationCommitService::class)->generateFull(
            $this->business, $website->fresh(), \App\Models\WebsiteTemplate::where('key', $website->fresh()->template_key)->firstOrFail(),
            (int) $this->customer->user_id, 'idem-dead-worker', $dead, null, null, null, null, $context,
        );
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $stale->status);
        $this->assertSame($count, $website->fresh()->pages()->count(), 'An obsolete worker never replaces newer pages.');
    }

    public function test_studio_edits_are_saved_protected_from_stale_overwrites_and_survive_a_look_only_change(): void
    {
        $website = $this->fullDogSite();
        $page = $website->pages()->where('slug', 'about-us')->firstOrFail();
        $edit = fn ($p, array $extra = []) => $this->put(route('customer.workspaces.businesses.website.pages.update', [$this->workspace->uid, $this->business->uid, $p->uid]), $extra + [
            'title' => $p->title, 'slug' => $p->slug, 'sections' => json_encode($p->sections), 'seo_title' => $p->seo_title, 'meta_description' => $p->meta_description,
        ]);

        $sections = $page->sections;
        $sections[1]['data']['body'] = 'Our own words about the farm.';
        $edit($page, ['sections' => json_encode($sections), 'draft_token' => $page->draftToken()])->assertRedirect();
        $this->assertStringContainsString('Our own words about the farm.', json_encode($page->fresh()->sections));

        // A second tab holding the OLD token cannot overwrite the newer edit.
        $stale = $sections;
        $stale[1]['data']['body'] = 'Overwritten from a stale tab.';
        $edit($page, ['sections' => json_encode($stale), 'draft_token' => $page->draftToken()])->assertRedirect();
        $this->assertStringNotContainsString('Overwritten from a stale tab.', json_encode($page->fresh()->sections));

        // Changing only the look keeps the owner's edit.
        $this->post(route('customer.workspaces.businesses.website.rebuild', [$this->workspace->uid, $this->business->uid]), [
            'template_key' => 'dog_boarding_playful', 'mode' => 'look_only', 'confirm_rebuild' => '1',
        ])->assertRedirect();
        $this->assertStringContainsString('Our own words about the farm.', json_encode($website->fresh()->pages()->where('slug', 'about-us')->firstOrFail()->sections));
    }

    public function test_a_full_rebuild_needs_explicit_confirmation_so_an_unconfirmed_request_cannot_discard_an_edit(): void
    {
        $website = $this->fullDogSite();
        $page = $website->pages()->where('slug', 'about-us')->firstOrFail();
        $sections = $page->sections;
        $sections[1]['data']['body'] = 'Edited by the owner.';
        $this->put(route('customer.workspaces.businesses.website.pages.update', [$this->workspace->uid, $this->business->uid, $page->uid]), [
            'title' => $page->title, 'slug' => $page->slug, 'sections' => json_encode($sections), 'seo_title' => $page->seo_title, 'meta_description' => $page->meta_description,
        ])->assertRedirect();

        $this->post(route('customer.workspaces.businesses.website.rebuild', [$this->workspace->uid, $this->business->uid]), ['template_key' => 'dog_boarding_warm']);

        $this->assertStringContainsString('Edited by the owner.', json_encode($website->fresh()->pages()->where('slug', 'about-us')->firstOrFail()->sections));
    }
}
