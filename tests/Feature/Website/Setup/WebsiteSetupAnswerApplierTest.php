<?php

namespace Tests\Feature\Website\Setup;

use App\Enums\Questionnaire\QuestionnaireResponseStatus;
use App\Library\Website\Setup\WebsiteSetupAnswerApplier;
use App\Models\BusinessBackdrop;
use App\Models\BusinessService;
use App\Models\BusinessKnowledgeProfile;
use App\Models\CatalogItem;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireResponse;
use App\Models\QuestionnaireVersion;
use App\Models\WebsiteForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Builder redesign — proves WebsiteSetupAnswerApplier writes into
 * REAL canonical Business OS records (never a website-only copy), and
 * that repeatable-entry re-application (packages/backdrops/services) is
 * idempotent by source key — the mechanism "Edit setup answers" depends
 * on to update rather than duplicate.
 */
class WebsiteSetupAnswerApplierTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function completedResponse($business, $website, array $steps, array $answers): QuestionnaireResponse
    {
        $definition = QuestionnaireDefinition::create(['key' => 'applier_test_' . uniqid('', true), 'name' => 'Applier Test']);
        $version = QuestionnaireVersion::create([
            'questionnaire_definition_id' => $definition->id,
            'version_number' => 1,
            'state' => 'published',
            'definition' => ['steps' => $steps],
            'published_at' => now(),
        ]);

        return QuestionnaireResponse::create([
            'business_id' => $business->id,
            'website_id' => $website->id,
            'questionnaire_definition_id' => $definition->id,
            'questionnaire_version_id' => $version->id,
            'status' => QuestionnaireResponseStatus::Completed,
            'answers' => $answers,
            'answers_revision' => 1,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    public function test_a_business_field_answer_updates_the_real_business_record(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $steps = [
            ['key' => 'biz_name', 'target_module' => 'business', 'target_field' => 'name'],
        ];
        $response = $this->completedResponse($business, $website, $steps, ['biz_name' => 'Renamed Photo Booth Co.']);

        app(WebsiteSetupAnswerApplier::class)->apply($business, $website, $response, $customer->user_id);

        $this->assertSame('Renamed Photo Booth Co.', $business->fresh()->name);
    }

    public function test_a_package_answer_creates_a_real_catalog_item_never_a_website_only_copy(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $steps = [
            ['key' => 'packages', 'target_module' => 'catalog_item'],
        ];
        $answers = ['packages' => [
            ['key' => 'pkg_1', 'name' => 'Wedding Package', 'description' => 'Great for weddings.', 'price_minor' => 89500, 'currency_code' => 'USD', 'featured' => true, 'features' => ['Unlimited prints', 'Props included']],
        ]];
        $response = $this->completedResponse($business, $website, $steps, $answers);

        app(WebsiteSetupAnswerApplier::class)->apply($business, $website, $response, $customer->user_id);

        $item = CatalogItem::where('business_id', $business->id)->sole();
        $this->assertSame('Wedding Package', $item->name);
        $this->assertSame(89500, $item->price_minor);
        $this->assertTrue($item->featured);
        $this->assertStringContainsString('Unlimited prints', $item->description);
        $this->assertSame('pkg_1', $item->source_questionnaire_item_key);
    }

    public function test_reapplying_the_same_package_answer_updates_rather_than_duplicates(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $steps = [['key' => 'packages', 'target_module' => 'catalog_item']];
        $applier = app(WebsiteSetupAnswerApplier::class);

        $first = $this->completedResponse($business, $website, $steps, ['packages' => [
            ['key' => 'pkg_1', 'name' => 'Wedding Package', 'description' => null, 'price_minor' => 89500, 'currency_code' => 'USD', 'featured' => false, 'features' => []],
        ]]);
        $applier->apply($business, $website, $first, $customer->user_id);

        $second = $this->completedResponse($business, $website, $steps, ['packages' => [
            ['key' => 'pkg_1', 'name' => 'Wedding Package — Updated', 'description' => null, 'price_minor' => 99500, 'currency_code' => 'USD', 'featured' => true, 'features' => []],
        ]]);
        $applier->apply($business, $website, $second, $customer->user_id);

        $this->assertSame(1, CatalogItem::where('business_id', $business->id)->count(), 'Re-applying must update the existing package, never create a second one.');
        $item = CatalogItem::where('business_id', $business->id)->sole();
        $this->assertSame('Wedding Package — Updated', $item->name);
        $this->assertSame(99500, $item->price_minor);
        $this->assertTrue($item->featured);
    }

    public function test_a_backdrop_answer_creates_a_business_scoped_backdrop(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $steps = [['key' => 'backdrops', 'target_module' => 'backdrop']];
        $answers = ['backdrops' => [
            ['key' => 'bd_1', 'name' => 'Sequin Wall', 'description' => 'Shimmering gold.', 'availability' => true, 'images' => []],
        ]];
        $response = $this->completedResponse($business, $website, $steps, $answers);

        app(WebsiteSetupAnswerApplier::class)->apply($business, $website, $response, $customer->user_id);

        $backdrop = BusinessBackdrop::where('business_id', $business->id)->sole();
        $this->assertSame('Sequin Wall', $backdrop->name);
        $this->assertTrue($backdrop->availability);
    }

    public function test_reapplying_the_same_backdrop_answer_updates_rather_than_duplicates(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $steps = [['key' => 'backdrops', 'target_module' => 'backdrop']];
        $applier = app(WebsiteSetupAnswerApplier::class);

        $first = $this->completedResponse($business, $website, $steps, ['backdrops' => [
            ['key' => 'bd_1', 'name' => 'Sequin Wall', 'description' => null, 'availability' => true, 'images' => []],
        ]]);
        $applier->apply($business, $website, $first, $customer->user_id);

        $second = $this->completedResponse($business, $website, $steps, ['backdrops' => [
            ['key' => 'bd_1', 'name' => 'Sequin Wall (Gold)', 'description' => null, 'availability' => false, 'images' => []],
        ]]);
        $applier->apply($business, $website, $second, $customer->user_id);

        $this->assertSame(1, BusinessBackdrop::where('business_id', $business->id)->count());
        $backdrop = BusinessBackdrop::where('business_id', $business->id)->sole();
        $this->assertSame('Sequin Wall (Gold)', $backdrop->name);
        $this->assertFalse($backdrop->availability);
    }

    public function test_a_services_answer_creates_real_business_service_rows_idempotently(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $steps = [['key' => 'services', 'target_module' => 'business_service']];
        $applier = app(WebsiteSetupAnswerApplier::class);

        $first = $this->completedResponse($business, $website, $steps, ['services' => [
            ['key' => 'svc_1', 'name' => 'Open-Air DSLR Booth', 'description' => null, 'starting_price' => null, 'currency_code' => null],
        ]]);
        $applier->apply($business, $website, $first, $customer->user_id);

        $second = $this->completedResponse($business, $website, $steps, ['services' => [
            ['key' => 'svc_1', 'name' => 'Open-Air DSLR Booth (Premium)', 'description' => null, 'starting_price' => null, 'currency_code' => null],
        ]]);
        $applier->apply($business, $website, $second, $customer->user_id);

        $this->assertSame(1, BusinessService::where('business_id', $business->id)->count());
        $this->assertSame('Open-Air DSLR Booth (Premium)', BusinessService::where('business_id', $business->id)->sole()->name);
    }

    public function test_a_form_answer_upserts_the_websites_real_form_never_a_second_one(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $steps = [['key' => 'contact_form', 'target_module' => 'website_form']];
        $answers = ['contact_form' => ['required_fields' => ['name', 'phone', 'email']]];
        $response = $this->completedResponse($business, $website, $steps, $answers);

        app(WebsiteSetupAnswerApplier::class)->apply($business, $website, $response, $customer->user_id);

        $form = WebsiteForm::where('website_id', $website->id)->sole();
        $this->assertSame($business->id, $form->business_id);
        $emailField = collect($form->fields)->firstWhere('key', 'email');
        $this->assertTrue($emailField['required']);
        $eventDateField = collect($form->fields)->firstWhere('key', 'event_date');
        $this->assertFalse($eventDateField['required']);
    }

    public function test_a_knowledge_profile_answer_writes_through_the_canonical_manager(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $steps = [['key' => 'brand_voice', 'target_module' => 'knowledge_profile', 'target_field' => 'brand_voice']];
        $response = $this->completedResponse($business, $website, $steps, ['brand_voice' => 'playful']);

        app(WebsiteSetupAnswerApplier::class)->apply($business, $website, $response, $customer->user_id);

        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $this->assertSame('playful', $profile?->brand_voice);
    }
}
