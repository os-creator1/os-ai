<?php

/*
|--------------------------------------------------------------------------
| MotionGrove Legal Center
|--------------------------------------------------------------------------
|
| One canonical, versioned source for every legal document. The text lives in
| resources/legal/<slug>.md; everything about its release lives here.
|
| RELEASE GATE. A document is public only when ALL of these hold (see
| App\Library\Legal\LegalRegistry::isPublished):
|   1. status = 'approved', with approved_by, approved_at and effective_date;
|   2. the text has no unresolved "[VERIFY: ...]" placeholder left after the
|      company facts below are filled in;
|   3. resources/legal/hashes.json holds the sha256 of exactly this text for
|      this version (written by `php artisan legal:status --seal`), so an
|      approved document cannot be edited without a new version.
| Until then the existing operative /terms-of-use and /privacy-policy keep
| answering and the new URLs return 404 (unless LEGAL_PREVIEW_DRAFTS is on,
| which is for staging review only and always renders a "draft" banner and
| noindex).
|
| Nothing here states that any document is attorney-approved. 'approved_by'
| is whoever released it; it is a release record, not a legal certification.
*/

return [
    'path' => resource_path('legal'),

    'preview_drafts' => (bool) env('LEGAL_PREVIEW_DRAFTS', false),

    'entity' => [
        'name' => 'Jazmin Media MB',
        'brand' => 'MotionGrove',
        // Spelling, company code and address are NOT verified in this repository.
        // They are supplied through the facts below and block release while unset.
        'description' => 'MotionGrove is a trading brand operated by Jazmin Media MB, a limited-liability legal entity registered in the Republic of Lithuania.',
    ],

    /*
    | Company facts substituted into {{placeholders}} in the documents. A blank
    | value renders as a visible "[VERIFY: label]" marker and blocks release.
    */
    'facts' => [
        'registered_entity' => ['label' => 'exact registered legal name, company code and address', 'value' => env('LEGAL_REGISTERED_ENTITY')],
        'registered_address' => ['label' => 'registered address', 'value' => env('LEGAL_REGISTERED_ADDRESS')],
        'legal_email' => ['label' => 'legal notices email', 'value' => env('LEGAL_EMAIL')],
        'billing_email' => ['label' => 'billing and payments email', 'value' => env('LEGAL_BILLING_EMAIL')],
        'privacy_email' => ['label' => 'privacy contact email', 'value' => env('LEGAL_PRIVACY_EMAIL')],
        'security_email' => ['label' => 'security contact email', 'value' => env('LEGAL_SECURITY_EMAIL')],
        'compliance_email' => ['label' => 'compliance contact email', 'value' => env('LEGAL_COMPLIANCE_EMAIL')],
        'support_email' => ['label' => 'support contact email', 'value' => env('LEGAL_SUPPORT_EMAIL')],
        'agency_support_email' => ['label' => 'agency support email', 'value' => env('LEGAL_AGENCY_SUPPORT_EMAIL')],
    ],

    /*
    | Documents, in Legal Center order. 'primary' marks the six principal pages;
    | the rest are incorporated addenda / a public disclosure.
    */
    'documents' => [
        'terms-of-service' => [
            'title' => 'Terms & Conditions',
            'short' => 'Terms & Conditions',
            'category' => 'Agreement',
            'primary' => true,
            'version' => '1.0-draft',
            'status' => 'draft',
            'effective_date' => null,
            'updated_at' => '2026-10-23',
            'approved_by' => null,
            'approved_at' => null,
            'description' => 'The principal agreement between your business and Jazmin Media MB, trading as MotionGrove.',
            'summary' => [
                'Contracting party is Jazmin Media MB; MotionGrove is its trading brand.',
                'Customer is responsible for its own transactions, content, communications and consents.',
                'Fees are non-refundable by default, subject to law (see Billing, Cancellation & Refund Policy).',
                'Liability limits and exclusions apply only where the law allows them.',
            ],
        ],
        'privacy-policy' => [
            'title' => 'Privacy Policy',
            'short' => 'Privacy Policy',
            'category' => 'Privacy',
            'primary' => true,
            'version' => '1.0-draft',
            'status' => 'draft',
            'effective_date' => null,
            'updated_at' => '2026-10-23',
            'approved_by' => null,
            'approved_at' => null,
            'description' => 'How Jazmin Media MB handles personal information for which it decides the purposes and means.',
            'release_note' => 'Requires a verified privacy and data-flow audit before publication.',
            'summary' => [
                'Covers personal data MotionGrove controls: website, accounts, billing and security.',
                'Business customers are usually the controller of their own CRM data; MotionGrove acts as processor.',
                'Retention, transfers and subprocessors are stated only once verified.',
                'Rights requests may need to be directed to the customer that controls the data.',
            ],
        ],
        'refund-policy' => [
            'title' => 'Billing, Cancellation & Refund Policy',
            'short' => 'Billing & Refunds',
            'category' => 'Billing',
            'primary' => true,
            'version' => '1.0-draft',
            'status' => 'draft',
            'effective_date' => null,
            'updated_at' => '2026-10-23',
            'approved_by' => null,
            'approved_at' => null,
            'description' => 'Plans, 28-day billing, renewal, cancellation, refunds, disputes and taxes.',
            'summary' => [
                'Core $97, Growth $297 and Agency $497 per 28-day period; a 28-day period is not a calendar month.',
                'Fees are non-refundable unless the law requires it, a binding agreement says so, or an authorized representative approves an exception in writing.',
                'Cancellation stops the next renewal; the paid period is not prorated.',
                'Legitimate chargebacks and statutory remedies are not restricted.',
            ],
        ],
        'acceptable-use' => [
            'title' => 'Acceptable Use & Communications Policy',
            'short' => 'Acceptable Use',
            'category' => 'Policy',
            'primary' => true,
            'version' => '1.0-draft',
            'status' => 'draft',
            'effective_date' => null,
            'updated_at' => '2026-10-23',
            'approved_by' => null,
            'approved_at' => null,
            'description' => 'Rules for SMS, calling, email, content, payments and automation, including consent and opt-out duties.',
            'summary' => [
                'You must have consent or another lawful basis before you message or call anyone.',
                'Honor STOP and other opt-outs and keep suppression lists current.',
                'Browser calling is not an emergency-calling service.',
                'Providing a feature does not make a particular use lawful.',
            ],
        ],
        'data-processing-addendum' => [
            'title' => 'Data Processing Addendum',
            'short' => 'Data Processing Addendum',
            'category' => 'Privacy',
            'primary' => true,
            'version' => '1.0-draft',
            'status' => 'draft',
            'effective_date' => null,
            'updated_at' => '2026-10-23',
            'approved_by' => null,
            'approved_at' => null,
            'description' => 'Controller-to-processor terms where MotionGrove processes Customer Personal Data on a customer’s behalf.',
            'release_note' => 'Not operative until the processing details and the security, subprocessor and transfer annexes are completed and approved.',
            'summary' => [
                'Applies where MotionGrove processes customer-controlled personal data as processor.',
                'Processing only on documented instructions; confidentiality and security duties.',
                'Annexes B–D (security measures, subprocessors, transfers) are completed only from verified facts.',
                'Does not limit rights that law gives to individuals.',
            ],
        ],
        'cookie-policy' => [
            'title' => 'Cookie Policy',
            'short' => 'Cookie Policy',
            'category' => 'Privacy',
            'primary' => true,
            'version' => '1.0-draft',
            'status' => 'draft',
            'effective_date' => null,
            'updated_at' => '2026-10-23',
            'approved_by' => null,
            'approved_at' => null,
            'description' => 'Cookies and similar technologies used by the MotionGrove website and application.',
            'release_note' => 'The cookie inventory and consent behavior must be verified against production before publication.',
            'summary' => [
                'Explains essential, preference, analytics and advertising technologies.',
                'Non-essential technologies require prior consent where the law requires it.',
                'Agency and customer-hosted sites carry their own cookie disclosures.',
            ],
        ],
        'payments-and-esignatures' => [
            'title' => 'Payments & Electronic Signatures Addendum',
            'short' => 'Payments & E-Signatures',
            'category' => 'Addendum',
            'primary' => false,
            'version' => '1.0-draft',
            'status' => 'draft',
            'effective_date' => null,
            'updated_at' => '2026-10-23',
            'approved_by' => null,
            'approved_at' => null,
            'description' => 'Incorporated terms for connected payments, documents and electronic signatures.',
            'release_note' => 'Stripe Connect loss-liability settings and e-signature evidence must be reviewed before release.',
            'summary' => [
                'MotionGrove supplies software; it is not your attorney, notary, bank or the seller of your goods.',
                'You verify signer identity and authority; signatures are not represented as qualified electronic signatures.',
                'Provider rights (including Stripe’s) are not displaced by this Addendum.',
                'Reimbursement applies only to customer-attributable losses and only where the law and provider rules permit.',
            ],
        ],
        'agency-terms' => [
            'title' => 'Agency & White-Label Addendum',
            'short' => 'Agency & White-Label',
            'category' => 'Addendum',
            'primary' => false,
            'version' => '1.0-draft',
            'status' => 'draft',
            'effective_date' => null,
            'updated_at' => '2026-10-23',
            'approved_by' => null,
            'approved_at' => null,
            'description' => 'Incorporated terms for Agencies that resell, white-label or manage client accounts.',
            'summary' => [
                'An Agency is an independent business and cannot bind MotionGrove.',
                'The Agency is responsible for its clients, its commercial promises and its own merchant obligations.',
                'Required MotionGrove and payment-provider disclosures stay visible on white-label pages.',
                'Client accounts stay separate; transition rights follow actual product capability and the law.',
            ],
        ],
        'security' => [
            'title' => 'Security & Service Disclosure',
            'short' => 'Security',
            'category' => 'Disclosure',
            'primary' => false,
            'version' => '1.0-draft',
            'status' => 'draft',
            'effective_date' => null,
            'updated_at' => '2026-10-23',
            'approved_by' => null,
            'approved_at' => null,
            'description' => 'Security responsibilities, service limits and how to report a vulnerability.',
            'release_note' => 'Every operational assertion must be verified against production before publication.',
            'summary' => [
                'Security responsibilities are shared between MotionGrove and the customer.',
                'No fixed uptime, recovery time or certification is promised unless separately stated.',
                'How to report a vulnerability responsibly.',
            ],
        ],
    ],

    /*
    | What must be presented and affirmatively accepted, per context. If ANY
    | listed document is not published, the checkbox is not shown and nothing is
    | recorded: a customer is never shown as having agreed to terms that were
    | not properly presented. Documents in 'linked' are shown as links only.
    */
    'acceptance' => [
        'signup_checkout' => [
            'accept' => ['terms-of-service', 'refund-policy', 'acceptable-use'],
            'linked' => ['privacy-policy', 'payments-and-esignatures', 'data-processing-addendum'],
        ],
        'agency_signup_checkout' => [
            'accept' => ['terms-of-service', 'refund-policy', 'acceptable-use', 'agency-terms'],
            'linked' => ['privacy-policy', 'payments-and-esignatures', 'data-processing-addendum'],
        ],
        'private_offer' => [
            'accept' => ['terms-of-service', 'refund-policy', 'acceptable-use'],
            'linked' => ['privacy-policy', 'payments-and-esignatures', 'data-processing-addendum'],
        ],
    ],
];
