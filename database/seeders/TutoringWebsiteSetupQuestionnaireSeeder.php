<?php

namespace Database\Seeders;

use App\Library\NicheBlueprint\Niches\TutoringBlueprint;
use App\Library\Website\Setup\QuestionnaireVersionPublisher;
use App\Models\QuestionnaireDefinition;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The guided Tutoring & Exam Preparation website setup: one questionnaire definition (niche `tutoring_exam_prep`) whose
 * versions are immutable and pinned per setup session, like every other niche's.
 *
 * It is DATA over the question types and target modules the wizard already supports, and it asks what the Tutoring
 * blueprint's own website intent says a tutoring site should say (subjects and grades, exam preparation, how a first lesson
 * works, who the teachers are, real testimonials only, small groups or individual attention only if true):
 *  - business identity and location reuse the shared-fact steps, so nothing already known is asked twice;
 *  - the lesson services write the real Services records; the subject presets are the blueprint's own student-form subjects;
 *  - confirmed differentiators, the audience and real testimonials are owner-confirmed Knowledge Profile facts;
 *  - how a first lesson works is the owner's own custom section, in their words;
 *  - the visitor's inquiry is the blueprint's `tutoring_form_student_inquiry` (Forms module), never a form this definition
 *    describes, so there is no form-field step; nothing here supplies a price, a result, a credential or a guarantee.
 *
 * Idempotent: an unchanged published version is left alone; a changed definition publishes a NEW version so sessions
 * mid-setup and generated websites keep the version they started on.
 */
class TutoringWebsiteSetupQuestionnaireSeeder extends Seeder
{
    public const KEY = 'tutoring_exam_prep_website_setup';

    public const NICHE_KEY = TutoringBlueprint::KEY;

    public function run(): void
    {
        DB::transaction(fn () => $this->activate());
    }

    private function activate(): void
    {
        $definition = QuestionnaireDefinition::firstOrCreate(
            ['key' => self::KEY],
            ['scope' => 'website_niche_setup', 'niche_key' => self::NICHE_KEY, 'name' => 'Tutoring Website Setup'],
        );

        $tree = self::canonical(self::steps());
        $published = $definition->publishedVersion();

        if ($published !== null && self::canonical($published->storedSteps()) === $tree) {
            return;
        }

        $publisher = app(QuestionnaireVersionPublisher::class);
        $draft = $definition->draftVersion();

        if ($draft === null) {
            $draft = $publisher->createDraft($definition, self::steps());
        } elseif (self::canonical($draft->storedSteps()) !== $tree) {
            throw new DomainException(
                'Refusing to seed the Tutoring questionnaire: the definition "' . $definition->key . '" already has a draft (version id ' . $draft->id
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
     * The lesson types a tutoring business may offer. The labels are the subjects the blueprint's own student inquiry form
     * lets a parent choose, each with a plain, neutral description the owner edits freely: a starting point, never a claim
     * (no result, grade, credential or price).
     *
     * @return array<int, array{key: string, label: string, description: string}>
     */
    public static function servicePresets(): array
    {
        return [
            ['key' => 'mathematics', 'label' => 'Mathematics Tutoring', 'description' => 'Lessons that work through the topics a student is studying in mathematics, at their pace.'],
            ['key' => 'english', 'label' => 'English Tutoring', 'description' => 'Lessons in reading, writing and language skills, matched to what the student is working on.'],
            ['key' => 'sciences', 'label' => 'Science Tutoring', 'description' => 'Lessons that help a student understand and practise the science they are studying.'],
            ['key' => 'native_language', 'label' => 'Native Language Lessons', 'description' => 'Lessons in the student\'s native language, from reading and writing to conversation.'],
            ['key' => 'exam_preparation', 'label' => 'Exam Preparation', 'description' => 'Lessons that prepare a student for an upcoming exam by practising its topics and format.'],
        ];
    }

    /** @return array<string, string> */
    public static function differentiatorOptions(): array
    {
        return [
            'one_on_one' => 'Lessons are one-on-one',
            'small_groups' => 'We teach in small groups',
            'in_person' => 'In-person lessons are available',
            'online' => 'Online lessons are available',
            'exam_preparation' => 'We prepare students for exams',
            'trial_lesson' => 'We offer a trial lesson or a free consultation',
            'progress_updates' => 'Parents receive updates on progress',
            'flexible_scheduling' => 'Lesson times are flexible',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function steps(): array
    {
        return [
            // A. Business identity — the confirm screen is prefilled from the real Business.
            self::step('business_name', 'Business name', 'text', true, 'business', 'name', screen: 'basics', sharedFact: true, screenPrompt: 'Confirm your business details', screenHelp: 'We already have these from your MotionGrove account. Change anything that is out of date, or just continue.'),
            self::step('phone', 'Phone number', 'tel', true, 'business', 'phone', screen: 'basics', sharedFact: true),
            self::step('email', 'Contact email', 'email', true, 'business', 'email', screen: 'basics', sharedFact: true),
            self::step('logo', 'Logo', 'image', false, 'website_look', 'logo', screen: 'basics'),
            self::step('base_location', 'Where do you teach?', 'location', true, 'business_location', null, sharedFact: true, helpText: 'Your main location. We use it for your contact details and to suggest nearby areas.'),
            self::step('service_area_cities', 'Which areas do your students come from?', 'string_list', true, 'business_location', null, sharedFact: true, suggest: true, areaPages: false, helpText: 'One place per row. We only mention areas you list here and never create a separate page for each one.'),

            // B. What you teach — real Services records
            self::step('lesson_services', 'What do you teach?', 'repeatable_group', true, 'business_service', null,
                helpText: 'Choose each subject or kind of lesson you actually offer, and describe it in your own words. Only the ones you keep visible appear on your website.',
                presets: self::servicePresets(), rowFields: ['name', 'description', 'features', 'visible'], itemKind: 'service'),
            self::step('ideal_customers', 'Who are your lessons for?', 'textarea', false, 'knowledge_profile', 'ideal_customers', ownerConfirms: true,
                helpText: 'For example the grades or ages you teach. Only what you write is published.'),
            self::step('how_lessons_work', 'How does a lesson work? (optional)', 'repeatable_group', false, 'custom_section', null,
                helpText: 'Add a section in your own words about how a first lesson works and how you teach. Leave it empty and no such section is created.'),

            // C. Trust — only what the owner confirms
            self::step('about_story', 'Tell families about your business', 'textarea', false, 'business', 'description',
                helpText: 'A short description in your own words. We will not add years of experience, results or qualifications you do not give us.'),
            self::step('advantages', 'What is true about your lessons?', 'multi_select', false, 'knowledge_profile', 'differentiators', ownerConfirms: true, options: self::differentiatorOptions(),
                helpText: 'Choose only what is genuinely true. Each one is shown to visitors as a fact about you.'),
            self::step('testimonials', 'Would you like to feature reviews from families?', 'repeatable_group', false, 'knowledge_profile', 'testimonials', ownerConfirms: true,
                helpText: 'Add only real reviews from real customers. With none, the section is simply left out.'),
            self::step('faq_items', 'What questions should your website answer?', 'repeatable_group', false, 'faq', null,
                helpText: 'Add the questions parents ask you and your own answers. A question you do not answer is not shown.'),

            // D. Images — the media library only
            self::step('gallery', 'Add photos (optional)', 'photo_upload', false, 'gallery', null,
                helpText: 'Upload real photos of your space or lessons if you have them. We never create photos for you.',
                categories: [['value' => 'space', 'label' => 'Learning space'], ['value' => 'team', 'label' => 'Teachers'], ['value' => 'branding', 'label' => 'Branding']]),

            // E. Look and conversion
            self::step('brand_personality', 'What style fits your business?', 'select', true, 'knowledge_profile', 'brand_voice',
                helpText: 'This picks the look we start with. You can compare and change it before you generate.',
                options: ['bold' => 'Bold & confident', 'calm' => 'Calm & classic', 'premium' => 'Premium & dark', 'friendly' => 'Friendly & bright'],
                templateHint: ['bold' => 'tutoring_modern', 'calm' => 'tutoring_editorial', 'premium' => 'tutoring_luxury', 'friendly' => 'tutoring_conversion']),
            self::step('primary_cta', 'What should visitors do first?', 'select', true, 'knowledge_profile', 'primary_conversion_goal',
                helpText: 'The student inquiry form from your Tutoring setup is what visitors fill in. An inquiry is never an instant booking.',
                options: ['quote_request' => 'Send a student inquiry (recommended)', 'call' => 'Call us']),
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
        ?array $presets = null,
        ?array $rowFields = null,
        ?string $itemKind = null,
        ?bool $areaPages = null,
        ?array $templateHint = null,
        bool $ownerConfirms = false,
    ): array {
        $step = [
            'key' => $key, 'prompt' => $prompt, 'help_text' => $helpText, 'input_type' => $inputType, 'required' => $required, 'options' => $options,
            'conditional_visibility' => null, 'target_module' => $targetModule, 'target_field' => $targetField, 'ai_instructions' => null,
        ];

        foreach (['screen' => $screen, 'categories' => $categories, 'screen_prompt' => $screenPrompt, 'screen_help' => $screenHelp, 'presets' => $presets, 'row_fields' => $rowFields, 'item_kind' => $itemKind, 'template_hint' => $templateHint] as $name => $value) {
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
