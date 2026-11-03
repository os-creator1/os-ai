<?php

namespace Database\Seeders;

use App\Library\Website\Setup\QuestionnaireVersionPublisher;
use App\Models\QuestionnaireDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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
 * CURRENTLY PUBLISHED version's step tree differs from this one (a matching
 * but superseded version does not count: it is published again as a new version).
 */
class PhotoboothWebsiteSetupQuestionnaireV3Seeder extends Seeder
{
    public function run(): void
    {
        // One transaction: on a database that still needs v2 first, nobody can start a setup on the intermediate v2 — the
        // definition goes from its current state straight to v3 for every other connection.
        DB::transaction(fn () => $this->activate());
    }

    private function activate(): void
    {
        (new PhotoboothWebsiteSetupQuestionnaireV2Seeder())->run();

        $definition = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->firstOrFail();
        // Already part of this definition's history (published now, or since superseded by a later version):
        // never republish it.
        $tree = self::canonical(self::steps());
        // Only the version that is published NOW counts as already active. A matching tree that was since superseded
        // (A -> B -> A) is history, not the live version: it is published again as a NEW version, and the old rows are
        // never edited.
        $published = $definition->publishedVersion();
        if ($published !== null && self::canonical($published->storedSteps()) === $tree) {
            return;
        }

        $publisher = app(QuestionnaireVersionPublisher::class);
        $draft = $definition->draftVersion();

        // There is no marker that proves a draft was written by a seeder, so ownership is never guessed. A draft is used
        // only when its steps are EXACTLY this tree (publishing it changes nothing); any other draft is somebody's work:
        // refuse, change nothing (inside the V3 transaction everything rolls back) and say how to resolve it.
        if ($draft === null) {
            $draft = $publisher->createDraft($definition, self::steps());
        } elseif (self::canonical($draft->storedSteps()) !== $tree) {
            throw new \DomainException(
                'Refusing to seed the v3 questionnaire: the definition "' . $definition->key . '" already has a draft (version id ' . $draft->id
                . ', version number ' . $draft->version_number . ') whose steps differ from the seeder\'s, and nothing proves the seeder wrote it. '
                . 'It was left exactly as it is and nothing was published. Publish or discard that draft deliberately, then run the seeder again.'
            );
        }

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
     * The experiences a photo booth business may offer. Each has a short,
     * professionally written default description the owner may edit freely —
     * a starting point, never a claim the owner has confirmed.
     *
     * @return array<int, array{key: string, label: string, description: string}>
     */
    public static function boothPresets(): array
    {
        return [
            ['key' => 'open_air', 'label' => 'Open-Air Photo Booth', 'description' => 'A modern, open-concept photo booth with a clean backdrop and professional lighting. With no walls or curtain, guests can join in from every side — a natural fit for weddings, parties and corporate events.'],
            ['key' => 'three_sixty', 'label' => '360 Photo Booth', 'description' => 'A rotating platform that records slow-motion video as the camera circles around your guests. Guests step on, strike a pose and become the highlight of the night.'],
            ['key' => 'mirror', 'label' => 'Mirror Photo Booth', 'description' => 'An interactive full-length mirror that guides guests through a playful photo session with on-screen prompts and animations.'],
            ['key' => 'glam', 'label' => 'Glam Photo Booth', 'description' => 'A polished, flattering-light booth experience styled for upscale celebrations, designed to make every guest feel like a star.'],
            ['key' => 'enclosed', 'label' => 'Enclosed Photo Booth', 'description' => 'A classic enclosed booth with a curtain for a private, playful photo session — a nostalgic favorite for guests who like a little privacy.'],
            ['key' => 'roaming', 'label' => 'Roaming Photo Booth', 'description' => 'Instead of a fixed booth, a roaming setup moves through your event so guests are captured wherever the celebration happens.'],
            ['key' => 'digital', 'label' => 'Digital Photo Booth', 'description' => 'A fully digital photo experience: guests take their photos on a touchscreen and receive them digitally.'],
            ['key' => 'ai', 'label' => 'AI Photo Booth', 'description' => 'An AI-powered photo experience that transforms guests\' photos into stylized portraits and artwork.'],
        ];
    }

    /** @return array<int, array{key: string, label: string, description: string}> */
    public static function eventPresets(): array
    {
        return [
            ['key' => 'weddings', 'label' => 'Weddings', 'description' => 'Add a fun, memorable moment to your wedding — a way for guests to celebrate with you and keep something special from the day.'],
            ['key' => 'corporate', 'label' => 'Corporate Events', 'description' => 'Give your team, clients and guests an engaging experience at company parties, conferences, product launches and holiday gatherings.'],
            ['key' => 'birthdays', 'label' => 'Birthdays', 'description' => 'Make a birthday celebration unforgettable with a photo experience guests of every age will enjoy.'],
            ['key' => 'graduations', 'label' => 'Graduations', 'description' => 'Capture the milestone with a photo experience that lets graduates, families and friends celebrate together.'],
            ['key' => 'school', 'label' => 'School Events', 'description' => 'Bring students, families and staff together at dances, proms, fundraisers and school celebrations.'],
            ['key' => 'holiday', 'label' => 'Holiday Parties', 'description' => 'Turn holiday parties into shared memories with festive photos for friends, family and coworkers.'],
            ['key' => 'baby_showers', 'label' => 'Baby Showers', 'description' => 'Celebrate the new arrival with a photo experience everyone at the shower can enjoy.'],
            ['key' => 'brand_activations', 'label' => 'Brand Activations', 'description' => 'Create engaging, shareable moments that put your brand at the center of the event.'],
            ['key' => 'private_parties', 'label' => 'Private Parties', 'description' => 'Bring a lively photo experience to anniversaries, reunions and every get-together in between.'],
        ];
    }

    /** @return array<int, array{key: string, label: string, description: string}> */
    public static function addonPresets(): array
    {
        return [
            ['key' => 'extra_hours', 'label' => 'Extra hours', 'description' => 'Keep the fun going with additional time at your event.'],
            ['key' => 'guest_book', 'label' => 'Guest book', 'description' => 'A guest book where photos can be kept alongside guests\' messages.'],
            ['key' => 'props', 'label' => 'Props', 'description' => 'Fun props for guests to use in their photos.'],
            ['key' => 'custom_print_designs', 'label' => 'Custom print designs', 'description' => 'Print layouts designed around your event.'],
            ['key' => 'additional_prints', 'label' => 'Additional prints', 'description' => 'More printed copies for your guests to take home.'],
            ['key' => 'custom_branding', 'label' => 'Custom branding', 'description' => 'Your name, logo or event theme added to the experience.'],
            ['key' => 'backdrop_upgrades', 'label' => 'Backdrop upgrades', 'description' => 'Choose from upgraded backdrop options for your event.'],
        ];
    }

    /**
     * Things a booth business can truthfully say about itself. Never
     * published by default: the owner confirms each one.
     *
     * @return array<string, string>
     */
    public static function advantageOptions(): array
    {
        return [
            'custom_print_designs' => 'Custom print designs',
            'digital_sharing' => 'Digital photo sharing',
            'professional_attendant' => 'Professional attendant',
            'custom_backdrops' => 'Custom backdrops',
            'unlimited_sessions' => 'Unlimited sessions',
            'premium_equipment' => 'Premium equipment',
            'setup_and_teardown' => 'Setup and teardown handled for you',
            'props_included' => 'Props included',
        ];
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
            self::step('service_area_cities', 'Where do you provide your services?', 'string_list', true, 'business_location', null, sharedFact: true, suggest: true, areaPages: false, areaDetails: true, helpText: 'One place per row — a city or area customers hire you in. Put your most important areas first. Optional: open "Add local details" on a place to add venues you have worked at, how serving it works, or event photos from there — that is what lets it earn its own page.'),

            self::step('booth_services', 'What photo booth experiences do you offer?', 'repeatable_group', true, 'business_service', null,
                helpText: 'Choose each experience you offer. Every one needs a real photo of that booth before your website can be published — you can add it now or later.',
                presets: self::boothPresets(), rowFields: ['name', 'description', 'cover', 'extra_images', 'features', 'price', 'visible'], itemKind: 'service'),
            self::step('event_types', 'What types of events do you serve?', 'repeatable_group', true, 'business_service', null,
                helpText: 'Tap the events you serve. We write the wording for you — change it any time.',
                presets: self::eventPresets(), rowFields: ['name', 'description', 'visible'], itemKind: 'event'),

            self::step('pricing_strategy', 'How do you price your photo booth services?', 'select', true, 'answers', null,
                helpText: 'This decides how prices appear across your website. We never show a price you haven\'t entered.',
                options: ['fixed_packages' => 'Fixed package pricing', 'starting_from' => 'Prices starting from an amount', 'custom_quote' => 'Custom quote — contact for pricing']),
            self::step('packages', 'What packages do you offer?', 'catalog_selection', true, 'catalog_item', null,
                helpText: 'Packages live in Packages & Products. Choose which ones to show, edit them, or add a new one — it appears in Packages & Products straight away. A package photo is optional.',
                conditional: ['depends_on' => 'pricing_strategy', 'condition' => 'not_equals', 'value' => 'custom_quote']),
            self::step('addons', 'Do you offer any extras?', 'repeatable_group', false, 'catalog_item', null,
                helpText: 'Optional. Only the extras you choose here appear on your website.',
                presets: self::addonPresets(), rowFields: ['name', 'description', 'price'], itemType: 'product'),

            self::step('offers_backdrops', 'Do you offer different backdrops?', 'boolean', true, 'answers', null, helpText: 'Choose No to skip backdrops entirely.'),
            self::step('backdrops', 'Show your backdrops', 'repeatable_group', true, 'backdrop', null,
                helpText: 'Add each backdrop with its own photo. A backdrop without a real photo can\'t be shown on your website.',
                categories: $backdropCategories, completeList: true,
                conditional: ['depends_on' => 'offers_backdrops', 'condition' => 'equals', 'value' => true]),

            self::step('gallery', 'Show off your best event photos', 'photo_upload', false, 'gallery', null,
                helpText: 'Upload real photos from your events, then choose which one is your homepage cover. Your homepage needs at least one real photo before it can be published.',
                categories: $backdropCategories),
            self::step('photo_rights', 'Confirm your photos', 'multi_select', true, 'answers', null,
                helpText: 'We never create photos of your equipment or past events for you — your website only shows photos you provide.',
                options: ['confirmed' => 'I have the right to use every photo I upload, and each photo accurately shows the booth, backdrop or event it is attached to.']),

            self::step('brand_personality', 'What style fits your business?', 'select', true, 'knowledge_profile', 'brand_voice',
                helpText: 'This picks the look we start your website with — you can compare and change it before you generate.',
                options: ['playful' => 'Playful & fun', 'elegant' => 'Elegant & refined', 'bold' => 'Bold & energetic', 'warm' => 'Warm & friendly'],
                templateHint: ['playful' => 'photo_booth_conversion', 'elegant' => 'photo_booth_luxury', 'bold' => 'photo_booth_modern', 'warm' => 'photo_booth_editorial']),
            self::step('about_story', 'Tell visitors about your business', 'textarea', true, 'answers', null, defaults: 'about', helpText: 'We wrote a short description from your confirmed details. Keep it, edit it, or try another.'),
            self::step('advantages', 'What makes your service special?', 'multi_select', false, 'knowledge_profile', 'differentiators', helpText: 'Choose only what is genuinely true for your business — each one is shown to visitors as a fact about you.', options: self::advantageOptions(), ownerConfirms: true),

            self::step('testimonials', 'Would you like to feature customer reviews?', 'repeatable_group', false, 'knowledge_profile', 'testimonials', helpText: 'Add only real reviews. If you have none, this section is simply left out.', ownerConfirms: true),
            self::step('primary_cta', 'What should visitors do when they want to book?', 'select', true, 'knowledge_profile', 'primary_conversion_goal', options: ['quote_request' => 'Request a quote (recommended)', 'call' => 'Call now', 'book_now' => 'Book now']),
            self::step('contact_form_fields', 'What should your quote form ask for?', 'multi_select', true, 'website_form', null, helpText: 'Name and a way to reach them are the usual starting point.', options: [
                'name' => 'Name',
                'phone' => 'Phone',
                'email' => 'Email',
                'event_date' => 'Event date',
                'event_type' => 'Event type',
                'event_location' => 'Event location',
                'message' => 'Message / additional details',
            ]),
            self::step('faq_items', 'What questions should your website answer?', 'repeatable_group', false, 'faq', null, defaults: 'faq', helpText: 'We filled in the common questions using your confirmed details. Edit, remove or add your own.'),
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
        ?array $conditional = null,
        ?array $presets = null,
        ?array $rowFields = null,
        ?string $itemKind = null,
        ?string $itemType = null,
        ?bool $areaPages = null,
        ?bool $areaDetails = null,
        bool $completeList = false,
        ?string $defaults = null,
        ?array $templateHint = null,
        bool $ownerConfirms = false,
    ): array {
        $step = [
            'key' => $key,
            'prompt' => $prompt,
            'help_text' => $helpText,
            'input_type' => $inputType,
            'required' => $required,
            'options' => $options,
            'conditional_visibility' => $conditional,
            'target_module' => $targetModule,
            'target_field' => $targetField,
            'ai_instructions' => null,
        ];

        foreach (['screen' => $screen, 'categories' => $categories, 'screen_prompt' => $screenPrompt, 'screen_help' => $screenHelp, 'presets' => $presets, 'row_fields' => $rowFields, 'item_kind' => $itemKind, 'item_type' => $itemType] as $name => $value) {
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

        if ($areaPages !== null) {
            $step['area_pages'] = $areaPages;
        }

        if ($areaDetails !== null) {
            $step['area_details'] = $areaDetails;
        }

        if ($completeList) {
            $step['complete_list'] = true;
        }

        if ($defaults !== null) {
            $step['defaults'] = $defaults;
        }

        if ($templateHint !== null) {
            $step['template_hint'] = $templateHint;
        }

        if ($ownerConfirms) {
            $step['owner_confirms'] = true;
        }

        return $step;
    }
}
