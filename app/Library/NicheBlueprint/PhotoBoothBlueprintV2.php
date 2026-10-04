<?php

namespace App\Library\NicheBlueprint;

use App\Library\NicheBlueprint\Adapters\AutomationWorkflowComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BookingTypeComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CitationRecommendationsComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CustomFieldComponentAdapter;
use App\Library\NicheBlueprint\Adapters\FormComponentAdapter;
use App\Library\NicheBlueprint\Adapters\PackageTemplateComponentAdapter;
use App\Library\NicheBlueprint\Adapters\SeoStrategyComponentAdapter;
use App\Library\NicheBlueprint\Adapters\TagSetComponentAdapter;
use App\Library\NicheBlueprint\Adapters\WebsiteConfigComponentAdapter;
use App\Library\NicheBlueprint\Workspace\BlueprintSurfaces;

/**
 * The Photo Booth Blueprint v2 content — the reusable niche configuration as
 * component descriptors. The one place the content lives: the
 * `blueprint:seed-photo-booth-v2` command publishes it, and tests assert on it.
 *
 * CONFIGURATION ONLY. Nothing here is a contact, deal, booking, submission,
 * invoice, payment, credential or review. Package/add-on templates carry NO
 * price (the owner sets their own); the proposal template references the
 * platform proposal templates by seed key.
 *
 * The v1 pipeline component (`photo_booth_default_pipeline`) is not repeated:
 * a v2 draft is a copy of v1, so it carries over with its identity intact.
 */
final class PhotoBoothBlueprintV2
{
    public const WEBSITE_TEMPLATE_KEY = 'photo_booth_editorial';

    /**
     * @return list<array{key: string, type: string, feature: string, payload: array<string, mixed>}>
     */
    public static function components(): array
    {
        $components = [
            self::c('photo_booth_tags', TagSetComponentAdapter::TYPE, 'crm', [
                'tags' => ['Wedding', 'Birthday', 'Corporate', 'School Event', 'Hot Lead', 'Deposit Paid', 'Past Client', 'Review Requested'],
            ]),
            self::c('photo_booth_cf_event_date', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Event date', 'type' => 'date']),
            self::c('photo_booth_cf_event_type', CustomFieldComponentAdapter::TYPE, 'crm', [
                'label' => 'Event type', 'type' => 'select', 'options' => ['Wedding', 'Birthday', 'Corporate', 'School', 'Other'],
            ]),
            self::c('photo_booth_cf_venue', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Venue name', 'type' => 'text']),
            self::c('photo_booth_cf_guests', CustomFieldComponentAdapter::TYPE, 'crm', ['label' => 'Guest count', 'type' => 'number']),

            self::c('photo_booth_form_availability', FormComponentAdapter::TYPE, 'forms', [
                'name' => 'Check availability',
                'intro' => 'Tell us about your event and we will confirm availability and send a proposal.',
                'submit_label' => 'Check availability',
                'success_message' => 'Thanks! We will be in touch shortly to confirm your date.',
                'fields' => [
                    ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                    ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
                    ['key' => 'event_date', 'label' => 'Event date', 'type' => 'date', 'required' => true, 'custom_field_label' => 'Event date'],
                    ['key' => 'event_type', 'label' => 'Event type', 'type' => 'select', 'required' => true,
                        'options' => ['Wedding', 'Birthday', 'Corporate', 'School', 'Other'], 'custom_field_label' => 'Event type'],
                    ['key' => 'venue', 'label' => 'Venue', 'type' => 'text', 'required' => false, 'custom_field_label' => 'Venue name'],
                    ['key' => 'guests', 'label' => 'Expected guests', 'type' => 'number', 'required' => false, 'custom_field_label' => 'Guest count'],
                    ['key' => 'message', 'label' => 'Anything else we should know?', 'type' => 'textarea', 'required' => false],
                ],
                'design' => ['accent' => '#7c3aed', 'background' => '#ffffff'],
                'create_opportunity' => true,
            ]),

            self::c('photo_booth_booking_consult', BookingTypeComponentAdapter::TYPE, 'calendar', [
                'name' => 'Planning call',
                'description' => 'A short call to confirm your event details and choose a package.',
                'duration_minutes' => 20,
                'minimum_notice_minutes' => 240,
                'buffer_before_minutes' => 5,
                'buffer_after_minutes' => 10,
                'slot_interval_minutes' => 30,
                'booking_window_days' => 60,
                'meeting_instructions' => 'We will call the number you provided.',
            ]),

            self::c('photo_booth_package_classic', PackageTemplateComponentAdapter::TYPE, 'packages_products', [
                'kind' => 'package', 'name' => 'Classic photo booth', 'description' => 'Open-air or enclosed booth, attendant, unlimited prints, online gallery. Set your hours and price.',
            ]),
            self::c('photo_booth_package_premium', PackageTemplateComponentAdapter::TYPE, 'packages_products', [
                'kind' => 'package', 'name' => 'Premium photo booth', 'description' => 'Everything in Classic plus custom print design, props and a guest book. Set your hours and price.',
            ]),
            self::c('photo_booth_addon_backdrop', PackageTemplateComponentAdapter::TYPE, 'packages_products', [
                'kind' => 'add_on', 'name' => 'Custom backdrop', 'description' => 'A themed or branded backdrop for your event.',
            ]),
            self::c('photo_booth_addon_extra_hour', PackageTemplateComponentAdapter::TYPE, 'packages_products', [
                'kind' => 'add_on', 'name' => 'Extra hour', 'description' => 'Extend your booth time.',
            ]),

            self::c('photo_booth_auto_new_inquiry', AutomationWorkflowComponentAdapter::TYPE, 'automations', [
                'name' => 'New inquiry follow-up',
                'trigger_type' => 'form_submitted',
                'steps' => [
                    ['type' => 'add_tag', 'tag' => 'Hot Lead'],
                    ['type' => 'internal_notification', 'message' => 'New photo booth inquiry — reply within the hour.'],
                    ['type' => 'wait', 'amount' => 5, 'unit' => 'minutes'],
                    ['type' => 'send_sms', 'body' => 'Hi {{contact.first_name}}, thanks for asking about our photo booth! We are checking your date and will reply shortly.'],
                    ['type' => 'wait', 'amount' => 1, 'unit' => 'days'],
                    ['type' => 'send_email', 'subject' => 'Your photo booth inquiry', 'body' => 'Hi {{contact.first_name}}, just following up on your photo booth inquiry. Reply to this email or book a quick planning call and we will lock in your date.'],
                ],
            ]),
            self::c('photo_booth_auto_booked', AutomationWorkflowComponentAdapter::TYPE, 'automations', [
                'name' => 'Planning call booked',
                'trigger_type' => 'appointment_scheduled',
                'steps' => [
                    ['type' => 'internal_notification', 'message' => 'A planning call was booked.'],
                    ['type' => 'send_email', 'subject' => 'Your planning call is booked', 'body' => 'Hi {{contact.first_name}}, your planning call is confirmed. We look forward to talking about your event.'],
                ],
            ]),
            self::c('photo_booth_auto_review', AutomationWorkflowComponentAdapter::TYPE, 'automations', [
                'name' => 'Review request after the event',
                'trigger_type' => 'opportunity_won',
                'steps' => [
                    ['type' => 'wait', 'amount' => 3, 'unit' => 'days'],
                    ['type' => 'add_tag', 'tag' => 'Review Requested'],
                    ['type' => 'send_sms', 'body' => 'Hi {{contact.first_name}}, thank you for choosing us! If you enjoyed your photo booth we would love a quick review.'],
                ],
            ]),

            self::c('photo_booth_website', WebsiteConfigComponentAdapter::TYPE, 'website_generation', [
                'template_key' => self::WEBSITE_TEMPLATE_KEY,
                'pages' => [
                    ['page' => 'home', 'title' => 'Home', 'sections' => ['hero', 'packages', 'gallery', 'testimonials', 'cta']],
                    ['page' => 'packages', 'title' => 'Packages', 'sections' => ['packages', 'add_ons', 'faq']],
                    ['page' => 'gallery', 'title' => 'Gallery', 'sections' => ['gallery']],
                    ['page' => 'contact', 'title' => 'Check availability', 'sections' => ['form', 'contact_details']],
                ],
                'navigation' => ['Home', 'Packages', 'Gallery', 'Check availability'],
                'content_prompts' => [
                    'Describe the photo booth experience for weddings, birthdays and corporate events.',
                    'Explain what is included in each package and how booking works.',
                    'Highlight service areas and the venues you have worked at.',
                ],
            ]),

            self::c('photo_booth_seo', SeoStrategyComponentAdapter::TYPE, 'seo_module', [
                'keyword_patterns' => [
                    ['pattern' => 'photo booth rental {city}', 'intent' => 'transactional'],
                    ['pattern' => 'wedding photo booth {city}', 'intent' => 'transactional'],
                    ['pattern' => 'corporate event photo booth {city}', 'intent' => 'commercial'],
                    ['pattern' => 'how much does a photo booth cost', 'intent' => 'informational'],
                    ['pattern' => 'photo booth near me', 'intent' => 'local'],
                ],
                'faq_topics' => ['How far in advance should we book?', 'What is included in a package?', 'How much space does a booth need?', 'Do you travel to my venue?'],
                'schema_types' => ['LocalBusiness', 'FAQPage', 'Service'],
                'schema_notes' => 'LocalBusiness on the home page; FAQPage on packages; one Service per package.',
                'internal_links' => [
                    ['from' => 'home', 'to' => 'packages', 'anchor' => 'photo booth packages'],
                    ['from' => 'gallery', 'to' => 'contact', 'anchor' => 'check your date'],
                ],
            ]),

            self::c('photo_booth_citations', CitationRecommendationsComponentAdapter::TYPE, 'seo_module', [
                'recommendations' => [
                    ['directory_key' => 'weddingwire_the_knot', 'importance' => 'recommended', 'guidance' => 'Couples search here for photo booths specifically. Finish one profile before touching the second site.'],
                    ['directory_key' => 'gigsalad', 'importance' => 'recommended', 'guidance' => 'The strongest non-wedding channel for birthdays, corporate and school events.'],
                    ['directory_key' => 'eventective', 'importance' => 'recommended', 'guidance' => 'A long-running event marketplace; the free listing shows your prices.'],
                    ['directory_key' => 'bark', 'importance' => 'optional', 'guidance' => 'Lead-based and credit-funded — set a small budget and track which leads book.'],
                    ['directory_key' => 'the_bash', 'importance' => 'optional', 'guidance' => 'Likely paid; set up the free listings first.'],
                    ['directory_key' => 'facebook_pages', 'importance' => 'recommended', 'guidance' => 'Your event gallery and reviews live here for most couples.'],
                    ['directory_key' => 'yelp', 'importance' => 'optional', 'guidance' => 'Claim the listing so photos and hours are correct.'],
                ],
            ]),
        ];

        return $components;
    }

    /**
     * Payment-term DEFAULTS for the platform proposal template. The document
     * template components themselves are assigned by
     * `documents:seed-photo-booth-templates` (17B §6b); this seed only adds the
     * guidance to the component that command created.
     *
     * @return array{deposit_percent: int, balance_due_days_before_event: int, note: string}
     */
    public static function proposalPaymentTerms(): array
    {
        return ['deposit_percent' => 25, 'balance_due_days_before_event' => 14, 'note' => 'Deposit holds the date; balance due before the event.'];
    }

    /** The surface positions the Workspace would assign, so a seeded blueprint orders like an authored one. */
    public static function positionFor(string $type, int $ordinal): int
    {
        $definition = app(Adapters\BlueprintComponentAdapterRegistry::class)->find($type);

        return BlueprintSurfaces::positionFor($definition->surface(), $ordinal);
    }

    /** @return array{key: string, type: string, feature: string, payload: array<string, mixed>} */
    private static function c(string $key, string $type, string $feature, array $payload): array
    {
        return ['key' => $key, 'type' => $type, 'feature' => $feature, 'payload' => $payload];
    }
}
