<?php

namespace App\Library\Marketing;

/**
 * The fictional sample data drawn inside the marketing illustrations (dashboard previews, mock website, CRM cards,
 * conversations, SEO keywords). It is presentation only: nothing here is a real customer, result or metric, and every
 * illustration is labelled as sample data by the view.
 *
 * The DEFAULT set is the platform's original example (an events business). An Agency site selects another set with its
 * validated `x.demo` setting, so the shared views stay single and the platform homepage and every other Agency render
 * exactly what they always did. An unknown or missing set falls back to the default.
 *
 * Alongside the strings, a set may carry config-tree overrides (`tree()`) for the few illustrative blocks that live in
 * config/marketing.php; the Agency's own saved copy is applied after them and still wins.
 */
final class MarketingDemo
{
    public const DEFAULT = 'default';

    public const DOG_TRAINING = 'dog-training';

    /** Sets an Agency may select (the default set is the absence of a choice). */
    public const SELECTABLE = [self::DOG_TRAINING => 'Dog training and boarding'];

    private const SETS = [
        self::DEFAULT => [
            'business' => 'Lumen Events',
            'hero.inquiry_title' => 'New inquiry — Jordan P.',
            'hero.inquiry_detail' => 'Wedding, June 14 · from your website form',
            'hero.inquiry_detail_phone' => 'Wedding · June 14',
            'hero.done_title' => 'Proposal signed — Riverside Gala',
            'hero.done_detail' => 'Payment request sent automatically',
            'hero.done_title_phone' => 'Proposal signed',
            'hero.done_detail_phone' => 'Payment request sent',
            'hero.followup' => 'Thanks Jordan — here’s our availability for June 14…',
            'collage.inbox' => 'Are you available June 14?',
            'collage.note' => 'Call back the Nguyen party!!',
            'collage.search' => 'photo booth near me',
            'seo.kw1' => 'photo booth rental near me',
            'seo.kw2' => 'wedding photo booth',
            'seo.kw3' => '360 booth hire',
            'seo.kw3_product' => 'event booth hire',
            'seo.next' => 'corporate events',
            'site.chip1' => 'Weddings',
            'site.chip2' => 'Corporate events',
            'site.chip3' => 'Birthdays',
            'site.domain' => 'lumenevents.com',
            'site.headline' => 'Unforgettable events, captured.',
            'site.cta' => 'Check availability',
            'site.services' => '',
            'site.page1' => 'Weddings',
            'site.page2' => 'Corporate events',
            'crm.name' => 'Jordan Park',
            'crm.detail' => 'Wedding · June 14',
            'crm.chip' => 'Proposal sent',
            'crm.t1' => 'Proposal opened · 2h ago',
            'crm.stage' => 'Proposal',
            'crm.p1.name' => 'Jordan Park',
            'crm.p1.detail' => 'Wedding',
            'crm.p2.name' => 'Mia Torres',
            'crm.p2.detail' => 'Birthday',
            'crm.p3.name' => 'Riverside Gala',
            'crm.p3.detail' => 'Corporate',
            'crm.p4.name' => 'Chen wedding',
            'crm.p4.detail' => 'Deposit paid',
            'conv.q' => 'Hi! Do you have a booth free on June 14?',
            'conv.a' => 'We do! Here’s our package list and a link to book.',
            'auto.lead' => 'Ready-made workflows answer new inquiries, send reminders, chase unsigned proposals and request payment after signing.',
            'auto.email' => 'Packages and a link to book',
            'auto.review' => 'Day after the event',
            'pay.chip' => 'Proposals & payments',
            'pay.text' => 'Send a proposal, let the customer sign online, and request payment automatically through your own Stripe account.',
            'pay.row1' => 'Proposal sent',
            'pay.row2' => 'Signed by customer',
            'book.title' => 'Pick a time · Sat, June 14',
            'ai.page' => 'Weddings',
            'ai.msg' => 'Two asked about June dates you still have open.',
        ],

        // Illustrative only: a fictional dog training business. Nothing here claims kennel occupancy, overnight boarding
        // reservations, vaccination tracking or pet medical records — only inquiries, websites, CRM, conversations,
        // follow-ups, consultation booking and SEO/ads reporting.
        self::DOG_TRAINING => [
            'business' => 'Paw & Progress Dog Training',
            'hero.inquiry_title' => 'New puppy training inquiry — Jordan P.',
            'hero.inquiry_detail' => 'Puppy consultation · from your website form',
            'hero.inquiry_detail_phone' => 'Puppy consultation',
            'hero.done_title' => 'Consultation booked — Jordan Parker',
            'hero.done_detail' => 'Confirmation sent automatically',
            'hero.done_title_phone' => 'Consultation booked',
            'hero.done_detail_phone' => 'Confirmation sent',
            'hero.followup' => 'Thanks for your inquiry! Here’s a link to book a consultation.',
            'collage.inbox' => 'Do you have space in a puppy class?',
            'collage.note' => 'Call back the Nguyen family!!',
            'collage.search' => 'dog trainer near me',
            'seo.kw1' => 'dog trainer near me',
            'seo.kw2' => 'puppy training',
            'seo.kw3' => 'dog boarding near me',
            'seo.kw3_product' => 'dog boarding near me',
            'seo.next' => 'board and train',
            'site.chip1' => 'Puppy Training',
            'site.chip2' => 'Obedience Training',
            'site.chip3' => 'Board & Train',
            'site.domain' => 'pawandprogress.example',
            'site.headline' => 'Confident dogs. Happy owners.',
            'site.cta' => 'Book a consultation',
            'site.services' => 'Puppy Training|Obedience Training|Board & Train',
            'site.page1' => 'Puppy Training',
            'site.page2' => 'Board & Train',
            'crm.name' => 'Jordan Parker',
            'crm.detail' => 'Puppy consultation',
            'crm.chip' => 'Consultation requested',
            'crm.t1' => 'Booking link opened · 2h ago',
            'crm.stage' => 'Consultation',
            'crm.p1.name' => 'Jordan Parker',
            'crm.p1.detail' => 'Puppy training',
            'crm.p2.name' => 'Mia Torres',
            'crm.p2.detail' => 'Boarding inquiry',
            'crm.p3.name' => 'Sam Okafor',
            'crm.p3.detail' => 'Obedience training',
            'crm.p4.name' => 'Lee family',
            'crm.p4.detail' => 'Board & train · deposit paid',
            'conv.q' => 'Hi, do you have availability for a puppy training consultation?',
            'conv.a' => 'Thanks for your inquiry! Here’s a link to book a consultation.',
            'auto.lead' => 'Ready-made workflows answer new inquiries and send appointment reminders and follow-ups.',
            'auto.email' => 'Programs and a link to book a consultation',
            'auto.review' => 'Day after the session',
            'pay.chip' => 'Agreements & payments',
            'pay.text' => 'Send a service agreement, let the client sign online, and request a deposit through your own Stripe account.',
            'pay.row1' => 'Agreement sent',
            'pay.row2' => 'Signed by client',
            'book.title' => 'Pick a consultation time · Sat, June 14',
            'ai.page' => 'Puppy Training',
            'ai.msg' => 'Two asked about puppy training consultations.',
        ],
    ];

    /** Config-tree overrides per set (dotted path => value); only illustrative blocks that live in config/marketing.php. */
    private static function trees(): array
    {
        return [
            self::DOG_TRAINING => [
                'copy.home.trust.industries_mobile' => ['Dog trainers', 'Obedience schools', 'Puppy programs', 'Board-and-train', 'Boarding facilities'],
                'flow_steps' => [
                    ['title' => 'Discover', 'tool' => 'Website & SEO', 'head' => 'A pet owner finds you.', 'body' => 'Your website and search visibility help local pet owners find you when they search for dog training or boarding — on Google, social or a referral link.', 'short' => 'Your website and search visibility bring people in.', 'ui_label' => 'Where visitors came from', 'rows' => [['Google search', 'Organic', '#1F5E4A'], ['Instagram ad', 'Meta', '#B4514A'], ['Referral link', 'Direct', '#A99E96']]],
                    ['title' => 'Capture', 'tool' => 'Forms', 'head' => 'Every inquiry is caught.', 'body' => 'Website forms, booking links and messages land in one place, with the details you need already filled in. Nothing waits in a separate inbox.', 'short' => 'Forms and booking links catch every inquiry.', 'ui_label' => 'New inquiry', 'rows' => [['Jordan Parker', 'Puppy consultation', '#B4514A'], ['Dog', 'Puppy, 4 months', '#A99E96'], ['Source', 'Website form', '#A99E96']]],
                    ['title' => 'Organize', 'tool' => 'CRM', 'head' => 'The lead is organized for you.', 'body' => 'A contact and an opportunity are created automatically, so you can see every prospective client, where they stand and what was said last.', 'short' => 'The CRM files the lead for you.', 'ui_label' => 'Pipeline', 'rows' => [['New inquiry', '4', '#B4514A'], ['Consultation', '3', '#C9A35B'], ['Enrolled', '6', '#1F5E4A']]],
                    ['title' => 'Follow up', 'tool' => 'Automations', 'head' => 'A reply goes out in minutes.', 'body' => 'A friendly text and email go out straight away with a link to book a consultation — and stop as soon as the client replies.', 'short' => 'A reply goes out in minutes, automatically.', 'ui_label' => 'Workflow', 'rows' => [['Text sent', '2 min', '#1F5E4A'], ['Email with booking link', '2 min', '#1F5E4A'], ['Reminder if no reply', 'Tomorrow', '#A99E96']]],
                    ['title' => 'Book', 'tool' => 'Calendar', 'head' => 'The client books a consultation.', 'body' => 'They pick a time that suits them on your booking calendar. Confirmations and reminders go out automatically.', 'short' => 'They pick a consultation time and get reminders.', 'ui_label' => 'Booking', 'rows' => [['Sat, 10 am', 'Confirmed', '#1F5E4A'], ['Reminder', 'Scheduled', '#A99E96'], ['Meet-and-greet', 'Booked', '#1F5E4A']]],
                    ['title' => 'Track', 'tool' => 'Dashboard', 'head' => 'You see what’s working.', 'body' => 'Leads, bookings and marketing sit in one dashboard, with clear next steps instead of a pile of reports.', 'short' => 'See what’s working in one dashboard.', 'ui_label' => 'This month', 'rows' => [['Leads', 'from 3 channels', '#B4514A'], ['Bookings', 'by source', '#1F5E4A'], ['Next step', 'suggested', '#C9A35B']]],
                ],
            ],
        ];
    }

    public static function known(?string $set): bool
    {
        return $set !== null && array_key_exists($set, self::SELECTABLE);
    }

    /** One sample string; a missing key in a set falls back to the default set, never to nothing. */
    public static function get(?string $set, string $key): string
    {
        $set = self::known($set) ? $set : self::DEFAULT;

        return (string) (self::SETS[$set][$key] ?? self::SETS[self::DEFAULT][$key] ?? '');
    }

    /** @return array<string, mixed> */
    public static function tree(?string $set): array
    {
        return self::known($set) ? (self::trees()[$set] ?? []) : [];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::SETS[self::DEFAULT]);
    }
}
