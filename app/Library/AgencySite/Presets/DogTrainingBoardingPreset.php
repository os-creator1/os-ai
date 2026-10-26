<?php

namespace App\Library\AgencySite\Presets;

/**
 * Starter content for an Agency that sells MotionGrove to dog training and dog boarding businesses.
 *
 * It is DATA for the Agency site's own validated editor paths (SiteSchema), applied through AgencySiteManager::save().
 * It never touches SiteDefaults or any other Agency. The wording claims only what the software does today: websites and
 * forms, CRM, conversations, automations, the booking calendar, SEO and ad reporting. It does not mention kennel
 * occupancy, vaccination or pet health records, guaranteed results, statistics or testimonials, and it never quotes a
 * price (the Pricing page reads the Agency's real plans).
 *
 * Deliberately NOT written, so the site keeps its safe behaviour: testimonials and the Customers page (hidden until
 * approved stories exist), "Support & onboarding" and "Setup commitment" (hidden until the Agency writes real promises),
 * legal links, and the Agency's brand identity.
 */
final class DogTrainingBoardingPreset
{
    public const NAME = 'dog-training-boarding';

    /**
     * @param  bool  $canSell  at least one plan can be bought online right now
     * @param  bool  $canBook  a bookable booking type is selected
     * @return array<string, mixed> editor path => value
     */
    public static function values(bool $canSell, bool $canBook): array
    {
        $primary = $canSell ? 'signup' : ($canBook ? 'booking' : 'pricing');
        $secondary = $canBook ? 'booking' : ($canSell ? 'signup' : 'pricing');

        return [
            // 1 — Business & website
            'x.audience' => 'Dog trainers, obedience schools, puppy programs, board-and-train businesses, boarding facilities and kennels',
            'x.industries' => ['Dog training businesses', 'Dog obedience schools', 'Puppy training programs', 'Board-and-train businesses', 'Dog boarding facilities', 'Kennels', 'Independent dog-care operators'],
            'x.language' => 'English',

            // 2 — Home
            'copy.home.hero.chip' => 'Software for dog training and boarding businesses',
            'copy.home.hero.title' => 'More clients. More bookings.',
            'copy.home.hero.title_accent' => 'Less busywork.',
            'copy.home.hero.lead' => 'MotionGrove helps dog training and boarding businesses turn inquiries into customers with websites, CRM, automated follow-ups, booking tools, and marketing in one place.',
            'copy.home.hero.lead_mobile' => 'Turn inquiries into customers with websites, CRM, automated follow-ups, booking tools and marketing in one place.',
            'copy.home.hero.primary' => $primary === 'signup' ? 'Get started' : ($primary === 'booking' ? 'Book a demo' : 'See pricing'),
            'x.cta.hero' => $primary,
            // The hero's second button always opens the How it works page (the shared view has no destination setting),
            // so its label must say exactly that.
            'copy.home.hero.secondary' => 'See how it works',
            'copy.home.hero.ticks' => ['Website, CRM and calendar together', 'Automated follow-ups for new inquiries', 'Set up for dog training and boarding'],

            'copy.home.trust.title' => 'Built for professional dog-care businesses',
            'copy.home.trust.industries' => ['Dog trainers', 'Obedience schools', 'Puppy programs', 'Board-and-train', 'Boarding facilities', 'Kennels', 'Dog-care operators'],

            'copy.home.problem.eyebrow' => 'The problem',
            'copy.home.problem.title' => 'Inquiries slip away when you are busy with dogs.',
            'copy.home.problem.items' => [
                ['0' => 'Missed inquiries', '1' => 'A prospective client calls or fills in a form while you are mid-session, and the message waits.'],
                ['0' => 'Slow follow-up', '1' => 'The first business to reply often wins the client, but manual follow-up gets pushed to the end of the day.'],
                ['0' => 'Scattered tools', '1' => 'Texts, emails, spreadsheets and notes live in different places, so nothing shows the full picture.'],
                ['0' => 'Missed consultations', '1' => 'Without reminders, booked consultations and appointments are easier to forget.'],
                ['0' => 'Hard to find locally', '1' => 'Pet owners search before they call. A weak online presence makes it harder to be discovered.'],
                ['0' => 'Endless admin', '1' => 'Repetitive messages and updates take time away from training and caring for dogs.'],
            ],
            'copy.home.problem.closing' => 'One place for every inquiry.',
            'copy.home.problem.closing_accent' => 'So no client slips away.',

            'copy.home.system.eyebrow' => 'One connected workflow',
            'copy.home.system.title' => 'From first inquiry to loyal client, in one place.',
            'copy.home.system.lead' => 'Your website and forms capture inquiries into the CRM. Conversations, automated follow-ups and your calendar keep every prospective client moving, without switching between tools.',

            'copy.home.capabilities.eyebrow' => 'What you get',
            'copy.home.capabilities.title' => 'The tools a growing dog business needs.',
            'capability_cards' => [
                ['0' => 'Website and forms', '1' => 'Turn visitors into inquiries', '2' => 'A mobile-ready website with forms that create contacts automatically, so every inquiry reaches your CRM.'],
                ['0' => 'CRM', '1' => 'Know where every client stands', '2' => 'Track prospective training and boarding clients, their notes and their pipeline stage in one place.'],
                ['0' => 'Conversations', '1' => 'Every reply in one inbox', '2' => 'Keep text, email and web chat conversations together on the client record.'],
                ['0' => 'Automations', '1' => 'Follow up without lifting a finger', '2' => 'Send an instant reply to new inquiries, plus appointment reminders and confirmations.'],
                ['0' => 'Calendar', '1' => 'Consultations that fit your day', '2' => 'Share booking links for the consultations and appointments you offer, with availability you control.'],
                ['0' => 'SEO and ads', '1' => 'Be found by local pet owners', '2' => 'Track search visibility and compare Google and Meta ad results with the leads they created, on plans that include them.'],
            ],

            'copy.home.how.eyebrow' => 'How it works',
            'copy.home.how.title' => 'From setup to steady growth.',
            'copy.home.how.cta' => 'See how it works',

            'copy.home.comparison.eyebrow' => 'Why one platform',
            'copy.home.comparison.title' => 'One platform instead of scattered tools.',
            'copy.home.comparison.note' => 'The pricing page shows exactly what each plan includes.',

            'copy.home.faq.eyebrow' => 'FAQ',
            'copy.home.faq.title' => 'Common questions',
            'faqs.home' => [
                ['q' => 'What is MotionGrove?', 'a' => 'MotionGrove is software for dog training and boarding businesses: a website, CRM, conversations, automations, a booking calendar and marketing tools in one place.'],
                ['q' => 'Who is it for?', 'a' => 'Dog trainers, obedience schools, puppy programs, board-and-train businesses, boarding facilities and kennels.'],
                ['q' => 'Does MotionGrove manage kennel occupancy or pet health records?', 'a' => 'No. MotionGrove helps you attract, respond to and organise clients. It does not include kennel occupancy, vaccination or pet health record management.'],
                ['q' => 'Can clients book online?', 'a' => 'MotionGrove includes a booking calendar for the consultations and appointments you offer. It is a general appointment calendar, not an overnight boarding reservation system.'],
                ['q' => 'How does billing work?', 'a' => 'Plans are billed on a recurring subscription through Stripe. You can manage your payment method and subscription from your account.'],
                ['q' => 'How do I get support?', 'a' => 'Use the support link in the footer of this site or inside your account.'],
            ],

            'copy.home.final.title' => 'Ready to win more clients with less busywork?',
            'copy.home.final.lead' => 'See how MotionGrove can help your dog training or boarding business capture inquiries, follow up faster and stay organised.',
            'copy.home.final.primary' => $primary === 'signup' ? 'Get started' : ($primary === 'booking' ? 'Book a demo' : 'See pricing'),
            'x.cta.final' => $primary,
            'copy.home.final.secondary' => 'Book a demo',
            'copy.footer_blurb' => 'MotionGrove helps dog training and boarding businesses attract clients, follow up with inquiries and reduce administrative work.',

            // 3 — Product
            'page_copy.product.eyebrow' => 'Product',
            'page_copy.product.title' => 'Everything you need to',
            'page_copy.product.title_accent' => 'grow a dog business.',
            'page_copy.product.lead' => 'Websites, a CRM, conversations, automations, a booking calendar and marketing tools, working together so inquiries turn into clients.',
            'page_copy.product.cta.title' => 'See it with your own dog business in mind.',
            'page_copy.product.cta.secondary' => 'Book a demo',
            'x.cta.product' => $secondary,
            'product_features.website.lead' => 'Build a mobile-ready site for your training or boarding business, with forms that turn visitors into inquiries.',
            'product_features.website.bullets' => ['Program, service and photo pages', 'Inquiry, quote-request and consultation forms', 'Hosting, SSL and your own domain', 'Forms that create contacts automatically'],
            'product_features.crm.title' => 'Know where every client stands.',
            'product_features.crm.lead' => 'Track prospective training and boarding clients from first inquiry to booked service, with every message and note on their record.',
            'product_features.crm.bullets' => ['Pipelines for inquiries, consultations and booked clients', 'Text, email and web chat in one inbox', 'Custom fields for details such as a dog name or program', 'Notes and history on every contact'],
            'product_features.auto.lead' => 'Reply to new inquiries and remind clients about appointments automatically, then adjust the timing and wording.',
            'product_features.auto.bullets' => ['Instant reply to new inquiries', 'Appointment reminders and confirmations'],
            'product_features.book.lead' => 'Share booking links for consultations and appointments, with availability you control and reminders for everyone.',
            'product_features.book.bullets' => ['Booking links and calendar sync', 'Services with durations and buffers', 'Reschedule and cancel flows for clients'],
            'product_features.seo.title' => 'See what brings clients in.',
            'product_features.seo.lead' => 'Track local search visibility, get plain-language recommendations, and compare Google and Meta ad results with the leads they created.',
            'product_features.ai.lead' => 'Ask MotionGrove about your leads, bookings or marketing. It explains what is happening and suggests a next step you can act on.',

            // 4 — Solutions
            'page_copy.solutions.eyebrow' => 'Solutions',
            'page_copy.solutions.title' => 'Built for dog training and boarding businesses',
            'page_copy.solutions.lead' => 'Two kinds of dog-care business, one everyday problem: inquiries to answer, clients to follow up and too many tools to juggle.',
            'page_copy.solutions.note' => 'Run a board-and-train program? The training tools apply to you too.',
            'industries' => [
                [
                    'name' => 'Dog Training Businesses',
                    'head' => 'Turn more inquiries into enrolled clients.',
                    'body' => 'Trainers, obedience schools, puppy programs and board-and-train operators run on conversations. MotionGrove helps you capture every inquiry, reply quickly and keep prospective clients moving toward a consultation.',
                    'jobs' => "Capture inquiries :: Website forms create contacts in your CRM automatically.\nSchedule consultations :: Share booking links for the consultations and appointments you offer.\nFollow up :: Send an instant reply to new inquiries, plus appointment reminders.\nManage your pipeline :: See who is new, who booked a consultation and who is ready to enrol.\nKeep conversations together :: Text, email and web chat on each client record.\nMarket locally :: Track search visibility and Google and Meta ad results.",
                ],
                [
                    'name' => 'Dog Boarding Businesses',
                    'head' => 'Never lose a boarding inquiry.',
                    'body' => 'Boarding facilities and kennels get inquiries at all hours. MotionGrove helps you capture requests, respond promptly and keep client communication organised. It supports your inquiries and marketing; it is not an overnight reservation or occupancy system.',
                    'jobs' => "Capture boarding inquiries :: Request and quote forms feed straight into your CRM.\nKeep contacts organised :: One record per client with notes and history.\nRespond faster :: Automated replies acknowledge new inquiries right away.\nStay in touch :: Text, email and web chat conversations in one inbox.\nOffer tours and meet-and-greets :: Use the booking calendar for appointments you offer.\nBe found locally :: Track search visibility and ad results.",
                ],
            ],
            'page_copy.solutions.cta.title' => 'See how it fits your dog business.',
            'page_copy.solutions.cta.lead' => 'See how MotionGrove could work for your training or boarding business.',
            'x.cta.solutions' => $secondary,

            // 5 — How it works (this page holds at most four steps)
            'page_copy.how-it-works.eyebrow' => 'How it works',
            'page_copy.how-it-works.title' => 'From setup to steady growth',
            'page_copy.how-it-works.title_accent' => 'in four steps.',
            'page_copy.how-it-works.lead' => 'Build your online presence, capture inquiries, follow up automatically, and manage leads and appointments, so you can grow with better local visibility and less manual work.',
            'page_copy.how-it-works.steps' => [
                ['0' => 'Build your online presence', '1' => 'Create a mobile-ready website for your training or boarding business.', '2' => 'Choose a plan and answer a few questions about your business.', '3' => 'Your workspace is set up for you.', '4' => 'Milestone', '5' => 'Workspace ready'],
                ['0' => 'Capture inquiries', '1' => 'Forms on your website create contacts in your CRM automatically.', '2' => 'Add inquiry or consultation forms to your pages.', '3' => 'Every submission lands on a client record.', '4' => 'Milestone', '5' => 'Inquiries in one place'],
                ['0' => 'Follow up automatically', '1' => 'Reply to new inquiries and remind clients about appointments without doing it by hand.', '2' => 'Choose the automations and adjust the wording.', '3' => 'Messages go out on the schedule you set.', '4' => 'Milestone', '5' => 'Faster responses'],
                ['0' => 'Manage leads and grow', '1' => 'Track leads and appointments, and use SEO and ad reporting on plans that include them to improve local visibility.', '2' => 'Move clients through your pipeline and review your results.', '3' => 'Your calendar, conversations and reports stay together.', '4' => 'Milestone', '5' => 'Less manual work'],
            ],
            'page_copy.how-it-works.cta.title' => 'Ready to get started?',
            'page_copy.how-it-works.cta.lead' => 'See how MotionGrove fits your dog training or boarding business.',
            'x.cta.how' => $primary,

            // 7 — Pricing page copy (the plans themselves come from the Agency's real catalogue)
            'page_copy.pricing.eyebrow' => 'Pricing',
            'page_copy.pricing.title' => 'Simple plans for dog-care businesses.',
            'page_copy.pricing.lead' => 'Choose the plan with the tools your business needs. What each plan includes is listed below.',
            'page_copy.pricing.compare.title' => 'What each plan includes',
            'page_copy.pricing.billing_faq' => 'Billing questions',

            // 10 — SEO
            'pages.home.title' => 'MotionGrove: software for dog training and boarding businesses',
            'pages.home.description' => 'Websites, CRM, automated follow-ups, booking tools and marketing for dog training and boarding businesses. Capture inquiries and grow with less busywork.',
            'pages.product.title' => 'Product: CRM, websites and follow-ups for dog businesses',
            'pages.product.description' => 'See how MotionGrove brings your website, CRM, conversations, automations, booking calendar and marketing tools together.',
            'pages.solutions.title' => 'Solutions for dog training and boarding businesses',
            'pages.solutions.description' => 'How dog trainers, board-and-train operators, boarding facilities and kennels use MotionGrove to capture and follow up inquiries.',
            'pages.how-it-works.title' => 'How MotionGrove works for dog businesses',
            'pages.how-it-works.description' => 'Build your online presence, capture inquiries, follow up automatically and manage leads and appointments.',
            'pages.pricing.title' => 'MotionGrove pricing for dog training and boarding',
            'pages.pricing.description' => 'Compare plans and see exactly what each one includes.',
        ];
    }
}
