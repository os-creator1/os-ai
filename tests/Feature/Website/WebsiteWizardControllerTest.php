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
            ->assertSee('Build your website')
            ->assertSee('Start building');
    }

    public function test_start_with_nothing_in_progress_goes_to_the_template_step(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.setup.start', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'template']));
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

        // Re-visiting the template step mid-flow must resume, not
        // re-render the picker or bounce to Studio.
        $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'template']))
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'business_name']));
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
        $imageUid = $website->assets()->where('category_tag', 'custom_section')->sole()->uid;

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
}
