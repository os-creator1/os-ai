<?php

namespace Database\Seeders;

use App\Library\Website\Setup\QuestionnaireVersionPublisher;
use App\Models\QuestionnaireDefinition;
use Illuminate\Database\Seeder;

/**
 * The guided, one-question-per-page Photo Booth website setup — published as
 * a NEW version of the same definition, side by side with v1 and v2.
 *
 * Versions are immutable and every setup session is pinned to the version it
 * started on, so publishing this changes nothing for anyone mid-setup or
 * already set up (their sessions, answers, "Edit setup answers" and generated
 * websites keep running on the version they started with — every v1/v2 input
 * shape stays supported). Only NEW setups start on the newest published
 * version. No existing answers are migrated.
 *
 * What v3 changes, all expressed as definition data (never hardcoded in the
 * Website views):
 *  - ONE question per wizard page. The single exception is the business
 *    basics (name, phone, email, logo), which are one closely related
 *    screen;
 *  - "Where are you based?" is its own question, prefilled from the real
 *    primary location, and feeds the next one;
 *  - "Where do you provide your services?" is a one-per-row list prefilled
 *    from the business's saved service areas first, and otherwise from
 *    grounded nearby-place suggestions (see App\Library\Geo);
 *  - the business's shared facts (name, phone, email, base location, service
 *    areas) are written to the canonical records when the owner confirms the
 *    screen (`shared_fact`), not only when the website is generated.
 *
 * Idempotent: it ensures v1/v2 exist, then publishes v3 only when the
 * currently published version's step tree differs from this one.
 */
class PhotoboothWebsiteSetupQuestionnaireV3Seeder extends Seeder
{
    public function run(): void
    {
        (new PhotoboothWebsiteSetupQuestionnaireV2Seeder())->run();

        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        $published = $definition->publishedVersion();

        if ($published !== null && self::canonical($published->storedSteps()) === self::canonical(self::steps())) {
            return;
        }

        $publisher = app(QuestionnaireVersionPublisher::class);
        $draft = $definition->draftVersion() ?? $publisher->createDraft($definition, self::steps());
        $publisher->publish($draft);
    }

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
        $backdropCategories = [
            ['value' => 'backdrop', 'label' => 'Backdrop'],
            ['value' => 'booth_setup', 'label' => 'Booth setup'],
            ['value' => 'event', 'label' => 'Event'],
            ['value' => 'package_example', 'label' => 'Package / example'],
        ];

        return [
            // Business basics — the one grouped screen. Prefilled from the real Business; confirm or correct.
            self::step('business_name', 'Business name', 'text', true, 'business', 'name', screen: 'basics', sharedFact: true, screenPrompt: 'Confirm your business details', screenHelp: 'We already have these from your MotionGrove account. Change anything that is out of date, or just continue.'),
            self::step('phone', 'Phone number', 'tel', true, 'business', 'phone', screen: 'basics', sharedFact: true),
            self::step('email', 'Booking / contact email', 'email', true, 'business', 'email', screen: 'basics', sharedFact: true),
            self::step('logo', 'Logo', 'image', false, 'website_look', 'logo', screen: 'basics'),

            self::step('base_location', 'Where are you based?', 'location', true, 'business_location', null, sharedFact: true, helpText: 'Your main city. We use it to suggest the areas you serve next.'),
            self::step('service_area_cities', 'Where do you provide your services?', 'string_list', true, 'business_location', null, sharedFact: true, suggest: true, helpText: 'One place per row — a city or area customers hire you in. Put your most important areas first.'),

            self::step('booth_types', 'What booth types do you offer?', 'repeatable_group', true, 'business_service', null, helpText: 'Add each booth type — e.g. Open-Air, Enclosed, Mirror. Add as many as you offer.'),
            self::step('services_event_types', 'What events or services do you specialize in?', 'repeatable_group', false, 'business_service', null, helpText: 'Optional — e.g. Weddings, Corporate events.'),
            self::step('packages', 'Which packages should your website show?', 'catalog_selection', true, 'catalog_item', null, helpText: 'Packages live in Packages & Products. Choose which ones to show, edit them, or add a new one — it appears in Packages & Products straight away.'),
            self::step('backdrops', 'Show your backdrops', 'repeatable_group', false, 'backdrop', null, helpText: 'Add each backdrop you offer, with a photo. Skip it if you don\'t offer selectable backdrops.', categories: $backdropCategories),
            self::step('gallery', 'Show your work', 'photo_upload', false, 'gallery', null, helpText: 'Upload real event photos — we\'ll build your homepage showcase and Gallery page from these.', categories: $backdropCategories),
            self::step('testimonials', 'Any reviews or testimonials you\'d like to feature?', 'repeatable_group', false, 'knowledge_profile', 'testimonials'),
            self::step('brand_personality', 'What style fits your business?', 'select', true, 'knowledge_profile', 'brand_voice', options: ['playful' => 'Playful & fun', 'elegant' => 'Elegant & refined', 'bold' => 'Bold & energetic', 'warm' => 'Warm & friendly']),
            self::step('about_story', 'Tell visitors about your business', 'textarea', true, 'business', 'description', helpText: 'A few notes in your own words is plenty.'),
            self::step('faq_items', 'Frequently asked questions', 'repeatable_group', false, 'faq', null, helpText: 'Add any questions customers often ask — these appear on your FAQ page exactly as written, never rewritten by AI.'),
            self::step('primary_cta', 'What should visitors do when they want to book?', 'select', true, 'knowledge_profile', 'primary_conversion_goal', options: ['quote_request' => 'Request a quote', 'call' => 'Call now', 'book_now' => 'Book now']),
            self::step('contact_form_fields', 'What information do you need from a lead?', 'multi_select', true, 'website_form', null, helpText: 'Name and a way to reach them are the usual starting point.', options: [
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
        bool $sharedFact = false,
        bool $suggest = false,
        ?string $screenPrompt = null,
        ?string $screenHelp = null,
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

        foreach (['screen' => $screen, 'categories' => $categories, 'screen_prompt' => $screenPrompt, 'screen_help' => $screenHelp] as $name => $value) {
            if ($value !== null) {
                $step[$name] = $value;
            }
        }

        if ($sharedFact) {
            $step['shared_fact'] = true;
        }

        if ($suggest) {
            $step['suggest'] = true;
        }

        return $step;
    }
}
