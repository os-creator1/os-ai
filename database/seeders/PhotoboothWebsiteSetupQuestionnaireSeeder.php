<?php

namespace Database\Seeders;

use App\Library\Website\Setup\QuestionnaireVersionPublisher;
use App\Models\QuestionnaireDefinition;
use Illuminate\Database\Seeder;

/**
 * Website Builder redesign — the first complete Niche Builder
 * questionnaire definition, seeded (not admin-authored: the general
 * authoring UI is an explicitly deferred follow-up). One question per
 * wizard screen ("one setup question per screen" — a `repeatable_group`
 * question collects several structured sub-entries on that one screen,
 * but is still one question). `target_module`/`target_field` on each
 * question is the routing metadata `WebsiteSetupAnswerApplier` reads to
 * write into the real canonical Business OS record that question is
 * really about — never a website-only copy.
 *
 * Idempotent: re-running this seeder is a no-op once the 'v1' version is
 * already published (QuestionnaireVersionPublisher::createDraft() refuses
 * a second draft while one exists, and publish() refuses a non-draft) —
 * matching WebsiteTemplateSeeder's own `updateOrCreate`-by-key idempotency
 * in spirit, though a NEW question-tree revision is always a genuinely
 * new published version, never an in-place edit of this one (the whole
 * point of the versioning/pinning mechanism).
 */
class PhotoboothWebsiteSetupQuestionnaireSeeder extends Seeder
{
    public const KEY = 'photobooth_website_setup';

    public function run(): void
    {
        $definition = QuestionnaireDefinition::firstOrCreate(
            ['key' => self::KEY],
            ['scope' => 'website_niche_setup', 'niche_key' => 'photo_booth_service', 'name' => 'Photo Booth Website Setup'],
        );

        if ($definition->publishedVersion() !== null) {
            return;
        }

        $publisher = app(QuestionnaireVersionPublisher::class);
        $draft = $definition->draftVersion() ?? $publisher->createDraft($definition, self::steps());
        $publisher->publish($draft);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function steps(): array
    {
        return [
            self::step('business_name', 'What\'s your business called?', 'text', true, 'business', 'name'),
            self::step('phone', 'What\'s the best phone number for customers to reach you?', 'tel', true, 'business', 'phone'),
            self::step('email', 'What\'s your booking/contact email?', 'email', true, 'business', 'email'),
            self::step('service_area_cities', 'Which cities or areas do you serve?', 'text', true, 'business_location', null, helpText: 'Separate multiple cities with commas.'),
            self::step('primary_cta', 'What should visitors do first?', 'select', true, 'knowledge_profile', 'primary_conversion_goal', options: ['quote_request' => 'Request a quote', 'call' => 'Call now', 'book_now' => 'Book now']),
            self::step('brand_personality', 'How would you describe your brand\'s personality?', 'select', true, 'knowledge_profile', 'brand_voice', options: ['playful' => 'Playful & fun', 'elegant' => 'Elegant & refined', 'bold' => 'Bold & energetic', 'warm' => 'Warm & friendly']),
            self::step('booth_types', 'What booth types do you offer?', 'repeatable_group', true, 'business_service', null, helpText: 'Add each booth type — e.g. Open-Air, Enclosed, Mirror.'),
            self::step('services_event_types', 'What events or services do you specialize in?', 'repeatable_group', false, 'business_service', null),
            self::step('packages', 'Set up your packages', 'repeatable_group', true, 'catalog_item', null, helpText: 'Name, price (or "contact for pricing"), and what\'s included.'),
            self::step('offers_backdrops', 'Do you offer selectable backdrops?', 'boolean', true, 'answers', null),
            self::step('backdrops', 'Tell us about your backdrops', 'repeatable_group', true, 'backdrop', null, conditionalVisibility: ['depends_on' => 'offers_backdrops', 'condition' => 'equals', 'value' => true]),
            self::step('gallery', 'Show your work', 'photo_upload', false, 'gallery', null, helpText: 'Upload real event photos — we\'ll build your homepage showcase and Gallery page from these.'),
            self::step('testimonials', 'Any reviews or testimonials you\'d like to feature?', 'repeatable_group', false, 'knowledge_profile', 'testimonials'),
            self::step('faq_items', 'Frequently asked questions', 'repeatable_group', false, 'answers', null, helpText: 'Add any questions customers often ask.'),
            self::step('about_story', 'Tell us about your business', 'textarea', true, 'business', 'description', helpText: 'A couple of sentences is plenty — we\'ll help expand it.'),
            self::step('contact_form_fields', 'What information do you need from a lead?', 'multi_select', true, 'website_form', null, options: ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'event_date' => 'Event date', 'event_type' => 'Event type', 'message' => 'Message']),
            self::step('custom_section', 'Want to add a custom section? (optional)', 'repeatable_group', false, 'custom_section', null, helpText: 'A polished editorial section for anything that doesn\'t fit elsewhere — e.g. "Red Carpet Experience."'),
        ];
    }

    private static function step(
        string $key,
        string $prompt,
        string $inputType,
        bool $required,
        string $targetModule,
        ?string $targetField,
        ?string $helpText = null,
        ?array $options = null,
        ?array $conditionalVisibility = null,
        ?string $aiInstructions = null,
    ): array {
        return [
            'key' => $key,
            'prompt' => $prompt,
            'help_text' => $helpText,
            'input_type' => $inputType,
            'required' => $required,
            'options' => $options,
            'conditional_visibility' => $conditionalVisibility,
            'target_module' => $targetModule,
            'target_field' => $targetField,
            'ai_instructions' => $aiInstructions,
        ];
    }
}
