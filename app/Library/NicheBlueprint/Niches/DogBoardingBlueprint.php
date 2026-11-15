<?php

namespace App\Library\NicheBlueprint\Niches;

use App\Library\NicheBlueprint\Adapters\AutomationWorkflowComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BookingTypeComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CitationRecommendationsComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CrmPipelineComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CustomFieldComponentAdapter;
use App\Library\NicheBlueprint\Adapters\FormComponentAdapter;
use App\Library\NicheBlueprint\Adapters\SeoStrategyComponentAdapter;
use App\Library\NicheBlueprint\Adapters\TagSetComponentAdapter;
use App\Library\NicheBlueprint\Adapters\WebsiteConfigComponentAdapter;

/**
 * The "Dog Boarding" niche Blueprint — a local business that boards (and
 * optionally day-cares, trains, grooms or transports) other people's dogs, and
 * uses MotionGrove to run its own customer-facing website, inquiries and growth.
 *
 * NOT the Dog Business white-label marketing-site preset: this is a customer
 * Business niche, vertical-bound exactly like Tutoring and Kids Ceramics (the
 * BusinessIndustry enum is locked; the niche is a row of `business_verticals`).
 *
 * TRUTHFULNESS RULES baked into the configuration:
 *  - an inquiry is a REQUEST, never a confirmed stay. MotionGrove has no
 *    kennel-occupancy data, so nothing here shows or implies availability; the
 *    pipeline places "Booking Confirmed" behind an owner-reviewed "Pending
 *    Approval" stage and the form's success message says a request was received.
 *  - every automation is installed as a DRAFT by the existing workflow adapter
 *    (the owner publishes it), every message waits for a send window and stops
 *    on a reply, an opt-out or a closed deal, and no workflow re-nags: each
 *    customer-facing reminder sends at most once.
 *  - no price, capacity, policy, credential or review is seeded. Rates and
 *    policies are the owner's questionnaire answers.
 *  - private pet-care detail (medication, behaviour, health) is deliberately NOT
 *    a public/required form field: the inquiry form asks only for the basics
 *    and a free-text note, and the owner collects care detail later from a
 *    known contact.
 *
 * Declined, canceled and closed outcomes are the Opportunity's LOST status
 * (canonical CRM semantics), not extra columns; "Booking Confirmed" is a working
 * stage the owner moves a request to — it is never set automatically.
 */
final class DogBoardingBlueprint
{
    public const KEY = 'dog_boarding';

    public const NAME = 'Dog Boarding';

    public const VERTICAL_KEY = self::KEY;

    /** The BusinessIndustry enum is locked and has no pet-care case. */
    public const BROAD_INDUSTRY = 'other';

    public const VERSION = '1.0.0';

    public const PIPELINE_TEMPLATE_KEY = 'blueprint_dog_boarding';

    public const PIPELINE = 'dogboard_pipeline_stays';

    public const FORM_STAY = 'dogboard_form_stay_request';

    public const FORM_STAY_NAME = 'Request a Stay';

    public const FORM_TOUR = 'dogboard_form_meet_greet';

    public const FORM_TOUR_NAME = 'Request a Meet & Greet';

    /** The shipped, active website template this niche prefers (WebsiteTemplateSeeder). */
    public const PREFERRED_TEMPLATE = 'dog_boarding_warm';

    public const STAGES = [
        ['New Inquiry', 'new_inquiry'],
        ['Awaiting Pet Details', 'awaiting_pet_details'],
        ['Meet & Greet / Assessment', 'meet_and_greet'],
        ['Pending Approval', 'pending_approval'],
        ['Booking Confirmed', 'booking_confirmed'],
        ['Stay Completed', 'stay_completed'],
    ];

    public const SERVICES = ['Overnight boarding', 'Daycare', 'Long-term stay', 'Puppy care', 'Grooming', 'Training', 'Transport', 'Not sure yet'];

    public const SIZES = ['Small (under 20 lb)', 'Medium (20-50 lb)', 'Large (50-90 lb)', 'Giant (90 lb+)', 'Not sure'];

    public const CONTACT_CHANNELS = ['Phone call', 'Text message', 'Email'];

    /**
     * @return list<array{key: string, type: string, feature: string, payload: array<string, mixed>}>
     */
    public static function components(): array
    {
        return [
            self::c('dogboard_tags', TagSetComponentAdapter::TYPE, 'crm', ['tags' => [
                'Boarding inquiry', 'Meet & greet requested', 'Confirmed stay', 'Past boarder', 'Review requested', 'Pet details needed',
            ]]),

            self::c('dogboard_cf_dog_name', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Dog name', 'type' => 'text']),
            self::c('dogboard_cf_dog_breed', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Dog breed', 'type' => 'text']),
            self::c('dogboard_cf_dog_size', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Dog size', 'type' => 'select', 'options' => self::SIZES]),
            self::c('dogboard_cf_dog_count', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Number of dogs', 'type' => 'number']),
            self::c('dogboard_cf_arrival', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Requested arrival date', 'type' => 'date']),
            self::c('dogboard_cf_departure', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Requested departure date', 'type' => 'date']),
            self::c('dogboard_cf_services', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Requested services', 'type' => 'multi_select', 'options' => self::SERVICES]),
            self::c('dogboard_cf_channel', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Preferred contact method', 'type' => 'select', 'options' => self::CONTACT_CHANNELS]),

            self::c(self::PIPELINE, CrmPipelineComponentAdapter::TYPE, 'crm', [
                'template_key' => self::PIPELINE_TEMPLATE_KEY,
                'template_version' => 1,
                'pipeline_key' => 'stay_requests',
                'name' => 'Stay Requests',
                'stages' => array_map(fn (array $s) => ['name' => $s[0], 'semantic_key' => $s[1]], self::STAGES),
            ]),

            self::c(self::FORM_STAY, FormComponentAdapter::TYPE, 'forms', [
                'name' => self::FORM_STAY_NAME,
                'intro' => 'Tell us about your dog and the dates you have in mind. This is a request, not a confirmed booking — we will review it and get back to you.',
                'submit_label' => 'Send stay request',
                'success_message' => 'Thank you! {business} has your stay request. This is not a confirmed booking yet — we will review it and contact you to confirm the details.',
                'fields' => [
                    ['key' => 'full_name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                    ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                    ['key' => 'dog_name', 'label' => "Your dog's name", 'type' => 'text', 'required' => true, 'custom_field_label' => 'Dog name'],
                    ['key' => 'dog_breed', 'label' => 'Breed (if you know it)', 'type' => 'text', 'required' => false, 'custom_field_label' => 'Dog breed'],
                    ['key' => 'dog_size', 'label' => 'Size', 'type' => 'select', 'required' => false, 'options' => self::SIZES, 'custom_field_label' => 'Dog size'],
                    ['key' => 'dog_count', 'label' => 'How many dogs?', 'type' => 'number', 'required' => false, 'custom_field_label' => 'Number of dogs'],
                    ['key' => 'arrival', 'label' => 'Preferred arrival date', 'type' => 'date', 'required' => false, 'custom_field_label' => 'Requested arrival date'],
                    ['key' => 'departure', 'label' => 'Preferred departure date', 'type' => 'date', 'required' => false, 'custom_field_label' => 'Requested departure date'],
                    ['key' => 'services', 'label' => 'What are you looking for?', 'type' => 'multi_select', 'required' => false, 'options' => self::SERVICES, 'custom_field_label' => 'Requested services'],
                    ['key' => 'channel', 'label' => 'Best way to reach you', 'type' => 'select', 'required' => false, 'options' => self::CONTACT_CHANNELS, 'custom_field_label' => 'Preferred contact method'],
                    ['key' => 'notes', 'label' => 'Questions, or anything we should know?', 'type' => 'textarea', 'required' => false],
                    ['key' => 'consent_transactional', 'label' => 'By checking this box, I consent to receive transactional SMS/text messages and emails from {business} about my request and stay. Message frequency may vary. Msg & data rates may apply. Reply HELP for help or STOP to unsubscribe.', 'type' => 'consent_transactional', 'required' => false],
                ],
                'design' => ['accent' => '#b45309', 'background' => '#ffffff'],
                'create_opportunity' => true,
                'pipeline_component_key' => self::PIPELINE,
            ]),

            self::c(self::FORM_TOUR, FormComponentAdapter::TYPE, 'forms', [
                'name' => self::FORM_TOUR_NAME,
                'intro' => 'Would you like to meet us and see the facility first? Tell us when suits you; we will confirm a time.',
                'submit_label' => 'Request a meet & greet',
                'success_message' => 'Thank you! {business} has your meet & greet request. It is not scheduled until we confirm a time with you.',
                'fields' => [
                    ['key' => 'full_name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                    ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
                    ['key' => 'dog_name', 'label' => "Your dog's name", 'type' => 'text', 'required' => false, 'custom_field_label' => 'Dog name'],
                    ['key' => 'notes', 'label' => 'Days or times that suit you', 'type' => 'textarea', 'required' => false],
                    ['key' => 'consent_transactional', 'label' => 'By checking this box, I consent to receive transactional SMS/text messages and emails from {business} about my request. Msg & data rates may apply. Reply STOP to unsubscribe.', 'type' => 'consent_transactional', 'required' => false],
                ],
                'design' => ['accent' => '#b45309', 'background' => '#ffffff'],
                'create_opportunity' => true,
                'pipeline_component_key' => self::PIPELINE,
            ]),

            // A real calendar booking type. Installing it connects NO provider: it is bookable only once the
            // owner has connected a calendar, and the website must not claim otherwise (see the site writer).
            self::c('dogboard_booking_meet_greet', BookingTypeComponentAdapter::TYPE, 'calendar', [
                'name' => 'Meet & greet',
                'description' => 'Meet the team and see where your dog would stay before you book a stay.',
                'duration_minutes' => 30,
                'minimum_notice_minutes' => 1440,
                'buffer_after_minutes' => 15,
                'slot_interval_minutes' => 30,
                'booking_window_days' => 45,
            ]),

            self::c('dogboard_website', WebsiteConfigComponentAdapter::TYPE, 'website_generation', [
                'template_key' => self::PREFERRED_TEMPLATE,
                'pages' => [
                    ['page' => 'home', 'title' => 'Home', 'sections' => ['hero', 'services', 'image_text', 'gallery', 'faq', 'cta']],
                    ['page' => 'services_overview', 'title' => 'Services', 'sections' => ['hero', 'services']],
                    ['page' => 'service_detail', 'title' => 'Dog Boarding / Dog Daycare', 'sections' => ['hero', 'text', 'image_text', 'faq', 'cta']],
                    ['page' => 'packages', 'title' => 'Rates & Packages', 'sections' => ['hero', 'services']],
                    ['page' => 'about', 'title' => 'About Us', 'sections' => ['hero', 'text']],
                    ['page' => 'gallery', 'title' => 'Gallery', 'sections' => ['hero', 'gallery']],
                    ['page' => 'faq', 'title' => 'FAQs', 'sections' => ['hero', 'faq']],
                    ['page' => 'custom_section', 'title' => 'Boarding Policies', 'sections' => ['hero', 'custom_section']],
                    ['page' => 'contact', 'title' => 'Request a Stay', 'sections' => ['hero', 'contact_details', 'form']],
                ],
                'navigation' => ['Dog Boarding', 'Daycare', 'Rates', 'Gallery', 'About', 'FAQs', 'Request a Stay'],
                'content_prompts' => [
                    'Write only from the owner\'s confirmed setup answers. Never invent prices, capacity, awards, certifications, years in business, staff counts, supervision claims, veterinary partnerships or reviews.',
                    'A stay is a REQUEST the owner confirms. Never promise availability or an instant booking.',
                ],
            ]),

            self::c('dogboard_seo', SeoStrategyComponentAdapter::TYPE, 'seo_module', [
                'keyword_patterns' => [
                    ['pattern' => 'dog boarding {city}', 'intent' => 'transactional'],
                    ['pattern' => 'overnight dog boarding {city}', 'intent' => 'transactional'],
                    ['pattern' => 'dog daycare {city}', 'intent' => 'transactional'],
                    ['pattern' => 'dog kennel {city}', 'intent' => 'local'],
                    ['pattern' => 'dog boarding near {area}', 'intent' => 'local'],
                ],
                'faq_topics' => [
                    'What vaccinations do you require?',
                    'How do I request a stay?',
                    'What should I bring for my dog?',
                    'Can I visit the facility first?',
                    'What is your cancellation policy?',
                ],
                'schema_types' => ['LocalBusiness', 'FAQPage'],
                'schema_notes' => 'LocalBusiness with the real address, phone and opening hours only; FAQPage only from the owner\'s own FAQ answers. No ratings or reviews markup unless real reviews exist.',
                'internal_links' => [
                    ['from' => 'home', 'to' => 'request-a-stay', 'anchor' => 'request a stay'],
                    ['from' => 'rates', 'to' => 'request-a-stay', 'anchor' => 'request a stay'],
                ],
            ]),

            self::c('dogboard_citations', CitationRecommendationsComponentAdapter::TYPE, 'seo_module', [
                'recommendations' => [
                    ['directory_key' => 'bing_places', 'importance' => 'recommended', 'guidance' => 'Keep name, address and phone identical to your Google Business Profile.'],
                    ['directory_key' => 'apple_business', 'importance' => 'recommended', 'guidance' => 'Dog owners often search on their phones with Apple Maps.'],
                    ['directory_key' => 'facebook_pages', 'importance' => 'recommended', 'guidance' => 'Local dog owners often ask neighbours and Facebook groups for boarding recommendations.'],
                    ['directory_key' => 'foursquare', 'importance' => 'optional', 'guidance' => 'Lower priority than the three above.'],
                ],
            ]),

            ...self::workflows(),
        ];
    }

    /**
     * The niche's automation DRAFTS. None publishes on install. Each message is behind an
     * opt-out check, a send window, and a reply / closed-deal stop; no workflow repeats a
     * reminder. Wording makes no availability, price or safety promise.
     *
     * @return list<array{key: string, type: string, feature: string, payload: array<string, mixed>}>
     */
    public static function workflows(): array
    {
        $sig = "\n\n— {{business.name}}";
        $window = ['type' => 'wait_for_send_window', 'business_hours' => true];
        $quiet = ['type' => 'wait_for_send_window', 'business_hours' => false];
        $task = fn (string $title, int $hours) => ['type' => 'internal_notification', 'message' => $title, 'task' => ['title' => $title, 'due_in_hours' => $hours]];

        return [
            // New inquiry: tag, notify the owner (a task that surfaces in Home), acknowledge once, and flag it
            // for the owner if it is still unanswered a day later (lead recovery).
            self::wf('dogboard_wf_new_inquiry', 'New Stay Request', 'form_submitted', null, self::FORM_STAY_NAME, [
                ['type' => 'add_tag', 'tag' => 'Boarding inquiry'],
                $task('Review the new stay request from {{contact.first_name}} and reply', 4),
                ['type' => 'end_if_unsubscribed'],
                $quiet,
                ['type' => 'send_email', 'subject' => 'We received your stay request, {{contact.first_name}}', 'body' => "Hi {{contact.first_name}},\n\nThanks for contacting {{business.name}}. We have your request and will review it shortly. This is a request only — your stay is not confirmed until we confirm it with you.\n\nIf anything changes, just reply to this email.".$sig],
                ['type' => 'wait', 'amount' => 1, 'unit' => 'days'],
                ['type' => 'end_if_replied'],
                ['type' => 'end_if_no_open_deal'],
                $task('Unanswered stay request: follow up with {{contact.first_name}}', 4),
            ]),

            self::wf('dogboard_wf_meet_greet_request', 'New Meet & Greet Request', 'form_submitted', null, self::FORM_TOUR_NAME, [
                ['type' => 'add_tag', 'tag' => 'Meet & greet requested'],
                $task('Offer {{contact.first_name}} a meet & greet time', 4),
                ['type' => 'end_if_unsubscribed'],
                $quiet,
                ['type' => 'send_email', 'subject' => 'We received your meet & greet request', 'body' => "Hi {{contact.first_name}},\n\nThanks for asking to visit {{business.name}}. We will reply with a time that works. Your visit is not scheduled until we confirm it.".$sig],
            ]),

            // Missing information: ask once, then hand back to the owner. No repeated nagging.
            self::wf('dogboard_wf_missing_details', 'Missing Pet Details', 'opportunity_stage_changed', 'Awaiting Pet Details', null, [
                ['type' => 'add_tag', 'tag' => 'Pet details needed'],
                $task('Ask {{contact.first_name}} for the missing details for their request', 4),
                ['type' => 'end_if_unsubscribed'], ['type' => 'end_if_no_open_deal'],
                $window,
                ['type' => 'send_email', 'subject' => 'A few details for your stay request', 'body' => "Hi {{contact.first_name}},\n\nTo review your request we need a few more details about your dog and your dates. Please reply to this email with whatever you can share.".$sig],
                ['type' => 'wait', 'amount' => 3, 'unit' => 'days'],
                ['type' => 'end_if_replied'], ['type' => 'end_if_no_open_deal'],
                $task('{{contact.first_name}} has not sent the missing details: call, or close the request', 4),
            ]),

            self::wf('dogboard_wf_meet_greet', 'Meet & Greet Scheduling', 'opportunity_stage_changed', 'Meet & Greet / Assessment', null, [
                $task('Confirm a meet & greet time with {{contact.first_name}}', 4),
                ['type' => 'end_if_unsubscribed'], ['type' => 'end_if_no_open_deal'],
                $window,
                ['type' => 'send_email', 'subject' => 'Let us find a time to meet', 'body' => "Hi {{contact.first_name}},\n\nWe would like to arrange a meet & greet before your stay. Reply with days and times that suit you, or use our booking link if we have shared one. Appointment reminders, where set up, come from our booking calendar.".$sig],
            ]),

            // Pending booking: internal only. Never contacts the customer, never says "confirmed".
            self::wf('dogboard_wf_pending_approval', 'Pending Approval Review', 'opportunity_stage_changed', 'Pending Approval', null, [
                $task('Stay request from {{contact.first_name}} awaits your decision: approve or decline. Nothing is confirmed until you do.', 24),
            ]),

            // Pre-stay: only after the OWNER moves the request to Booking Confirmed.
            self::wf('dogboard_wf_booking_confirmed', 'Stay Confirmed', 'opportunity_stage_changed', 'Booking Confirmed', null, [
                ['type' => 'add_tag', 'tag' => 'Confirmed stay'],
                ['type' => 'end_if_unsubscribed'], ['type' => 'end_if_no_open_deal'],
                $quiet,
                ['type' => 'send_email', 'subject' => 'Your stay with {{business.name}} is confirmed', 'body' => "Hi {{contact.first_name}},\n\nWe are happy to confirm your stay. Please check the confirmation details we sent for dates, check-in and what to bring. If anything is unclear, reply to this email or call {{business.phone}}.".$sig],
            ]),

            // Post-stay: one review ask, only if the owner has set a review link.
            self::wf('dogboard_wf_post_stay', 'After the Stay', 'opportunity_stage_changed', 'Stay Completed', null, [
                ['type' => 'add_tag', 'tag' => 'Past boarder'],
                ['type' => 'end_if_unsubscribed'], ['type' => 'end_if_no_review_link'],
                ['type' => 'wait', 'amount' => 2, 'unit' => 'days'],
                ['type' => 'end_if_unsubscribed'], ['type' => 'end_if_tagged', 'tag' => 'Review requested'],
                $window,
                ['type' => 'add_tag', 'tag' => 'Review requested'],
                ['type' => 'send_email', 'subject' => 'How was your dog\'s stay?', 'body' => "Hi {{contact.first_name}},\n\nThank you for trusting {{business.name}} with your dog. If you have a moment, a short review helps other dog owners: {{business.review_link}}".$sig],
            ]),
        ];
    }

    public static function positionFor(string $type, int $ordinal): int
    {
        return NicheBlueprintPositions::for($type, $ordinal);
    }

    private static function wf(string $key, string $name, string $trigger, ?string $stage, ?string $form, array $steps): array
    {
        $payload = ['name' => $name, 'trigger_type' => $trigger, 'steps' => self::optOutBeforeSends(self::guardsAfterWindow($steps))];

        if ($form !== null) {
            $payload['trigger_form'] = $form;
        }

        if ($stage !== null) {
            $payload['trigger_stage'] = $stage;
        }

        return self::c($key, AutomationWorkflowComponentAdapter::TYPE, 'automations', $payload);
    }

    /**
     * A send-window wait can park a run for hours, so a reply, opt-out or closed deal can land during it:
     * guards sitting directly before a window wait move to directly after it.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private static function guardsAfterWindow(array $steps): array
    {
        $out = [];
        $held = [];

        foreach ($steps as $step) {
            if (str_starts_with($step['type'], 'end_if_')) {
                $held[] = $step;

                continue;
            }

            if ($step['type'] === 'wait_for_send_window') {
                $out[] = $step;
                array_push($out, ...$held);
            } else {
                array_push($out, ...$held);
                $out[] = $step;
            }
            $held = [];
        }

        array_push($out, ...$held);

        return $out;
    }

    /**
     * After any wait, the first send is preceded by an opt-out check (consent can be withdrawn while a run is parked).
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private static function optOutBeforeSends(array $steps): array
    {
        $out = [];
        $checked = false;

        foreach ($steps as $step) {
            if (in_array($step['type'], ['wait', 'wait_for_send_window'], true)) {
                $checked = false;
            } elseif ($step['type'] === 'end_if_unsubscribed') {
                $checked = true;
            } elseif (in_array($step['type'], ['send_sms', 'send_email'], true) && ! $checked) {
                $out[] = ['type' => 'end_if_unsubscribed'];
                $checked = true;
            }
            $out[] = $step;
        }

        return $out;
    }

    /** @return array{key: string, type: string, feature: string, payload: array<string, mixed>} */
    private static function c(string $key, string $type, string $feature, array $payload): array
    {
        return ['key' => $key, 'type' => $type, 'feature' => $feature, 'payload' => $payload];
    }
}
