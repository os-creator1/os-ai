<?php

    return [
        /*
        |--------------------------------------------------------------------------
        | Application Permissions
        |--------------------------------------------------------------------------
        */

        // Dashboard Module
        'access_backend'            => [
            'display_name' => 'dashboard',
            'category'     => 'Dashboard',
            'default'      => true,
        ],

        // reports Module
        'view_reports'              => [
            'display_name' => 'view_reports',
            'category'     => 'Reports',
            'default'      => true,
        ],

        //automation
        'automations'               => [
            'display_name' => 'automations',
            'category'     => 'Automations',
            'default'      => true,
        ],
        //website generation (contract §28)
        'website'                   => [
            'display_name' => 'website',
            'category'     => 'Website',
            'default'      => true,
        ],
        //google business profile (contract §16.1)
        // view defaults TRUE, following the existing safe view-capability
        // precedent (view_reports / view_contact / view_numbers /
        // view_sender_id / view_blacklist are all true). manage defaults
        // FALSE: there is no existing administrator/owner precedent in
        // this repository that would require granting a management
        // capability by default, so the conservative default stands and a
        // Workspace owner grants it explicitly.
        'view_google_business_profile'   => [
            'display_name' => 'read_google_business_profile',
            'category'     => 'Google Business Profile',
            'default'      => true,
        ],
        'manage_google_business_profile' => [
            'display_name' => 'manage_google_business_profile',
            'category'     => 'Google Business Profile',
            'default'      => false,
        ],
        // SEO (Contract 18 §10.2). Three independent capabilities, none of
        // which reuses view_keywords (the legacy inbound-SMS keyword
        // product), view_reports, website or any Google Business Profile
        // key. A capability answers "may this actor use this FEATURE at
        // all"; tenancy and entitlement are checked separately.
        //
        // view_seo / manage_seo default TRUE, like `website` and
        // `automations`: reading the SEO overview and editing the
        // customer's own keywords/citations/review records are ordinary
        // day-to-day work. manage_search_console defaults FALSE, like
        // manage_google_business_profile: it will govern connecting a Google
        // account, which is credential-class. It is declared now so the
        // identity exists; nothing consumes it until the Search Console
        // sub-slice.
        'view_seo'              => [
            'display_name' => 'view_seo',
            'category'     => 'SEO',
            'default'      => true,
        ],
        'manage_seo'            => [
            'display_name' => 'manage_seo',
            'category'     => 'SEO',
            'default'      => true,
        ],
        'manage_search_console' => [
            'display_name' => 'manage_search_console',
            'category'     => 'SEO',
            'default'      => false,
        ],
        /*
         * Implementation Contract 17 §6.1 — Payments & Contracts (Proposal /
         * Contract / e-signature / Invoice). ONE capability for the whole
         * module, the simple single-key shape `website`/`automations` use —
         * deliberately not a CRUD matrix of per-operation keys (Contract 16
         * §15's precedent).
         *
         * Default true: it follows the `website` precedent, and the backfill
         * migration grants it to existing customers so the persisted
         * per-customer permission list does not refuse a surface their plan
         * entitles them to. It is necessary but never sufficient — the
         * canonical gate chain (§6.1) is tenancy, THIS capability, the
         * EntitlementManager check for the exact PlatformFeature, then
         * LocationAccessGuard for the document's Location. While the
         * PlatformFeature is Planned (until Sub-slice G) this key opens
         * nothing: every authenticated route fails closed at the entitlement
         * gate.
         */
        /*
         * Implementation Contract 19 §5.4(2)/§12.19.D — the AI COO /
         * Business Advisor approve-then-execute surface.
         *
         * ONE key for the whole module, the single-key shape `website` /
         * `automations` / `payments_contracts` use. Named for the engine's
         * own canonical identity (OpportunityWorkerKey::BusinessAdvisor =
         * 'business_advisor'), not invented here.
         *
         * WHY A NEW KEY AT ALL. §5.4(2) puts "capability (the actor's
         * feature permission for the action's domain)" third in the guard
         * chain, and a grep of this file finds no opportunity / advisor /
         * COO / approve / execute key: the surface is gated today only by
         * the blanket `access_backend`, which every customer holds and which
         * therefore gates nothing. `view_reports` was considered and
         * rejected — it is a READ capability, and gating a Business-mutating
         * approval on permission to view reports is a category error.
         *
         * Default true: it follows the `payments_contracts` / `manage_seo`
         * precedent. Approving a recommendation about your own Business
         * profile is ordinary day-to-day work, and the accompanying backfill
         * migration grants it to existing customers so a surface they can
         * use today does not silently disappear.
         *
         * Necessary but never sufficient. It is one link in the §5.4(2)
         * chain — kill switch, tenancy, THIS capability, Location,
         * entitlement (PlatformFeature::AiCooBasic), action hash, approval
         * freshness, paid-effect guards, idempotency claim — and it is
         * re-evaluated from the DURABLE permission set at execution time,
         * never inherited from the approval.
         */
        'business_advisor'               => [
            'display_name' => 'business_advisor',
            'category'     => 'AI COO',
            'default'      => true,
        ],
        'payments_contracts'             => [
            'display_name' => 'payments_contracts',
            'category'     => 'Payments & Contracts',
            'default'      => true,
        ],
        /*
         * Customer Experience Slice 3 §4.7 — advanced / BYO provider settings.
         *
         * Conservative default of false, following the
         * manage_google_business_profile precedent above, and registered as a
         * Gate automatically by AuthServiceProvider's existing generic loop.
         *
         * This permission is necessary but never sufficient: the relocated
         * advanced-settings surface additionally requires authoritative
         * Workspace OWNERSHIP (WorkspaceCandidate::$isOwner), not
         * canManage(), not plan tier, and not admin membership. Holding the
         * permission alone never grants access, and ownership alone never
         * grants access without it.
         */
        'manage_advanced_provider' => [
            'display_name' => 'manage_advanced_provider',
            'category'     => 'Messaging',
            'default'      => false,
        ],
        //contacts module
        'view_contact_group'        => [
            'display_name' => 'read_contact_group',
            'category'     => 'Contacts',
            'default'      => true,
        ],
        'create_contact_group'      => [
            'display_name' => 'create_contact_group',
            'category'     => 'Contacts',
            'default'      => true,
        ],
        'update_contact_group'      => [
            'display_name' => 'update_contact_group',
            'category'     => 'Contacts',
            'default'      => true,
        ],
        'delete_contact_group'      => [
            'display_name' => 'delete_contact_group',
            'category'     => 'Contacts',
            'default'      => true,
        ],
        'view_contact'              => [
            'display_name' => 'read_contact',
            'category'     => 'Contacts',
            'default'      => true,
        ],
        'create_contact'            => [
            'display_name' => 'create_contact',
            'category'     => 'Contacts',
            'default'      => true,
        ],
        'update_contact'            => [
            'display_name' => 'update_contact',
            'category'     => 'Contacts',
            'default'      => true,
        ],
        'delete_contact'            => [
            'display_name' => 'delete_contact',
            'category'     => 'Contacts',
            'default'      => true,
        ],

        //numbers module
        'view_numbers'              => [
            'display_name' => 'read_numbers',
            'category'     => 'Phone Numbers',
            'default'      => true,
        ],
        'buy_numbers'               => [
            'display_name' => 'buy_numbers',
            'category'     => 'Phone Numbers',
            'default'      => true,
        ],

//        'buy_numbers_using_api'     => [
//                'display_name' => 'buy_numbers_using_api',
//                'category'     => 'Phone Numbers',
//                'default'      => true,
//        ],
        'release_numbers'           => [
            'display_name' => 'release_numbers',
            'category'     => 'Phone Numbers',
            'default'      => true,
        ],

        //keywords module
        'view_keywords'             => [
            'display_name' => 'read_keywords',
            'category'     => 'Keywords',
            'default'      => false,
        ],
        'create_keywords'           => [
            'display_name' => 'create_keywords',
            'category'     => 'Keywords',
            'default'      => false,
        ],
        'buy_keywords'              => [
            'display_name' => 'buy_keywords',
            'category'     => 'Keywords',
            'default'      => false,
        ],
        'update_keywords'           => [
            'display_name' => 'update_keywords',
            'category'     => 'Keywords',
            'default'      => false,
        ],
        'release_keywords'          => [
            'display_name' => 'release_keywords',
            'category'     => 'Keywords',
            'default'      => false,
        ],

        //sender id
        'view_sender_id'            => [
            'display_name' => 'read_sender_id',
            'category'     => 'Sender ID',
            'default'      => true,
        ],
        'create_sender_id'          => [
            'display_name' => 'request_sender_id',
            'category'     => 'Sender ID',
            'default'      => true,
        ],
        'delete_sender_id'          => [
            'display_name' => 'delete_sender_id',
            'category'     => 'Sender ID',
            'default'      => false,
        ],

        //blacklist
        'view_blacklist'            => [
            'display_name' => 'read_blacklist',
            'category'     => 'Blacklist',
            'default'      => true,
        ],
        'create_blacklist'          => [
            'display_name' => 'create_blacklist',
            'category'     => 'Blacklist',
            'default'      => true,
        ],
        'delete_blacklist'          => [
            'display_name' => 'delete_blacklist',
            'category'     => 'Blacklist',
            'default'      => true,
        ],

        //sms module
        'sms_campaign_builder'      => [
            'display_name' => 'campaign_builder',
            'category'     => 'SMS',
            'default'      => true,
        ],
        'sms_quick_send'            => [
            'display_name' => 'quick_send',
            'category'     => 'SMS',
            'default'      => true,
        ],
        'sms_bulk_messages'         => [
            'display_name' => 'bulk_messages',
            'category'     => 'SMS',
            'default'      => true,
        ],

        //voice module
        'voice_campaign_builder'    => [
            'display_name' => 'campaign_builder',
            'category'     => 'Voice',
            'default'      => false,
        ],
        'voice_quick_send'          => [
            'display_name' => 'quick_send',
            'category'     => 'Voice',
            'default'      => false,
        ],
        'voice_bulk_messages'       => [
            'display_name' => 'bulk_messages',
            'category'     => 'Voice',
            'default'      => false,
        ],

        //mms module
        'mms_campaign_builder'      => [
            'display_name' => 'campaign_builder',
            'category'     => 'MMS',
            'default'      => false,
        ],
        'mms_quick_send'            => [
            'display_name' => 'quick_send',
            'category'     => 'MMS',
            'default'      => false,
        ],
        'mms_bulk_messages'         => [
            'display_name' => 'bulk_messages',
            'category'     => 'MMS',
            'default'      => false,
        ],

        //whatsapp module
        'whatsapp_campaign_builder' => [
            'display_name' => 'campaign_builder',
            'category'     => 'WhatsApp',
            'default'      => false,
        ],
        'whatsapp_quick_send'       => [
            'display_name' => 'quick_send',
            'category'     => 'WhatsApp',
            'default'      => false,
        ],
        'whatsapp_bulk_messages'    => [
            'display_name' => 'bulk_messages',
            'category'     => 'WhatsApp',
            'default'      => false,
        ],


        //viber module
        'viber_campaign_builder'    => [
            'display_name' => 'campaign_builder',
            'category'     => 'Viber',
            'default'      => false,
        ],
        'viber_quick_send'          => [
            'display_name' => 'quick_send',
            'category'     => 'Viber',
            'default'      => false,
        ],
        'viber_bulk_messages'       => [
            'display_name' => 'bulk_messages',
            'category'     => 'Viber',
            'default'      => false,
        ],

        //OTP module
        'otp_campaign_builder'      => [
            'display_name' => 'campaign_builder',
            'category'     => 'OTP',
            'default'      => false,
        ],
        'otp_quick_send'            => [
            'display_name' => 'quick_send',
            'category'     => 'OTP',
            'default'      => false,
        ],
        'otp_bulk_messages'         => [
            'display_name' => 'bulk_messages',
            'category'     => 'OTP',
            'default'      => false,
        ],


        //sms template
        'sms_template'              => [
            'display_name' => 'sms_template',
            'category'     => 'SMS Template',
            'default'      => true,
        ],

        //chat box
        'chat_box'                  => [
            'display_name' => 'chat_box',
            'category'     => 'Chat Box',
            'default'      => true,
        ],

        //knowledge bases
        'developers'                => [
            'display_name' => 'developers',
            'category'     => 'Developers',
            'default'      => true,
        ],


        // Support module
        'read_ticket'               => [
            'display_name' => 'read',
            'category'     => 'Support Tickets',
            'default'      => false,
        ],
        'create_ticket'             => [
            'display_name' => 'create',
            'category'     => 'Support Tickets',
            'default'      => false,
        ],
        'manage_ticket'             => [
            'display_name' => 'update',
            'category'     => 'Support Tickets',
            'default'      => false,
        ],
        'delete_ticket'             => [
            'display_name' => 'delete',
            'category'     => 'Support Tickets',
            'default'      => false,
        ],
        'create_ticket_replies'     => [
            'display_name' => 'create',
            'category'     => 'Ticket Replies',
            'default'      => false,
        ],
        'update_ticket_replies'     => [
            'display_name' => 'update',
            'category'     => 'Ticket Replies',
            'default'      => false,
        ],
        'delete_ticket_replies'     => [
            'display_name' => 'delete',
            'category'     => 'Ticket Replies',
            'default'      => false,
        ],
        'view_articles'             => [
            'display_name' => 'read',
            'category'     => 'Articles',
            'default'      => false,
        ],

        'view_faqs' => [
            'display_name' => 'read',
            'category'     => 'FAQs',
            'default'      => false,
        ],
    ];
