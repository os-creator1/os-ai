<?php

namespace App\Library\NicheBlueprint;

use App\Library\NicheBlueprint\Adapters\AutomationWorkflowComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CrmPipelineComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CustomFieldComponentAdapter;
use App\Library\NicheBlueprint\Adapters\FormComponentAdapter;
use App\Library\NicheBlueprint\Adapters\TagSetComponentAdapter;

/**
 * Photo Booth Business Pack v3 — the reusable, versioned configuration a new
 * photo booth customer (or a GoHighLevel migration) installs.
 *
 * It ADDS to PhotoBoothBlueprintV2 under new `pb3_*` component keys; v2 keys are
 * untouched. Seeding is additive by key (see blueprint:seed-photo-booth-v3), and
 * the Blueprint installer's update detector compares fingerprints, so a customer's
 * edits to an installed pipeline, form or workflow are never overwritten by a later
 * pack version — a new version is offered as a copy.
 *
 * CONFIGURATION ONLY. No contact, deal, price, credential, review link, phone number
 * or any other customer's private record appears here. Every automation installs as
 * a DRAFT; the owner publishes it.
 *
 * KNOWN ENGINE GAPS (not papered over — see docs/migrations/lumina-aura/):
 *  - no business-hours / quiet-hours condition exists in the workflow engine, so no
 *    workflow here claims to gate on hours; consent is enforced by the SMS/email
 *    executors and the `end_if_unsubscribed` guard, reply detection by `end_if_replied`;
 *  - no call-task node, so "call the lead" is an internal notification;
 *  - the questionnaire link is not a merge field, so reminders point to the
 *    customer's own questionnaire message rather than inventing a URL.
 */
final class PhotoBoothBusinessPackV3
{
    public const VERSION = '3.0.0';

    public const PIPELINE_TEMPLATE_KEY = 'blueprint_photo_booth';

    public const INQUIRY_STAGES = [
        ['New Lead', 'new_inquiry'], ["Didn't Pick Up", 'didnt_pick_up'], ['No Contact Made', 'no_contact_made'],
        ['In Contact', 'in_contact'], ['Proposal Sent', 'proposal_sent'], ['Follow-Up', 'follow_up'],
        ['Nurture', 'nurture'], ['Cold (7+ days)', 'cold'], ['Lost', 'lost'],
    ];

    public const BOOKING_STAGES = [
        ['Booking Confirmed', 'new_inquiry'], ['Questionnaire Submitted', 'questionnaire_submitted'],
        ['Awaiting Design Approval', 'awaiting_design_approval'], ['Ready For Event', 'ready_for_event'], ['Completed', 'completed'],
    ];

    /**
     * Blueprint components of the earlier Photo Booth versions that Business Pack V3 supersedes. A CONSOLIDATED draft drops them
     * (never from a published version): the V3 lead form with consents replaces the V2 form of the same name, the V3 stage-driven
     * journeys replace the V2 inquiry / booking / review automations, Inquiries + Bookings replace the V1 "Sales pipeline",
     * and V3's Event date / Event type (multi-select, the type GHL data uses) replace V2's single-select Event type.
     * Everything not listed (tags, venue/guest fields, booking type, packages, documents, website, SEO, citations) is kept.
     */
    public const SUPERSEDED_EARLIER_KEYS = [
        'photo_booth_form_availability', 'photo_booth_auto_new_inquiry', 'photo_booth_auto_booked', 'photo_booth_auto_review',
        'photo_booth_default_pipeline', 'photo_booth_cf_event_date', 'photo_booth_cf_event_type',
    ];

    public const LEAD_FORM_NAME = 'Check Availability';

    public const QUESTIONNAIRE_FORM_NAME = 'Event questionnaire';

    public const EVENT_TYPES = ['Wedding', 'Birthday Party', 'Corporate Party', 'Brand Activation', 'Event', 'Party', 'Graduation Party', 'Fundraiser', 'Trade Show', 'Baby Shower'];

    /** @return list<array{key: string, type: string, feature: string, payload: array<string, mixed>}> */
    public static function components(): array
    {
        return [
            self::c('pb3_tags', TagSetComponentAdapter::TYPE, 'crm', ['tags' => [
                'Inquiry', 'Client', 'Past Client', 'Questionnaire Submitted', 'Review Requested', 'Nurture', 'Cold', 'Lost', 'Proposal Sent', 'Follow Up', 'In Contact', "Didn't Pick Up", 'No Contact',
            ]]),

            self::c('pb3_cf_booth_start_time', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Booth start time', 'type' => 'text']),
            self::c('pb3_cf_booth_location', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Booth location at venue', 'type' => 'text']),
            self::c('pb3_cf_power_confirmed', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Power available near booth', 'type' => 'select', 'options' => ['Yes', 'No', 'Not sure']]),
            self::c('pb3_cf_day_of_phone', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Event day coordinator phone', 'type' => 'text']),
            self::c('pb3_cf_backdrop', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Backdrop choice', 'type' => 'text']),
            self::c('pb3_cf_event_date', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Event date', 'type' => 'date']),
            self::c('pb3_cf_event_type', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Event type', 'type' => 'multi_select', 'options' => self::EVENT_TYPES]),
            self::c('pb3_cf_instagram', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Instagram handle', 'type' => 'text']),

            self::c('pb3_pipeline_inquiries', CrmPipelineComponentAdapter::TYPE, 'crm', self::pipeline('inquiries', 'Inquiries', self::INQUIRY_STAGES)),
            self::c('pb3_pipeline_bookings', CrmPipelineComponentAdapter::TYPE, 'crm', self::pipeline('bookings', 'Bookings', self::BOOKING_STAGES)),

            // The lead form: "New Inquiry Received" is bound to THIS form only, so an Event questionnaire response can never start it.
            self::c('pb3_form_lead', FormComponentAdapter::TYPE, 'forms', [
                'name' => self::LEAD_FORM_NAME,
                'intro' => 'Fill in the short form below to check availability for your event date!',
                'submit_label' => 'Check availability now',
                'success_message' => "Thank you. We'll confirm our availability shortly! One of our expert photo booth planners will contact you shortly to discuss the details for your event.",
                'fields' => [
                    ['key' => 'full_name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                    ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                    ['key' => 'event_date', 'label' => 'Event date', 'type' => 'date', 'required' => true, 'custom_field_label' => 'Event date'],
                    ['key' => 'event_type', 'label' => 'What type of event are you planning?', 'type' => 'multi_select', 'required' => true, 'options' => self::EVENT_TYPES, 'custom_field_label' => 'Event type'],
                    ['key' => 'notes', 'label' => 'Anything else we should know?', 'type' => 'textarea', 'required' => false],
                    ['key' => 'consent_transactional', 'label' => 'By checking this box, I consent to receive transactional SMS/text messages and emails from {business} regarding my inquiry, event details, and service updates. Message frequency may vary. Msg & data rates may apply. Reply HELP for help or STOP to unsubscribe.', 'type' => 'consent_transactional', 'required' => false],
                    ['key' => 'consent_marketing', 'label' => 'By checking this box, I agree to receive marketing and promotional messages from {business}, including special offers, discounts, and new product updates. Message frequency may vary. Msg & data rates may apply. Consent is not required for purchase. Reply STOP to unsubscribe.', 'type' => 'consent_marketing', 'required' => false],
                ],
                'design' => ['accent' => '#7c3aed', 'background' => '#ffffff'],
                'create_opportunity' => true,
                'pipeline_component_key' => 'pb3_pipeline_inquiries',
            ]),

            self::c('pb3_form_questionnaire', FormComponentAdapter::TYPE, 'forms', [
                'name' => self::QUESTIONNAIRE_FORM_NAME,
                'intro' => 'Help us get everything ready for your event.',
                'submit_label' => 'Submit questionnaire',
                'success_message' => 'Thank you! We have your event details.',
                'fields' => [
                    ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                    ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
                    ['key' => 'start_time', 'label' => 'Booth start time', 'type' => 'text', 'required' => true, 'custom_field_label' => 'Booth start time'],
                    ['key' => 'location', 'label' => 'Where should the booth be set up?', 'type' => 'text', 'required' => true, 'custom_field_label' => 'Booth location at venue'],
                    ['key' => 'power', 'label' => 'Will power be available near the booth?', 'type' => 'select', 'required' => true, 'options' => ['Yes', 'No', 'Not sure'], 'custom_field_label' => 'Power available near booth'],
                    ['key' => 'coordinator_phone', 'label' => 'Event day coordinator phone', 'type' => 'text', 'required' => false, 'custom_field_label' => 'Event day coordinator phone'],
                    ['key' => 'backdrop', 'label' => 'Backdrop you like', 'type' => 'text', 'required' => false, 'custom_field_label' => 'Backdrop choice'],
                    ['key' => 'instagram', 'label' => 'Instagram account (so we can tag you)', 'type' => 'text', 'required' => false, 'custom_field_label' => 'Instagram handle'],
                ],
                'design' => ['accent' => '#7c3aed', 'background' => '#ffffff'],
                'create_opportunity' => false,
            ]),

            ...self::workflows(),
        ];
    }

    /**
     * The email/SMS wording used by the workflows, by key — a catalogue so the
     * copy is reviewable in one place. Merge fields are limited to ones the
     * MergeFieldRegistry resolves.
     *
     * @return array<string, array{channel: string, subject?: string, body: string}>
     */
    public static function templates(): array
    {
        $out = [];
        foreach (self::workflows() as $component) {
            foreach ($component['payload']['steps'] as $i => $step) {
                if (in_array($step['type'], ['send_sms', 'send_email'], true)) {
                    $out[$component['key'].'.'.($i + 1)] = ['channel' => $step['type'] === 'send_sms' ? 'sms' : 'email'] + array_intersect_key($step, ['subject' => 1, 'body' => 1]);
                }
            }
        }

        return $out;
    }

    /**
     * Every automated message waits for a send window first (never overnight, in the Business's timezone; also within
     * recorded business hours for lead follow-ups), and every follow-up stops on a reply, an opt-out or a closed deal.
     *
     * @return list<array{key: string, type: string, feature: string, payload: array<string, mixed>}>
     */
    public static function workflows(): array
    {
        $sig = "\n\n— {{business.name}}";
        $window = ['type' => 'wait_for_send_window', 'business_hours' => true];
        $quiet = ['type' => 'wait_for_send_window', 'business_hours' => false];
        $stop = [['type' => 'end_if_unsubscribed'], ['type' => 'end_if_no_open_deal']];
        $call = fn (string $title, int $hours) => ['type' => 'internal_notification', 'message' => $title, 'task' => ['title' => $title, 'due_in_hours' => $hours]];

        return [
            self::wf('pb3_wf_new_inquiry', 'New Inquiry Received', 'form_submitted', null, [
                ['type' => 'add_tag', 'tag' => 'Inquiry'],
                $call('Call {{contact.first_name}} about their new event inquiry', 1),
                ['type' => 'end_if_unsubscribed'],
                $quiet,
                ['type' => 'send_email', 'subject' => 'Thanks for your inquiry, {{contact.first_name}}!', 'body' => "Hi {{contact.first_name}},\n\nThanks for reaching out to {{business.name}}. We are checking your date and will send availability and a quote shortly. If it is urgent, call or text {{business.phone}}.".$sig],
                ['type' => 'wait', 'amount' => 5, 'unit' => 'minutes'],
                ['type' => 'end_if_replied'], ...$stop,
                $window,
                ['type' => 'send_sms', 'body' => 'Hi {{contact.first_name}}, this is {{business.name}} — thanks for your inquiry! We are checking your date and will reply shortly. Reply STOP to opt out.'],
                ['type' => 'wait', 'amount' => 1, 'unit' => 'days'],
                ['type' => 'end_if_replied'],
                ['type' => 'end_if_no_open_deal'],
                $call('No reply yet — call {{contact.first_name}} again about their inquiry', 2),
            ]),
            self::wf('pb3_wf_missed_call', 'Missed Call Follow-Up', 'opportunity_stage_changed', "Didn't Pick Up", [
                ...$stop, $window,
                ['type' => 'send_sms', 'body' => 'Hi {{contact.first_name}}, we tried to reach you about your event. When is a good time to talk? — {{business.name}}'],
                ['type' => 'wait', 'amount' => 1, 'unit' => 'days'],
                ['type' => 'end_if_replied'], ['type' => 'end_if_no_open_deal'], $window,
                ['type' => 'send_email', 'subject' => 'We tried to call you', 'body' => "Hi {{contact.first_name}},\n\nWe tried to call about your event inquiry. Reply with a good time and we will ring you, or text {{business.phone}}.".$sig],
                ['type' => 'wait', 'amount' => 2, 'unit' => 'days'],
                ['type' => 'end_if_replied'], ['type' => 'end_if_no_open_deal'],
                $call('Still no answer — try {{contact.first_name}} once more, then move to No Contact Made', 4),
            ]),
            self::wf('pb3_wf_inquiry_followup', 'Inquiry Follow-Up', 'opportunity_stage_changed', 'No Contact Made', [
                ...$stop,
                ['type' => 'wait', 'amount' => 2, 'unit' => 'days'],
                ['type' => 'end_if_replied'], ['type' => 'end_if_no_open_deal'], $window,
                ['type' => 'send_sms', 'body' => 'Hi {{contact.first_name}}, just checking in — are you still looking for a photo booth for your event? — {{business.name}}'],
                ['type' => 'wait', 'amount' => 3, 'unit' => 'days'],
                ['type' => 'end_if_replied'], ['type' => 'end_if_no_open_deal'], $window,
                ['type' => 'send_email', 'subject' => 'Still planning your event?', 'body' => "Hi {{contact.first_name}},\n\nWe would love to be part of your event. Dates fill up quickly — reply and we will check availability for you.".$sig],
                ['type' => 'wait', 'amount' => 4, 'unit' => 'days'],
                ['type' => 'end_if_replied'], ['type' => 'end_if_no_open_deal'],
                $call('No response after two follow-ups — call {{contact.first_name}} or move to Nurture/Cold', 4),
            ]),
            self::wf('pb3_wf_proposal_followup', 'Proposal Follow-Up', 'opportunity_stage_changed', 'Proposal Sent', [
                ...$stop,
                ['type' => 'wait', 'amount' => 2, 'unit' => 'days'],
                ['type' => 'end_if_replied'], ['type' => 'end_if_no_open_deal'], $window,
                ['type' => 'send_sms', 'body' => 'Hi {{contact.first_name}}, did you get a chance to look at your proposal? Happy to answer any questions. — {{business.name}}'],
                ['type' => 'wait', 'amount' => 3, 'unit' => 'days'],
                ['type' => 'end_if_replied'], ['type' => 'end_if_no_open_deal'], $window,
                ['type' => 'send_email', 'subject' => 'Questions about your proposal?', 'body' => "Hi {{contact.first_name}},\n\nJust following up on the proposal we sent. If you would like to lock in your date or adjust anything, reply here.".$sig],
                ['type' => 'wait', 'amount' => 4, 'unit' => 'days'],
                ['type' => 'end_if_replied'], ['type' => 'end_if_no_open_deal'],
                $call('Proposal unanswered for 9 days — call {{contact.first_name}}', 4),
            ]),
            self::wf('pb3_wf_booking_confirmed', 'Booking Confirmed', 'opportunity_stage_changed', 'Booking Confirmed', [
                ['type' => 'add_tag', 'tag' => 'Client'],
                ['type' => 'internal_notification', 'message' => 'Booking confirmed — the event questionnaire goes out automatically once its link is set up.'],
                ['type' => 'end_if_unsubscribed'], $quiet,
                ['type' => 'send_email', 'subject' => 'Your booking is confirmed!', 'body' => "Hi {{contact.first_name}},\n\nYour booking with {{business.name}} is confirmed — thank you! We will send you a short event questionnaire so we can prepare everything.".$sig],
                ['type' => 'end_if_no_questionnaire_link'],
                ['type' => 'send_sms', 'body' => 'Hi {{contact.first_name}}, your booking with {{business.name}} is confirmed! Please complete your event questionnaire: {{business.questionnaire_link}}'],
            ]),
            self::wf('pb3_wf_questionnaire_submitted', 'Questionnaire Submitted', 'form_submitted', null, [
                ['type' => 'add_tag', 'tag' => 'Questionnaire Submitted'],
                ['type' => 'internal_notification', 'message' => 'Event questionnaire submitted — review details and start design approval if needed.'],
            ]),
            self::wf('pb3_wf_questionnaire_reminder', 'Questionnaire Reminder', 'opportunity_stage_changed', 'Booking Confirmed', [
                ['type' => 'end_if_unsubscribed'], ['type' => 'end_if_no_questionnaire_link'],
                ['type' => 'wait', 'amount' => 3, 'unit' => 'days'],
                ['type' => 'end_if_tagged', 'tag' => 'Questionnaire Submitted'], ['type' => 'end_if_no_open_deal'], $quiet,
                ['type' => 'send_sms', 'body' => 'Hi {{contact.first_name}}, a quick reminder to complete your event questionnaire so we can prepare for your event: {{business.questionnaire_link}} — {{business.name}}'],
                ['type' => 'wait', 'amount' => 4, 'unit' => 'days'],
                ['type' => 'end_if_tagged', 'tag' => 'Questionnaire Submitted'], ['type' => 'end_if_no_open_deal'], $quiet,
                ['type' => 'send_email', 'subject' => 'Your event questionnaire', 'body' => "Hi {{contact.first_name}},\n\nWe still need your event questionnaire to finalize details. It only takes a couple of minutes: {{business.questionnaire_link}}".$sig],
                ['type' => 'wait', 'amount' => 3, 'unit' => 'days'],
                ['type' => 'end_if_tagged', 'tag' => 'Questionnaire Submitted'], ['type' => 'end_if_no_open_deal'],
                $call('Questionnaire still outstanding after 10 days — call {{contact.first_name}}', 4),
            ]),
            self::wf('pb3_wf_event_prep', 'Event Preparation', 'opportunity_stage_changed', 'Ready For Event', [
                ['type' => 'internal_notification', 'message' => 'Ready for event — confirm equipment, backdrop, arrival time and venue contact.'],
                ['type' => 'end_if_unsubscribed'], $quiet,
                ['type' => 'send_email', 'subject' => 'You are all set for your event', 'body' => "Hi {{contact.first_name}},\n\nEverything is ready for your event. We will arrive about an hour before your booth start time to set up. Questions? Text {{business.phone}}.".$sig],
            ]),
            self::wf('pb3_wf_event_completed', 'Event Completed', 'opportunity_stage_changed', 'Completed', [
                ['type' => 'add_tag', 'tag' => 'Past Client'],
                ['type' => 'internal_notification', 'message' => 'Event completed — deliver any content and confirm the final balance is settled.'],
                ['type' => 'wait', 'amount' => 1, 'unit' => 'days'],
                ['type' => 'end_if_unsubscribed'], $quiet,
                ['type' => 'send_email', 'subject' => 'Thank you!', 'body' => "Hi {{contact.first_name}},\n\nThank you for choosing {{business.name}}. We hope your guests had a great time!".$sig],
            ]),
            self::wf('pb3_wf_review_request', 'Review Request', 'opportunity_stage_changed', 'Completed', [
                ['type' => 'end_if_unsubscribed'], ['type' => 'end_if_no_review_link'],
                ['type' => 'wait', 'amount' => 3, 'unit' => 'days'],
                ['type' => 'end_if_unsubscribed'], ['type' => 'end_if_tagged', 'tag' => 'Review Requested'], $window,
                ['type' => 'add_tag', 'tag' => 'Review Requested'],
                ['type' => 'send_sms', 'body' => 'Hi {{contact.first_name}}, thank you for choosing {{business.name}}! If you enjoyed it, a quick review helps us a lot: {{business.review_link}}'],
            ]),
            self::wf('pb3_wf_inactive_nurture', 'Inactive Lead Nurture', 'opportunity_stage_changed', 'Nurture', [
                ...$stop,
                ['type' => 'wait', 'amount' => 7, 'unit' => 'days'],
                ['type' => 'end_if_replied'], ['type' => 'end_if_no_open_deal'], $window,
                ['type' => 'send_email', 'subject' => 'Ideas for your next event', 'body' => "Hi {{contact.first_name}},\n\nPlanning an event? Here is what makes a 360 video booth a guest favorite — and how early to book. Reply any time and we will check availability.".$sig],
                ['type' => 'wait', 'amount' => 14, 'unit' => 'days'],
                ['type' => 'end_if_replied'], ['type' => 'end_if_no_open_deal'], $window,
                ['type' => 'send_sms', 'body' => 'Hi {{contact.first_name}}, {{business.name}} here — still planning an event? We would love to help. Reply STOP to opt out.'],
                ['type' => 'wait', 'amount' => 30, 'unit' => 'days'],
                ['type' => 'end_if_replied'], ['type' => 'end_if_no_open_deal'], $window,
                ['type' => 'send_email', 'subject' => 'Dates are filling up', 'body' => "Hi {{contact.first_name}},\n\nPopular dates are booking out. If your plans are still open, reply and we will hold a date while you decide.".$sig],
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private static function pipeline(string $key, string $name, array $stages): array
    {
        return [
            'template_key' => self::PIPELINE_TEMPLATE_KEY,
            'template_version' => 1,
            'pipeline_key' => $key,
            'name' => $name,
            'stages' => array_map(fn (array $s) => ['name' => $s[0], 'semantic_key' => $s[1]], $stages),
        ];
    }

    private static function wf(string $key, string $name, string $trigger, ?string $stage, array $steps): array
    {
        $payload = ['name' => $name, 'trigger_type' => $trigger, 'steps' => self::optOutBeforeSends(self::guardsAfterWindow($steps))];
        if ($trigger === 'form_submitted') {
            // Bound to one form by name: a single-page form can never satisfy the multi-page `questionnaire_submitted` trigger.
            $payload['trigger_form'] = $key === 'pb3_wf_questionnaire_submitted' ? self::QUESTIONNAIRE_FORM_NAME : self::LEAD_FORM_NAME;
        }
        if ($stage !== null) {
            $payload['trigger_stage'] = $stage;
        }

        return self::c($key, AutomationWorkflowComponentAdapter::TYPE, 'automations', $payload);
    }

    /**
     * A send-window wait can park a run for hours (overnight, or until business hours), so a reply, opt-out, tag or
     * closed deal can land during it. Move every guard that sits directly before a window wait to directly after it,
     * so the guards are evaluated immediately before the message is sent, not before the wait.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private static function guardsAfterWindow(array $steps): array
    {
        $isGuard = fn (array $s): bool => str_starts_with($s['type'], 'end_if_');
        $out = [];
        $held = [];
        foreach ($steps as $step) {
            if ($isGuard($step)) {
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
