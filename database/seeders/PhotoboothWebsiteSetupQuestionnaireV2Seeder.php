<?php

namespace Database\Seeders;

use App\Library\Website\Setup\QuestionnaireVersionPublisher;
use App\Models\QuestionnaireDefinition;
use Illuminate\Database\Seeder;

/**
 * The shorter, smarter Photo Booth website setup — published as a NEW
 * version of the same definition, side by side with v1.
 *
 * Versions are immutable and every setup session is pinned to the version
 * it started on, so publishing this changes nothing for anyone mid-setup or
 * already set up: their sessions, answers, "Edit setup answers" and
 * generated websites keep running on the version they started with (every
 * v1 input shape stays supported). Only NEW setups start on the newest
 * published version. No existing answers are migrated.
 *
 * What v2 changes, all expressed as definition data (never hardcoded in the
 * Website views):
 *  - 9 wizard SCREENS (consecutive steps share a `screen` key) instead of
 *    17 one-question screens — each step is still atomic;
 *  - service areas are a one-per-row `string_list` (no comma parsing);
 *  - packages are a `catalog_selection` over the canonical Packages &
 *    Products catalog (the answer stores only catalog uids);
 *  - backdrops and gallery photos pick their category from the niche's own
 *    `categories` vocabulary below;
 *  - "do you offer backdrops?" is gone — the backdrops list is simply
 *    optional.
 *
 * Idempotent: it ensures the definition (and, on a fresh database, the v1
 * version) exists, then publishes v2 only when the currently published
 * version's step tree differs from this one.
 */
class PhotoboothWebsiteSetupQuestionnaireV2Seeder extends Seeder
{
    public function run(): void
    {
        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->first();

        if ($definition === null || $definition->publishedVersion() === null) {
            // A fresh database: v1 first, so v2 is a true successor version.
            (new PhotoboothWebsiteSetupQuestionnaireSeeder())->run();
            $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        }

        $published = $definition->publishedVersion();

        if ($published !== null && self::canonical($published->storedSteps()) === self::canonical(self::steps())) {
            return;
        }

        $publisher = app(QuestionnaireVersionPublisher::class);
        $draft = $definition->draftVersion() ?? $publisher->createDraft($definition, self::steps());
        $publisher->publish($draft);
    }

    /**
     * A comparable form of a step tree: object keys sorted (the database
     * does not preserve JSON object key order), list order preserved.
     */
    private static function canonical(mixed $value): string
    {
        $normalize = function (mixed $node) use (&$normalize) {
            if (! is_array($node)) {
                return $node;
            }

            $node = array_map($normalize, $node);

            if (! array_is_list($node)) {
                ksort($node);
            }

            return $node;
        };

        return (string) json_encode($normalize($value));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function steps(): array
    {
        // An ORDERED list: this is the order the owner's category select shows.
        $backdropCategories = [
            ['value' => 'backdrop', 'label' => 'Backdrop'],
            ['value' => 'booth_setup', 'label' => 'Booth setup'],
            ['value' => 'event', 'label' => 'Event'],
            ['value' => 'package_example', 'label' => 'Package / example'],
        ];

        return [
            // Screen 1 — Business basics
            self::step('business_name', 'What\'s your business called?', 'text', true, 'business', 'name', screen: 'basics'),
            self::step('phone', 'What\'s the best phone number for customers to reach you?', 'tel', true, 'business', 'phone', screen: 'basics'),
            self::step('email', 'What\'s your booking/contact email?', 'email', true, 'business', 'email', screen: 'basics'),
            self::step('primary_cta', 'What should visitors do first?', 'select', true, 'knowledge_profile', 'primary_conversion_goal', screen: 'basics', options: ['quote_request' => 'Request a quote', 'call' => 'Call now', 'book_now' => 'Book now']),

            // Screen 2 — Service areas
            self::step('service_area_cities', 'Where do you provide your services?', 'string_list', true, 'business_location', null, screen: 'areas', helpText: "Add the main cities or areas where customers hire you.\n\nRecommended to start: 5–10 important service areas. You can add more later. Put your most important areas first — they get their own pages first."),

            // Screen 3 — Services / booth types
            self::step('booth_types', 'What booth types do you offer?', 'repeatable_group', true, 'business_service', null, screen: 'services', helpText: 'Add each booth type — e.g. Open-Air, Enclosed, Mirror. Add as many as you offer.'),
            self::step('services_event_types', 'What events or services do you specialize in?', 'repeatable_group', false, 'business_service', null, screen: 'services', helpText: 'Optional — e.g. Weddings, Corporate events.'),

            // Screen 4 — Packages (canonical Packages & Products)
            self::step('packages', 'Which packages should your website show?', 'catalog_selection', true, 'catalog_item', null, screen: 'packages', helpText: 'Packages live in Packages & Products. Choose which ones to show, edit them, or add a new one — it appears in Packages & Products straight away.'),

            // Screen 5 — Backdrops and gallery
            self::step('backdrops', 'Show your backdrops', 'repeatable_group', false, 'backdrop', null, screen: 'media', helpText: 'Add each backdrop you offer, with a photo. Skip it if you don\'t offer selectable backdrops.', categories: $backdropCategories),
            self::step('gallery', 'Show your work', 'photo_upload', false, 'gallery', null, screen: 'media', helpText: 'Upload real event photos — we\'ll build your homepage showcase and Gallery page from these.', categories: $backdropCategories),

            // Screen 6 — Reviews / social proof
            self::step('testimonials', 'Any reviews or testimonials you\'d like to feature?', 'repeatable_group', false, 'knowledge_profile', 'testimonials', screen: 'social_proof'),

            // Screen 7 — About / brand
            self::step('about_story', 'Tell us about your business', 'textarea', true, 'business', 'description', screen: 'about', helpText: 'Write a few notes in your own words. AI will turn them into polished About copy that you can edit.'),
            self::step('brand_personality', 'How would you describe your brand\'s personality?', 'select', true, 'knowledge_profile', 'brand_voice', screen: 'about', options: ['playful' => 'Playful & fun', 'elegant' => 'Elegant & refined', 'bold' => 'Bold & energetic', 'warm' => 'Warm & friendly']),

            // Screen 8 — Optional extras
            self::step('faq_items', 'Frequently asked questions', 'repeatable_group', false, 'faq', null, screen: 'extras', helpText: 'Add any questions customers often ask — these appear on your FAQ page exactly as written, never rewritten by AI.'),
            self::step('custom_section', 'Want to add a custom section? (optional)', 'repeatable_group', false, 'custom_section', null, screen: 'extras', helpText: 'A polished editorial section for anything that doesn\'t fit elsewhere — e.g. "Red Carpet Experience."'),

            // Screen 9 — Lead form
            self::step('contact_form_fields', 'What information do you need from a lead?', 'multi_select', true, 'website_form', null, helpText: 'Name and a way to reach them are the usual starting point. "Message / additional details" lets visitors add anything else you should know about their event.', options: [
                'name' => 'Name',
                'phone' => 'Phone',
                'email' => 'Email',
                'event_date' => 'Event date',
                'event_type' => 'Event type',
                'message' => 'Message / additional details',
            ]),
        ];
    }

    private static function step(
        string $key,
        string $prompt,
        string $inputType,
        bool $required,
        string $targetModule,
        ?string $targetField,
        ?string $screen = null,
        ?string $helpText = null,
        ?array $options = null,
        ?array $categories = null,
    ): array {
        $step = [
            'key' => $key,
            'prompt' => $prompt,
            'help_text' => $helpText,
            'input_type' => $inputType,
            'required' => $required,
            'options' => $options,
            'conditional_visibility' => null,
            'target_module' => $targetModule,
            'target_field' => $targetField,
            'ai_instructions' => null,
        ];

        if ($screen !== null) {
            $step['screen'] = $screen;
        }

        if ($categories !== null) {
            $step['categories'] = $categories;
        }

        return $step;
    }
}
