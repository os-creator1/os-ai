<?php

/*
|--------------------------------------------------------------------------
| MotionGrove public marketing website
|--------------------------------------------------------------------------
|
| Everything the public site says or links to is defined here, in one place,
| and read through App\Library\Marketing\MarketingSite. The Blade views under
| resources/views/marketing contain layout and decorative product illustrations
| only. A later owner-only content editor can replace this file with database
| rows by changing MarketingSite::content() alone; no view needs to change.
|
| Rules that hold for every value below (docs/product/MARKETING-WEBSITE.md):
|   - Public prices are Core $97, Growth $297, Agency $497 per 28 days. The
|     private Growth offer, the private 28-day trial, sales-call checkout links
|     and any trial activation are never public.
|   - Advertising is always optional.
|   - Example dashboard data is always labelled as such.
|   - A value that is not confirmed yet is null. Null means "do not render", or
|     "render a clearly labelled placeholder" only while show_placeholders is on.
*/

return [
    'enabled' => (bool) env('MARKETING_SITE_ENABLED', true),

    // Hostnames the marketing routes answer on. Blank = the platform host only (the APP_URL host:
    // getmotiongrove.com in production, staging.getmotiongrove.com on staging). Add others explicitly,
    // e.g. "getmotiongrove.com,www.getmotiongrove.com". No separate app host exists in this repository.
    'hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MARKETING_HOSTS', ''))))),

    // Labelled placeholders ("[Customer name]", "[Video ID]") render only while this is on.
    // Default: on everywhere except production, so a real visitor never sees one.
    'show_placeholders' => env('MARKETING_SHOW_PLACEHOLDERS') === null
        ? ! (env('APP_ENV') === 'production')
        : filter_var(env('MARKETING_SHOW_PLACEHOLDERS'), FILTER_VALIDATE_BOOL),

    // Copy that has not been confirmed as production-ready (flagged `unverified` below).
    'show_unverified_features' => filter_var(env('MARKETING_SHOW_UNVERIFIED_FEATURES', false), FILTER_VALIDATE_BOOL),

    'site' => [
        'name' => 'MotionGrove',
        'og_image' => 'img/mg-mark.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Call-to-action destinations
    |--------------------------------------------------------------------------
    */
    'cta' => [
        // ONE destination for every "Book a demo" / "Talk to us" button.
        // Precedence: this explicit URL (e.g. a verified Calendly link), then the
        // native /book-demo route once it is operational (see MarketingSite::bookDemoUrl()),
        // else the buttons are not rendered at all rather than lead to a broken page.
        'book_demo_url' => env('MARKETING_BOOK_DEMO_URL'),

        // Where "Log in" goes. Null = the application's own login route.
        'login_url' => env('MARKETING_LOGIN_URL'),

        // One destination per plan. Null = the application's own signup for that plan
        // (route('register', ['plan' => key]), which validates against the plan catalog),
        // else Book a demo. There is deliberately no trial option.
        'plans' => [
            'core' => env('MARKETING_CHECKOUT_CORE_URL'),
            'growth' => env('MARKETING_CHECKOUT_GROWTH_URL'),
            'agency' => env('MARKETING_CHECKOUT_AGENCY_URL'),
        ],
    ],

    'links' => [
        'help_centre' => env('MARKETING_HELP_URL'),
        'support_email' => env('MARKETING_SUPPORT_EMAIL'),
        'rates' => env('MARKETING_RATES_URL'),
        'privacy' => env('MARKETING_PRIVACY_URL'),
        'terms' => env('MARKETING_TERMS_URL'),
        'acceptable_use' => env('MARKETING_ACCEPTABLE_USE_URL'),
    ],

    'social' => [
        'youtube' => env('MARKETING_YOUTUBE_URL'),
        'instagram' => env('MARKETING_INSTAGRAM_URL'),
    ],

    // Approved customer logos, shown only when sections.home.trust_logos is on:
    // [['name' => 'Acme Events', 'image' => 'img/logos/acme.svg'], ...]
    'trust_logos' => [],

    // Section switches. Defaults follow HANDOFF.md.
    'sections' => [
        'home' => [
            'trust_logos' => false,        // hidden until customers approve their logos
            'testimonials' => true,
            'setup_specialist_card' => true,
        ],
    ],

    // Product screenshots. A path (relative to public/marketing) replaces the
    // matching drawn illustration; null keeps the approved illustration.
    'screenshots' => [
        'hero' => null,
        'hero_phone' => null,
    ],

    // Setup specialist shown in the "Real people" card. Null = labelled placeholder (non-production only).
    'setup_specialist' => [
        'name' => null,
        'photo' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Plans (public prices)
    |--------------------------------------------------------------------------
    | Verified against workspace_plan_catalog by `php artisan marketing:verify-pricing`.
    */
    'plans' => [
        'core' => [
            'name' => 'Core', 'price' => 97, 'cycle' => 'every_28_days', 'featured' => false,
            'tagline' => 'Organize and follow up on every lead.',
            'tagline_pricing' => 'CRM and the everyday business tools.',
            'tagline_mobile' => 'CRM, calendar, forms, automations, website',
            'cta' => 'Choose Core',
            // Packaging follows workspace_plan_features: website generation and the basic AI assistant are in every plan.
            'features' => [
                'CRM, contacts & pipelines', 'Conversations', 'Calendar & bookings',
                'Forms & lead capture', 'Automations & follow-ups',
                'AI website generation & hosting', 'Ask MotionGrove AI assistant',
            ],
            'features_pricing' => [
                'CRM, contacts & pipelines', 'Conversations inbox', 'Calendar & bookings',
                'Forms & lead capture', 'Automations & follow-ups',
                'AI website generation & hosting', 'Ask MotionGrove AI assistant',
            ],
        ],
        'growth' => [
            'name' => 'Growth', 'price' => 297, 'cycle' => 'every_28_days', 'featured' => true,
            'badge' => 'Most popular',
            'tagline' => 'Get found, get leads, get booked.',
            'tagline_pricing' => 'Your complete growth system.',
            'tagline_mobile' => 'Core plus SEO tools, ads performance if you run ads, and guided onboarding.',
            'cta' => 'Choose Growth',
            'features_lead' => 'Everything in Core, plus:',
            'features' => [
                'SEO tools & recommendations',
                'Google & Meta ads performance, if you run ads',
                'Guided onboarding & assistance',
            ],
            'features_pricing' => [
                'SEO tools & recommendations',
                'Google & Meta ads performance',
                'Guided onboarding & assistance',
            ],
        ],
        'agency' => [
            'name' => 'Agency', 'price' => 497, 'cycle' => 'every_28_days', 'featured' => false,
            'tagline' => 'Manage many businesses, under your brand.',
            'tagline_pricing' => 'For agencies managing several businesses.',
            'tagline_mobile' => 'Multi-account & white-label',
            'cta' => 'Choose Agency',
            'features_lead' => 'Everything in Growth, plus:',
            'features' => ['Multi-account management', 'White-label branding', 'Client workspaces & permissions'],
            'features_pricing' => ['Multi-account management', 'White-label branding', 'Client workspaces & permissions'],
        ],
    ],

    // Plan comparison table on /pricing. Cell values: true (included), false (not included),
    // 'confirm' (entitlement not confirmed; neutral wording in production).
    'plan_comparison' => [
        ['group' => 'Run the business', 'rows' => [
            ['CRM, contacts & pipelines', [true, true, true]],
            ['Conversations (text, email, chat)', [true, true, true], 'Text messages and calls are billed by usage.'],
            ['Calendar & bookings', [true, true, true]],
            ['Forms & surveys', [true, true, true]],
            ['Automations & follow-ups', [true, true, true]],
        ]],
        ['group' => 'Grow', 'rows' => [
            ['AI website generation & hosting', [true, true, true]],
            ['Ask MotionGrove AI assistant', [true, true, true], 'Some AI features are billed by usage.'],
            ['SEO visibility & rank tracking', [true, true, true]],
            ['SEO tools & recommendations', [false, true, true]],
            ['Google & Meta ads performance', [false, true, true], 'See ad results next to your leads. Ad spend is paid to Google or Meta directly.'],
            ['Guided onboarding & assistance', ['confirm', true, true]],
        ]],
        ['group' => 'Agencies', 'rows' => [
            ['Multi-account management', [false, false, true]],
            ['White-label branding', [false, false, true]],
        ]],
    ],

    // Comparison rows that map to a catalog feature key; `marketing:verify-pricing` checks them against
    // workspace_plan_features so the table cannot claim a tier gets (or lacks) something it does not.
    'plan_feature_keys' => [
        'CRM, contacts & pipelines' => 'crm',
        'Conversations (text, email, chat)' => 'conversations',
        'Calendar & bookings' => 'calendar',
        'Forms & surveys' => 'forms',
        'Automations & follow-ups' => 'automations',
        'AI website generation & hosting' => 'website_generation',
        'Ask MotionGrove AI assistant' => 'ai_coo_basic',
        'SEO visibility & rank tracking' => 'seo_basic_visibility',
        'SEO tools & recommendations' => 'seo_module',
        'Google & Meta ads performance' => 'ads_module',
        'Multi-account management' => 'agency_package_capabilities',
        'White-label branding' => 'white_label',
    ],

    'usage_charges' => [
        ['Text messages', 'Per message sent and received.'],
        ['Calls & phone numbers', 'Per minute and per number.'],
        ['AI usage', 'For some AI features, by usage.'],
        ['Ad spend', 'Paid directly to Google or Meta. Not billed by MotionGrove.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Page metadata (unique title + description per page)
    |--------------------------------------------------------------------------
    */
    'pages' => [
        'home' => [
            'title' => 'MotionGrove — More bookings. Less busywork.',
            'description' => 'MotionGrove helps small businesses get found, turn visitors into leads and get booked — with a website, SEO, optional ads, CRM, automations and real people to help you set up.',
        ],
        'product' => [
            'title' => 'Product — MotionGrove',
            'description' => 'See what is inside MotionGrove: a website built to get inquiries, CRM, automations, bookings, SEO and an AI assistant, connected in one place.',
        ],
        'solutions' => [
            'title' => 'Solutions — MotionGrove',
            'description' => 'MotionGrove for event services, photo booths, home services, health and wellness, salons, professional services and agencies.',
        ],
        'how-it-works' => [
            'title' => 'How it works — MotionGrove',
            'description' => 'From sign-up to running smoothly in four steps, with guided setup and real people helping at every step.',
        ],
        'customers' => [
            'title' => 'Customer stories — MotionGrove',
            'description' => 'Business owners talking, in their own words, about how they run things today.',
        ],
        'pricing' => [
            'title' => 'Pricing — MotionGrove',
            'description' => 'Core $97, Growth $297 and Agency $497, billed every 28 days. Messaging, calls, some AI features and ad spend are billed separately.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared copy
    |--------------------------------------------------------------------------
    */
    'copy' => [
        'example_tag' => 'Example workspace · sample data',
        'jazmin_disclaimer_home' => 'Stories marked “Jazmin Media client” describe results from Jazmin Media’s agency services before MotionGrove, not results produced by the MotionGrove software.',
        'unspecified_disclaimer' => 'These stories are told by the clients themselves. Some describe work from before MotionGrove, including Jazmin Media’s agency services; none is evidence of results produced by the MotionGrove software.',
        'jazmin_disclaimer_customers' => 'Stories marked “Jazmin Media client” describe results from Jazmin Media’s agency services before MotionGrove existed. They are not evidence of results produced by the MotionGrove software.',
        'footer_blurb' => 'More bookings. Less busywork. Get found, get leads, get booked — with real people behind it.',
        'usage_note' => 'Messaging, calls, some AI features and ad spend are billed separately.',

        'home' => [
            'hero' => [
                'chip' => 'Get found. Get leads. Get booked.',
                'title' => 'More bookings.',
                'title_accent' => 'Less busywork.',
                'lead' => 'MotionGrove brings new customers to your door first — a website built to turn visitors into inquiries, plus SEO, ads or both, whichever fits where your business is right now. Then it follows up, books them in and takes the busywork off your plate, with real people here when you need help.',
                'lead_mobile' => 'MotionGrove gets you found and brings in leads first — then follows up, books them in and handles the busywork, with real people here when you need help.',
                'primary' => 'Get started',
                'secondary' => 'See how it works',
                'ticks' => ['Guided setup on eligible plans', 'Real people when you need help', 'No technical skills needed'],
            ],
            'trust' => [
                'title' => 'Built for businesses that run on inquiries and bookings',
                'title_mobile' => 'Built for businesses that run on bookings',
                'industries' => ['Event services', 'Photo booth rentals', 'Home services', 'Health & wellness', 'Salons & studios', 'Professional services', 'Marketing agencies'],
                'industries_mobile' => ['Event services', 'Photo booths', 'Home services', 'Wellness', 'Agencies'],
                'logos_note' => 'Logo row shows only once customers approve the use of their logo.',
            ],
            'problem' => [
                'eyebrow' => 'The problem',
                'title' => 'Great work doesn’t get booked if customers can’t find you.',
                'lead' => ['Most business software helps you manage the customers you already have. It doesn’t bring you new ones. If you don’t show up on Google, your website doesn’t turn visitors into inquiries, or your reply comes a day late, the job goes to someone else.', 'And the leads you do get end up scattered across five different tabs.'],
                'lead_mobile' => 'Most software helps you manage the customers you already have. MotionGrove starts by bringing you new ones.',
                'items' => [
                    ['Customers can’t find you', 'When people search for what you do, your competitors show up first.', 'Competitors show up first on Google.'],
                    ['Visitors don’t become inquiries', 'Your website looks fine, but it isn’t built to get people to reach out.', 'Your website isn’t built to get inquiries.', 'Visitors don’t reach out'],
                    ['Marketing is a guessing game', 'You can see what you spend, but not which channel actually brought the booking.', null],
                    ['Follow-ups come too late', 'By the time you reply, the customer has already booked someone else.', 'The customer already booked someone else.'],
                    ['Leads are scattered', 'Forms, texts, emails and DMs in different places — some never get answered.', 'Forms, texts and DMs in five different places.'],
                    ['Admin eats your evenings', 'Reminders, confirmations and paperwork pile up after the real work is done.', null],
                ],
                'closing' => 'MotionGrove fixes it in the right order.',
                'closing_accent' => 'First more leads. Then less busywork.',
            ],
            'system' => [
                'eyebrow' => 'One connected growth system',
                'eyebrow_mobile' => 'One connected system',
                'title' => 'From being found to being booked — without anything falling through.',
                'title_mobile' => 'From first visit to booked.',
                'lead' => 'Each part of MotionGrove hands off to the next. Choose a step to see what happens.',
            ],
            'capabilities' => [
                'eyebrow' => 'What’s inside',
                'title' => 'First it brings customers in. Then it takes care of the rest.',
                'title_mobile' => 'First it brings customers in. Then it handles the rest.',
            ],
            'people' => [
                'eyebrow' => 'Guided setup & ongoing help',
                'eyebrow_mobile' => 'Guided setup & help',
                'title' => 'Powerful software. Real people behind it.',
                'lead' => 'You don’t have to figure it out alone. A real person helps you get set up, connect your tools and make the most of MotionGrove as your business grows.',
                'lead_mobile' => 'A real person helps you set up, connect your tools and keep improving.',
                'points' => [
                    ['Personalized initial setup', 'around how your business works'],
                    ['Help connecting', 'your calendar, phone number, domain and ad accounts'],
                    ['Your essential workflows configured', '— inquiries, reminders, follow-ups'],
                    ['Guidance on your processes', ', not just the software'],
                    ['Ongoing support', 'and growth advice when you need it'],
                ],
                'note' => 'Setup help covers your plan’s standard scope. Custom website projects and managed advertising aren’t included in a subscription.',
            ],
            'how' => [
                'eyebrow' => 'How it works',
                'title' => 'Built for business owners, not software specialists.',
                'cta' => 'See the full process',
            ],
            'videos' => [
                'eyebrow' => 'Customer stories',
                'title' => 'Hear it from the business owners themselves.',
                'title_mobile' => 'Hear it from the owners.',
                'cta' => 'All customer stories',
            ],
            'comparison' => [
                'eyebrow' => 'Why one platform',
                'title' => 'More leads coming in. Fewer tools to juggle.',
                'note' => 'Some features depend on your plan.',
                'rows' => [
                    ['Getting found', null, 'A separate SEO tool, or hoping for the best', 'Built in', ' — visibility tracking and clear next steps'],
                    ['Ads', '(optional)', 'Two ad dashboards, no link to bookings', 'One view', ' of ad results next to the leads they bring — only if you choose to run ads'],
                    ['Website', null, 'Separate website builder', 'Built in', ' — designed to get inquiries, forms feed your CRM'],
                    ['Leads & customers', null, 'Separate CRM or a spreadsheet', 'Built in', ' — with full conversation history'],
                    ['Follow-ups', null, 'Email tool plus manual texts', 'Automated', ' text and email workflows'],
                    ['Bookings', null, 'Separate scheduling app', 'Built in', ' — tied to each contact'],
                    ['Reporting', null, 'Pieced together by hand', 'One dashboard', ' for the whole business'],
                    ['Setup & help', null, 'Help docs for each tool', 'Guided setup', ' and real people'],
                ],
            ],
            'pricing' => [
                'eyebrow' => 'Pricing',
                'title' => 'Simple plans. No surprises.',
                'title_mobile' => 'Simple plans.',
                'lead' => 'Billed every 28 days. Choose the plan that fits today and change it as you grow.',
                'lead_mobile' => 'Billed every 28 days.',
                'link' => 'See full plan details and usage charges',
                'link_mobile' => 'Full plan details and usage charges',
            ],
            'commitment' => [
                'eyebrow' => 'Our implementation commitment',
                'title' => 'We’ll help you get it working.',
                'lead' => 'Eligible plans include guided setup. If an agreed standard setup item is still incomplete because of us, we keep helping — with no additional implementation fees.',
                'cards' => [
                    ['What it covers', 'The agreed standard setup for your plan — your website, CRM, calendar and core workflows.'],
                    ['What we need from you', 'The information, account access and approvals needed to finish each step.'],
                    ['What it isn’t', 'A guarantee of leads, bookings or revenue, unlimited managed marketing, or a pause on subscription charges.'],
                ],
            ],
            'faq' => ['eyebrow' => 'FAQ', 'title' => 'Questions, answered.', 'title_mobile' => 'Questions', 'lead' => 'Still unsure?', 'lead_link' => 'Talk to our team'],
            'final' => [
                'title' => 'Get more customers in. Spend less time managing them.',
                'lead' => 'MotionGrove helps people find you, turns them into leads, and handles the follow-ups, bookings and busywork — with real people behind it.',
                'lead_mobile' => 'Get found, get leads, get booked — with real people behind it.',
                'primary' => 'Get started',
                'secondary' => 'Book a demo',
            ],
        ],
    ],


    /*
    |--------------------------------------------------------------------------
    | Inner-page headers and closing calls to action
    |--------------------------------------------------------------------------
    */
    'page_copy' => [
        'product' => [
            'eyebrow' => 'Product',
            'title' => 'Everything your business runs on.', 'title_accent' => 'Connected.',
            'lead' => 'Website, CRM, conversations, bookings, automations and marketing — built to work together, so you spend less time switching tabs and more time with customers.',
            'jump' => [['website', 'Website'], ['crm', 'CRM & conversations'], ['auto', 'Automations'], ['book', 'Bookings'], ['seo', 'SEO & ads'], ['ai', 'AI assistant'], ['more', 'More']],
            'cta' => ['title' => 'See it with your own business in mind.', 'lead' => null, 'secondary' => 'Book a demo'],
        ],
        'solutions' => [
            'eyebrow' => 'Solutions',
            'title' => 'Made for businesses that live on inquiries and bookings.', 'title_accent' => null,
            'lead' => 'Different industries, the same everyday problem: too many tools and not enough time. Pick yours to see how MotionGrove fits.',
            'note' => 'Dedicated industry pages are on the way. Don’t see yours?',
            'agency' => [
                'eyebrow' => 'For agencies', 'title' => 'Run your clients’ growth from one account.',
                'lead' => 'The Agency plan gives you a workspace for each client business, with your own branding and the same connected tools.',
                'primary' => 'See the Agency plan', 'secondary' => 'Talk to us',
                'tiles' => [['Client workspaces', 'One login, every client'], ['White-label', 'Your logo and branding'], ['Permissions', 'Control what clients see'], ['Shared templates', 'Reuse what works']],
            ],
            'cta' => ['title' => 'Whatever you sell, the busywork looks the same.', 'lead' => 'Let’s talk about how your business takes inquiries today.'],
        ],
        'how-it-works' => [
            'eyebrow' => 'How it works',
            'title' => 'From sign-up to running smoothly —', 'title_accent' => 'with help at every step.',
            'lead' => 'No technical skills needed. You bring what you know about your business; we help with the rest.',
            'steps' => [
                ['Tell us about your business', 'Guided questions about what you offer, where you work and who your customers are. This shapes your website, services and first workflows.', 'Answer the questions, add photos and your logo.', 'Review your answers and suggest what to set up first.', 'Milestone', 'Business profile complete'],
                ['Set up and connect your tools', 'Publish your website, connect your calendar, business phone number, domain and ad accounts.', 'Approve access to the accounts you already use.', 'Walk you through each connection on a call or in chat.', 'Milestone', 'Website live and calendar connected'],
                ['Automate your everyday workflows', 'Turn on the follow-ups, reminders and payment requests that save the most time, starting from templates.', 'Choose the moments to automate and tweak the wording.', 'Help configure your essential workflows and test them.', 'Milestone', 'First automated reply sent'],
                ['Track progress and keep improving', 'Your Home dashboard shows what needs you and what’s working. When results stall, it says so — and suggests the next step.', 'Check Home, act on suggestions, ask questions.', 'Ongoing support and growth guidance when you need it.', 'Ongoing', 'Regular check-ins on what to improve'],
            ],
            'commitment' => [
                'eyebrow' => 'Our implementation commitment', 'title' => 'We’ll help you get it working.',
                'lead' => 'If an agreed standard setup item is still incomplete because of us, we keep helping — with no additional implementation fees.',
                'cards' => [
                    ['Included in standard setup', 'Website, CRM, calendar and core workflows for your plan.'],
                    ['What we need from you', 'Information, account access and approvals.'],
                    ['Not included', 'Custom website projects, managed advertising, or unlimited bespoke work.'],
                    ['Not a guarantee of results', 'We don’t promise leads, bookings or revenue, and setup help doesn’t pause your subscription.'],
                ],
            ],
            'cta' => ['title' => 'Ready when you are.', 'lead' => 'Choose a plan and start today, or talk it through with our team first.'],
        ],
        'customers' => [
            'eyebrow' => 'Customer stories',
            'title' => 'Real businesses. Real experiences.', 'title_accent' => null,
            'lead' => 'Business owners talking, in their own words, about how they run things today.',
            'empty' => 'Customer stories are coming soon.',
            'cta' => ['title' => 'Want to see it for your business?', 'lead' => 'Book a call and we’ll show you how it would work for you.', 'secondary' => 'See pricing'],
        ],
        'pricing' => [
            'eyebrow' => 'Pricing',
            'title' => 'Simple plans. Real help included.', 'title_accent' => null,
            'lead' => 'All plans are billed every 28 days. Usage like text messages and ad spend is billed separately, so you only pay for what you use.',
            'choose_note' => 'Not sure which plan fits?',
            'usage' => ['eyebrow' => 'Usage charges', 'title' => 'What’s billed separately', 'lead' => 'These depend on how much you use them, so they aren’t bundled into a plan.', 'rates' => 'See current rates'],
            'compare' => ['title' => 'Compare plans', 'note' => 'Final plan entitlements to be confirmed before publishing.'],
            'billing_faq' => 'Billing questions',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Homepage "one connected growth system" steps (also the mobile timeline)
    |--------------------------------------------------------------------------
    */
    'flow_steps' => [
        ['title' => 'Discover', 'tool' => 'Website & SEO', 'head' => 'A customer finds you.', 'body' => 'Your MotionGrove website and search visibility help people find you when they search for what you offer — on Google, social or a referral link.', 'short' => 'Your website and search visibility bring people in.', 'ui_label' => 'Where visitors came from', 'rows' => [['Google search', 'Organic', '#1F5E4A'], ['Instagram ad', 'Meta', '#B4514A'], ['Referral link', 'Direct', '#A99E96']]],
        ['title' => 'Capture', 'tool' => 'Forms', 'head' => 'Every inquiry is caught.', 'body' => 'Website forms, booking links and messages land in one place, with the details you need already filled in. Nothing waits in a separate inbox.', 'short' => 'Forms and booking links catch every inquiry.', 'ui_label' => 'New inquiry', 'rows' => [['Jordan Park', 'Wedding · June 14', '#B4514A'], ['Guests', '~120', '#A99E96'], ['Source', 'Website form', '#A99E96']]],
        ['title' => 'Organize', 'tool' => 'CRM', 'head' => 'The lead is organized for you.', 'body' => 'A contact and an opportunity are created automatically, so you can see every lead, where it stands and what was said last.', 'short' => 'The CRM files the lead for you.', 'ui_label' => 'Pipeline', 'rows' => [['New inquiry', '4', '#B4514A'], ['Proposal sent', '3', '#C9A35B'], ['Booked', '6', '#1F5E4A']]],
        ['title' => 'Follow up', 'tool' => 'Automations', 'head' => 'A reply goes out in minutes.', 'body' => 'A friendly text and email go out straight away, with your availability and packages — and stop as soon as the customer replies.', 'short' => 'A reply goes out in minutes, automatically.', 'ui_label' => 'Workflow', 'rows' => [['Text sent', '2 min', '#1F5E4A'], ['Email with packages', '2 min', '#1F5E4A'], ['Reminder if no reply', 'Tomorrow', '#A99E96']]],
        ['title' => 'Book', 'tool' => 'Calendar & payments', 'head' => 'The customer books.', 'body' => 'They pick a time, sign the proposal and pay online. Confirmations and reminders go out automatically.', 'short' => 'They pick a time, sign and pay online.', 'ui_label' => 'Booking', 'rows' => [['Sat, June 14 · 5 pm', 'Confirmed', '#1F5E4A'], ['Proposal', 'Signed', '#1F5E4A'], ['Deposit', 'Paid', '#1F5E4A']]],
        ['title' => 'Track', 'tool' => 'Dashboard', 'head' => 'You see what’s working.', 'body' => 'Leads, bookings and marketing sit in one dashboard, with clear next steps instead of a pile of reports.', 'short' => 'See what’s working in one dashboard.', 'ui_label' => 'This month', 'rows' => [['Leads', 'from 3 channels', '#B4514A'], ['Bookings', 'by source', '#1F5E4A'], ['Next step', 'suggested', '#C9A35B']]],
    ],

    // Homepage "What's inside" cards shown as the mobile swipe row (Home-phone.dc.html).
    'capability_cards' => [
        ['SEO, ads — or both', 'Show up when people search', 'Grow through search, add ads when you want leads faster, or run both — whatever fits.'],
        ['Website', 'A site built to get inquiries', 'Made from a few questions, with a booking form on every page.'],
        ['CRM', 'Every lead caught and answered', 'Contacts, pipelines and every conversation together.'],
        ['Automations', 'Reply in minutes', 'Follow-ups, reminders and payment requests on autopilot.'],
        ['Bookings', 'A calendar that fills itself', 'Booking links, availability and reminders.'],
        ['AI assistant', 'Ask what to do next', 'Practical suggestions based on your own business.'],
    ],

    'how_steps' => [
        ['Tell us about your business', 'Answer guided questions about your services, area and customers.', 'Milestone: profile complete'],
        ['Set up and connect your tools', 'Publish your website and connect your calendar, number and accounts — with help.', 'Milestone: website live'],
        ['Automate everyday work', 'Turn on follow-ups, reminders and payment requests from ready-made templates.', 'Milestone: first automated reply'],
        ['Track progress and keep improving', 'See leads, bookings and marketing together, and act on clear next steps.', 'Ongoing: monthly check-ins'],
    ],

    /*
    |--------------------------------------------------------------------------
    | FAQs. `pages` lists where each appears; `mobile` marks the short mobile set.
    | A null answer is an unconfirmed term: it renders only while show_placeholders is on.
    |--------------------------------------------------------------------------
    */
    'faqs' => [
        'home' => [
            ['q' => 'Will MotionGrove help me get more customers?', 'a' => 'That’s what it’s built for. Your website and SEO work to bring in inquiries — and ads too, if you choose to run them — and every inquiry gets a fast follow-up. We can’t guarantee results — they depend on your market, your offer and your reviews — but you’ll always see what’s working and what to do next.'],
            ['q' => 'Is MotionGrove difficult to set up?', 'a' => 'No. You answer guided questions about your business, and we walk you through connecting your calendar, phone number and other tools. Eligible plans include setup help from a real person.', 'mobile' => ['Is it difficult to set up?', 'No. Guided questions, plus setup help from a real person on eligible plans.']],
            ['q' => 'Can I use MotionGrove with my existing website?', 'a' => 'Yes. You can keep your current site and use MotionGrove for your CRM, follow-ups and bookings, or build a new website with MotionGrove.'],
            ['q' => 'Does MotionGrove replace my CRM?', 'a' => 'For most small businesses, yes. Contacts, pipelines, conversations and history live in one place, connected to your website, calendar and marketing.'],
            ['q' => 'Can MotionGrove help automate customer follow-ups?', 'a' => 'Yes. Workflows can reply to new inquiries, send reminders, chase unsigned proposals and request payment — by text or email, in your own words.'],
            ['q' => 'Do you help with setup?', 'a' => 'Yes. Eligible plans include guided setup of your website, CRM, calendar and core workflows, plus ongoing support when you need it.'],
            ['q' => 'Is advertising spend included?', 'a' => 'No — and you don’t have to run ads at all. Many local businesses grow through search alone. If you do run ads, spend is paid directly to Google or Meta, and MotionGrove shows how they perform next to the leads and bookings they bring in.', 'mobile' => ['Is ad spend included?', 'No, and ads are optional. If you run them, spend is paid directly to Google or Meta.']],
            ['q' => 'Is MotionGrove suitable for multiple locations?', 'a' => 'Yes. You can add several locations to one business and keep their bookings and details organized.'],
            ['q' => 'Can agencies manage their clients?', 'a' => 'Yes. The Agency plan lets you manage multiple client businesses from one account, with white-label branding.'],
            ['q' => 'Are there additional messaging or AI usage charges?', 'a' => 'Yes. Text messages, calls and some AI features are usage-based and billed separately from your plan. Current rates are listed with the full plan details.', 'link' => 'pricing', 'mobile' => ['Are there usage charges?', 'Texts, calls and some AI features are billed separately from your plan.']],
            ['q' => 'Can I cancel my subscription?', 'a' => null, 'placeholder' => 'Yes. [Cancellation terms and notice period — confirm against the Terms of Service before publishing.]', 'mobile' => ['Can I cancel?', null]],
        ],
        'pricing' => [
            ['q' => 'Why every 28 days?', 'a' => 'Plans renew on a fixed 28-day cycle, so every billing period is the same length.'],
            ['q' => 'Can I change plans later?', 'a' => null, 'placeholder' => 'Yes. [Confirm how upgrades and downgrades are prorated.]'],
            ['q' => 'Can I cancel?', 'a' => null, 'placeholder' => 'Yes. [Confirm cancellation terms against the Terms of Service.]'],
            ['q' => 'Is online payment from my customers included?', 'a' => 'Customers pay you through your own Stripe account. Stripe’s fees go to Stripe and are separate from your MotionGrove plan.'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Solutions (industry selector)
    | `unverified` => true marks copy for a feature that is not confirmed production-ready;
    | it is hidden unless show_unverified_features is on.
    |--------------------------------------------------------------------------
    */
    'industries' => [
        ['name' => 'Event services', 'head' => 'Fill your calendar without living in your inbox.', 'body' => 'Planners, DJs, caterers and rental companies get dozens of date checks. MotionGrove answers them fast, keeps every event on one record and chases the deposit for you.', 'jobs' => [['Instant replies to date checks', 'Availability and packages sent within minutes.'], ['Proposals and deposits', 'Customers sign and pay online; you see it on Home.'], ['Event-day reminders', 'Confirmations to the client before the big day.'], ['Reviews afterwards', 'A thank-you and review request the next morning.', 'unverified' => true]]],
        ['name' => 'Photo booth rentals', 'head' => 'Book more events, chase fewer emails.', 'body' => 'Booth operators juggle inquiries, packages and weekend logistics. MotionGrove keeps packages, quotes and bookings in one place and follows up while you are on site.', 'jobs' => [['Package pages that convert', 'Your booths, backdrops and add-ons on your website.'], ['Quote and booking follow-ups', 'Automatic chasers until they book or say no.'], ['Deposits and contracts', 'Signed and paid online, linked to the booking.'], ['Local search visibility', 'See how you rank for “photo booth near me”.']]],
        ['name' => 'Home services', 'head' => 'Answer every call-back request.', 'body' => 'Cleaners, landscapers and trades lose jobs to slow replies. MotionGrove replies first, books the visit and reminds the customer.', 'jobs' => [['Missed-call text back', 'An automatic text when you cannot pick up.', 'unverified' => true], ['Estimate requests', 'A form that collects the details you need.'], ['Visit reminders', 'Fewer no-shows and wasted trips.'], ['Repeat-service nudges', 'Reminders when it is time to book again.']]],
        ['name' => 'Health & wellness', 'head' => 'Spend your time with clients, not admin.', 'body' => 'Studios, therapists and coaches get booking requests at all hours. MotionGrove lets clients book themselves and keeps intake organized.', 'jobs' => [['Self-service booking', 'Clients pick a time that suits both of you.'], ['Intake forms', 'Collected before the first session.'], ['Reminders and rebooking', 'Fewer gaps in your schedule.'], ['Multiple locations', 'Availability per studio or room.']]],
        ['name' => 'Salons & studios', 'head' => 'Keep chairs full and clients coming back.', 'body' => 'MotionGrove handles booking, reminders and follow-ups so regulars return and new clients find you.', 'jobs' => [['Online booking', 'Services with durations and staff.'], ['Reminders', 'Text reminders before every appointment.'], ['Win-back messages', 'A nudge when a regular is overdue.', 'unverified' => true], ['Review requests', 'Grow your reputation after each visit.', 'unverified' => true]]],
        ['name' => 'Professional services', 'head' => 'A tidy pipeline from first call to signed.', 'body' => 'Consultants, photographers and small firms track leads, send proposals and get paid in one place.', 'jobs' => [['Pipeline by stage', 'See every opportunity at a glance.'], ['Proposals and e-signature', 'Sent, signed and filed on the record.'], ['Payment requests', 'Online payment after signing.'], ['Consultation booking', 'A booking link in every reply.']]],
    ],

    /*
    |--------------------------------------------------------------------------
    | Testimonials (CMS-ready shape — see HANDOFF.md)
    |   context: 'motiongrove' | 'jazmin_media' | null (origin not confirmed)
    | Every value that is not supplied yet is null. Do not invent any of it.
    | The five slots and their order follow the approved design; slot 4 is the
    | historical Jazmin Media client slot.
    |--------------------------------------------------------------------------
    */
    'testimonials' => [
        ['id' => 'story-1', 'youtubeId' => 'QBjC92lyUBc', 'format' => 'standard', 'customerName' => null, 'businessName' => null, 'category' => null, 'location' => null, 'quote' => null, 'context' => null, 'featured' => true, 'order' => 1, 'visible' => true],
        ['id' => 'story-2', 'youtubeId' => 'KytVA5-R2DM', 'format' => 'standard', 'customerName' => null, 'businessName' => null, 'category' => null, 'location' => null, 'quote' => null, 'context' => null, 'featured' => false, 'order' => 2, 'visible' => true],
        ['id' => 'story-3', 'youtubeId' => 'l7L4vXSwYMk', 'format' => 'standard', 'customerName' => null, 'businessName' => null, 'category' => null, 'location' => null, 'quote' => null, 'context' => null, 'featured' => false, 'order' => 3, 'visible' => true],
        ['id' => 'story-4', 'youtubeId' => 'h-YIMbpoTxw', 'format' => 'standard', 'customerName' => null, 'businessName' => null, 'category' => null, 'location' => null, 'quote' => null, 'context' => null, 'featured' => false, 'order' => 4, 'visible' => true],
        ['id' => 'story-5', 'youtubeId' => 'YrB0XJeqz1s', 'format' => 'short', 'customerName' => null, 'businessName' => null, 'category' => null, 'location' => null, 'quote' => null, 'context' => null, 'featured' => false, 'order' => 5, 'visible' => true],
    ],
];
