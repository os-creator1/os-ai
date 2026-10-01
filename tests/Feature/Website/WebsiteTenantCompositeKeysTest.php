<?php

namespace Tests\Feature\Website;

use App\Models\QuestionnaireResponse;
use App\Models\WebsiteForm;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round — `questionnaire_responses` and
 * `website_forms` each independently referenced `business_id` and a
 * Website, but nothing in the database proved the referenced Website
 * actually belonged to that same Business; only application-layer checks
 * did. Proves the new composite foreign keys
 * (`websites (id, business_id)`) now refuse both cross-Business
 * combinations at the database layer itself — not merely a service-layer
 * re-check that a forged or buggy raw write could bypass.
 */
class WebsiteTenantCompositeKeysTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_a_questionnaire_response_cannot_reference_another_businesses_website(): void
    {
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        [, $businessA] = $this->entitledTenant();
        [, $businessB] = $this->entitledTenant();
        $websiteB = $this->createWebsite($businessB);

        $definition = \App\Models\QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        $version = $definition->publishedVersion();

        $this->expectException(QueryException::class);

        QuestionnaireResponse::create([
            'business_id' => $businessA->id,
            'website_id' => $websiteB->id,
            'questionnaire_definition_id' => $definition->id,
            'questionnaire_version_id' => $version->id,
            'status' => 'in_progress',
            'answers' => [],
            'answers_revision' => 1,
            'started_at' => now(),
        ]);
    }

    public function test_a_website_form_cannot_claim_a_different_business_than_its_website(): void
    {
        [, $businessA] = $this->entitledTenant();
        [, $businessB] = $this->entitledTenant();
        $websiteB = $this->createWebsite($businessB);

        $this->expectException(QueryException::class);

        WebsiteForm::create([
            'website_id' => $websiteB->id,
            'business_id' => $businessA->id,
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Forged cross-business form',
            'fields' => [],
            'submit_label' => 'Submit',
        ]);
    }

    public function test_a_questionnaire_response_for_the_websites_own_business_is_accepted(): void
    {
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $definition = \App\Models\QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();

        $response = QuestionnaireResponse::create([
            'business_id' => $business->id,
            'website_id' => $website->id,
            'questionnaire_definition_id' => $definition->id,
            'questionnaire_version_id' => $definition->publishedVersion()->id,
            'status' => 'in_progress',
            'answers' => [],
            'answers_revision' => 1,
            'started_at' => now(),
        ]);

        $this->assertNotNull($response->id);
    }
}
