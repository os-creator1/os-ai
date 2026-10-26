<?php

/*
|--------------------------------------------------------------------------
| MotionGrove Legal Center
|--------------------------------------------------------------------------
|
| The registry of the nine public legal documents. The text of each lives once, in
| resources/legal/<slug>.md, and is rendered by App\Library\Legal\LegalDocuments; nothing
| here duplicates it. The operator's identity (name, registration number, registered
| office, contact) is NOT here either: it is the database-backed, Platform Owner-editable
| App\Library\Legal\LegalCompany, with `company_defaults` below used only until a row exists.
|
| `acceptance_eligible` is false while a document still depends on facts that have not been
| verified (see docs/legal/RELEASE-READINESS.md). Such a document is published for reading but
| is never offered for affirmative acceptance. `acceptance_enabled` is the master switch.
*/

return [
    // Nothing is offered for acceptance until counsel review and the release checklist are done.
    'acceptance_enabled' => (bool) env('LEGAL_ACCEPTANCE_ENABLED', false),

    'company_defaults' => [
        'brand_name' => 'MotionGrove',
        'legal_entity' => 'Jazmin medija, MB',
        'company_number' => '306304073',
        'registered_address' => 'Bičių g. 23, LT-11111 Vilnius, Lithuania',
        'country' => 'Lithuania',
        'legal_email' => 'legal@getmotiongrove.com',
    ],

    'documents' => [
        'terms-of-service' => [
            'tier' => 'primary',
            'title' => 'Terms & Conditions',
            'nav' => 'Terms & Conditions',
            'category' => 'Legal · Agreement',
            'version' => '1.0-draft',
            'updated' => '2026-10-26',
            'acceptance_eligible' => true,
            'seo_title' => 'Terms & Conditions | MotionGrove',
            'description' => 'The principal agreement between MotionGrove and its business customers: accounts, billing, payments, communications, AI, hosted websites, liability and governing law.',
            'intro' => 'The principal agreement for using MotionGrove. Terms of Service and Terms & Conditions are the same document.',
            'glance' => [
                'The main agreement for business customers of MotionGrove.',
                'Plans are billed every 28 days; refunds are not given by default, subject to law.',
                'Governed by Lithuanian law, subject to mandatory protections.',
                'Read together with the billing, acceptable-use, privacy and addendum documents.',
            ],
        ],
        'privacy-policy' => [
            'tier' => 'primary',
            'title' => 'Privacy Policy',
            'nav' => 'Privacy Policy',
            'category' => 'Legal · Privacy',
            'version' => '1.0-draft',
            'updated' => '2026-10-26',
            'acceptance_eligible' => false,
            'seo_title' => 'Privacy Policy | MotionGrove',
            'description' => 'How MotionGrove collects, uses, shares, transfers and protects personal information, and the rights individuals have.',
            'intro' => 'How we handle personal information, and how that differs when a customer controls the data.',
            'glance' => [
                'Explains when MotionGrove is the controller and when a customer is.',
                'Covers payments, communications, AI processing and integrations.',
                'Describes the rights available to individuals and how to use them.',
                'Some operational details are still being confirmed before this document is final.',
            ],
        ],
        'refund-policy' => [
            'tier' => 'primary',
            'title' => 'Billing, Cancellation & Refund Policy',
            'nav' => 'Billing & Refunds',
            'category' => 'Legal · Billing',
            'version' => '1.0-draft',
            'updated' => '2026-10-26',
            'acceptance_eligible' => true,
            'seo_title' => 'Billing, Cancellation & Refund Policy | MotionGrove',
            'description' => 'Standard plans, 28-day billing, recurring payments, cancellation, usage charges, disputes and the no-refund default.',
            'intro' => 'How subscriptions are billed, cancelled and, in limited cases, refunded.',
            'glance' => [
                'Core $97, Growth $297 and Agency $497 per 28-day period.',
                'No refunds by default; legally required or expressly agreed refunds are exceptions.',
                'Cancelling stops future renewal charges; unused time is not automatically refunded.',
                'Usage already incurred stays payable where lawful.',
            ],
        ],
        'acceptable-use' => [
            'tier' => 'primary',
            'title' => 'Acceptable Use & Communications Policy',
            'nav' => 'Acceptable Use',
            'category' => 'Legal · Conduct',
            'version' => '1.0-draft',
            'updated' => '2026-10-26',
            'acceptance_eligible' => true,
            'seo_title' => 'Acceptable Use & Communications Policy | MotionGrove',
            'description' => 'Rules for using MotionGrove, including consent, SMS, calls, email, call recording, automation, AI-assisted outreach and prohibited content.',
            'intro' => 'What you may and may not do with MotionGrove, especially when contacting people.',
            'glance' => [
                'Customers are responsible for consent and lawful contact lists.',
                'Covers SMS, voice calls, call recording and commercial email.',
                'Browser calling is not an emergency telephone service.',
                'Describes enforcement, appeals and cooperation.',
            ],
        ],
        'data-processing-addendum' => [
            'tier' => 'primary',
            'title' => 'Data Processing Addendum',
            'nav' => 'Data Processing Addendum',
            'category' => 'Legal · Data protection',
            'version' => '1.0-draft',
            'updated' => '2026-10-26',
            'acceptance_eligible' => false,
            'seo_title' => 'Data Processing Addendum | MotionGrove',
            'description' => 'The terms on which MotionGrove processes customer personal data on a customer’s behalf.',
            'intro' => 'Supplements the Terms where MotionGrove processes personal data on a customer’s instructions.',
            'glance' => [
                'Applies when MotionGrove acts as a processor for a customer.',
                'Covers instructions, confidentiality, breaches, transfers and deletion.',
                'The technical measures, subprocessor and transfer annexes are not yet published.',
                'Not operative until those annexes are completed and approved.',
            ],
        ],
        'cookie-policy' => [
            'tier' => 'primary',
            'title' => 'Cookie Policy',
            'nav' => 'Cookie Policy',
            'category' => 'Legal · Cookies',
            'version' => '1.0-draft',
            'updated' => '2026-10-26',
            'acceptance_eligible' => false,
            'seo_title' => 'Cookie Policy | MotionGrove',
            'description' => 'How cookies and similar technologies are used, and the choices available to visitors.',
            'intro' => 'What cookies and similar technologies are, and the choices you have.',
            'glance' => [
                'Explains necessary, preference, analytics and advertising technologies.',
                'Describes consent, rejection and withdrawal.',
                'The cookie inventory is not yet published.',
                'Customers and Agencies are responsible for cookies on sites they control.',
            ],
        ],
        'payments-and-esignatures' => [
            'tier' => 'supporting',
            'title' => 'Payments & Electronic Signatures Addendum',
            'nav' => 'Payments & E-Signatures',
            'category' => 'Legal · Addendum',
            'version' => '1.0-draft',
            'updated' => '2026-10-26',
            'acceptance_eligible' => true,
            'seo_title' => 'Payments & Electronic Signatures Addendum | MotionGrove',
            'description' => 'Merchant responsibilities, connected-account arrangements, disputes, negative balances, and the use of document and electronic signature features.',
            'intro' => 'Additional terms for customers who take payments or send documents for signature through MotionGrove.',
            'glance' => [
                'MotionGrove provides technical workflows, not legal advice.',
                'Merchants remain responsible for their own sales, refunds and disputes.',
                'Covers negative balances, reserves and lawful reimbursement rights.',
                'Does not promise that any document is enforceable or a qualified signature.',
            ],
        ],
        'agency-terms' => [
            'tier' => 'supporting',
            'title' => 'Agency & White-Label Addendum',
            'nav' => 'Agency Terms',
            'category' => 'Legal · Addendum',
            'version' => '1.0-draft',
            'updated' => '2026-10-26',
            'acceptance_eligible' => true,
            'seo_title' => 'Agency & White-Label Addendum | MotionGrove',
            'description' => 'Additional terms for Agencies that resell, white-label or manage client accounts on MotionGrove.',
            'intro' => 'Additional terms for Agencies that resell or white-label MotionGrove.',
            'glance' => [
                'Agencies are independent businesses with their own client commitments.',
                'An Agency’s Stripe account, pricing and refunds are its own.',
                'Covers white-label sites, managed clients and account separation.',
                'No revenue or reseller economics are promised.',
            ],
        ],
        'security' => [
            'tier' => 'supporting',
            'title' => 'Security & Service Disclosure',
            'nav' => 'Security',
            'category' => 'Legal · Security',
            'version' => '1.0-draft',
            'updated' => '2026-10-26',
            'acceptance_eligible' => false,
            'seo_title' => 'Security & Service Disclosure | MotionGrove',
            'description' => 'Shared security responsibilities, account protection, availability, incident response and how to report a vulnerability.',
            'intro' => 'Who is responsible for what, and how to report a security concern.',
            'glance' => [
                'Security is a shared responsibility between MotionGrove and customers.',
                'Describes account protection, availability and incident response.',
                'Report vulnerabilities to the legal contact address.',
                'Makes no certification claims.',
            ],
        ],
    ],
];
