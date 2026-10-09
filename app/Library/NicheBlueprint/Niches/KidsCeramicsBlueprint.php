<?php

namespace App\Library\NicheBlueprint\Niches;

use App\Library\NicheBlueprint\Adapters\AcquisitionPurposeComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BookingTypeComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CitationRecommendationsComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CrmPipelineComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CustomFieldComponentAdapter;
use App\Library\NicheBlueprint\Adapters\FormComponentAdapter;
use App\Library\NicheBlueprint\Adapters\SeoStrategyComponentAdapter;
use App\Library\NicheBlueprint\Adapters\TagSetComponentAdapter;

/**
 * The "Kids Ceramics Studio / Kids Ceramics Classes" niche Blueprint.
 *
 * A LOCAL, physical Business with ONE customer pipeline and ONE acquisition
 * purpose (Class Enrollment). There is no teacher or recruitment funnel here.
 *
 * Its emphasis is local discovery first — Google Business Profile, Website,
 * local SEO, reviews, booking, citations, rank tracking — with Meta Ads as an
 * optional extra, which is why the purpose's guidance says so. Local keyword
 * patterns use the `{city}` placeholder: they are PATTERNS filled from the
 * Business's real Location, never a seeded claim about any one city.
 *
 * CONFIGURATION ONLY: no price, cost or target — those are the Business's
 * answers to the class-enrolment questions.
 */
final class KidsCeramicsBlueprint
{
    public const KEY = 'kids_ceramics';

    public const NAME = 'Kids Ceramics Studio / Classes';

    public const BROAD_INDUSTRY = 'kids_activities';

    public const PIPELINE = 'ceramics_class_pipeline';

    public const FORM = 'ceramics_form_class_enquiry';

    private const CLASS_INTERESTS = ['Weekly class', 'Trial class', 'Birthday workshop', 'Holiday workshop', 'Not sure yet'];

    /**
     * @return list<array{key: string, type: string, feature: string, payload: array<string, mixed>}>
     */
    public static function components(): array
    {
        return [
            self::c('ceramics_tags', TagSetComponentAdapter::TYPE, 'crm', [
                'tags' => ['Parent', 'Birthday workshop', 'Holiday workshop', 'Trial class', 'Weekly class', 'Review requested'],
            ]),
            self::c('ceramics_cf_child_age', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Child age', 'type' => 'number']),
            self::c('ceramics_cf_class_interest', CustomFieldComponentAdapter::TYPE, 'crm', [
                'label' => 'Class interest', 'type' => 'select', 'options' => self::CLASS_INTERESTS,
            ]),

            // "Enrolled" is the Opportunity's WON status and "lost" its LOST status (canonical CRM
            // semantics); only the working stages are columns.
            self::c(self::PIPELINE, CrmPipelineComponentAdapter::TYPE, 'crm', [
                'template_key' => 'blueprint_kids_ceramics',
                'template_version' => 1,
                'pipeline_key' => 'class_enrollment',
                'name' => 'Class Enrollment',
                'stages' => [
                    ['name' => 'New inquiry', 'semantic_key' => 'new_inquiry'],
                    ['name' => 'Contacted', 'semantic_key' => 'contacted'],
                    ['name' => 'Trial class booked', 'semantic_key' => 'trial_booked'],
                    ['name' => 'Attended', 'semantic_key' => 'attended'],
                ],
            ]),

            self::c(self::FORM, FormComponentAdapter::TYPE, 'forms', [
                'name' => 'Class enquiry',
                'intro' => 'Tell us about your child and which class interests you, and we will reply with dates and availability.',
                'submit_label' => 'Send enquiry',
                'success_message' => 'Thank you! We will reply shortly.',
                'fields' => [
                    ['key' => 'full_name', 'label' => 'Parent name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                    ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
                    ['key' => 'child_age', 'label' => "Child's age", 'type' => 'number', 'required' => true, 'custom_field_label' => 'Child age'],
                    ['key' => 'class_interest', 'label' => 'What are you interested in?', 'type' => 'select', 'required' => true,
                        'options' => self::CLASS_INTERESTS, 'custom_field_label' => 'Class interest'],
                    ['key' => 'message', 'label' => 'Anything else we should know?', 'type' => 'textarea', 'required' => false],
                ],
                'design' => ['accent' => '#c2410c', 'background' => '#ffffff'],
                'create_opportunity' => true,
                'pipeline_component_key' => self::PIPELINE,
            ]),

            self::c('ceramics_booking_trial', BookingTypeComponentAdapter::TYPE, 'calendar', [
                'name' => 'Trial class',
                'description' => 'A first class so your child can try ceramics before committing.',
                'duration_minutes' => 60,
                'minimum_notice_minutes' => 1440,
                'buffer_after_minutes' => 15,
                'slot_interval_minutes' => 30,
                'booking_window_days' => 60,
            ]),

            self::c('ceramics_purpose_class_enrollment', AcquisitionPurposeComponentAdapter::TYPE, 'crm', [
                'purpose_key' => 'class_enrollment',
                'name' => 'Class Enrollment',
                'outcome_type' => 'enrolment',
                'calculator' => 'class_enrollment',
                'sort_order' => 1,
                'pipeline_component_key' => self::PIPELINE,
                'form_component_key' => self::FORM,
                'labels' => [
                    'person' => 'child',
                    'lead' => 'qualified class inquiry',
                    'leads' => 'qualified class inquiries',
                    'outcome' => 'enrolment',
                    'outcomes' => 'enrolments',
                    'cost_per_lead' => 'Cost / qualified inquiry',
                    'cost_per_outcome' => 'Cost / enrolment',
                    'pipeline_cta' => 'Open Class Enrollment pipeline',
                ],
                'guidance' => [
                    ['title' => 'Be easy to find nearby first', 'body' => 'Most parents look for a children\'s class close to home. Complete your Google Business Profile (address, hours, photos, services) before spending on ads, keep your name, address and phone identical everywhere, and ask happy parents for reviews.'],
                    ['title' => 'Make booking effortless', 'body' => 'A clear trial-class booking button on your website and your Google profile turns local searches into attended classes.'],
                    ['title' => 'Meta ads are optional', 'body' => 'Once your local presence is solid, Meta ads can add demand for trial classes, birthday workshops and holiday workshops. Treat them as an extra, and judge them by enrolments, not clicks.'],
                ],
                'website_intent' => [
                    'audience' => 'Parents looking for a children\'s ceramics class nearby',
                    'cta' => 'Class enquiry form / trial class booking',
                    'pages' => [
                        ['page_key' => 'kids-ceramics-classes', 'title' => 'Kids ceramics classes', 'summary' => 'What classes you run, for which ages, and how to join.'],
                        ['page_key' => 'trial-class', 'title' => 'Book a trial class', 'summary' => 'The booking call to action for a first class.'],
                        ['page_key' => 'birthday-workshops', 'title' => 'Birthday ceramics workshops', 'summary' => 'Party workshops, group sizes and how booking works.'],
                        ['page_key' => 'holiday-workshops', 'title' => 'Holiday and school-break workshops', 'summary' => 'Only if you run them.'],
                        ['page_key' => 'about-the-studio', 'title' => 'About the studio', 'summary' => 'Who you are and what a class feels like.'],
                        ['page_key' => 'find-us', 'title' => 'Location and contact', 'summary' => 'Clear physical address, opening hours, a map and how to get in touch.'],
                    ],
                    'emphasis' => [
                        'Local intent: the studio\'s real address and area on every key page',
                        'A booking button on every page',
                        'Real photos of children\'s work (with permission)',
                        'Reviews and Google Business Profile consistency',
                    ],
                    'content_prompts' => [
                        'Describe the ceramics classes for children: ages, group size, what they make and how a first class works.',
                        'Give the studio\'s full address, opening hours and how to book a trial class.',
                    ],
                ],
            ]),

            self::c('ceramics_seo', SeoStrategyComponentAdapter::TYPE, 'seo_module', [
                'keyword_patterns' => [
                    ['pattern' => 'kids ceramics classes {city}', 'intent' => 'transactional'],
                    ['pattern' => 'pottery classes for children {city}', 'intent' => 'transactional'],
                    ['pattern' => 'ceramics birthday workshop {city}', 'intent' => 'transactional'],
                    ['pattern' => 'after-school ceramics {city}', 'intent' => 'local'],
                    ['pattern' => 'kids activities near me', 'intent' => 'local'],
                ],
                'faq_topics' => ['What age is a ceramics class for?', 'Do you offer a trial class?', 'How does a birthday workshop work?', 'Are children\'s creations fired and glazed?', 'Where is the studio?'],
                'schema_types' => ['LocalBusiness', 'FAQPage'],
                'schema_notes' => 'LocalBusiness with the real address and opening hours on the home and contact pages; FAQPage on the classes page.',
                'internal_links' => [
                    ['from' => 'home', 'to' => 'trial-class', 'anchor' => 'book a trial class'],
                    ['from' => 'kids-ceramics-classes', 'to' => 'birthday-workshops', 'anchor' => 'birthday workshops'],
                ],
            ]),

            self::c('ceramics_citations', CitationRecommendationsComponentAdapter::TYPE, 'seo_module', [
                'recommendations' => [
                    ['directory_key' => 'bing_places', 'importance' => 'recommended', 'guidance' => 'Keep name, address and phone identical to your Google Business Profile.'],
                    ['directory_key' => 'apple_business', 'importance' => 'recommended', 'guidance' => 'Parents often use Apple Maps on their phones.'],
                    ['directory_key' => 'facebook_pages', 'importance' => 'recommended', 'guidance' => 'Parents also look for local studios on Facebook.'],
                    ['directory_key' => 'foursquare', 'importance' => 'optional', 'guidance' => 'Lower priority than the three above.'],
                ],
            ]),
        ];
    }

    public static function positionFor(string $type, int $ordinal): int
    {
        return NicheBlueprintPositions::for($type, $ordinal);
    }

    /** @return array{key: string, type: string, feature: string, payload: array<string, mixed>} */
    private static function c(string $key, string $type, string $feature, array $payload): array
    {
        return ['key' => $key, 'type' => $type, 'feature' => $feature, 'payload' => $payload];
    }
}
