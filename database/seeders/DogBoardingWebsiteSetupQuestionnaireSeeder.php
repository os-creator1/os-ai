<?php

namespace Database\Seeders;

use App\Library\Website\Setup\QuestionnaireVersionPublisher;
use App\Models\QuestionnaireDefinition;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The guided Dog Boarding website setup: one questionnaire definition (niche `dog_boarding`) whose versions are
 * immutable and pinned per setup session, exactly like the Photo Booth definition.
 *
 * Everything a dog boarding owner tells us is expressed as definition DATA using the question types and target
 * modules the wizard already supports:
 *  - business identity and location reuse the shared-fact steps, so nothing already known is asked twice;
 *  - services/rates/add-ons write the real Services and Packages & Products records (never a hardcoded price);
 *  - facility, policy and requirement answers stay in the setup response and are read by the site writer. They
 *    describe the OPERATOR's own practice; nothing here supplies a vaccination rule, a safety promise or a legal term;
 *  - photos come only from the media library (`gallery`, service covers). No image is ever generated.
 *
 * Idempotent: the currently published version is left alone when its steps already match; a changed definition is
 * published as a NEW version (v2 …) so sessions mid-setup and generated websites keep the version they started on.
 */
class DogBoardingWebsiteSetupQuestionnaireSeeder extends Seeder
{
    public const KEY = 'dog_boarding_website_setup';

    public const NICHE_KEY = 'dog_boarding';

    public function run(): void
    {
        DB::transaction(fn () => $this->activate());
    }

    private function activate(): void
    {
        $definition = QuestionnaireDefinition::firstOrCreate(
            ['key' => self::KEY],
            ['scope' => 'website_niche_setup', 'niche_key' => self::NICHE_KEY, 'name' => 'Dog Boarding Website Setup'],
        );

        $tree = self::canonical(self::steps());
        $published = $definition->publishedVersion();

        if ($published !== null && self::canonical($published->storedSteps()) === $tree) {
            return;
        }

        $publisher = app(QuestionnaireVersionPublisher::class);
        $draft = $definition->draftVersion();

        // Nothing proves a draft was written by this seeder, so ownership is never guessed: only an identical draft is used.
        if ($draft === null) {
            $draft = $publisher->createDraft($definition, self::steps());
        } elseif (self::canonical($draft->storedSteps()) !== $tree) {
            throw new DomainException(
                'Refusing to seed the Dog Boarding questionnaire: the definition "' . $definition->key . '" already has a draft (version id ' . $draft->id
                . ') whose steps differ from the seeder\'s. It was left as it is and nothing was published. Publish or discard that draft deliberately, then run the seeder again.'
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
     * The stay types a dog boarding business may offer. Each has a plain, neutral default description the owner edits
     * freely: a starting point, never a claim the owner has confirmed (no capacity, safety, supervision or price).
     *
     * @return array<int, array{key: string, label: string, description: string}>
     */
    public static function servicePresets(): array
    {
        return [
            ['key' => 'overnight_boarding', 'label' => 'Overnight Dog Boarding', 'description' => 'Your dog stays with us overnight while you are away, with meals, outdoor time and a place to rest.'],
            ['key' => 'daycare', 'label' => 'Dog Daycare', 'description' => 'A day of care, exercise and company for your dog while you are at work or out for the day.'],
            ['key' => 'long_stay', 'label' => 'Long-Stay Boarding', 'description' => 'Longer stays for holidays, moves or extended trips, with a routine suited to a longer visit.'],
            ['key' => 'puppy_care', 'label' => 'Puppy Boarding & Care', 'description' => 'Boarding and day care for puppies, arranged around their age and routine.'],
            ['key' => 'family_style', 'label' => 'Family-Style Boarding', 'description' => 'Boarding in a home-like setting, where your dog stays as part of our household routine.'],
        ];
    }

    /** @return array<int, array{key: string, label: string, description: string}> */
    public static function addonPresets(): array
    {
        return [
            ['key' => 'grooming', 'label' => 'Grooming', 'description' => 'Optional grooming before pickup.'],
            ['key' => 'training', 'label' => 'Training', 'description' => 'Optional training sessions during a stay.'],
            ['key' => 'transport', 'label' => 'Pickup & drop-off transport', 'description' => 'Optional transport to and from our facility.'],
            ['key' => 'extra_walk', 'label' => 'Extra walk or play session', 'description' => 'An additional walk or play session for your dog.'],
        ];
    }

    /** @return array<string, string> */
    public static function differentiatorOptions(): array
    {
        return [
            'small_numbers' => 'We keep our numbers small',
            'home_environment' => 'Dogs stay in a home-like environment',
            'outdoor_space' => 'We have outdoor space for dogs',
            'separate_play_groups' => 'We separate dogs into play groups',
            'private_rooms' => 'Private rooms or suites are available',
            'daily_updates' => 'We send owners updates during the stay',
            'meet_greet_first' => 'We offer a meet and greet before a first stay',
            'owner_on_site' => 'The owner is on site',
            'special_needs' => 'We can care for senior dogs or dogs with special needs',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function steps(): array
    {
        $galleryCategories = [
            ['value' => 'facility', 'label' => 'Facility'],
            ['value' => 'indoor', 'label' => 'Indoor area'],
            ['value' => 'outdoor', 'label' => 'Outdoor area'],
            ['value' => 'sleeping', 'label' => 'Sleeping space'],
            ['value' => 'play', 'label' => 'Play area'],
            ['value' => 'team', 'label' => 'Team and dogs'],
            ['value' => 'branding', 'label' => 'Branding'],
        ];

        return [
            // A. Business identity — the confirm screen is prefilled from the real Business.
            self::step('business_name', 'Business name', 'text', true, 'business', 'name', screen: 'basics', sharedFact: true, screenPrompt: 'Confirm your business details', screenHelp: 'We already have these from your MotionGrove account. Change anything that is out of date, or just continue.'),
            self::step('phone', 'Phone number', 'tel', true, 'business', 'phone', screen: 'basics', sharedFact: true),
            self::step('email', 'Contact email', 'email', true, 'business', 'email', screen: 'basics', sharedFact: true),
            self::step('logo', 'Logo', 'image', false, 'website_look', 'logo', screen: 'basics'),
            self::step('base_location', 'Where is your facility?', 'location', true, 'business_location', null, sharedFact: true, helpText: 'Your main location. We use it for your contact details and to suggest nearby areas.'),
            self::step('service_area_cities', 'Which areas do your customers come from?', 'string_list', true, 'business_location', null, sharedFact: true, suggest: true, areaPages: false, helpText: 'One place per row. We only mention areas you list here and never create a separate page for each one.'),
            self::step('multiple_locations', 'Do you operate from more than one location?', 'boolean', true, 'answers', null, helpText: 'Choose Yes if dogs can stay at more than one address. You can add the others in Locations.'),
            self::step('dropoff_hours', 'Drop-off hours', 'text', false, 'answers', null, screen: 'hours', screenPrompt: 'When can owners drop off and collect?', screenHelp: 'Leave anything you are not sure about blank. We only publish what you enter.', helpText: 'For example: Mon–Fri 7:00–10:00'),
            self::step('pickup_hours', 'Pickup hours', 'text', false, 'answers', null, screen: 'hours', helpText: 'For example: Mon–Fri 4:00–6:30'),

            // B. Services
            self::step('dog_services', 'What do you offer for dogs?', 'repeatable_group', true, 'business_service', null,
                helpText: 'Choose each service you actually offer. A service needs a real photo of your facility or area before your website can be published — add it now or later.',
                presets: self::servicePresets(), rowFields: ['name', 'description', 'cover', 'extra_images', 'features', 'visible'], itemKind: 'service'),
            self::step('service_eligibility', 'Who is each service for?', 'textarea', false, 'answers', null, helpText: 'Optional. For example: ages, sizes or breeds you accept, or dogs that need an assessment first.'),

            // C. Facility and accommodation (all optional; only entered answers appear on the site)
            self::step('facility_description', 'Describe your facility', 'textarea', false, 'answers', null, screen: 'facility', screenPrompt: 'Your facility and a typical day', screenHelp: 'Describe only what is true. Anything you leave blank is simply not mentioned.', helpText: 'What visitors would see when they arrive.'),
            self::step('indoor_spaces', 'Indoor spaces', 'textarea', false, 'answers', null, screen: 'facility'),
            self::step('outdoor_spaces', 'Outdoor spaces', 'textarea', false, 'answers', null, screen: 'facility'),
            self::step('sleeping_arrangements', 'Where do dogs sleep?', 'textarea', false, 'answers', null, screen: 'facility'),
            self::step('daily_routine', 'A typical day: exercise, play and rest', 'textarea', false, 'answers', null, screen: 'facility'),
            self::step('supervision_approach', 'How are dogs supervised?', 'textarea', false, 'answers', null, screen: 'facility', helpText: 'Say what you actually do. We will not add promises such as constant supervision.'),
            self::step('group_play_policy', 'Group play and separation', 'textarea', false, 'answers', null, screen: 'facility2', screenPrompt: 'Play groups and separate care', helpText: 'Do dogs play in groups? How do you decide who plays together?'),
            self::step('separate_care', 'Can a dog be cared for separately from the others?', 'boolean', false, 'answers', null, screen: 'facility2'),
            self::step('capacity_note', 'Capacity (optional)', 'text', false, 'answers', null, screen: 'facility2', helpText: 'Only if you are happy to publish it, for example "up to 12 dogs". Leave blank to say nothing about capacity.'),

            // D. Pricing — real catalog records, never a seeded price
            self::step('pricing_strategy', 'How do you show your prices?', 'select', true, 'answers', null,
                helpText: 'Boarding prices often depend on the dog, dates and service. We never show a price you have not entered, and never imply an exact quote.',
                options: ['fixed_packages' => 'Fixed rates', 'starting_from' => 'Rates starting from an amount', 'custom_quote' => 'Contact us for pricing']),
            self::step('packages', 'Which rates and packages do you offer?', 'catalog_selection', true, 'catalog_item', null,
                helpText: 'Rates live in Packages & Products. Choose which to show, edit them, or add a new one, such as a nightly rate or a daycare day rate.',
                conditional: ['depends_on' => 'pricing_strategy', 'condition' => 'not_equals', 'value' => 'custom_quote']),
            self::step('addons', 'Do you offer add-ons?', 'repeatable_group', false, 'catalog_item', null,
                helpText: 'Optional. Only the add-ons you choose here appear on your website.',
                presets: self::addonPresets(), rowFields: ['name', 'description', 'price'], itemType: 'product'),
            self::step('additional_dog_rate', 'Rate for an additional dog', 'text', false, 'answers', null, screen: 'ratenotes', screenPrompt: 'Other pricing details', screenHelp: 'Optional. Anything you leave blank is not mentioned.', helpText: 'For example: dogs from the same household'),
            self::step('holiday_rates', 'Holiday or peak-season rates', 'text', false, 'answers', null, screen: 'ratenotes'),
            self::step('deposit_policy', 'Deposits', 'text', false, 'answers', null, screen: 'ratenotes'),

            // E. Requirements and policies — the operator's own practice, in their words
            self::step('vaccination_requirements', 'Vaccination requirements', 'textarea', false, 'answers', null, screen: 'policies1', screenPrompt: 'Your requirements', screenHelp: 'These describe YOUR policies and appear exactly as you write them. We do not supply legal or veterinary wording.', helpText: 'List what you require, or leave blank.'),
            self::step('age_requirements', 'Age requirements', 'text', false, 'answers', null, screen: 'policies1'),
            self::step('spay_neuter_requirements', 'Spay or neuter requirements', 'text', false, 'answers', null, screen: 'policies1'),
            self::step('temperament_assessment', 'Temperament assessment or trial day', 'textarea', false, 'answers', null, screen: 'policies1'),
            self::step('feeding_medication', 'Feeding and medication', 'textarea', false, 'answers', null, screen: 'policies2', screenPrompt: 'Day-to-day policies'),
            self::step('checkin_checkout', 'Check-in and check-out', 'textarea', false, 'answers', null, screen: 'policies2'),
            self::step('emergency_contact', 'Emergency contact requirements', 'textarea', false, 'answers', null, screen: 'policies2'),
            self::step('cancellation_policy', 'Cancellation policy', 'textarea', false, 'answers', null, screen: 'policies2'),

            // F. Trust and differentiation
            self::step('about_story', 'Tell owners about your business', 'textarea', true, 'answers', null, defaults: 'about', helpText: 'We wrote a short description from your confirmed details. Keep it, edit it, or try another.'),
            self::step('staff_description', 'Who looks after the dogs?', 'textarea', false, 'answers', null, helpText: 'Optional. Describe the owner or team in your own words. We will not state numbers or qualifications you do not give us.'),
            self::step('care_philosophy', 'Your approach to care', 'textarea', false, 'answers', null),
            self::step('credentials_note', 'Credentials you can verify (optional)', 'text', false, 'answers', null, helpText: 'Only list a licence, certification or membership that is current and that you can prove.'),
            self::step('advantages', 'What makes your boarding different?', 'multi_select', false, 'knowledge_profile', 'differentiators', helpText: 'Choose only what is genuinely true. Each one is shown to visitors as a fact about you.', options: self::differentiatorOptions(), ownerConfirms: true),
            self::step('accepted_breeds', 'Accepted breeds and sizes', 'textarea', false, 'answers', null, helpText: 'Optional. For example any size limits, or breeds you cannot accept.'),
            self::step('testimonials', 'Would you like to feature customer reviews?', 'repeatable_group', false, 'knowledge_profile', 'testimonials', helpText: 'Add only real reviews from real customers. With none, the section is simply left out.', ownerConfirms: true),
            self::step('faq_items', 'What questions should your website answer?', 'repeatable_group', false, 'faq', null, defaults: 'faq', helpText: 'We suggest common questions. Add your own answers; a question you do not answer is not shown.'),

            // G. Images — the media library only
            self::step('gallery', 'Add photos of your facility', 'photo_upload', false, 'gallery', null,
                helpText: 'Upload real photos: indoor and outdoor areas, sleeping and play spaces, and your team. Your homepage needs at least one real photo before it can be published.',
                categories: $galleryCategories),
            self::step('photo_rights', 'Confirm your photos', 'multi_select', true, 'answers', null,
                helpText: 'We never create photos of your facility for you. Your website only shows photos you provide.',
                options: ['confirmed' => 'I have the right to use every photo I upload, and every person or dog shown has agreed (or their owner has) to appear on my website.']),

            // H. Conversions
            self::step('brand_personality', 'What style fits your business?', 'select', true, 'knowledge_profile', 'brand_voice',
                helpText: 'This picks the look we start with. You can compare and change it before you generate.',
                options: ['warm' => 'Warm & homely', 'fresh' => 'Fresh & outdoorsy', 'playful' => 'Playful & bright'],
                templateHint: ['warm' => 'dog_boarding_warm', 'fresh' => 'dog_boarding_fresh', 'playful' => 'dog_boarding_playful']),
            self::step('primary_cta', 'What should visitors do first?', 'select', true, 'knowledge_profile', 'primary_conversion_goal',
                helpText: 'A stay request is reviewed and confirmed by you. It is never an instant booking.',
                options: ['quote_request' => 'Request a stay (recommended)', 'consultation_booking' => 'Request a meet & greet', 'call' => 'Call us', 'calendar_booking' => 'Book a meet & greet in my calendar (only once you have activated a booking type)']),
            self::step('offers_meet_greet', 'Do you offer a meet & greet or facility tour?', 'boolean', true, 'answers', null),
            self::step('preferred_contact_channels', 'How do you prefer owners to contact you?', 'multi_select', false, 'answers', null, options: ['phone' => 'Phone call', 'text' => 'Text message', 'email' => 'Email']),
            self::step('contact_form_fields', 'What should your stay request form ask for?', 'multi_select', true, 'website_form', null,
                helpText: 'Name and a phone number are required. Keep private care details, such as medication or medical history, for a later conversation.',
                options: [
                    'name' => 'Name',
                    'phone' => 'Phone',
                    'email' => 'Email',
                    'dog_name' => "Dog's name",
                    'dog_breed_size' => 'Breed and size',
                    'dog_count' => 'Number of dogs',
                    'arrival_date' => 'Arrival date',
                    'departure_date' => 'Departure date',
                    'services_requested' => 'Services wanted',
                    'preferred_contact' => 'Best way to reach you',
                    'message' => 'Questions or notes',
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
        ?array $conditional = null,
        ?array $presets = null,
        ?array $rowFields = null,
        ?string $itemKind = null,
        ?string $itemType = null,
        ?bool $areaPages = null,
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

        foreach (['screen' => $screen, 'categories' => $categories, 'screen_prompt' => $screenPrompt, 'screen_help' => $screenHelp, 'presets' => $presets, 'row_fields' => $rowFields, 'item_kind' => $itemKind, 'item_type' => $itemType, 'defaults' => $defaults, 'template_hint' => $templateHint] as $name => $value) {
            if ($value !== null) {
                $step[$name] = $value;
            }
        }

        foreach (['shared_fact' => $sharedFact, 'suggest' => $suggest, 'owner_confirms' => $ownerConfirms] as $name => $flag) {
            if ($flag) {
                $step[$name] = true;
            }
        }

        if ($areaPages !== null) {
            $step['area_pages'] = $areaPages;
        }

        return $step;
    }
}
