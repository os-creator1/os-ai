<?php

namespace Tests\Feature\Website;

use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\BusinessService;
use App\Models\CatalogItem;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteForm;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Builder redesign — the new setup wizard's HTTP surface.
 *
 * Covers a real regression found only by browser-clicking through the
 * actual flow (never caught by the library-level Setup tests alone):
 * the wizard deliberately creates the Website SHELL row early, at the
 * template step (WebsiteStarterDraftService::createShellFromTemplate()),
 * so every route past that point must resume the in-progress
 * questionnaire session rather than bouncing to Website Studio just
 * because a Website row now exists.
 */
class WebsiteWizardControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
    }

    public function test_a_business_with_no_website_sees_the_empty_state(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.show', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Create your website')
            ->assertSee('Answer a few questions and we&#039;ll build the first draft for you.', false)
            ->assertSee('Create my website')
            ->assertDontSee('Manage pages')
            ->assertDontSee('Publish');
    }

    public function test_start_with_nothing_in_progress_begins_on_the_first_question_with_the_default_template(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        // No up-front style question: the look is chosen on the Review screen.
        $this->get(route('customer.workspaces.businesses.website.setup.start', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']));

        $website = Website::where('business_id', $business->id)->sole();
        $this->assertSame('photo_booth_modern', $website->template_key, 'Template 1 is the niche default.');
        $this->assertSame(0, $website->pages()->count());
    }

    public function test_choosing_a_template_creates_the_website_shell_and_starts_the_questionnaire(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), [
            'template_key' => 'photo_booth_modern',
        ])->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']));

        $this->assertSame(1, Website::where('business_id', $business->id)->count());
        $this->assertSame(1, QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->count());
    }

    /**
     * THE REGRESSION: once the template step creates the Website shell,
     * every subsequent wizard route must still resume the in-progress
     * session — never bounce to Studio merely because a Website row
     * exists.
     */
    public function test_the_website_shell_existing_never_bounces_an_in_progress_session_to_studio(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), [
            'template_key' => 'photo_booth_modern',
        ]);

        $this->assertTrue(Website::where('business_id', $business->id)->exists(), 'Precondition: the shell must already exist.');

        // start() must resume, not redirect to Studio.
        $this->get(route('customer.workspaces.businesses.website.setup.start', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']));

        // The question step itself must render, not redirect to Studio.
        $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']))
            ->assertOk()
            ->assertSee("What&#039;s your business called?", false);

        // Independent-review correction round 2 — re-visiting the
        // template step mid-flow (reached via the first question's own
        // Back arrow) now genuinely renders the picker again, pre-
        // selecting the current choice, rather than bouncing back to the
        // question or to Studio — this is what makes Back from the first
        // question actually work (see test_back_from_the_first_question_
        // shows_the_template_picker_for_a_first_time_session()).
        $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'template']))
            ->assertOk();
    }

    public function test_autosaving_an_answer_advances_to_the_next_step(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'business_name']), [
            'value' => 'Test Photo Booth Co.',
            'answers_revision' => 1,
        ])->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'phone']));

        $response = QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole();
        $this->assertSame('Test Photo Booth Co.', $response->answers['business_name']);
        $this->assertSame('phone', $response->current_step_key);
    }

    public function test_going_back_preserves_the_previously_saved_answer(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'business_name']), [
            'value' => 'Test Photo Booth Co.', 'answers_revision' => 1,
        ]);

        $this->post(route('customer.workspaces.businesses.website.setup.back', [$workspace->uid, $business->uid, 'phone']))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']));

        $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']))
            ->assertOk()
            ->assertSee('Test Photo Booth Co.');
    }

    public function test_a_foreign_business_can_never_reach_another_businesses_wizard_step(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $otherBusiness->uid, 'template']))
            ->assertNotFound();
    }

    /**
     * The full journey, through the REAL HTTP controller end to end:
     * template -> every required question -> generate -> a real
     * guided-generated Website with real canonical Business/Service/
     * CatalogItem/BusinessBackdrop facts applied first, exactly as
     * WebsiteSetupAnswerApplier + GuidedGenerationCommitService are each
     * separately proven to do — this proves the controller actually
     * wires them together correctly.
     */
    public function test_the_full_wizard_journey_generates_a_real_website_from_real_answers(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $params = fn (string $step) => [$workspace->uid, $business->uid, $step];

        // Echo back exactly the page_keys the real plan contains, whatever
        // they are — this proves the controller wiring without also
        // having to predict WebsitePageStrategy's exact plan shape for
        // this business's specific answers (services/packages/gallery
        // eligibility all depend on the answers just submitted).
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function (array $messages) {
            $userMessage = collect($messages)->firstWhere('role', 'user')['content'] ?? '{}';
            $jsonStart = strpos($userMessage, '{');
            $payload = json_decode($jsonStart !== false ? substr($userMessage, $jsonStart) : '{}', true) ?? [];
            $plan = $payload['plan'] ?? [];

            $pages = collect($plan)->map(fn (array $page) => [
                'page_key' => $page['page_key'],
                'title' => $page['entity']['name'] ?? ucwords(str_replace(['_', ':'], ' ', explode(':', $page['page_key'])[0])),
                'seo_title' => 'Full journey | ' . $page['page_key'],
                'meta_description' => 'Full journey generated content for ' . $page['page_key'] . '.',
                'sections' => [['type' => 'hero', 'data' => ['heading' => $page['page_key'], 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]]],
            ])->values()->all();

            return json_encode(['pages' => $pages]);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        // Re-fetch the response fresh before every answer, since each
        // autosave bumps the revision and the controller enforces it.
        $freshRevision = fn () => QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole()->answers_revision;

        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('business_name')), ['value' => 'Full Journey Photo Booth', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('phone')), ['value' => '6305550199', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('email')), ['value' => 'hello@fulljourney.test', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('service_area_cities')), ['value' => 'Naperville, Aurora', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('primary_cta')), ['value' => 'quote_request', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('brand_personality')), ['value' => 'playful', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('booth_types')), ['items' => [['name' => 'Open-Air Booth', 'description' => 'Our flagship booth.']], 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('services_event_types')), ['items' => [], 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('packages')), ['items' => [['name' => 'Wedding Package', 'description' => 'Great for weddings.', 'price' => '895.00', 'currency_code' => 'USD', 'featured' => '1', 'features_text' => "Unlimited prints\nProps included"]], 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('offers_backdrops')), ['value' => '0', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('gallery')), ['value' => null, 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('testimonials')), ['items' => [], 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('faq_items')), ['items' => [], 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('about_story')), ['value' => 'We throw the best photo booth parties in town.', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('contact_form_fields')), ['value' => ['name', 'phone'], 'answers_revision' => $freshRevision()])
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', $params('custom_section')));
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('custom_section')), ['items' => [], 'answers_revision' => $freshRevision()])
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.review', [$workspace->uid, $business->uid]));

        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]));

        $business = $business->fresh();
        $this->assertSame('Full Journey Photo Booth', $business->name);
        $this->assertSame('We throw the best photo booth parties in town.', $business->description);

        $website = Website::where('business_id', $business->id)->sole();
        $this->assertGreaterThan(0, $website->pages()->count());

        $this->assertSame(1, BusinessService::where('business_id', $business->id)->count());
        $catalogItem = CatalogItem::where('business_id', $business->id)->sole();
        $this->assertSame('Wedding Package', $catalogItem->name);
        $this->assertSame(89500, $catalogItem->price_minor);
        $this->assertTrue($catalogItem->featured);

        $form = WebsiteForm::where('website_id', $website->id)->sole();
        $this->assertSame($business->id, $form->business_id);

        $completedResponse = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('completed', $completedResponse->status->value);
    }

    /**
     * Shared by every correction-round test below: runs the wizard
     * through template choice + every required Photobooth step (one
     * package, one required service, backdrops declined, no gallery/
     * custom-section content unless $stepOverrides supplies it), landing
     * on the GET review screen. Returns the resulting in_progress
     * QuestionnaireResponse.
     *
     * @param  array<string, array>  $stepOverrides  step key => full autosave payload, replacing the default for that step
     */
    private function completeAllRequiredSteps(object $workspace, object $business, array $stepOverrides = []): QuestionnaireResponse
    {
        $params = fn (string $step) => [$workspace->uid, $business->uid, $step];
        $freshRevision = fn () => QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole()->answers_revision;

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $defaults = [
            'business_name' => ['value' => 'Correction Round Photo Booth'],
            'phone' => ['value' => '6305550100'],
            'email' => ['value' => 'hello@correctionround.test'],
            'service_area_cities' => ['value' => 'Naperville'],
            'primary_cta' => ['value' => 'quote_request'],
            'brand_personality' => ['value' => 'playful'],
            'booth_types' => ['items' => [['name' => 'Open-Air Booth', 'description' => 'Flagship booth.']]],
            'services_event_types' => ['items' => []],
            'packages' => ['items' => [['name' => 'Wedding Package', 'description' => 'Great for weddings.', 'price' => '895.00', 'currency_code' => 'USD', 'featured' => '1', 'features_text' => 'Unlimited prints']]],
            'offers_backdrops' => ['value' => '0'],
            'gallery' => ['value' => null],
            'testimonials' => ['items' => []],
            'faq_items' => ['items' => []],
            'about_story' => ['value' => 'We throw the best photo booth parties in town.'],
            'contact_form_fields' => ['value' => ['name', 'phone']],
            'custom_section' => ['items' => []],
        ];

        foreach ($defaults as $stepKey => $payload) {
            $payload = $stepOverrides[$stepKey] ?? $payload;
            $payload['answers_revision'] = $freshRevision();
            $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params($stepKey)), $payload);
        }

        return QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole();
    }

    /**
     * Note: Laravel's `Route::getController()` caches the resolved
     * controller instance ON THE ROUTE OBJECT after its first dispatch,
     * and that same Route object is reused for every subsequent request
     * to the same URI+method WITHIN one test method — so rebinding a
     * mock mid-test via `$this->app->instance()` has no effect on a
     * SECOND call to a route already dispatched once in this same test
     * (its controller, and everything constructor-injected into it, was
     * already resolved and cached on the first call). Tests that need
     * different AI behavior across two calls to the SAME route therefore
     * bind ONE stateful mock up front (see
     * bindPlanEchoingAiClientThatFailsOnce()) rather than rebinding.
     */
    private function bindPlanEchoingAiClient(): void
    {
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function (array $messages) {
            $userMessage = collect($messages)->firstWhere('role', 'user')['content'] ?? '{}';
            $jsonStart = strpos($userMessage, '{');
            $payload = json_decode($jsonStart !== false ? substr($userMessage, $jsonStart) : '{}', true) ?? [];
            $plan = $payload['plan'] ?? [];

            $pages = collect($plan)->map(fn (array $page) => [
                'page_key' => $page['page_key'],
                'title' => $page['entity']['name'] ?? ucwords(str_replace(['_', ':'], ' ', explode(':', $page['page_key'])[0])),
                'seo_title' => 'Correction round | ' . $page['page_key'],
                'meta_description' => 'Correction round content for ' . $page['page_key'] . '.',
                'sections' => [['type' => 'hero', 'data' => ['heading' => $page['page_key'], 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]]],
            ])->values()->all();

            return json_encode(['pages' => $pages]);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
    }

    /**
     * One stateful mock, bound ONCE: fails closed (null) on its first
     * call, then behaves exactly like bindPlanEchoingAiClient() on every
     * call after that — used to prove a failed generation is genuinely
     * retryable within a single test (see the class docblock above on
     * why rebinding mid-test cannot be used for this instead).
     */
    private function bindPlanEchoingAiClientThatFailsOnce(): void
    {
        $calls = 0;
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function (array $messages) use (&$calls) {
            $calls++;
            // GuidedGenerationCommitService's own bounded corrective
            // retry means ONE outer generate() call can invoke complete()
            // up to twice — both of the first outer call's internal
            // attempts must fail for that whole outer call to be
            // recorded `failed`; only calls from the SECOND outer
            // generate() call onward succeed.
            if ($calls <= 2) {
                return null;
            }

            $userMessage = collect($messages)->firstWhere('role', 'user')['content'] ?? '{}';
            $jsonStart = strpos($userMessage, '{');
            $payload = json_decode($jsonStart !== false ? substr($userMessage, $jsonStart) : '{}', true) ?? [];
            $plan = $payload['plan'] ?? [];

            $pages = collect($plan)->map(fn (array $page) => [
                'page_key' => $page['page_key'],
                'title' => $page['entity']['name'] ?? ucwords(str_replace(['_', ':'], ' ', explode(':', $page['page_key'])[0])),
                'seo_title' => 'Correction round | ' . $page['page_key'],
                'meta_description' => 'Correction round content for ' . $page['page_key'] . '.',
                'sections' => [['type' => 'hero', 'data' => ['heading' => $page['page_key'], 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]]],
            ])->values()->all();

            return json_encode(['pages' => $pages]);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
    }

    public function test_main_website_navigation_resumes_an_active_session_at_its_saved_step(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'business_name']), ['value' => 'Resume Me Photo Booth', 'answers_revision' => 1]);

        $this->get(route('customer.workspaces.businesses.website.show', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'phone']));
    }

    public function test_back_navigation_persists_and_main_navigation_resumes_the_earlier_step(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'business_name']), ['value' => 'Back Nav Photo Booth', 'answers_revision' => 1]);
        // Now on 'phone'. Go back once.
        $this->post(route('customer.workspaces.businesses.website.setup.back', [$workspace->uid, $business->uid, 'phone']))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']));

        // Leaving immediately and returning via main nav must resume at
        // business_name (where Back left it), never phone (the step the
        // owner had been on before clicking Back) — this is the exact
        // regression a plain GET back-link (never persisting the step)
        // would reproduce.
        $this->get(route('customer.workspaces.businesses.website.show', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']));
    }

    public function test_a_forged_select_option_is_refused_and_the_answer_is_not_saved(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        // Fast-forward to primary_cta (a select step) directly via saveAnswer chain.
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'business_name']), ['value' => 'X', 'answers_revision' => 1]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'phone']), ['value' => '6305550100', 'answers_revision' => 2]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'email']), ['value' => 'x@x.test', 'answers_revision' => 3]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'service_area_cities']), ['value' => 'Chicago', 'answers_revision' => 4]);

        $response = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('primary_cta', $response->current_step_key);

        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'primary_cta']), ['value' => 'forged_option', 'answers_revision' => $response->answers_revision])
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'primary_cta']));

        $response = $response->fresh();
        $this->assertSame('primary_cta', $response->current_step_key, 'A refused answer must not advance the step.');
        $this->assertNull($response->answer('primary_cta'));
    }

    public function test_a_missing_required_boolean_choice_is_refused_rather_than_defaulting_to_no(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $params = fn (string $step) => [$workspace->uid, $business->uid, $step];
        $freshRevision = fn () => QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole()->answers_revision;

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('business_name')), ['value' => 'X', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('phone')), ['value' => '6305550100', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('email')), ['value' => 'x@x.test', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('service_area_cities')), ['value' => 'Chicago', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('primary_cta')), ['value' => 'quote_request', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('brand_personality')), ['value' => 'playful', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('booth_types')), ['items' => [['name' => 'Booth']], 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('services_event_types')), ['items' => [], 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('packages')), ['items' => [['name' => 'Pkg']], 'answers_revision' => $freshRevision()]);

        $response = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('offers_backdrops', $response->current_step_key);

        // No `value` key submitted at all — exactly what a checkbox/radio
        // group sends when nothing is checked. This must be refused, not
        // silently coerced into "No."
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('offers_backdrops')), [
            'answers_revision' => $response->answers_revision,
        ])->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'offers_backdrops']));

        $response = $response->fresh();
        $this->assertSame('offers_backdrops', $response->current_step_key, 'A refused answer must not advance the step.');
        $this->assertNull($response->answer('offers_backdrops'));
    }

    public function test_editing_answers_reopens_the_completed_response_and_saving_reconciles_without_regenerating(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindPlanEchoingAiClient();

        $response = $this->completeAllRequiredSteps($workspace, $business);
        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]));

        $website = Website::where('business_id', $business->id)->sole();
        $pagesBefore = $website->pages()->orderBy('id')->pluck('title', 'slug')->all();
        $this->assertNotEmpty($pagesBefore);

        // Manually edit a draft page — this must survive the edit-answers round trip below.
        $page = $website->pages()->first();
        $page->update(['title' => 'Manually Edited Title']);

        $this->get(route('customer.workspaces.businesses.website.edit-setup', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']));

        $reopened = QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole();
        $this->assertTrue($reopened->edit_mode);

        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'business_name']), [
            'value' => 'Renamed Via Edit',
            'answers_revision' => $reopened->answers_revision,
        ]);

        // Jump straight to generate() (edit_mode finish never requires
        // re-answering every step — only visiting business_name for this
        // test).
        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.studio.show', [$workspace->uid, $business->uid]));

        $this->assertSame('Renamed Via Edit', $business->fresh()->name);

        $completed = QuestionnaireResponse::where('business_id', $business->id)->where('status', 'completed')->sole();
        $this->assertFalse($completed->edit_mode);

        $page->refresh();
        $this->assertSame('Manually Edited Title', $page->title, 'Editing setup answers must never silently regenerate or overwrite manually edited pages.');
        $pagesAfter = $website->fresh()->pages()->orderBy('id')->pluck('title', 'slug')->all();
        $this->assertSame(count($pagesBefore), count($pagesAfter), 'The page set itself must be unchanged by an answer edit alone.');
    }

    public function test_a_failed_generation_leaves_the_response_retryable_and_a_later_retry_can_succeed(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        // Bound ONCE, up front — see bindPlanEchoingAiClientThatFailsOnce()'s
        // own docblock for why rebinding mid-test cannot be used here
        // (Route::getController() caches the resolved controller, and
        // everything constructor-injected into it, on its first dispatch
        // within a test).
        $this->bindPlanEchoingAiClientThatFailsOnce();
        $response = $this->completeAllRequiredSteps($workspace, $business);

        // First attempt: the AI client fails closed (null) — generation fails.
        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.review', [$workspace->uid, $business->uid]));

        $stillInProgress = QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->first();
        $this->assertNotNull($stillInProgress, 'A failed generation must leave the response in_progress and retryable.');
        $this->assertSame($response->id, $stillInProgress->id);

        // The review screen itself must still render (GET, not a bounce to Studio).
        $this->get(route('customer.workspaces.businesses.website.setup.review', [$workspace->uid, $business->uid]))->assertOk();

        // Retry: the same mock now returns a valid batch.
        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]));

        $this->assertSame('completed', QuestionnaireResponse::where('business_id', $business->id)->sole()->status->value);
    }

    public function test_a_duplicate_generation_submission_does_not_duplicate_canonical_records_or_pages(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindPlanEchoingAiClient();

        $this->completeAllRequiredSteps($workspace, $business);

        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]));

        // A second submission of the exact same completed/stable-key
        // request (e.g. a double-click, or the browser retrying the
        // POST) must converge to the same underlying result rather than
        // spending AI again or duplicating canonical records. By the
        // time this second call runs, the response is already
        // `completed`, so generate() redirects through start() — the
        // real proof is that only one attempt/one CatalogItem exist.
        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]));

        $website = Website::where('business_id', $business->id)->sole();
        $this->assertSame(1, $website->guidedGenerationAttempts()->where('status', 'succeeded')->count());
        $this->assertSame(1, CatalogItem::where('business_id', $business->id)->count());
    }

    public function test_gallery_uploads_survive_resume_and_a_small_collection_does_not_reach_the_gallery_page_threshold(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindPlanEchoingAiClient();

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $website = Website::where('business_id', $business->id)->sole();

        $this->post(route('customer.workspaces.businesses.website.setup.gallery.upload', [$workspace->uid, $business->uid]), [
            'photos' => [$this->fakeImageUpload('one.png'), $this->fakeImageUpload('two.png')],
        ])->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'gallery']));

        $this->assertSame(2, $website->assets()->count());
        $firstAssetPath = $website->assets()->orderBy('sort_order')->first()->path;

        // Leaving and returning to the gallery step (a fresh GET) must
        // still show the uploaded photos — they live durably in
        // website_assets, never only in the response's own answers.
        $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'gallery']))
            ->assertOk()
            ->assertSee($firstAssetPath, false);
    }

    /**
     * Independent-review correction round 5 (item 2) — a CRASHED
     * generation (its lease token left set forever, since nothing was
     * left running to call release()) must never permanently block
     * autosave. WebsiteSetupSessionManager::runIfNotGenerating() used to
     * check only `generation_lease_token !== null`, with no expiry check
     * at all — this proves the fix: once the lease's own LEASE_SECONDS
     * window has passed, autosave succeeds immediately, without needing
     * some later, unrelated generation attempt to happen to reclaim and
     * release it first.
     */
    public function test_an_expired_crashed_lease_never_permanently_blocks_autosave(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $website = Website::where('business_id', $business->id)->sole();

        // Simulates a worker that crashed mid-generation: the lease token
        // is still set, but its own window expired long ago, and nothing
        // is left running to ever call release().
        $website->forceFill([
            'generation_lease_token' => (string) \Illuminate\Support\Str::uuid(),
            'generation_lease_started_at' => now()->subSeconds(\App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator::LEASE_SECONDS + 60),
        ])->save();

        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'business_name']), [
            'value' => 'Recovered After Crash',
            'answers_revision' => 1,
        ])->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'phone']));

        $response = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('Recovered After Crash', $response->answer('business_name'));
        $this->assertNull($website->fresh()->generation_lease_token, 'The expired crashed lease must be cleared, not merely bypassed.');
    }

    /**
     * Independent-review correction round 5 (item 2) — the same recovery
     * for the gallery/custom-section upload endpoints, which also route
     * through runIfNotGenerating(). A crashed generation must never
     * permanently block uploading a photo either.
     */
    public function test_an_expired_crashed_lease_never_permanently_blocks_gallery_or_custom_section_uploads(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $website = Website::where('business_id', $business->id)->sole();

        $website->forceFill([
            'generation_lease_token' => (string) \Illuminate\Support\Str::uuid(),
            'generation_lease_started_at' => now()->subSeconds(\App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator::LEASE_SECONDS + 60),
        ])->save();

        $this->post(route('customer.workspaces.businesses.website.setup.gallery.upload', [$workspace->uid, $business->uid]), [
            'photos' => [$this->fakeImageUpload('recovered.png')],
        ])->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'gallery']));

        $this->assertSame(1, $website->assets()->where('purpose', \App\Enums\Website\WebsiteAssetPurpose::Gallery->value)->count());
        $this->assertNull($website->fresh()->generation_lease_token);

        // Re-expire it again (the gallery upload above already cleared
        // and, since it never itself re-leases, left it null — re-set it
        // to prove the custom-section endpoint recovers independently
        // too, not merely because the gallery call happened to run
        // first). Refreshed first: this PHP object's own `original` is
        // stale after the coordinator's separate, direct-query clear
        // during the gallery call above, which would otherwise make
        // Eloquent believe `generation_lease_started_at` is unchanged
        // from its own first forceFill() and silently skip persisting it.
        $website->refresh()->forceFill([
            'generation_lease_token' => (string) \Illuminate\Support\Str::uuid(),
            'generation_lease_started_at' => now()->subSeconds(\App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator::LEASE_SECONDS + 60),
        ])->save();

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.upload', [$workspace->uid, $business->uid]), [
            'photo' => $this->fakeImageUpload('recovered-custom.png'),
        ])->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'custom_section']));

        $this->assertSame(1, $website->assets()->where('purpose', \App\Enums\Website\WebsiteAssetPurpose::CustomSection->value)->count());
        $this->assertNull($website->fresh()->generation_lease_token);
    }

    public function test_a_gallery_collection_at_the_documented_threshold_enables_the_gallery_page(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindPlanEchoingAiClient();

        $response = $this->completeAllRequiredSteps($workspace, $business);
        $website = $response->website;

        $this->post(route('customer.workspaces.businesses.website.setup.gallery.upload', [$workspace->uid, $business->uid]), [
            'photos' => array_map(fn ($i) => $this->fakeImageUpload("photo{$i}.png"), range(1, \App\Library\Website\WebsitePageStrategy::MIN_GALLERY_ASSETS)),
        ]);

        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]));

        $this->assertTrue($website->fresh()->pages()->where('slug', 'gallery')->exists(), 'A gallery-page-eligible collection must produce a real Gallery page.');
    }

    public function test_custom_section_body_layout_and_images_reach_the_generated_section(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindPlanEchoingAiClient();

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $website = Website::where('business_id', $business->id)->sole();

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.upload', [$workspace->uid, $business->uid]), [
            'photo' => $this->fakeImageUpload('custom.png'),
        ]);
        $imageUid = $website->assets()->where('purpose', \App\Enums\Website\WebsiteAssetPurpose::CustomSection->value)->sole()->uid;

        $response = $this->completeAllRequiredSteps($workspace, $business, [
            'custom_section' => ['items' => [[
                'name' => 'Red Carpet Experience',
                'description' => null,
                'body' => 'Roll out the red carpet for your guests.',
                'layout' => 'image_left',
                'images' => [$imageUid],
            ]]],
        ]);

        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]));

        $page = $website->fresh()->pages()->where('slug', 'red-carpet-experience')->sole();
        $section = collect($page->sections)->firstWhere('type', 'custom_section');
        $this->assertNotNull($section);
        $this->assertSame('Roll out the red carpet for your guests.', $section['data']['body']);
        $this->assertSame('image_left', $section['data']['layout']);
        $this->assertSame([$imageUid], $section['data']['images']);
    }

    /**
     * Independent-review correction round 3 (item 6) — a submitted
     * custom_section `images[]` uid is untrusted client input. A gallery-
     * purpose asset (uploaded through a completely different endpoint,
     * for a completely different purpose) must never be accepted into a
     * custom section merely because its uid was replayed in the form.
     */
    public function test_a_custom_section_image_uid_belonging_to_the_gallery_is_rejected(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $website = Website::where('business_id', $business->id)->sole();

        $this->post(route('customer.workspaces.businesses.website.setup.gallery.upload', [$workspace->uid, $business->uid]), [
            'photos' => [$this->fakeImageUpload('gallery.png')],
        ]);
        $galleryUid = $website->assets()->where('purpose', \App\Enums\Website\WebsiteAssetPurpose::Gallery->value)->sole()->uid;

        $response = $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'custom_section']), [
            'items' => [['name' => 'Section', 'body' => 'Body', 'images' => [$galleryUid]]],
            'answers_revision' => 1,
        ]);
        $response->assertSessionHas('status', 'error');

        $stored = QuestionnaireResponse::where('business_id', $business->id)->sole()->answer('custom_section');
        $this->assertNull($stored, 'A forged gallery-purpose uid must never be accepted into the custom_section answer.');
    }

    /**
     * The same forged-uid family but from a completely different Website
     * — cross-tenant, not merely cross-purpose.
     */
    public function test_a_custom_section_image_uid_belonging_to_a_foreign_website_is_rejected(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        [, $otherBusiness] = $this->entitledTenant();
        $otherWebsite = Website::create(['business_id' => $otherBusiness->id, 'name' => 'Other', 'status' => 'draft']);
        $foreignAsset = \App\Models\WebsiteAsset::create([
            'website_id' => $otherWebsite->id, 'disk' => 'public', 'path' => 'images/websites/x/foreign.png',
            'mime_type' => 'image/png', 'size' => 100, 'purpose' => \App\Enums\Website\WebsiteAssetPurpose::CustomSection->value,
        ]);

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $response = $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'custom_section']), [
            'items' => [['name' => 'Section', 'body' => 'Body', 'images' => [$foreignAsset->uid]]],
            'answers_revision' => 1,
        ]);
        $response->assertSessionHas('status', 'error');

        $stored = QuestionnaireResponse::where('business_id', $business->id)->sole()->answer('custom_section');
        $this->assertNull($stored, 'A foreign Website\'s asset uid must never be accepted into this Website\'s custom_section answer.');
    }

    /**
     * Independent-review correction round 3 (item 9) — v1 supports
     * exactly one custom section; a forged multi-entry submission is
     * safely normalized to its first entry, never silently accepted in
     * full.
     */
    public function test_a_forged_multi_entry_custom_section_submission_keeps_only_the_first_entry(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'custom_section']), [
            'items' => [
                ['name' => 'First Section', 'body' => 'First body'],
                ['name' => 'Second Section', 'body' => 'Second body'],
            ],
            'answers_revision' => 1,
        ])->assertSessionDoesntHaveErrors();

        $stored = QuestionnaireResponse::where('business_id', $business->id)->sole()->answer('custom_section');
        $this->assertCount(1, $stored);
        $this->assertSame('First Section', $stored[0]['name']);
    }

    /**
     * Independent-review correction round 3 (item 7) — the "Make cover"
     * form submits ONLY `is_cover`; title/category/alt must survive
     * untouched, proven via the REAL HTTP endpoint (not a direct manager
     * call), since the bug was specifically in how the controller
     * interpreted the request's missing fields.
     */
    public function test_making_a_photo_the_cover_via_http_preserves_its_existing_metadata(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $website = Website::where('business_id', $business->id)->sole();

        $this->post(route('customer.workspaces.businesses.website.setup.gallery.upload', [$workspace->uid, $business->uid]), [
            'photos' => [$this->fakeImageUpload('a.png')],
        ]);
        $asset = $website->assets()->sole();

        $this->post(route('customer.workspaces.businesses.website.setup.gallery.update', [$workspace->uid, $business->uid, $asset->uid]), [
            'title' => 'Open-air booth', 'category_tag' => 'ceremony', 'alt_text' => 'My own hand-written alt text',
        ])->assertSessionDoesntHaveErrors();

        // The real "Make cover" form: ONLY is_cover, exactly as the
        // wizard's own gallery blade renders it.
        $this->post(route('customer.workspaces.businesses.website.setup.gallery.update', [$workspace->uid, $business->uid, $asset->uid]), [
            'is_cover' => '1',
        ])->assertSessionDoesntHaveErrors();

        $fresh = $asset->fresh();
        $this->assertTrue($fresh->is_cover);
        $this->assertSame('Open-air booth', $fresh->title, 'Title must survive a cover-only submission.');
        $this->assertSame('ceremony', $fresh->category_tag, 'Category must survive a cover-only submission.');
        $this->assertSame('My own hand-written alt text', $fresh->alt_text, 'Explicit alt text must survive a cover-only submission.');
        $this->assertTrue($fresh->alt_text_is_custom);
    }

    /**
     * The inverse: a metadata-only update (no is_cover field at all)
     * must never flip or clear the cover flag.
     */
    public function test_updating_metadata_via_http_never_touches_the_cover_flag(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $website = Website::where('business_id', $business->id)->sole();

        $this->post(route('customer.workspaces.businesses.website.setup.gallery.upload', [$workspace->uid, $business->uid]), [
            'photos' => [$this->fakeImageUpload('a.png')],
        ]);
        $asset = $website->assets()->sole();

        $this->post(route('customer.workspaces.businesses.website.setup.gallery.update', [$workspace->uid, $business->uid, $asset->uid]), ['is_cover' => '1']);
        $this->assertTrue($asset->fresh()->is_cover);

        $this->post(route('customer.workspaces.businesses.website.setup.gallery.update', [$workspace->uid, $business->uid, $asset->uid]), [
            'title' => 'A new title',
        ])->assertSessionDoesntHaveErrors();

        $this->assertTrue($asset->fresh()->is_cover, 'A metadata-only update must never clear an existing cover.');
        $this->assertSame('A new title', $asset->fresh()->title);
    }

    /**
     * Independent-review correction round 3 (item 7) — a wrong-purpose
     * removal request explicitly 404s via the real HTTP endpoint, rather
     * than silently no-opping.
     */
    public function test_removing_a_custom_section_asset_through_the_gallery_endpoint_404s(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.upload', [$workspace->uid, $business->uid]), [
            'photo' => $this->fakeImageUpload('custom.png'),
        ]);
        $website = Website::where('business_id', $business->id)->sole();
        $customAsset = $website->assets()->where('purpose', \App\Enums\Website\WebsiteAssetPurpose::CustomSection->value)->sole();

        $this->delete(route('customer.workspaces.businesses.website.setup.gallery.remove', [$workspace->uid, $business->uid, $customAsset->uid]))
            ->assertNotFound();

        $this->assertNotNull($customAsset->fresh(), 'The wrong-purpose asset must survive an endpoint scoped to a different purpose.');
    }

    public function test_removing_a_gallery_asset_through_the_custom_section_endpoint_404s(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $this->post(route('customer.workspaces.businesses.website.setup.gallery.upload', [$workspace->uid, $business->uid]), [
            'photos' => [$this->fakeImageUpload('gallery.png')],
        ]);
        $website = Website::where('business_id', $business->id)->sole();
        $galleryAsset = $website->assets()->sole();

        $this->delete(route('customer.workspaces.businesses.website.setup.custom-section.remove', [$workspace->uid, $business->uid, $galleryAsset->uid]))
            ->assertNotFound();

        $this->assertNotNull($galleryAsset->fresh(), 'The wrong-purpose asset must survive an endpoint scoped to a different purpose.');
    }

    public function test_back_from_the_first_question_returns_to_the_website_landing_and_the_picker_stays_reachable(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $this->post(route('customer.workspaces.businesses.website.setup.back', [$workspace->uid, $business->uid, 'business_name']))
            ->assertRedirect(route('customer.workspaces.businesses.website.show', [$workspace->uid, $business->uid]));

        $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'template']))
            ->assertOk()
            ->assertSee('Choose a style');
    }

    public function test_choosing_a_different_template_from_the_picker_updates_the_same_setup_without_losing_answers(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'business_name']), ['value' => 'Keep My Answer', 'answers_revision' => 1]);

        $response = QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole();
        $this->assertSame('phone', $response->current_step_key);

        // Back to the template step, then choose a DIFFERENT template.
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_editorial'])
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'phone']));

        $this->assertSame(1, Website::where('business_id', $business->id)->count(), 'Swapping the template must never create a second Website.');
        $this->assertSame(1, QuestionnaireResponse::where('business_id', $business->id)->count(), 'Swapping the template must never create a second response.');

        $website = Website::where('business_id', $business->id)->sole();
        $this->assertSame('photo_booth_editorial', $website->template_key);

        $reloaded = $response->fresh();
        $this->assertSame('Keep My Answer', $reloaded->answer('business_name'), 'Swapping the template must never lose a saved answer.');
        $this->assertSame('phone', $reloaded->current_step_key, 'Swapping the template must never rewind the resume position.');
    }

    public function test_an_edit_mode_sessions_back_from_the_first_question_exits_to_studio_never_exposing_template_replacement(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindPlanEchoingAiClient();
        $this->completeAllRequiredSteps($workspace, $business);
        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]));

        $this->get(route('customer.workspaces.businesses.website.edit-setup', [$workspace->uid, $business->uid]));
        $reopened = QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole();
        $this->assertTrue($reopened->edit_mode);
        $firstStepKey = $reopened->current_step_key;

        $this->post(route('customer.workspaces.businesses.website.setup.back', [$workspace->uid, $business->uid, $firstStepKey]))
            ->assertRedirect(route('customer.workspaces.businesses.website.studio.show', [$workspace->uid, $business->uid]));

        // Direct navigation to the template step during an edit_mode
        // session must never expose template replacement either.
        $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'template']))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, $firstStepKey]));
    }

    /**
     * Binds a mock whose complete() call is asserted to receive the given
     * "current_body" — proving Improve with AI sent whatever was
     * SUBMITTED, never a stale persisted value.
     */
    private function bindImproveMockExpectingCurrentBody(string $expectedBody, ?string $improvedReturn): void
    {
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')
            ->withArgs(function (array $messages) use ($expectedBody) {
                $userContent = json_decode(collect($messages)->firstWhere('role', 'user')['content'] ?? '{}', true);

                return ($userContent['current_body'] ?? null) === $expectedBody;
            })
            ->andReturn($improvedReturn !== null ? json_encode(['body' => $improvedReturn]) : null);
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
    }

    public function test_improve_with_ai_uses_the_currently_submitted_unsaved_body_not_a_stale_one(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        // Persist an OLD body first via an ordinary autosave.
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'custom_section']), [
            'items' => [['name' => 'Section', 'body' => 'Old stale body']],
            'answers_revision' => 1,
        ]);

        // Bind a mock that only succeeds if it receives the NEW, not-yet-
        // saved body this request is about to submit.
        $this->bindImproveMockExpectingCurrentBody('Brand new unsaved body', 'Improved: brand new unsaved body');

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section', 'body' => 'Brand new unsaved body']],
            'answers_revision' => 2,
        ])->assertSessionHas('status', 'success');

        $response = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('Improved: brand new unsaved body', $response->answer('custom_section')[0]['body']);
    }

    public function test_improve_with_ai_preserves_submitted_text_on_oversized_output(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $oversized = str_repeat('x', 801);
        $this->bindImproveMockExpectingCurrentBody('My body', $oversized);

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section', 'body' => 'My body']],
            'answers_revision' => 1,
        ])->assertSessionHas('status', 'error');

        $response = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('My body', $response->answer('custom_section')[0]['body'], 'An oversized AI response must never replace the submitted text.');
    }

    public function test_improve_with_ai_preserves_submitted_text_on_malformed_output(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturn('not valid json {{{');
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section', 'body' => 'My body']],
            'answers_revision' => 1,
        ])->assertSessionHas('status', 'error');

        $response = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('My body', $response->answer('custom_section')[0]['body']);
    }

    public function test_improve_with_ai_preserves_submitted_text_on_refusal(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $this->mockAiClient(null);

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section', 'body' => 'My body']],
            'answers_revision' => 1,
        ])->assertSessionHas('status', 'error');

        $response = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('My body', $response->answer('custom_section')[0]['body']);
    }

    public function test_improve_with_ai_reports_budget_exhaustion_distinctly(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(true);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturn(null);
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section', 'body' => 'My body']],
            'answers_revision' => 1,
        ])->assertSessionHas('message', 'The included AI generation budget is used up for this period.');
    }

    public function test_improve_with_ai_is_idempotent_against_a_duplicate_submission_and_spends_ai_once(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $calls = 0;
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return json_encode(['body' => 'Improved once']);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        // Same response id, same exact submitted body, same observed
        // answers_revision, twice in a row — simulating a genuine
        // double-click of the same rendered page (a real browser submits
        // the SAME hidden answers_revision value both times, since the
        // page has not been reloaded between clicks).
        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section', 'body' => 'Same body']],
            'answers_revision' => 1,
        ]);
        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section', 'body' => 'Same body']],
            'answers_revision' => 1,
        ]);

        $this->assertSame(1, $calls, 'A duplicate identical improve submission must spend AI exactly once.');
    }

    /**
     * Independent-review correction round 4 (item 2) — the stale-
     * submission revision check must happen BEFORE any AI call, not
     * merely before persisting its result: a form that observed an
     * older revision must never spend AI at all.
     */
    public function test_improve_with_ai_refuses_a_stale_revision_before_ever_calling_ai(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldNotReceive('complete');
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section', 'body' => 'My body']],
            'answers_revision' => 999,
        ])->assertSessionHas('status', 'error');
    }

    /**
     * Independent-review correction round 4 (item 2) — a stale AI
     * response: the submitted text changes (via a genuine autosave, not
     * merely a different unsaved draft) WHILE an Improve call for an
     * OLDER revision is still in flight. The late-arriving improvement
     * must be discarded, never silently overwrite the newer edit.
     */
    public function test_improve_with_ai_discards_a_stale_ai_response_when_the_text_changed_while_it_was_in_flight(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $response = QuestionnaireResponse::where('business_id', $business->id)->sole();

        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function () use ($response) {
            // Simulates a genuinely newer autosave landing while this
            // Improve call's own (mocked) AI round-trip is in flight.
            app(\App\Library\Website\Setup\WebsiteSetupSessionManager::class)
                ->updateAnswerInPlace($response->fresh(), 'custom_section', [[
                    'key' => 'section', 'name' => 'Section', 'description' => null,
                    'body' => 'Edited while AI was thinking', 'layout' => 'stacked', 'images' => [],
                ]]);

            return json_encode(['body' => 'Improved: should never land']);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section', 'body' => 'My body']],
            'answers_revision' => 1,
        ])->assertSessionHas('status', 'error');

        $final = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('Edited while AI was thinking', $final->answer('custom_section')[0]['body'], 'The newer edit made while AI was in flight must survive untouched.');
    }

    /**
     * Independent-review correction round 4 (item 2) — after a failed
     * (malformed-output) attempt, a genuine retry with the identical
     * submission must still spend AI again (never permanently blocked)
     * and must use a FRESH per-attempt ledger key, never reusing the
     * first attempt's.
     */
    public function test_improve_with_ai_allows_a_genuine_retry_after_a_failed_attempt_with_a_fresh_ledger_key(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $ledgerKeys = [];
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function ($messages, $business, $userId, $maxTokens, $ledgerKey) use (&$ledgerKeys) {
            $ledgerKeys[] = $ledgerKey;

            return count($ledgerKeys) === 1 ? 'not valid json {{{' : json_encode(['body' => 'Improved on retry']);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $payload = [
            'items' => [['name' => 'Section', 'body' => 'My body']],
            'answers_revision' => 1,
        ];

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), $payload)
            ->assertSessionHas('status', 'error');

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), $payload)
            ->assertSessionHas('status', 'success');

        $this->assertCount(2, $ledgerKeys, 'A retry after a failed attempt must spend AI again.');
        $this->assertNotSame($ledgerKeys[0], $ledgerKeys[1], 'Each attempt must use its own fresh ledger key, never reusing a failed attempt\'s.');

        $response = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('Improved on retry', $response->answer('custom_section')[0]['body']);
    }

    /**
     * Independent-review correction round 4 (item 2) — a PENDING attempt
     * whose own lease has expired (the worker presumably crashed before
     * ever calling completeCustomSectionImprove()) must be treated as
     * abandoned: a resubmission must start a fresh attempt and genuinely
     * spend AI, never block forever behind "this is already being
     * improved."
     */
    public function test_improve_with_ai_recovers_an_abandoned_pending_attempt_rather_than_blocking_forever(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $response = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $logicalKey = hash('sha256', implode('|', [$response->uid, 1, 'Section', 'My body', 'stacked']));

        // A pending attempt that was opened and then abandoned — the
        // crashed worker's own call to beginCustomSectionImprove()
        // without a matching completeCustomSectionImprove().
        app(\App\Library\Website\Setup\WebsiteSetupSessionManager::class)->beginCustomSectionImprove(
            $response, 'custom_section', $logicalKey,
            ['key' => 'section', 'name' => 'Section', 'description' => null, 'body' => 'My body', 'layout' => 'stacked', 'images' => []],
            1,
        );

        // Age the pending lease well past IMPROVE_PENDING_LEASE_SECONDS (90s).
        QuestionnaireResponse::whereKey($response->id)->update([
            'custom_section_improve_pending_started_at' => now()->subSeconds(200),
        ]);

        $this->mockAiClient(json_encode(['body' => 'Recovered and improved']));

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section', 'body' => 'My body']],
            'answers_revision' => 1,
        ])->assertSessionHas('status', 'success');

        $final = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('Recovered and improved', $final->answer('custom_section')[0]['body']);
        $this->assertSame(2, (int) $final->custom_section_improve_attempt_ordinal, 'The recovered attempt must be a new ordinal, not the abandoned one.');
    }

    /**
     * Independent-review correction round 4 (item 2) — the logical key
     * incorporates the title, not only the body: a changed title with
     * the identical body is a genuinely new submission, never mistaken
     * for a duplicate of the prior one.
     */
    public function test_improve_with_ai_treats_the_same_body_with_a_changed_title_as_a_new_submission(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $calls = 0;
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return json_encode(['body' => 'Improved #' . $calls]);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section One', 'body' => 'Shared body']],
            'answers_revision' => 1,
        ])->assertSessionHas('status', 'success');

        $midway = QuestionnaireResponse::where('business_id', $business->id)->sole();

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section Two', 'body' => 'Shared body']],
            'answers_revision' => $midway->answers_revision,
        ])->assertSessionHas('status', 'success');

        $this->assertSame(2, $calls, 'A changed title with the same body must be treated as a distinct submission, spending AI again.');
    }

    /**
     * Independent-review correction round 4 (item 2) — a genuine
     * AiGateway ledger-key collision (UniqueConstraintViolationException)
     * must convert to the same friendly "could not improve" outcome a
     * provider failure already produces, never a raw 500/uncaught
     * exception, and must never be mistaken for a success.
     */
    public function test_improve_with_ai_converts_a_gateway_idempotency_collision_into_a_friendly_response(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andThrow(new \Illuminate\Database\UniqueConstraintViolationException('testing', 'insert', [], new \Exception('Duplicate entry')));
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section', 'body' => 'My body']],
            'answers_revision' => 1,
        ])->assertSessionHas('status', 'error');

        $response = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('My body', $response->answer('custom_section')[0]['body'], 'A gateway collision must never overwrite the submitted text, and must never surface as an uncaught exception.');
    }

    /**
     * Independent-review correction round 2 — a non-empty testimonial
     * used to reach the canonical knowledge profile in the WRONG shape
     * (generic name/description instead of quote/author_name/
     * author_title) and could throw; the seeded faq_items question was
     * dead input, never wired into the generated FAQ page. Proves both
     * end to end: no exception, the testimonial reaches
     * BusinessKnowledgeProfile in its required shape, and the exact
     * customer FAQ question/answer appear in the generated draft.
     */
    /**
     * Independent-review correction round 4 (item 8) — the post-
     * generation "presentation changes pending" lifecycle (round 3, item
     * 11) was never actually covered by a focused test despite being
     * described as such. Proves the full cycle: an edit-mode change to
     * the custom section sets the pending flag; exiting edit mode alone
     * (without an explicit rebuild) never clears it or silently
     * regenerates; a deliberate rebuild both clears the flag AND carries
     * the edited content through to the real generated page.
     */
    public function test_a_post_generation_edit_sets_the_pending_flag_and_only_a_deliberate_rebuild_clears_it(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindPlanEchoingAiClient();

        $this->completeAllRequiredSteps($workspace, $business, [
            'custom_section' => ['items' => [['name' => 'Original Section', 'description' => null, 'body' => 'Original body.', 'layout' => 'stacked']]],
        ]);
        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]));

        $website = Website::where('business_id', $business->id)->sole();
        $this->assertNull($website->presentation_changes_pending_at, 'A fresh generation must never start with a pending flag already set.');

        $this->get(route('customer.workspaces.businesses.website.edit-setup', [$workspace->uid, $business->uid]));
        $reopened = QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole();
        $this->assertTrue($reopened->edit_mode);

        $this->post(route('customer.workspaces.businesses.website.setup.autosave', [$workspace->uid, $business->uid, 'custom_section']), [
            'items' => [['name' => 'Edited Section', 'description' => null, 'body' => 'Edited body.', 'layout' => 'stacked']],
            'answers_revision' => $reopened->answers_revision,
        ]);

        $this->assertNotNull($website->fresh()->presentation_changes_pending_at, 'Editing a presentation-only answer after generation must flag that a rebuild is still needed.');

        // Exiting edit mode (the wizard's own "finish editing" submission,
        // never an explicit rebuild) must NEVER itself clear the pending
        // flag or silently regenerate pages.
        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.studio.show', [$workspace->uid, $business->uid]));

        $this->assertNotNull($website->fresh()->presentation_changes_pending_at, 'Merely exiting edit mode must never clear the pending flag.');
        $pageBeforeRebuild = $website->fresh()->pages()->first();
        $this->assertNotNull($pageBeforeRebuild);

        // The deliberate rebuild action.
        $this->post(route('customer.workspaces.businesses.website.rebuild', [$workspace->uid, $business->uid]), [
            'template_key' => 'photo_booth_modern',
            'confirm_rebuild' => '1',
        ]);

        $rebuilt = $website->fresh();
        $this->assertNull($rebuilt->presentation_changes_pending_at, 'A deliberate rebuild must clear the pending flag.');

        $customSectionPage = $rebuilt->pages()->get()->first(fn ($page) => collect($page->sections)->contains(fn ($section) => ($section['type'] ?? null) === 'custom_section'));
        $this->assertNotNull($customSectionPage, 'The rebuild must still include the custom section page.');
        $section = collect($customSectionPage->sections)->firstWhere('type', 'custom_section');
        $this->assertSame('Edited body.', $section['data']['body'], 'The rebuild must carry the EDITED presentation content through, not the original.');
    }

    public function test_non_empty_testimonials_and_faq_reach_canonical_and_generated_output_without_error(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindPlanEchoingAiClient();

        $response = $this->completeAllRequiredSteps($workspace, $business, [
            'testimonials' => ['items' => [[
                'quote' => 'They made our wedding unforgettable!',
                'author_name' => 'Jamie Rivera',
                'author_title' => 'Bride',
            ]]],
            'faq_items' => ['items' => [[
                'question' => 'How far in advance should we book?',
                'answer' => 'At least 6 weeks before your event date.',
            ]]],
        ]);

        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]));

        $profile = \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->sole();
        $this->assertSame([
            ['quote' => 'They made our wedding unforgettable!', 'author_name' => 'Jamie Rivera', 'author_title' => 'Bride'],
        ], $profile->testimonials);

        $website = $response->website;
        $faqPage = $website->fresh()->pages()->where('slug', 'photo-booth-faq')->sole();
        $faqSection = collect($faqPage->sections)->firstWhere('type', 'faq');
        $this->assertNotNull($faqSection);
        $matchingFaqItem = collect($faqSection['data']['items'])->firstWhere('question', 'How far in advance should we book?');
        $this->assertNotNull($matchingFaqItem, 'The exact customer-entered FAQ question must appear in the generated FAQ section, verbatim.');
        $this->assertSame('At least 6 weeks before your event date.', $matchingFaqItem['answer']);
    }

    public function test_empty_optional_testimonials_and_faq_lists_remain_valid_and_generate_successfully(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindPlanEchoingAiClient();

        $this->completeAllRequiredSteps($workspace, $business, [
            'testimonials' => ['items' => []],
            'faq_items' => ['items' => []],
        ]);

        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]));

        $this->assertSame('completed', QuestionnaireResponse::where('business_id', $business->id)->sole()->status->value);
    }

    // ------------------------------------------------------------------
    // Website creation flow — "a Website row is not a created website".
    // WebsiteCreationStateResolver is the single authority; these tests
    // pin every entry point to it.
    // ------------------------------------------------------------------

    private function websiteShowUrl(object $workspace, object $business): string
    {
        return route('customer.workspaces.businesses.website.show', [$workspace->uid, $business->uid]);
    }

    public function test_a_shell_website_with_zero_pages_and_no_answers_is_not_a_created_website(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->createWebsite($business);

        // Precondition: the shell exists, with no pages and no session.
        $this->assertSame(1, Website::where('business_id', $business->id)->count());

        $this->get($this->websiteShowUrl($workspace, $business))
            ->assertOk()
            ->assertSee('Create my website')
            ->assertDontSee('Manage pages')
            ->assertDontSee('Connect a domain')
            ->assertDontSee('Edit setup answers')
            ->assertDontSee('Rebuild from setup answers')
            ->assertDontSee('Publish');
    }

    public function test_start_with_a_leftover_shell_and_no_session_adopts_the_shell_and_starts_not_studio(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $shell = $this->createWebsite($business);

        $this->get(route('customer.workspaces.businesses.website.setup.start', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']));

        $this->assertSame(1, Website::where('business_id', $business->id)->count(), 'The leftover shell is adopted, never duplicated.');
        $this->assertSame('photo_booth_modern', $shell->fresh()->template_key);

        $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'template']))
            ->assertOk();
    }

    public function test_choosing_a_template_adopts_a_leftover_shell_instead_of_creating_a_second_website(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $shell = $this->createWebsite($business);

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern'])
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']));

        $this->assertSame(1, Website::where('business_id', $business->id)->count());
        $this->assertSame($shell->id, Website::where('business_id', $business->id)->sole()->id);
        $this->assertSame(1, QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->count());
    }

    public function test_repeated_start_and_template_submissions_never_duplicate_the_website_or_the_session(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $startUrl = route('customer.workspaces.businesses.website.setup.start', [$workspace->uid, $business->uid]);
        $templateUrl = route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]);

        $this->get($startUrl);
        $this->get($startUrl);
        $this->post($templateUrl, ['template_key' => 'photo_booth_modern']);
        $this->post($templateUrl, ['template_key' => 'photo_booth_modern']);
        $this->get($startUrl)
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']));
        $this->get($this->websiteShowUrl($workspace, $business))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']));

        $this->assertSame(1, Website::where('business_id', $business->id)->count());
        $this->assertSame(1, QuestionnaireResponse::where('business_id', $business->id)->count());
    }

    public function test_a_session_sitting_at_the_final_question_lands_on_the_review_screen_not_studio(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->completeAllRequiredSteps($workspace, $business);

        $reviewUrl = route('customer.workspaces.businesses.website.setup.review', [$workspace->uid, $business->uid]);
        $this->get($this->websiteShowUrl($workspace, $business))->assertRedirect($reviewUrl);
        $this->get(route('customer.workspaces.businesses.website.setup.start', [$workspace->uid, $business->uid]))->assertRedirect($reviewUrl);
    }

    public function test_the_review_screen_summarises_answers_with_labels_and_offers_generate_and_back(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->completeAllRequiredSteps($workspace, $business);

        $this->get(route('customer.workspaces.businesses.website.setup.review', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Review your answers')
            ->assertSee('About your business')
            ->assertSee('Services &amp; packages', false)
            ->assertSee('Correction Round Photo Booth')
            // select answers show the human label, never the stored key
            ->assertSee('Request a quote')
            ->assertSee('Playful &amp; fun', false)
            ->assertDontSee('quote_request')
            ->assertSee('Generate my website')
            ->assertSee('Back and edit')
            ->assertDontSee('Manage pages');
    }

    public function test_when_ai_is_unavailable_generation_fails_honestly_and_never_enters_studio_or_completes_the_response(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        // The testing environment has OPENAI_ACTIVE=false and no AI client
        // is mocked: this exercises the real "AI is switched off" refusal.
        config(['services.openai.active' => false]);

        $response = $this->completeAllRequiredSteps($workspace, $business);

        $this->get(route('customer.workspaces.businesses.website.setup.review', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee("Website generation isn't available in this environment right now.", false);

        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.review', [$workspace->uid, $business->uid]))
            ->assertSessionHas('message', fn ($m) => str_contains($m, 'isn\'t available in this environment'));

        $website = Website::where('business_id', $business->id)->sole();
        $this->assertSame(0, $website->pages()->count(), 'A failed generation must not leave a half-built site.');

        $still = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame($response->id, $still->id);
        $this->assertSame('in_progress', $still->status->value, 'A failed generation must never mark setup completed.');

        // Website entry still lands on the review screen — never an empty Studio.
        $this->get($this->websiteShowUrl($workspace, $business))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.review', [$workspace->uid, $business->uid]));

        // ...and it offers a retry plus the preserved answers.
        $this->get(route('customer.workspaces.businesses.website.setup.review', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Try again')
            ->assertSee('Correction Round Photo Booth');
    }

    public function test_a_failed_generation_shows_try_again_and_the_retry_succeeds(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindPlanEchoingAiClientThatFailsOnce();
        $this->completeAllRequiredSteps($workspace, $business);

        $reviewUrl = route('customer.workspaces.businesses.website.setup.review', [$workspace->uid, $business->uid]);

        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect($reviewUrl);
        $this->assertSame(0, Website::where('business_id', $business->id)->sole()->pages()->count());
        $this->assertSame('in_progress', QuestionnaireResponse::where('business_id', $business->id)->sole()->status->value);

        $this->get($reviewUrl)->assertOk()->assertSee('Try again');

        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]));
        $this->assertGreaterThan(0, Website::where('business_id', $business->id)->sole()->pages()->count());
        $this->assertSame('completed', QuestionnaireResponse::where('business_id', $business->id)->sole()->status->value);
    }

    public function test_a_generated_website_lands_in_studio_with_the_rebuild_wording(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindPlanEchoingAiClient();
        $this->completeAllRequiredSteps($workspace, $business);
        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]));

        // The overview offers only Preview / Publish / Edit; the management actions live under Settings.
        $this->get($this->websiteShowUrl($workspace, $business))
            ->assertOk()
            ->assertSee('data-testid="website-overview"', false)
            ->assertSee('Preview')
            ->assertSee('Publish')
            ->assertSee('Edit')
            ->assertDontSee('data-testid="settings-pages"', false)
            ->assertDontSee('Rebuild from setup answers')
            ->assertDontSee('Regenerate with AI');

        $this->get(route('customer.workspaces.businesses.website.studio.show', [$workspace->uid, $business->uid, 'settings']))
            ->assertOk()
            ->assertSee('Manage pages')
            ->assertSee('Edit setup answers')
            ->assertSee('Change template or rebuild')
            ->assertSee('History')
            ->assertSee('Domain')
            ->assertDontSee('Regenerate with AI');

        // Start on a generated site goes to Studio, never back into the wizard.
        $this->get(route('customer.workspaces.businesses.website.setup.start', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.studio.show', [$workspace->uid, $business->uid]));
    }

    public function test_the_pages_and_preview_screens_redirect_to_creation_when_there_are_zero_pages(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->createWebsite($business);

        $this->get(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]))
            ->assertRedirect($this->websiteShowUrl($workspace, $business));
        $this->get(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]))
            ->assertRedirect($this->websiteShowUrl($workspace, $business));
    }

    public function test_a_website_with_zero_pages_cannot_be_published(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $website = $this->createWebsite($business);

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertRedirect($this->websiteShowUrl($workspace, $business))
            ->assertSessionHas('status', 'error');

        $website->refresh();
        $this->assertSame('draft', $website->status->value);
        $this->assertNull($website->published_revision_id);
    }

    public function test_a_completed_setup_whose_pages_were_all_deleted_returns_to_generate_without_a_redirect_loop(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindPlanEchoingAiClient();
        $this->completeAllRequiredSteps($workspace, $business);
        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]));

        $website = Website::where('business_id', $business->id)->sole();
        $website->pages()->delete();

        // Entry resolves to the review screen...
        $reviewUrl = route('customer.workspaces.businesses.website.setup.review', [$workspace->uid, $business->uid]);
        $this->get($this->websiteShowUrl($workspace, $business))->assertRedirect($reviewUrl);

        // ...which renders (reopening the completed response) instead of bouncing back.
        $this->get($reviewUrl)->assertOk()->assertSee('Generate my website');
        $this->assertSame(1, QuestionnaireResponse::where('business_id', $business->id)->count());
        $this->assertSame('in_progress', QuestionnaireResponse::where('business_id', $business->id)->sole()->status->value);

        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]));
        $this->assertGreaterThan(0, $website->fresh()->pages()->count());
    }
}
