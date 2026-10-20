<?php

namespace App\Library\AgencySite;

/**
 * White Label — the starter wording for an Agency site where the MotionGrove original would put
 * words in the Agency's mouth.
 *
 * The starter site reuses the MotionGrove copy for what the SOFTWARE does. It must not, however,
 * promise anything only the Agency can promise: implementation help, named specialists, response
 * times, prices, billing cadence, results, customer stories. Those are replaced here with neutral
 * statements, and the sections that exist purely to make such promises (support & onboarding,
 * setup commitment) start switched off until the Agency deliberately writes its own.
 */
final class SiteDefaults
{
    /**
     * @return array<string, mixed> dot path => value, applied on top of the re-branded MotionGrove tree
     */
    public static function values(string $brand): array
    {
        return [
            'copy.home.hero.lead' => "{$brand} brings your website, customers, conversations and bookings into one place, so you can follow up faster and spend less time on busywork.",
            'copy.home.hero.lead_mobile' => "{$brand} brings your website, customers, conversations and bookings into one place.",
            'copy.home.hero.ticks' => ['Website, CRM and calendar together', 'Automated follow-ups', 'Built for small businesses'],
            'copy.home.hero.secondary' => 'See how it works',
            'copy.home.trust.title' => 'Built for businesses that run on inquiries and bookings',
            'copy.home.trust.title_mobile' => 'Built for businesses that run on bookings',
            'copy.home.trust.logos_note' => '',
            'copy.home.pricing.lead' => 'Choose the plan that fits your business.',
            'copy.home.pricing.lead_mobile' => 'Choose the plan that fits.',
            'copy.home.pricing.link' => 'See full plan details',
            'copy.home.pricing.link_mobile' => 'Full plan details',
            'copy.home.comparison.note' => 'What is included depends on the plan you choose.',
            'copy.home.people.eyebrow' => 'Support',
            'copy.home.people.title' => 'Help when you need it.',
            'copy.home.people.lead' => 'Describe the support you actually offer your customers here.',
            'copy.home.people.lead_mobile' => 'Describe the support you actually offer here.',
            'copy.home.people.points' => [['Support by email', 'Tell customers how to reach you.']],
            'copy.home.people.note' => '',
            'copy.home.commitment.eyebrow' => 'Getting started',
            'copy.home.commitment.title' => 'What to expect when you sign up.',
            'copy.home.commitment.lead' => 'Describe exactly what you provide when a customer signs up.',
            'copy.home.commitment.cards' => [['What you get', 'Your own workspace with the tools in your plan.']],
            'copy.home.final.lead' => "Bring your website, customers and bookings together with {$brand}.",
            'copy.home.final.lead_mobile' => "Bring your website, customers and bookings together with {$brand}.",
            'copy.footer_blurb' => "{$brand} — your website, customers and bookings in one place.",
            'copy.jazmin_disclaimer_home' => null,
            'copy.jazmin_disclaimer_customers' => null,
            'copy.unspecified_disclaimer' => null,
            'copy.usage_note' => '',
            'page_copy.how-it-works.lead' => 'Four simple steps from sign-up to getting started.',
            'page_copy.how-it-works.steps' => [
                ['Create your account', 'Choose a plan and tell us about your business.', 'Enter your details and choose a plan.', 'Your account is created and linked to your plan.', 'Milestone', 'Account created'],
                ['Set up your workspace', 'Add your business details, services and availability.', 'Fill in your business profile.', 'Your workspace is ready with the tools in your plan.', 'Milestone', 'Workspace ready'],
                ['Connect your tools', 'Connect your website, calendar and messaging.', 'Connect the channels you use.', 'The connections are available in your workspace.', 'Milestone', 'Tools connected'],
                ['Start using it', 'Capture leads, book appointments and follow up.', 'Run your day from one place.', 'Your plan stays active while your subscription is paid.', 'Milestone', 'Up and running'],
            ],
            'page_copy.how-it-works.commitment.eyebrow' => 'Getting started',
            'page_copy.how-it-works.commitment.title' => 'What you get.',
            'page_copy.how-it-works.commitment.lead' => 'Describe exactly what you provide when a customer signs up.',
            'page_copy.how-it-works.commitment.cards' => [['Your workspace', 'Access to the tools included in your plan.']],
            'page_copy.how-it-works.cta.lead' => 'Choose a plan and start today.',
            'page_copy.pricing.title' => 'Simple plans.',
            'page_copy.pricing.lead' => 'Choose the plan that fits your business.',
            'page_copy.pricing.choose_note' => 'Not sure which plan fits?',
            'page_copy.customers.empty' => 'Customer stories will appear here.',
            'page_copy.customers.lead' => 'Business owners talking, in their own words.',
            'page_copy.customers.cta.lead' => 'Book a call and see how it would work for you.',
            'page_copy.solutions.lead' => 'Different industries, the same everyday problem: too many tools and not enough time.',
            'page_copy.solutions.note' => 'Don’t see your industry? The platform isn’t industry-specific.',
            'page_copy.product.cta.title' => 'See it with your own business in mind.',
            'usage_charges' => [],
            'screenshots.hero' => null,
            'setup_specialist.name' => null,
        ];
    }

    /** Starter Q&A that states only facts about the software and the Agency's own billing flow. */
    public static function homeFaqs(string $brand): array
    {
        return [
            ['q' => "What is {$brand}?", 'a' => "{$brand} is an all-in-one workspace for your website, customers, conversations, calendar and follow-ups."],
            ['q' => 'Who is it for?', 'a' => 'Small businesses that run on inquiries and bookings.'],
            ['q' => 'How do I get started?', 'a' => 'Choose a plan, create your account and your workspace is set up for you.'],
            ['q' => 'How does billing work?', 'a' => 'Plans are billed on a recurring subscription through Stripe. You can manage your payment method and subscription from your account.'],
            ['q' => 'How do I contact support?', 'a' => 'Use the support link in the footer of this site or inside your account.'],
        ];
    }

    public static function pricingFaqs(): array
    {
        return [
            ['q' => 'How am I billed?', 'a' => 'Your plan renews automatically on its billing interval until you cancel. Payments are processed securely by Stripe.'],
            ['q' => 'Can I change or cancel my plan?', 'a' => 'You can manage your subscription, payment method and invoices from the billing portal in your account.'],
        ];
    }

    /** Starter wording for the Agency's own flow when it chose to book calls instead of selling online. */
    public static function bookCallNote(): string
    {
        return 'Pricing is discussed on a call.';
    }
}
