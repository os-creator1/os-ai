<?php

namespace Tests\Feature\Website\Setup;

use App\Library\Website\Setup\QuestionnaireDefinitionValidator;
use App\Library\Website\Setup\WebsiteSetupAnswerApplier;
use App\Library\Website\WebsitePageStrategy;
use App\Models\BusinessBackdrop;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessService;
use App\Models\BusinessServiceImage;
use App\Models\CatalogItem;
use App\Models\QuestionnaireDefinition;
use App\Models\Website;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV3Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\TestCase;

/**
 * The v3 questionnaire's content steps: booth services (presets, photos, stable
 * ids), event types, pricing, add-ons, backdrops, About / FAQ defaults,
 * advantages and the brand style — and that none of them create duplicates or
 * overwrite what the owner already confirmed.
 */
class WizardV3GuidedFlowTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGuidedWizard();
    }

    private function apply(): void
    {
        $response = $this->wizardSession();
        app(WebsiteSetupAnswerApplier::class)->apply($this->business->fresh(), Website::where('business_id', $this->business->id)->firstOrFail(), $response, (int) $this->customer->user_id);
    }

    private function card(string $key, string $name, array $extra = []): array
    {
        return array_merge(['key' => $key, 'name' => $name, 'description' => "About {$name}.", 'visible' => '1'], $extra);
    }

    // ------------------------------------------------------------- the definition

    public function test_the_v3_definition_is_valid_published_once_and_leaves_older_versions_alone(): void
    {
        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();

        (new QuestionnaireDefinitionValidator())->validate(PhotoboothWebsiteSetupQuestionnaireV3Seeder::steps());

        $this->assertSame(3, (int) $definition->publishedVersion()->version_number);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class); // idempotent
        $this->assertSame([1, 2, 3], $definition->versions()->orderBy('version_number')->pluck('version_number')->map(fn ($n) => (int) $n)->all());

        // v1 and v2 are immutable history: their stored step trees are still the originals.
        $this->assertSame(PhotoboothWebsiteSetupQuestionnaireSeeder::steps()[0]['key'], $definition->versions()->where('version_number', 1)->first()->steps()[0]['key']);
    }

    public function test_every_question_but_the_basics_has_a_page_of_its_own(): void
    {
        $this->tenant();

        $screens = [];
        foreach ($this->wizardSession()->version->steps() as $step) {
            $screens[$step['screen'] ?? $step['key']][] = $step['key'];
        }

        foreach ($screens as $screen => $keys) {
            $this->assertCount($screen === 'basics' ? 4 : 1, $keys, "Screen {$screen} holds one question (only the basics are grouped).");
        }
    }

    public function test_the_screen_count_follows_the_owners_answers(): void
    {
        $this->tenant();

        $withEverything = $this->visibleScreens();
        $this->assertContains('packages', $withEverything);
        $this->assertNotContains('backdrops', $this->visibleScreens(), 'Backdrops are not asked about until the owner says they offer them.');

        $this->continueStep('pricing_strategy', ['value' => 'custom_quote'])->assertOk();
        $quoteOnly = $this->visibleScreens();
        $this->assertNotContains('packages', $quoteOnly, 'Quote-only pricing has no predefined packages.');
        $this->assertSame(count($withEverything) - 1, count($quoteOnly));

        $this->continueStep('offers_backdrops', ['value' => '1'])->assertOk();
        $this->assertContains('backdrops', $this->visibleScreens());
        $this->assertSame(count($quoteOnly) + 1, count($this->visibleScreens()));

        $page = $this->get($this->url('step', 'business_name'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Step 1 of ' . (count($this->visibleScreens()) + 1) . '/', $page, 'The progress total is the real number of visible screens plus the review.');
    }

    // ----------------------------------------------------------- booth services

    public function test_the_booth_services_page_offers_presets_with_default_descriptions_and_selects_nothing(): void
    {
        $this->tenant();

        $html = $this->get($this->url('step', 'booth_services'))
            ->assertOk()
            ->assertSee('What photo booth experiences do you offer?')
            ->assertSee('data-preset-bar', false)
            ->assertSee('Open-Air Photo Booth')
            ->assertSee('360 Photo Booth')
            ->assertSee('Mirror Photo Booth')
            ->assertSee('Glam Photo Booth')
            ->assertSee('Enclosed Photo Booth')
            ->assertSee('Roaming Photo Booth')
            ->assertSee('Digital Photo Booth')
            ->assertSee('AI Photo Booth')
            ->assertSee('+ Add a custom service')
            ->assertSee('data-service-card', false)
            ->getContent();

        $this->assertSame(8, substr_count($html, 'data-preset-add data-preset-key'));
        $this->assertStringContainsString('data-preset-description="A modern, open-concept photo booth', $html);
        $this->assertSame(0, BusinessService::where('business_id', $this->business->id)->count(), 'Nothing is created or confirmed until the owner says so.');
    }

    public function test_services_photos_attach_to_the_service_by_id_and_survive_a_rename(): void
    {
        $this->tenant();
        $path = $this->uploadPhoto('open-air');

        $this->continueStep('booth_services', ['items' => [
            $this->card('k-open', 'Open-Air Photo Booth', ['preset' => 'open_air', 'image_path' => $path, 'features' => ['Props included'], 'price' => '399', 'currency_code' => 'usd']),
            $this->card('k-360', '360 Photo Booth', ['preset' => 'three_sixty']),
        ]])->assertOk();

        $this->apply();

        $open = BusinessService::where('business_id', $this->business->id)->where('source_questionnaire_item_key', 'k-open')->firstOrFail();
        $this->assertSame('service', $open->kind);
        $this->assertTrue($open->website_visible);
        $this->assertSame('399.00', (string) $open->starting_price);
        $this->assertStringContainsString('- Props included', (string) $open->description, 'Confirmed features ride in the description.');
        $this->assertSame([$path], BusinessServiceImage::where('business_service_id', $open->id)->pluck('path')->all());
        $this->assertTrue((bool) BusinessServiceImage::where('business_service_id', $open->id)->value('is_cover'));
        $this->assertCount(0, BusinessServiceImage::where('business_service_id', BusinessService::where('source_questionnaire_item_key', 'k-360')->value('id'))->get(), 'A service without a photo has none — nothing is borrowed from another service.');

        // Rename it and re-apply: same record, same photo — never matched by name.
        $this->continueStep('booth_services', ['items' => [
            $this->card('k-open', 'Signature Open-Air Booth', ['preset' => 'open_air', 'image_path' => $path]),
            $this->card('k-360', '360 Photo Booth', ['preset' => 'three_sixty']),
        ]])->assertOk();
        $this->apply();

        $this->assertSame(2, BusinessService::where('business_id', $this->business->id)->count(), 'No duplicates.');
        $renamed = BusinessService::where('source_questionnaire_item_key', 'k-open')->firstOrFail();
        $this->assertSame($open->id, $renamed->id);
        $this->assertSame('Signature Open-Air Booth', $renamed->name);
        $this->assertSame([$path], BusinessServiceImage::where('business_service_id', $renamed->id)->pluck('path')->all());
    }

    public function test_removing_a_card_removes_the_service_from_the_website_without_deleting_it(): void
    {
        $this->tenant();
        $this->continueStep('booth_services', ['items' => [$this->card('a', 'Open-Air'), $this->card('b', 'Mirror')]])->assertOk();
        $this->apply();

        $this->continueStep('booth_services', ['items' => [$this->card('a', 'Open-Air')]])->assertOk();
        $this->apply();

        $names = app(WebsitePageStrategy::class)->eligibleServices($this->business->fresh())->pluck('name')->all();
        $this->assertSame(['Open-Air'], $names);
        $this->assertSame(1, BusinessService::where('business_id', $this->business->id)->where('status', 'inactive')->count(), 'The removed wizard-created service is deactivated, not deleted.');
    }

    public function test_a_card_can_be_hidden_from_the_website_without_leaving_motiongrove(): void
    {
        $this->tenant();
        $this->continueStep('booth_services', ['items' => [
            $this->card('a', 'Open-Air'),
            ['key' => 'b', 'name' => 'Mirror', 'description' => 'Mirror booth.'], // no "Show on my website" ticked
        ]])->assertOk();
        $this->apply();

        $mirror = BusinessService::where('source_questionnaire_item_key', 'b')->firstOrFail();
        $this->assertFalse($mirror->website_visible);
        $this->assertSame('active', $mirror->status->value);
        $this->assertSame(['Open-Air'], app(WebsitePageStrategy::class)->eligibleServices($this->business->fresh())->pluck('name')->all());
    }

    public function test_existing_confirmed_services_are_preselected_and_updated_in_place_never_duplicated(): void
    {
        $this->tenant();
        $existing = BusinessService::create([
            'business_id' => $this->business->id, 'name' => 'Mirror Photo Booth', 'slug' => 'mirror-photo-booth',
            'description' => 'Our mirror booth.', 'status' => 'active', 'sort_order' => 0,
        ]);

        $html = $this->get($this->url('step', 'booth_services'))->getContent();
        $this->assertStringContainsString('value="Mirror Photo Booth"', $html);
        $this->assertStringContainsString('value="svc:' . $existing->uid . '"', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html, 'The matching preset shows as already chosen.');

        // Confirming (and editing) the prefilled card updates THAT record.
        $this->continueStep('booth_services', ['items' => [
            $this->card('svc:' . $existing->uid, 'Mirror Photo Booth', ['description' => 'An updated description.', 'preset' => 'mirror']),
            $this->card('new1', 'Open-Air Photo Booth', ['preset' => 'open_air']),
        ]])->assertOk();
        $this->apply();

        $this->assertSame(2, BusinessService::where('business_id', $this->business->id)->count());
        $this->assertSame('An updated description.', $existing->fresh()->description);
        $this->assertNull($existing->fresh()->source_questionnaire_item_key, 'A manually created service stays manual.');
        $this->assertSame('mirror-photo-booth', $existing->fresh()->slug);
    }

    // -------------------------------------------------------------- event types

    public function test_event_types_are_presets_stored_as_event_services_and_never_become_booth_cards(): void
    {
        $this->tenant();

        $this->get($this->url('step', 'event_types'))
            ->assertOk()
            ->assertSee('What types of events do you serve?')
            ->assertSee('Weddings')->assertSee('Corporate Events')->assertSee('Birthdays')->assertSee('Graduations')
            ->assertSee('School Events')->assertSee('Holiday Parties')->assertSee('Baby Showers')
            ->assertSee('Brand Activations')->assertSee('Private Parties')
            ->assertSee('+ Add a custom event type');

        $this->continueStep('booth_services', ['items' => [$this->card('a', 'Open-Air')]])->assertOk();
        $this->continueStep('event_types', ['items' => [
            ['key' => 'e1', 'name' => 'Weddings', 'description' => 'Wedding fun.', 'preset' => 'weddings', 'visible' => '1'],
            ['key' => 'e2', 'name' => 'Quinceañeras', 'description' => 'Custom event.', 'visible' => '1'],
        ]])->assertOk();
        $this->apply();

        $strategy = app(WebsitePageStrategy::class);
        $this->assertSame(['Open-Air'], $strategy->eligibleServices($this->business->fresh())->pluck('name')->all());
        $this->assertSame(['Weddings', 'Quinceañeras'], $strategy->eligibleEventTypes($this->business->fresh())->pluck('name')->all());
        $this->assertSame(2, BusinessService::where('business_id', $this->business->id)->where('kind', 'event')->count());
    }

    // ------------------------------------------------------------------ pricing

    public function test_pricing_strategy_is_a_choice_that_never_invents_amounts(): void
    {
        $this->tenant();

        $this->get($this->url('step', 'pricing_strategy'))
            ->assertOk()
            ->assertSee('How do you price your photo booth services?')
            ->assertSee('Fixed package pricing')
            ->assertSee('Prices starting from an amount')
            ->assertSee('Custom quote');

        $this->continueStep('pricing_strategy', ['value' => 'bogus'])->assertStatus(422);
        $this->continueStep('pricing_strategy', ['value' => 'starting_from'])->assertOk();

        $this->assertSame('starting_from', \App\Library\Website\Setup\WizardPresentationAnswers::pricingStrategy($this->wizardSession()));
        $this->assertSame(0, CatalogItem::where('business_id', $this->business->id)->count());
    }

    public function test_quote_only_pricing_means_no_packages_not_every_catalog_item(): void
    {
        $this->tenant();
        CatalogItem::create(['business_id' => $this->business->id, 'type' => 'product', 'name' => 'Stray product', 'lifecycle_state' => 'active', 'position' => 0]);

        $this->assertNull(\App\Library\Website\Setup\WizardPresentationAnswers::catalogSelection($this->wizardSession()) , 'Not yet answered: the older "all items" default is untouched only when the step is visible.');

        $this->continueStep('pricing_strategy', ['value' => 'custom_quote'])->assertOk();

        $this->assertSame([], \App\Library\Website\Setup\WizardPresentationAnswers::catalogSelection($this->wizardSession()), 'The packages step is hidden, so the website shows no predefined packages.');
    }

    // ------------------------------------------------------------------ add-ons

    public function test_addons_are_optional_presets_published_only_when_confirmed(): void
    {
        $this->tenant();

        $this->get($this->url('step', 'addons'))
            ->assertOk()
            ->assertSee('Do you offer any extras?')
            ->assertSee('Extra hours')->assertSee('Guest book')->assertSee('Props')->assertSee('Custom print designs')
            ->assertSee('Additional prints')->assertSee('Custom branding')->assertSee('Backdrop upgrades')
            ->assertSee('Skip');

        $this->continueStep('addons', ['items' => []])->assertOk(); // skipping is allowed
        $this->continueStep('addons', ['items' => [['key' => 'x1', 'name' => 'Extra hours', 'description' => 'More time.', 'price' => '75', 'currency_code' => 'USD', 'preset' => 'extra_hours']]])->assertOk();
        $this->apply();

        $addon = CatalogItem::where('business_id', $this->business->id)->where('source_questionnaire_item_key', 'x1')->firstOrFail();
        $this->assertSame('product', $addon->type->value);
        $this->assertSame(7500, $addon->price_minor);
        $this->assertSame(1, CatalogItem::where('business_id', $this->business->id)->count(), 'Only the confirmed extra exists.');
    }

    // ---------------------------------------------------------------- backdrops

    public function test_backdrops_are_asked_about_first_and_a_no_skips_them_without_applying_stale_rows(): void
    {
        $this->tenant();
        $path = $this->uploadPhoto('floral');

        $this->continueStep('offers_backdrops', ['value' => '1'])->assertOk();
        $this->continueStep('backdrops', ['items' => [['key' => 'b1', 'name' => 'Floral wall', 'category' => 'backdrop', 'image_path' => $path, 'availability' => '1']]])->assertOk();
        $this->apply();

        $backdrop = BusinessBackdrop::where('source_questionnaire_item_key', 'b1')->firstOrFail();
        $this->assertSame([$path], $backdrop->images()->pluck('path')->all());

        // Rename: the photo stays on the same backdrop record.
        $this->continueStep('backdrops', ['items' => [['key' => 'b1', 'name' => 'Pink floral wall', 'category' => 'backdrop', 'image_path' => $path, 'availability' => '1']]])->assertOk();
        $this->apply();
        $this->assertSame($backdrop->id, BusinessBackdrop::where('source_questionnaire_item_key', 'b1')->value('id'));
        $this->assertSame([$path], $backdrop->fresh()->images()->pluck('path')->all());

        // Changing the conditional answer to No hides the step; its stale rows are never re-applied and the
        // wizard-created backdrop is switched off, not deleted.
        $this->continueStep('offers_backdrops', ['value' => '0'])->assertOk();
        $this->assertNotContains('backdrops', $this->visibleScreens());
        $this->apply();
        $this->assertFalse((bool) BusinessBackdrop::where('source_questionnaire_item_key', 'b1')->value('availability'));
        $this->assertSame(1, BusinessBackdrop::where('business_id', $this->business->id)->count());
    }

    public function test_existing_backdrops_are_preselected_and_updated_in_place(): void
    {
        $this->tenant();
        $existing = BusinessBackdrop::create(['business_id' => $this->business->id, 'name' => 'Gold sequin', 'availability' => true, 'position' => 0, 'category' => 'backdrop']);

        $this->get($this->url('step', 'offers_backdrops'))->assertOk()->assertSee('value="1" checked', false);
        $this->continueStep('offers_backdrops', ['value' => '1'])->assertOk();

        $html = $this->get($this->url('step', 'backdrops'))->getContent();
        $this->assertStringContainsString('value="bd:' . $existing->uid . '"', $html);
        $this->assertStringContainsString('value="Gold sequin"', $html);

        $this->continueStep('backdrops', ['items' => [['key' => 'bd:' . $existing->uid, 'name' => 'Gold sequin wall', 'category' => 'backdrop', 'availability' => '1']]])->assertOk();
        $this->apply();

        $this->assertSame(1, BusinessBackdrop::where('business_id', $this->business->id)->count());
        $this->assertSame('Gold sequin wall', $existing->fresh()->name);
    }

    // --------------------------------------------------------------- About / FAQ

    public function test_about_starts_complete_and_continue_works_without_typing(): void
    {
        $this->tenant([], ['city' => 'Irvine', 'region' => 'CA']);
        $this->continueStep('booth_services', ['items' => [$this->card('a', 'Open-Air Photo Booth'), $this->card('b', '360 Photo Booth')]])->assertOk();
        $this->continueStep('event_types', ['items' => [['key' => 'e', 'name' => 'Weddings', 'visible' => '1']]])->assertOk();
        $this->continueStep('brand_personality', ['value' => 'elegant'])->assertOk();

        $html = $this->get($this->url('step', 'about_story'))
            ->assertOk()
            ->assertSee('Tell visitors about your business')
            ->assertSee('Keep this')->assertSee('Edit')->assertSee('Try another suggestion')->assertSee('Restore default')
            ->getContent();

        preg_match('/<textarea[^>]*data-about-text[^>]*>(.*?)<\/textarea>/s', $html, $m);
        $text = html_entity_decode($m[1]);
        $this->assertStringContainsString('Open-Air Photo Booth and 360 Photo Booth', $text);
        $this->assertStringContainsString('Weddings', $text);
        $this->assertDoesNotMatchRegularExpression('/\b(years|award|guarantee|since \d{4}|family-owned|\d+ events)\b/i', $text, 'No invented years, awards or guarantees.');

        // Continue with the box untouched: the default is what is saved.
        $this->continueStep('about_story', ['value' => $text])->assertOk();
        $this->assertSame($text, $this->wizardSession()->answers['about_story']);

        // Another suggestion is genuinely different; the default is restorable; a saved text always wins.
        $a = $this->jget($this->url('content-default'), ['kind' => 'about', 'variant' => 1])->assertOk()->json('text');
        $this->assertNotSame($text, $a);
        $this->assertSame($text, $this->jget($this->url('content-default'), ['kind' => 'about', 'variant' => 0])->json('text'));

        $this->continueStep('about_story', ['value' => 'My own words about our booths.'])->assertOk();
        $this->get($this->url('step', 'about_story'))->assertSee('My own words about our booths.')->assertDontSee('Open-Air Photo Booth and 360 Photo Booth');
    }

    public function test_the_faq_is_prefilled_with_six_complete_answers_from_confirmed_facts_and_can_be_accepted_unchanged(): void
    {
        $this->tenant([], ['city' => 'Irvine', 'region' => 'CA']);
        $this->continueStep('event_types', ['items' => [['key' => 'e', 'name' => 'Weddings', 'visible' => '1'], ['key' => 'e2', 'name' => 'Birthdays', 'visible' => '1']]])->assertOk();
        $this->continueStep('service_area_cities', ['value' => ['Irvine, CA', 'Tustin, CA']])->assertOk();
        $this->continueStep('pricing_strategy', ['value' => 'custom_quote'])->assertOk();
        $this->continueStep('primary_cta', ['value' => 'quote_request'])->assertOk();

        $html = $this->get($this->url('step', 'faq_items'))->assertOk()->getContent();

        $this->assertSame(6, substr_count($html, 'name="items[') > 0 ? preg_match_all('/name="items\[\d+\]\[question\]"/', $html) : 0);
        foreach (['What kinds of events do you serve?', 'Where do you offer your services?', 'How do I request a booking?', 'How much does a photo booth rental cost?', 'What&#039;s included in your packages?', 'How far in advance should I inquire?'] as $question) {
            $this->assertStringContainsString($question, $html);
        }
        $this->assertStringContainsString('We serve Weddings and Birthdays.', $html);
        $this->assertStringContainsString('Irvine, CA and Tustin, CA', $html);
        $this->assertStringContainsString('custom quotes', $html);
        $this->assertStringContainsString('Restore default questions', $html);
        $this->assertDoesNotMatchRegularExpression('/cancell|refund|travel fee|deposit|setup time|\$\d/i', $html, 'No invented policies, fees or prices.');

        // Accepting them unchanged saves exactly those six entries.
        $items = [];
        preg_match_all('/name="items\[(\d+)\]\[key\]" value="([^"]*)"/', $html, $keys, PREG_SET_ORDER);
        foreach ($this->jget($this->url('content-default'), ['kind' => 'faq'])->json('items') as $i => $item) {
            $items[] = $item;
        }
        $this->continueStep('faq_items', ['items' => $items])->assertOk();
        $saved = $this->wizardSession()->answers['faq_items'];
        $this->assertCount(6, $saved);
        $this->assertSame($items[0]['question'], $saved[0]['question']);

        // An edited answer is exactly what comes back — the defaults never overwrite it.
        $items[0]['answer'] = 'Our own wording about events.';
        $this->continueStep('faq_items', ['items' => $items])->assertOk();
        $this->get($this->url('step', 'faq_items'))->assertSee('Our own wording about events.');
        $this->assertSame('Our own wording about events.', $this->wizardSession()->answers['faq_items'][0]['answer']);
    }

    // ------------------------------------------------------ advantages and brand

    public function test_advantages_preselect_only_what_is_already_confirmed_and_never_drop_other_confirmed_facts(): void
    {
        $this->tenant();
        BusinessKnowledgeProfile::create(['business_id' => $this->business->id, 'differentiators' => ['Premium equipment', 'Family owned since forever']]);

        $html = $this->get($this->url('step', 'advantages'))->assertOk()->assertSee('What makes your service special?')->getContent();
        $this->assertMatchesRegularExpression('/value="premium_equipment"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="unlimited_sessions"[^>]*checked/', $html, 'A common claim is never preselected.');

        $this->continueStep('advantages', ['value' => ['premium_equipment', 'digital_sharing']])->assertOk();
        $this->apply();

        $facts = BusinessKnowledgeProfile::where('business_id', $this->business->id)->value('differentiators');
        $this->assertContains('Digital photo sharing', $facts);
        $this->assertContains('Premium equipment', $facts);
        $this->assertContains('Family owned since forever', $facts, 'A confirmed fact this question never offered is kept.');
    }

    public function test_the_brand_style_picks_the_starting_template_only_before_pages_exist_and_only_when_it_changes(): void
    {
        $this->tenant();
        $website = Website::where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame('photo_booth_modern', $website->template_key);

        $this->continueStep('brand_personality', ['value' => 'elegant'])->assertOk();
        $this->assertSame('photo_booth_luxury', $website->fresh()->template_key);

        // The owner chooses a different look on Review afterwards; re-confirming the SAME style never undoes it.
        $website->forceFill(['template_key' => 'photo_booth_editorial'])->save();
        $this->continueStep('brand_personality', ['value' => 'elegant'])->assertOk();
        $this->assertSame('photo_booth_editorial', $website->fresh()->template_key);

        // A website that already has pages is never re-skinned by an answer.
        $this->homePage($website);
        $this->continueStep('brand_personality', ['value' => 'playful'])->assertOk();
        $this->assertSame('photo_booth_editorial', $website->fresh()->template_key);
    }

    public function test_photo_rights_must_be_confirmed(): void
    {
        $this->tenant();

        $this->continueStep('photo_rights', ['value' => []])->assertStatus(422);
        $this->continueStep('photo_rights', ['value' => ['confirmed']])->assertOk();
        $this->assertSame(['confirmed'], $this->wizardSession()->answers['photo_rights']);
    }

    public function test_the_quote_form_offers_the_useful_default_fields(): void
    {
        $fields = array_column(\App\Library\Website\WebsiteFormPresets::photoBoothQuoteRequest(), 'key');
        foreach (['name', 'phone', 'email', 'event_date', 'event_type', 'event_location', 'message'] as $key) {
            $this->assertContains($key, $fields);
        }
    }
}
