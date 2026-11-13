<?php

namespace App\Library\AgencySite;

/**
 * White Label — industry starter content for an Agency site.
 *
 * A niche is a LAYER, not a template: it supplies industry wording and the made-up sample data inside the product
 * illustrations (config('marketing.demo')) on top of the shared MotionGrove tree, and underneath everything the Agency
 * has saved. So choosing a niche never replaces the design or the sections, and never overwrites a word the Agency
 * already edited; clearing the niche (or resetting a group) returns to the shared defaults.
 *
 * The content describes the Agency's customers' businesses and only features the software really has (website, CRM,
 * conversations, calendar bookings, automations, local SEO, AI assistant). It makes no claim about kennel management,
 * pet health or vaccination records, and never carries MotionGrove's own customer stories.
 */
final class NichePresets
{
    public const GENERAL = 'general';

    public const DOG = 'dog_business';

    /** key => label shown in the editor */
    public const NICHES = [
        self::GENERAL => 'General small business (MotionGrove defaults)',
        self::DOG => 'Dog trainers, puppy schools and boarding businesses',
    ];

    /**
     * @return array{values: array<string, mixed>, demo: array<string, mixed>, industries: list<array<string, mixed>>, whoFor: ?string}
     */
    public static function for(string $niche, string $brand): array
    {
        return match ($niche) {
            self::DOG => self::dog($brand),
            default => ['values' => [], 'demo' => [], 'industries' => [], 'whoFor' => null],
        };
    }

    private static function dog(string $brand): array
    {
        return [
            'values' => [
                'copy.home.hero.title' => 'More clients. More bookings.',
                'copy.home.hero.title_accent' => 'Less busywork.',
                'copy.home.hero.lead' => 'Your website, leads, conversations, bookings and follow-ups in one place — built for dog training and boarding businesses.',
                'copy.home.hero.lead_mobile' => 'Your website, leads, conversations, bookings and follow-ups in one place — built for dog training and boarding businesses.',
                'copy.home.hero.ticks' => ['Website, CRM and calendar together', 'Automated follow-ups for new inquiries', 'Built for dog training and boarding businesses'],
                'copy.home.trust.title' => 'Built for dog training and boarding businesses',
                'copy.home.trust.title_mobile' => 'Built for dog businesses',
                'copy.home.trust.industries' => ['Dog trainers', 'Puppy-training schools', 'Dog obedience businesses', 'Board-and-train operators', 'Dog boarding businesses'],
                'copy.home.trust.industries_mobile' => ['Dog trainers', 'Puppy schools', 'Obedience', 'Board & train', 'Boarding'],
                'copy.home.final.lead' => 'Bring your website, leads and bookings together — built for dog training and boarding businesses.',
                'copy.home.final.lead_mobile' => 'Bring your website, leads and bookings together.',
                'copy.footer_blurb' => "{$brand} — your website, leads and bookings in one place, built for dog training and boarding businesses.",
                'pages.solutions.description' => "{$brand} for dog trainers, puppy-training schools, obedience businesses, board-and-train operators and dog boarding businesses.",
                'page_copy.solutions.lead' => 'Different kinds of dog business, the same everyday problem: too many inquiries, too many tools and not enough time.',
                'flow_steps.1.rows' => [['Jordan Park', 'Puppy training · consult', '#B4514A'], ['Dog', 'Biscuit, 5 months', '#A99E96'], ['Source', 'Website form', '#A99E96']],
                'page_copy.solutions.note' => 'Don’t see your kind of dog business? The platform works for any business that runs on inquiries and bookings.',
            ],
            'demo' => [
                'business' => 'Maple Ridge Dog Training',
                'owner' => 'Sam',
                'lead_name' => 'Jordan P.',
                'lead_full' => 'Jordan Park',
                'lead_detail' => 'Puppy training, consultation request · from your website form',
                'lead_tag' => 'Puppy training · consult',
                'signed_name' => 'Biscuit’s board-and-train',
                'reply_snippet' => 'Thanks Jordan — here’s our next puppy class and a time for a consultation…',
                'inbox_subject' => 'Do you have room in the next puppy class?',
                'callback_note' => 'Call back Biscuit’s owner!!',
                'search_query' => 'dog trainer near me',
                'keywords' => ['dog training near me', 'puppy training classes', 'board and train dog trainer'],
                'keywords_product' => ['dog training near me', 'puppy training classes', 'dog boarding and training'],
                'seo_next' => 'Next step: add a service page for puppy training',
                'services' => ['Puppy training', 'Obedience classes', 'Board & train'],
                'site_domain' => 'mapleridgedogs.com',
                'site_tagline' => 'Calm, confident dogs. Happy owners.',
                'site_cta' => 'Book a consultation',
                'chat_question' => 'Hi! Do you have space in a puppy class this Saturday?',
                'chat_answer' => 'We do! Here’s our class schedule and a link to book a consultation.',
                'crm_a_name' => 'Jordan Park',
                'crm_a_kind' => 'Puppy training',
                'crm_b_name' => 'Mia Torres',
                'crm_b_kind' => 'Obedience',
                'crm_c_name' => 'Biscuit’s board & train',
                'crm_c_kind' => 'Board & train',
                'crm_d_name' => 'Rex’s obedience plan',
                'open_dates' => 'class spots and consultation times you still have open.',
                'after_event' => 'Day after the class',
            ],
            'industries' => [
                [
                    'name' => 'Dog trainers',
                    'head' => 'Fill your classes without living in your inbox.',
                    'body' => "Independent trainers get inquiries at all hours. {$brand} replies fast, books the consultation and follows up until they sign up.",
                    'jobs' => [['Instant replies to new inquiries', 'Class times and consultation links sent within minutes.'], ['Consultation booking', 'Owners pick a time on your calendar.'], ['Follow-ups that keep going', 'Automatic reminders until they book or say no.'], ['Local search visibility', 'See how you rank for “dog trainer near me”.']],
                ],
                [
                    'name' => 'Puppy-training schools',
                    'head' => 'Keep every puppy class full.',
                    'body' => "Schools juggle waitlists, class dates and new-owner questions. {$brand} keeps every inquiry, conversation and booking on one record.",
                    'jobs' => [['A page for every class', 'Your puppy classes and schedule on your website.'], ['Enquiry capture', 'Website forms feed straight into your CRM.'], ['Booking confirmations', 'Confirmation and reminder messages sent automatically.']],
                ],
                [
                    'name' => 'Dog obedience businesses',
                    'head' => 'A tidy pipeline from first inquiry to enrolled.',
                    'body' => "Track every lead, conversation and consultation in one pipeline, with {$brand} handling the follow-up.",
                    'jobs' => [['One pipeline for every lead', 'See who is new, who has booked and who needs a nudge.'], ['Conversations in one place', 'Text and email threads next to each contact.'], ['AI assistant', 'Ask what to focus on this week.']],
                ],
                [
                    'name' => 'Board-and-train operators',
                    'head' => 'Book more programs, chase fewer messages.',
                    'body' => "Board-and-train programs start with a conversation. {$brand} captures the inquiry, books the consultation and follows up while you are working with dogs.",
                    'jobs' => [['Program pages that convert', 'Your programs and what is included on your website.'], ['Consultation scheduling', 'Owners book a time that suits your calendar.'], ['Quote and booking follow-ups', 'Automatic reminders until they decide.']],
                ],
                [
                    'name' => 'Dog boarding businesses',
                    'head' => 'Answer every booking inquiry.',
                    'body' => "Boarding inquiries often arrive after hours. {$brand} replies first, shares your details and books the next step.",
                    'jobs' => [['Instant replies after hours', 'New inquiries answered by text or email.'], ['Calendar bookings', 'Tours and consultations booked online.'], ['Local search visibility', 'See how you rank for “dog boarding near me”.']],
                ],
            ],
            'whoFor' => 'Dog trainers, puppy-training schools, dog obedience businesses, board-and-train operators and dog boarding businesses.',
        ];
    }
}
