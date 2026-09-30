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
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]));

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
}
