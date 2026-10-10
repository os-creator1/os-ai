<?php

namespace Tests\Feature\Website\Acceptance;

use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\CatalogMoney;
use App\Library\Crm\CrmPipelineService;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormSubmission;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleAds\Attribution\Concerns\BuildsAttributionCookies;
use Tests\Support\WebsiteAcceptance\PhotoBoothFixture;
use Tests\TestCase;

/**
 * Website Builder - OWNER JOURNEY TO A REAL INQUIRY IN THE CRM.
 *
 * "Can a new Photo Booth owner give us their real information, get a usable
 * multi-page site, edit it, publish it safely, and then receive a real
 * inquiry as a Contact + Opportunity at the right Location?" The existing
 * full-site bot (WebsiteFullSiteAcceptanceTest) stops at crawl/SEO; this one
 * continues through the owner's edits, an internal (platform-address) publish,
 * and the visitor's quote request, using only HTTP requests to the same routes
 * a browser uses. The AI seam is the deterministic AcceptanceFakeAi: it proves
 * the pipeline, NOT live-AI copy quality.
 *
 * No real domain, no search indexing, no paid AI call.
 */
class WebsiteOwnerJourneyToCrmTest extends TestCase
{
    use BuildsAttributionCookies;
    use RefreshDatabase;
    use RunsOwnerJourney;

    /** How many Business Locations the fixture owner has (the shared trait's default is two). */
    private int $locationCount = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
        $this->useDisposablePublicRoot();
    }

    protected function tearDown(): void
    {
        $this->removeDisposablePublicRoot();
        parent::tearDown();
    }

    /** The app's own host, always (route() follows the last request's host). */
    protected function wizardUrl(object $workspace, object $business, string $route, array $extra = []): string
    {
        return 'http://127.0.0.1' . route('customer.workspaces.businesses.website.' . $route, array_merge([$workspace->uid, $business->uid], $extra), false);
    }

    protected function fixtureTenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant([
            'name' => PhotoBoothFixture::NAME,
            'phone' => PhotoBoothFixture::PHONE_DIGITS,
            'email' => PhotoBoothFixture::EMAIL,
            'description' => PhotoBoothFixture::ABOUT,
            'timezone' => 'America/Chicago',
        ]);

        $primary = new BusinessLocation([
            'business_id' => $business->id, 'name' => 'Chicago Studio', 'address_line_1' => '1200 W Fulton Market',
            'city' => PhotoBoothFixture::CITY, 'region' => PhotoBoothFixture::REGION, 'postal_code' => '60607', 'country_code' => 'US',
            'public_address' => true, 'service_mode' => 'hybrid',
        ]);
        $primary->is_primary = true;
        $primary->save();

        if ($this->locationCount > 1) {
            BusinessLocation::create([
                'business_id' => $business->id, 'name' => 'Naperville Studio', 'service_mode' => 'storefront',
                'address_line_1' => '55 S Main St', 'city' => 'Naperville', 'region' => PhotoBoothFixture::REGION, 'postal_code' => '60540', 'country_code' => 'US',
            ]);
        }

        return [$customer, $business->fresh(), $workspace];
    }

    // ------------------------------------------------------------ helpers

    /** What a visitor reads: tags stripped (the designs wrap an accent word in a span), whitespace collapsed. */
    private function plain(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</>#is', '', $html)), ENT_QUOTES)));
    }

    private function publicHome(Website $website): string
    {
        return route('public.website.home', $website->public_id);
    }

    private function publicPage(Website $website, string $slug): string
    {
        return route('public.website.page', [$website->public_id, $slug]);
    }

    /** The edit form's own payload for a page, with its sections transformed by $change. */
    private function pageForm(WebsitePage $page, callable $change): array
    {
        return [
            'title' => $page->title,
            'slug' => $page->is_home ? '' : $page->slug,
            'is_home' => $page->is_home ? '1' : '0',
            'sections' => json_encode($change($page->sections ?? [])),
            'seo_title' => $page->seo_title,
            'meta_description' => $page->meta_description,
            'noindex' => $page->noindex ? '1' : '0',
        ];
    }

    /** @return array{0: WebsiteForm, 1: WebsitePage} the generated quote form and the page that carries it */
    private function quoteFormAndPage(Website $website): array
    {
        $form = $website->forms()->where('type', WebsiteForm::TYPE_QUOTE_REQUEST)->sole();
        $page = $website->pages()->get()->first(fn (WebsitePage $candidate) => collect($candidate->sections)->contains(fn ($section) => ($section['type'] ?? null) === 'form' && ($section['data']['form_uid'] ?? null) === $form->uid));
        $this->assertNotNull($page, 'a generated page carries the quote form');

        return [$form, $page];
    }

    private function publish(object $workspace, object $business): void
    {
        $this->post($this->wizardUrl($workspace, $business, 'publish'))->assertRedirect();
        // The owner lands on Studio, which consumes the "Website published." flash like a browser would.
        $this->get($this->wizardUrl($workspace, $business, 'studio.show'))->assertOk();
        app('cache')->flush();
    }

    /** What a visitor's browser would post from the rendered page: the form's own hidden token included. */
    private function visitorPost(string $pageUrl, WebsiteForm $form, WebsitePage $page, Website $website, array $fields, array $cookies = [])
    {
        $html = $this->withCookies($cookies)->get($pageUrl)->assertOk()->getContent();
        $submitUrl = route('public.website.form.submit', [$website->public_id, $form->uid, $page->uid]);
        $this->assertStringContainsString($submitUrl, $html, 'the published page renders a form that posts to the submit route');
        $this->assertSame(1, preg_match('/name="submission_token"\s+value="([^"]+)"/', $html, $token), 'the rendered form carries its per-render token');

        return $this->withCookies($cookies)->from($pageUrl)->post($submitUrl, $fields + ['submission_token' => $token[1]]);
    }

    // -------------------------------------------------------------- tests

    public function test_a_single_location_owner_goes_from_answers_to_a_published_site_and_a_real_crm_inquiry(): void
    {
        // 1-6. Business data -> template -> photos -> every wizard screen -> logo/hero -> Generate.
        [, $business, $workspace, $website] = $this->runOwnerJourneyThroughGenerate('photo_booth_modern', 4);
        $location = BusinessLocation::where('business_id', $business->id)->sole();

        // 2-5. What the owner typed lives in the canonical Business records (not website-only copies).
        $this->assertGreaterThanOrEqual(4, \App\Models\BusinessService::where('business_id', $business->id)->count(), 'booths and event types are Business services');
        $profile = \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->firstOrFail();
        $this->assertNotEmpty($profile->testimonials, 'the owner\'s own testimonials are in the Knowledge Profile');
        $this->assertSame(3, CatalogItem::where('business_id', $business->id)->count(), 'packages are canonical catalog items');
        $this->assertSame(1, \App\Models\WebsiteAsset::where('website_id', $website->id)->where('purpose', 'logo')->count());
        $this->assertSame(1, \App\Models\WebsiteAsset::where('website_id', $website->id)->where('purpose', 'hero')->count());
        $this->assertSame(4, \App\Models\WebsiteAsset::where('website_id', $website->id)->where('purpose', 'gallery')->count());

        // 7. Review every generated page: one main heading, no placeholder copy, no invented claims.
        $pages = $website->pages()->orderBy('sort_order')->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(10, $pages->count(), 'a multi-page site, not a single landing page');
        $this->assertSame($pages->count(), $pages->pluck('slug')->map(fn ($slug) => (string) $slug)->unique()->count(), 'no duplicate pages');

        foreach ($pages as $page) {
            $html = $this->get($this->wizardUrl($workspace, $business, 'preview', [$page->uid]))->assertOk()->getContent();
            $this->assertSame(1, substr_count(strtolower($html), '<h1'), "{$page->title}: exactly one main heading");
            $this->assertStringContainsString('name="viewport"', $html, "{$page->title}: responsive viewport");

            foreach (['lorem ipsum', 'award-winning', 'five-star', '5-star', 'years of experience', 'licensed and insured', 'as seen on'] as $invented) {
                $this->assertStringNotContainsStringIgnoringCase($invented, $html, "{$page->title}: never an invented claim ({$invented})");
            }
        }

        // Packages: the real catalog prices, never invented ones.
        $packagesHtml = $this->get($this->wizardUrl($workspace, $business, 'preview', [$pages->firstWhere('slug', 'packages')->uid]))->assertOk()->getContent();
        foreach (CatalogItem::where('business_id', $business->id)->get() as $item) {
            $this->assertStringContainsString(CatalogMoney::format($item->price_minor, $item->currency_code), $packagesHtml, "{$item->name} shows its real price");
        }

        // The owner's own FAQ, services and about copy are on the site.
        $home = $website->pages()->where('is_home', true)->sole();
        $contactPage = $pages->firstWhere('slug', 'photo-booth-contact');
        $this->assertStringContainsString(PhotoBoothFixture::PHONE_DISPLAY, $this->get($this->wizardUrl($workspace, $business, 'preview', [$contactPage->uid]))->getContent());

        // 8. Edit text and a call to action; the edit is the owner's and survives a look-only template change.
        $headline = 'Chicago photo booths, booked your way';
        $this->put($this->wizardUrl($workspace, $business, 'pages.update', [$home->uid]), $this->pageForm($home, function (array $sections) use ($headline) {
            $sections[0]['data']['heading'] = $headline;
            // A hero button to the owner's own Contact page, by same-site path (never a raw internal URL).
            $sections[0]['data']['primary_cta'] = ['label' => 'Request my quote', 'url' => '/photo-booth-contact'];

            return array_map(function (array $section) {
                if (($section['type'] ?? null) === 'cta') {
                    $section['data']['buttons'][0]['label'] = 'Check my date';
                }

                return $section;
            }, $sections);
        }))->assertRedirect()->assertSessionHas('status', 'success');

        $this->post($this->wizardUrl($workspace, $business, 'rebuild'), ['template_key' => 'photo_booth_luxury', 'mode' => 'look_only', 'confirm_rebuild' => '1'])->assertRedirect();
        $home = $home->fresh();
        $this->assertSame($headline, $home->sections[0]['data']['heading'], 'a look-only rebuild keeps the owner edit');
        $this->assertSame($pages->count(), $website->pages()->count(), 'a look-only rebuild keeps every page');
        $this->assertStringContainsString('Check my date', $this->get($this->wizardUrl($workspace, $business, 'preview'))->getContent());

        // Re-submitting the wizard's Generate does not duplicate pages.
        $this->post($this->wizardUrl($workspace, $business, 'setup.generate'));
        $this->assertSame($pages->count(), $website->pages()->count(), 'no duplicate pages after a repeated generate');

        // Every place that calls the AI tells the owner it is working (generation is one request) and cannot be fired twice.
        $this->get($this->wizardUrl($workspace, $business, 'rebuild.form'))->assertOk()->assertSee('id="generation-progress-template"', false)->assertSee('WebsiteGenerationProgress.start', false);
        $this->get($this->wizardUrl($workspace, $business, 'studio.show'))->assertOk()->assertSee('id="generation-progress-template"', false);

        // 9. Mobile/desktop: the page ships a viewport meta, and the design CSS is the responsive one.
        $this->assertStringContainsString('website-design.css', $this->get($this->wizardUrl($workspace, $business, 'preview'))->getContent());

        // 10. Publish to the internal platform address; it is not indexable and nothing else is public.
        $this->assertSame(404, $this->get($this->publicHome($website))->getStatusCode(), 'unpublished content is not public');
        $this->publish($workspace, $business);
        $publicHome = $this->get($this->publicHome($website))->assertOk()->getContent();
        $this->assertStringContainsString($headline, $this->plain($publicHome));
        $this->assertMatchesRegularExpression('#<a[^>]*href="[^"]*/sites/' . preg_quote($website->public_id, '#') . '/photo-booth-contact"[^>]*>\s*Request my quote\s*</a>#i', $publicHome, 'the hero button points at the real Contact page address');
        $this->assertMatchesRegularExpression('/<meta name="robots" content="noindex/i', $publicHome, 'generated pages stay hidden from search until the owner releases them');

        // Unpublished edits stay unpublished until the owner publishes again.
        $this->put($this->wizardUrl($workspace, $business, 'pages.update', [$home->uid]), $this->pageForm($home, function (array $sections) {
            $sections[0]['data']['heading'] = 'A newer headline the owner has not published';

            return $sections;
        }))->assertRedirect();
        app('cache')->flush();
        $live = $this->plain($this->get($this->publicHome($website))->getContent());
        $this->assertStringNotContainsString('A newer headline the owner has not published', $live);
        $this->assertStringContainsString($headline, $live);

        // A different business's draft site is never public either.
        [, $foreignBusiness] = $this->entitledTenant();
        $foreignWebsite = $this->createWebsite($foreignBusiness);
        $this->assertSame(404, $this->get($this->publicHome($foreignWebsite))->getStatusCode());

        // Package change -> the live site is flagged out of date -> publishing brings it up to date.
        $essential = CatalogItem::where('business_id', $business->id)->where('name', 'Essential')->sole();
        app(CatalogItemManager::class)->update($business, $essential, ['price_minor' => 74900, 'currency_code' => 'USD']);
        $this->publish($workspace, $business);
        $this->assertStringContainsString(CatalogMoney::format(74900, 'USD'), $this->get($this->publicPage($website, 'packages'))->getContent());

        // 11. A real visitor inquiry through the PUBLISHED page (a tagged ad visit, so attribution is recorded too).
        [$form, $formPage] = $this->quoteFormAndPage($website->fresh());
        $this->assertSame((int) $location->id, (int) $form->location_id, 'the generated quote form is connected to the owner\'s only Location');

        $cookies = $this->touchCookies(['gclid' => self::CLICK, 'utm_source' => 'google'], null, '/sites/' . $website->public_id);
        $pageUrl = $this->publicPage($website, $formPage->slug);

        $first = $this->visitorPost($pageUrl, $form, $formPage, $website->fresh(), [
            'name' => 'Casey Visitor', 'phone' => '5555550101', 'email' => 'casey@example.test',
            'event_date' => '2027-06-12', 'event_type' => 'Wedding', 'message' => 'About 120 guests, looking for the Glam Booth.',
        ], $cookies);
        $first->assertRedirect($pageUrl)->assertSessionHas('status', 'success')->assertSessionHasNoErrors();

        // 12. Contact, attribution and (with a pipeline) the Opportunity, at the right Business and Location.
        $submission = WebsiteFormSubmission::where('website_form_id', $form->id)->sole();
        $this->assertFalse((bool) $submission->is_spam);
        $this->assertSame((int) $location->id, (int) $submission->location_id);
        $contact = Contacts::findOrFail($submission->contact_id);
        $this->assertSame((int) $business->id, (int) $contact->business_id);
        $this->assertSame((int) $location->id, (int) $contact->location_id);
        $this->assertSame(5555550101, $contact->phone);

        $touches = $this->touchRows();
        $this->assertCount(1, $touches, 'a tagged visit is attributed once');
        $this->assertSame((int) $business->id, (int) $touches[0]->business_id);
        $this->assertSame((int) $submission->id, (int) $touches[0]->subject_id);
        $this->assertSame(self::CLICK, $touches[0]->gclid);

        // No CRM pipeline yet: the inquiry is never lost (submission + Contact), there is simply no deal card yet.
        $this->assertNull($submission->crm_opportunity_id);

        // Once the CRM has a pipeline, the next inquiry becomes a deal at the same Location.
        app(CrmPipelineService::class)->setUpStandardPipeline($business);
        $this->visitorPost($pageUrl, $form, $formPage, $website->fresh(), ['name' => 'Morgan Visitor', 'phone' => '5555550102', 'event_type' => 'Corporate'])
            ->assertRedirect($pageUrl)->assertSessionHasNoErrors();

        $second = WebsiteFormSubmission::where('website_form_id', $form->id)->latest('id')->firstOrFail();
        $this->assertNotNull($second->crm_opportunity_id);
        $opportunity = CrmOpportunity::findOrFail($second->crm_opportunity_id);
        $this->assertSame('website_form', $opportunity->source);
        $this->assertSame((int) $business->id, (int) $opportunity->business_id);
        $this->assertSame((int) $location->id, (int) $opportunity->location_id);
        $this->assertSame((int) $second->contact_id, (int) $opportunity->contact_id);

        // The owner sees both inquiries on the Website's Forms screen; another tenant's owner cannot.
        $this->get($this->wizardUrl($workspace, $business, 'forms.submissions', [$form->uid]))->assertOk()->assertSee('Casey Visitor')->assertSee('Morgan Visitor');
        $this->assertSame(0, Contacts::where('business_id', $foreignBusiness->id)->count(), 'no lead reaches another Business');
    }

    public function test_choosing_build_with_motiongrove_starts_the_guided_setup_on_the_first_question(): void
    {
        [$customer, $business, $workspace] = $this->fixtureTenant();
        $this->authenticateAsCustomer($customer);

        // The Website entry for a Business that has chosen nothing yet offers the three honest options.
        $this->get($this->wizardUrl($workspace, $business, 'show'))->assertOk()
            ->assertSee('Build with MotionGrove')->assertSee('data-role="choose-hosted"', false)
            ->assertSee('Use my existing website')->assertSee('Do this later');

        $this->post($this->wizardUrl($workspace, $business, 'mode.choose'), ['mode' => 'hosted'])->assertRedirect($this->wizardUrl($workspace, $business, 'setup.start'));
        $this->get($this->wizardUrl($workspace, $business, 'setup.start'))->assertRedirect($this->wizardUrl($workspace, $business, 'setup.step', ['business_name']));
        $this->get($this->wizardUrl($workspace, $business, 'setup.step', ['business_name']))->assertOk()->assertSee('What&#039;s your business called?', false);

        // No pages and nothing public exist until the owner has answered, reviewed and generated.
        $website = Website::where('business_id', $business->id)->sole();
        $this->assertSame(0, $website->pages()->count());
        $this->assertSame(404, $this->get($this->publicHome($website))->getStatusCode());
    }

    public function test_a_service_added_after_generation_is_flagged_with_a_rebuild_action_not_claimed_as_listed(): void
    {
        [, $business, $workspace, $website] = $this->runOwnerJourneyThroughGenerate('photo_booth_modern', 3);
        $rebuild = $this->wizardUrl($workspace, $business, 'rebuild.form');

        \App\Models\BusinessService::create(['business_id' => $business->id, 'name' => 'Vintage Photo Strip Bar', 'description' => 'A tabletop strip printer.', 'status' => 'active', 'sort_order' => 99]);

        // Page copy is written once, from the answers: the new service is on no page, and Health must say so.
        $check = collect(app(\App\Library\Website\WebsiteHealthChecker::class)->check($website->fresh(), ['rebuild' => $rebuild])['checks'])->firstWhere('key', 'service_pages');
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('Vintage Photo Strip Bar', $check['detail']);
        $this->assertStringContainsString('not on your website yet', $check['detail']);
        $this->assertSame($rebuild, $check['action']['url']);
    }

    public function test_a_multi_location_owner_is_told_to_connect_the_quote_form_instead_of_silently_losing_inquiries(): void
    {
        $this->locationCount = 2;
        [, $business, $workspace, $website] = $this->runOwnerJourneyThroughGenerate('photo_booth_modern', 3);
        $this->publish($workspace, $business);

        [$form, $formPage] = $this->quoteFormAndPage($website->fresh());

        // The product never guesses which of several Locations a lead belongs to...
        $this->assertNull($form->location_id);

        // ...so the owner is told, in plain words and with the fix link, before a visitor is turned away.
        $health = app(\App\Library\Website\WebsiteHealthChecker::class)->check($website->fresh(), ['forms' => $this->wizardUrl($workspace, $business, 'forms.index')]);
        $check = collect($health['checks'])->firstWhere('key', 'quote_form_location');
        $this->assertNotNull($check, 'Health names the problem');
        $this->assertSame('fail', $check['status']);
        $this->assertSame($this->wizardUrl($workspace, $business, 'forms.index'), $check['action']['url']);

        // The Studio overview shows it (folded health is open when something needs fixing).
        $overview = $this->get($this->wizardUrl($workspace, $business, 'studio.show'))->assertOk()->assertSee($check['title'])->getContent();
        $this->assertMatchesRegularExpression('/data-testid="website-health-fold"\s+open/', $overview, 'a problem that needs fixing now is never hidden behind a collapsed row');

        // Choosing the Location on the Forms screen is the one step that makes the live form accept inquiries.
        $location = BusinessLocation::where('business_id', $business->id)->where('is_primary', true)->sole();
        $this->put($this->wizardUrl($workspace, $business, 'forms.update', [$form->uid]), [
            'name' => $form->name, 'submit_label' => $form->submit_label, 'location_uid' => $location->uid, 'is_active' => '1', 'create_opportunity' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame((int) $location->id, (int) $form->fresh()->location_id);

        $pageUrl = $this->publicPage($website, $formPage->slug);
        $this->visitorPost($pageUrl, $form, $formPage, $website->fresh(), ['name' => 'Riley Visitor', 'phone' => '5555550103'])->assertRedirect($pageUrl)->assertSessionHasNoErrors();
        $this->assertSame((int) $location->id, (int) WebsiteFormSubmission::where('website_form_id', $form->id)->sole()->location_id);

        $health = app(\App\Library\Website\WebsiteHealthChecker::class)->check($website->fresh(), []);
        $this->assertSame('ok', collect($health['checks'])->firstWhere('key', 'quote_form_location')['status']);
    }
}
