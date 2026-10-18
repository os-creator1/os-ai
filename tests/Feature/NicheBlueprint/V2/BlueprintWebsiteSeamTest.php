<?php

namespace Tests\Feature\NicheBlueprint\V2;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Library\Website\GuidedGeneration\GuidedWebsiteGenerationClient;
use App\Library\Website\WebsiteBlueprintDefaults;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteTemplate;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV2Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\NicheBlueprint\V2\Support\SeedsPhotoBoothBlueprintV2;
use Tests\TestCase;

/**
 * The Website module consuming BlueprintConfigReader::websiteConfig(): the wizard pre-selects the
 * Blueprint's preferred template, guided generation receives niche hints, and neither ever
 * writes to (or overrides) an existing Website.
 */
class BlueprintWebsiteSeamTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPhotoBoothBlueprintV2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPhotoBoothBlueprintV2();
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class);
    }

    private function templateStep($customer, Business $business)
    {
        $this->authenticateAs($customer);

        return $this->get(route('customer.workspaces.businesses.website.setup.step', [
            \App\Models\Workspace::query()->findOrFail($business->workspace_id)->uid, $business->uid, 'template',
        ]));
    }

    private function checkedKey(string $html): ?string
    {
        preg_match_all('/<input[^>]*name="template_key"[^>]*value="([^"]+)"[^>]*>/', $html, $inputs);

        foreach ($inputs[0] as $i => $tag) {
            if (preg_match('/\bchecked\b/', $tag)) {
                return $inputs[1][$i];
            }
        }

        return null;
    }

    public function test_a_fresh_photo_booth_business_starts_the_picker_on_the_blueprints_template(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Fresh Booth', 'Fresh WS');
        $this->assertSame('photo_booth_editorial', app(BlueprintConfigReader::class)->websiteConfig($business)['template_key']);

        $html = $this->templateStep($customer, $business)->assertOk()->getContent();

        $this->assertSame('photo_booth_editorial', $this->checkedKey($html));
        $this->assertSame(0, Website::query()->where('business_id', $business->id)->count(), 'a suggestion creates no Website');
    }

    public function test_a_business_with_no_blueprint_gets_the_pickers_ordinary_default(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Plain Co', 'Plain WS');
        DB::table('business_blueprint_component_installations')->where('business_id', $business->id)->delete();
        $this->assertNull(app(BlueprintConfigReader::class)->websiteConfig($business));

        $html = $this->templateStep($customer, $business)->assertOk()->getContent();

        $this->assertSame(WebsiteTemplate::query()->where('is_active', true)->orderBy('key')->value('key'), $this->checkedKey($html), 'first template, exactly as before');
    }

    public function test_an_existing_websites_own_template_is_never_replaced_by_the_blueprint(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Styled Booth', 'Styled WS');
        $website = Website::create(['business_id' => $business->id, 'name' => 'Styled', 'template_key' => 'photo_booth_luxury']);

        $html = $this->templateStep($customer, $business)->assertOk()->getContent();

        $this->assertSame('photo_booth_luxury', $this->checkedKey($html), 'the shell keeps its own style');
        $this->assertSame('photo_booth_luxury', $website->fresh()->template_key);
    }

    private function startSetup($customer, Business $business)
    {
        $this->authenticateAs($customer);

        return $this->get(route('customer.workspaces.businesses.website.setup.start', [
            \App\Models\Workspace::query()->findOrFail($business->workspace_id)->uid, $business->uid,
        ]));
    }

    public function test_a_brand_new_setup_begins_on_the_blueprints_template_not_just_a_pre_ticked_picker(): void
    {
        // Setup no longer opens on a style question: it starts on the niche default and the owner changes the
        // look on Review. The Blueprint's preferred template must be that starting point for a new Website.
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Start Booth', 'Start WS');

        $this->startSetup($customer, $business)->assertRedirect();

        $this->assertSame('photo_booth_editorial', Website::query()->where('business_id', $business->id)->sole()->template_key);
    }

    public function test_a_setup_for_a_business_with_no_blueprint_still_starts_on_template_one(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Plain Start Co', 'Plain Start WS');
        DB::table('business_blueprint_component_installations')->where('business_id', $business->id)->delete();

        $this->startSetup($customer, $business)->assertRedirect();

        $this->assertSame('photo_booth_modern', Website::query()->where('business_id', $business->id)->sole()->template_key, 'Template 1, exactly as before');
    }

    public function test_a_shell_that_already_has_a_style_keeps_it_when_setup_starts(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Chosen Booth', 'Chosen WS');
        $website = Website::create(['business_id' => $business->id, 'name' => 'Chosen', 'template_key' => 'photo_booth_luxury']);

        $this->startSetup($customer, $business)->assertRedirect();

        $this->assertSame('photo_booth_luxury', $website->fresh()->template_key, 'a Blueprint never replaces a style the owner already has');
        $this->assertSame(1, Website::query()->where('business_id', $business->id)->count());
    }

    public function test_a_preferred_template_the_website_does_not_offer_is_ignored(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Mismatch Booth', 'Mismatch WS');
        $defaults = app(WebsiteBlueprintDefaults::class);

        $this->assertSame('photo_booth_editorial', $defaults->preferredTemplateKey($business, WebsiteTemplate::query()->get()));
        $this->assertNull($defaults->preferredTemplateKey($business, WebsiteTemplate::query()->where('key', '!=', 'photo_booth_editorial')->get()));
        $this->assertNull($defaults->preferredTemplateKey($business, []));
    }

    public function test_generation_hints_are_translated_to_the_websites_vocabulary_and_never_exceed_the_plan(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Hint Booth', 'Hint WS');
        $plan = [
            ['page_key' => 'home', 'page_type' => 'home', 'allowed_section_types' => ['hero', 'services', 'image_text', 'testimonials', 'faq', 'cta', 'contact_details', 'gallery']],
            ['page_key' => 'packages', 'page_type' => 'packages', 'allowed_section_types' => ['hero', 'services', 'cta', 'contact_details']],
            ['page_key' => 'about', 'page_type' => 'about', 'allowed_section_types' => ['hero', 'text']],
            ['page_key' => 'contact', 'page_type' => 'contact', 'allowed_section_types' => ['hero', 'contact_details', 'form']],
        ];

        $hints = app(WebsiteBlueprintDefaults::class)->generationHints($business, $plan);

        $this->assertNotNull($hints);
        $this->assertCount(3, $hints['content_prompts']);
        // Blueprint "packages" -> Website "services"; "add_ons" collapses into the same; "faq" is not allowed on Packages.
        $this->assertSame(['hero', 'services', 'gallery', 'testimonials', 'cta'], $hints['suggested_sections']['home']);
        $this->assertSame(['services'], $hints['suggested_sections']['packages']);
        $this->assertSame(['form', 'contact_details'], $hints['suggested_sections']['contact']);
        $this->assertArrayNotHasKey('about', $hints['suggested_sections'], 'the Blueprint has no About page: nothing is invented for it');
        $this->assertArrayNotHasKey('gallery', $hints['suggested_sections'], 'a page that is not in the Website plan is not suggested');
    }

    public function test_the_generation_prompt_carries_niche_defaults_only_for_a_business_that_has_them(): void
    {
        [, $withBlueprint] = $this->tenant(WorkspacePlanTier::Growth, 'With Booth', 'With WS');
        [, $without] = $this->tenant(WorkspacePlanTier::Growth, 'Without Co', 'Without WS');
        DB::table('business_blueprint_component_installations')->where('business_id', $without->id)->delete();

        $plan = [['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'allowed_section_types' => ['hero', 'services'], 'entity' => null]];
        $build = new \ReflectionMethod(GuidedWebsiteGenerationClient::class, 'buildMessages');
        $build->setAccessible(true);
        $client = app(GuidedWebsiteGenerationClient::class);

        $with = $build->invoke($client, $withBlueprint, $plan);
        $without = $build->invoke($client, $without, $plan);

        $this->assertArrayHasKey('niche_defaults', json_decode($with[1]['content'], true));
        $this->assertStringContainsString('niche_defaults', $with[0]['content']);
        $this->assertArrayNotHasKey('niche_defaults', json_decode($without[1]['content'], true));
        $this->assertStringNotContainsString('niche_defaults', $without[0]['content'], 'the prompt is unchanged without a Blueprint');
    }
}
