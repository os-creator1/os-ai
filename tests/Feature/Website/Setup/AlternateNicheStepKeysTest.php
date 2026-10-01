<?php

namespace Tests\Feature\Website\Setup;

use App\Library\Website\Setup\QuestionnaireResolver;
use App\Library\Website\Setup\QuestionnaireVersionPublisher;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round 4 (item 4) — proves the wizard's
 * gallery/custom-section/FAQ handling genuinely resolves each step's KEY
 * from its target_module on the response's own PINNED version, never from
 * a hardcoded 'gallery'/'custom_section'/'faq_items' literal (the
 * Photobooth-only assumption the previous implementation made). This
 * fixture deliberately names those three steps something else entirely,
 * for a niche OTHER than Photobooth, and drives the complete autosave/
 * upload/Improve/generate path through them end to end — gallery upload,
 * custom-section image upload, Improve with AI, FAQ autosave, and final
 * generation all correctly resolving and binding through these
 * differently-named steps.
 */
class AlternateNicheStepKeysTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private const NICHE_KEY = 'event_services';

    private function alternateNicheTemplate(): WebsiteTemplate
    {
        $this->seed(WebsiteTemplateSeeder::class);
        $base = WebsiteTemplate::where('key', 'photo_booth_modern')->firstOrFail();

        return WebsiteTemplate::create([
            'key' => 'event_services_classic',
            'niche_key' => self::NICHE_KEY,
            'display_name' => 'Classic',
            'description' => 'Alternate-niche fixture template (independent-review correction round 4, item 4).',
            'theme' => $base->theme,
            'page_manifest' => $base->page_manifest,
            'manifest_version' => $base->manifest_version,
            'is_active' => true,
        ]);
    }

    private function publishAlternateDefinition(): void
    {
        $definition = QuestionnaireDefinition::create([
            'scope' => QuestionnaireResolver::SCOPE,
            'niche_key' => self::NICHE_KEY,
            'name' => 'Event Services Website Setup',
        ]);

        // Deliberately NOT 'gallery'/'custom_section'/'faq_items' — the
        // whole point of this fixture.
        $steps = [
            ['key' => 'biz_name', 'prompt' => 'What is your business called?', 'help_text' => null, 'input_type' => 'text', 'required' => true, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'business', 'target_field' => 'name', 'ai_instructions' => null],
            ['key' => 'event_gallery', 'prompt' => 'Show off your events', 'help_text' => null, 'input_type' => 'photo_upload', 'required' => false, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'gallery', 'target_field' => null, 'ai_instructions' => null],
            ['key' => 'promo_block', 'prompt' => 'Add a promo section', 'help_text' => null, 'input_type' => 'repeatable_group', 'required' => false, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'custom_section', 'target_field' => null, 'ai_instructions' => null],
            ['key' => 'questions_people_ask', 'prompt' => 'Answer common questions', 'help_text' => null, 'input_type' => 'repeatable_group', 'required' => false, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'faq', 'target_field' => null, 'ai_instructions' => null],
        ];

        $publisher = app(QuestionnaireVersionPublisher::class);
        $publisher->publish($publisher->createDraft($definition, $steps));
    }

    public function test_the_complete_autosave_upload_improve_and_generate_path_works_for_a_niche_with_differently_named_steps(): void
    {
        $template = $this->alternateNicheTemplate();
        $this->publishAlternateDefinition();

        [$customer, $business, $workspace] = $this->entitledTenant(['industry' => self::NICHE_KEY]);
        $this->authenticateAsCustomer($customer);
        $params = fn (string $step) => [$workspace->uid, $business->uid, $step];

        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function (array $messages) {
            $userMessage = collect($messages)->firstWhere('role', 'user')['content'] ?? '{}';
            $jsonStart = strpos($userMessage, '{');
            $payload = json_decode($jsonStart !== false ? substr($userMessage, $jsonStart) : '{}', true) ?? [];

            // The custom-section Improve call has a different, flatter
            // shape (current_body) than the full-generation plan call
            // (plan) — branch on which one this is.
            if (array_key_exists('current_body', $payload)) {
                return json_encode(['body' => 'Improved: ' . $payload['current_body']]);
            }

            $plan = $payload['plan'] ?? [];
            $pages = collect($plan)->map(fn (array $page) => [
                'page_key' => $page['page_key'],
                'title' => ucwords(str_replace(['_', ':'], ' ', explode(':', $page['page_key'])[0])),
                'seo_title' => 'Alt niche | ' . $page['page_key'],
                'meta_description' => 'Alt niche generated content for ' . $page['page_key'] . '.',
                'sections' => [['type' => 'hero', 'data' => ['heading' => $page['page_key'], 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]]],
            ])->values()->all();

            return json_encode(['pages' => $pages]);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => $template->key])
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', $params('biz_name')));

        $freshRevision = fn () => QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole()->answers_revision;

        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('biz_name')), ['value' => 'Alt Niche Events', 'answers_revision' => $freshRevision()])
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', $params('event_gallery')));

        $website = Website::where('business_id', $business->id)->sole();

        // Gallery upload must resolve the ALTERNATE 'event_gallery' key —
        // never a hardcoded 'gallery' literal.
        $this->post(route('customer.workspaces.businesses.website.setup.gallery.upload', [$workspace->uid, $business->uid]), [
            'photos' => [$this->fakeImageUpload('event1.png')],
        ])->assertRedirect(route('customer.workspaces.businesses.website.setup.step', $params('event_gallery')));

        $this->assertSame(1, WebsiteAsset::where('website_id', $website->id)->where('purpose', 'gallery')->count());

        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('event_gallery')), ['value' => null, 'answers_revision' => $freshRevision()])
            ->assertRedirect(route('customer.workspaces.businesses.website.setup.step', $params('promo_block')));

        // Custom-section image upload must resolve the ALTERNATE
        // 'promo_block' key — never a hardcoded 'custom_section' literal.
        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.upload', [$workspace->uid, $business->uid]), [
            'photo' => $this->fakeImageUpload('promo.png'),
        ])->assertRedirect(route('customer.workspaces.businesses.website.setup.step', $params('promo_block')));

        $customSectionImageUid = WebsiteAsset::where('website_id', $website->id)->where('purpose', 'custom_section')->sole()->uid;
        $this->assertSame(1, WebsiteAsset::where('website_id', $website->id)->where('purpose', 'custom_section')->count());

        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('promo_block')), [
            'items' => [['name' => 'Big Sale', 'body' => 'Book now and save.', 'images' => [$customSectionImageUid]]],
            'answers_revision' => $freshRevision(),
        ])->assertRedirect(route('customer.workspaces.businesses.website.setup.step', $params('questions_people_ask')));

        // Improve with AI must also resolve the ALTERNATE 'promo_block'
        // key.
        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Big Sale', 'body' => 'Book now and save.', 'images' => [$customSectionImageUid]]],
            'answers_revision' => $freshRevision(),
        ])->assertSessionHas('status', 'success');

        $improvedResponse = QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole();
        $this->assertSame('Improved: Book now and save.', $improvedResponse->answer('promo_block')[0]['body']);

        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('questions_people_ask')), [
            'items' => [['question' => 'Do you travel?', 'answer' => 'Yes, statewide.']],
            'answers_revision' => $freshRevision(),
        ])->assertRedirect(route('customer.workspaces.businesses.website.setup.review', [$workspace->uid, $business->uid]));

        $this->post(route('customer.workspaces.businesses.website.setup.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]));

        $pages = $website->fresh()->pages()->get();
        $this->assertGreaterThan(0, $pages->count());

        $completed = QuestionnaireResponse::where('business_id', $business->id)->sole();
        $this->assertSame('completed', $completed->status->value);

        $allSections = $pages->flatMap(fn ($page) => $page->sections);

        $faqSection = $allSections->first(fn ($section) => ($section['type'] ?? null) === 'faq');
        $this->assertNotNull($faqSection, 'The FAQ answered under the alternate step key must still reach the generated FAQ page.');
        $this->assertTrue(collect($faqSection['data']['items'] ?? [])->contains(fn ($item) => $item['question'] === 'Do you travel?' && $item['answer'] === 'Yes, statewide.'));

        $customSection = $allSections->first(fn ($section) => ($section['type'] ?? null) === 'custom_section');
        $this->assertNotNull($customSection, 'The custom section answered under the alternate step key must still reach the generated page.');
        $this->assertSame('Big Sale', $customSection['data']['heading']);
        $this->assertSame('Improved: Book now and save.', $customSection['data']['body']);
        $this->assertCount(1, $customSection['data']['images'], 'The custom-section image uploaded under the alternate step key must still be bound.');

        // Rebuild (Studio's own action, not the wizard's generate()) must
        // also work cleanly against this alternate-niche Website —
        // proving item 4's "rebuild path still works" for a niche whose
        // steps are not named like Photobooth's.
        $rebuildAttempt = app(\App\Library\Website\GuidedGeneration\GuidedGenerationCommitService::class)->rebuild(
            $business->fresh(), $website->fresh(), $template, $customer->user_id, 'alt-niche-rebuild',
            null,
            \App\Library\Website\Setup\WizardPresentationAnswers::customSection($completed),
            \App\Library\Website\Setup\WizardPresentationAnswers::customerFaq($completed),
        );

        $this->assertSame(\App\Models\WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $rebuildAttempt->status);
        $rebuiltSections = $website->fresh()->pages()->get()->flatMap(fn ($page) => $page->sections);
        $this->assertNotNull($rebuiltSections->first(fn ($section) => ($section['type'] ?? null) === 'custom_section'), 'A rebuild must still carry the alternate-niche custom section through.');
        $this->assertNotNull($rebuiltSections->first(fn ($section) => ($section['type'] ?? null) === 'faq'), 'A rebuild must still carry the alternate-niche FAQ through.');
    }
}
