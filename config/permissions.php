<?php

    return [
        /*
        |--------------------------------------------------------------------------
        | Application Permissions
        |--------------------------------------------------------------------------
        */

        // Dashboard Module
        'access backend' => [
            'display_name' => 'dashboard',
            'category'     => 'Dashboard',
        ],

        // Customer Module
        'view customer'  => [
            'display_name' => 'read',
            'category'     => 'Customer',
        ],

        'create customer' => [
            'display_name' => 'create',
            'category'     => 'Customer',
        ],

        'edit customer' => [
            'display_name' => 'update',
            'category'     => 'Customer',
        ],

        'delete customer'   => [
            'display_name' => 'delete',
            'category'     => 'Customer',
        ],

        /*Version 3.9*/
        'view announcement' => [
            'display_name' => 'read',
            'category'     => 'Announcements',
        ],

        'create announcement' => [
            'display_name' => 'create',
            'category'     => 'Announcements',
        ],

        'edit announcement' => [
            'display_name' => 'update',
            'category'     => 'Announcements',
        ],

        'delete announcement' => [
            'display_name' => 'delete',
            'category'     => 'Announcements',
        ],

        /*End Version 3.9*/

        'view subscription' => [
            'display_name' => 'read',
            'category'     => 'Subscriptions',
        ],

        'new subscription' => [
            'display_name' => 'create',
            'category'     => 'Subscriptions',
        ],

        'manage subscription' => [
            'display_name' => 'update',
            'category'     => 'Subscriptions',
        ],

        'delete subscription' => [
            'display_name' => 'delete',
            'category'     => 'Subscriptions',
        ],

        // Plan Module

        'manage plans' => [
            'display_name' => 'update',
            'category'     => 'Plan',
        ],
        'create plans' => [
            'display_name' => 'create',
            'category'     => 'Plan',
        ],
        'edit plans'   => [
            'display_name' => 'update',
            'category'     => 'Plan',
        ],
        'delete plans' => [
            'display_name' => 'delete',
            'category'     => 'Plan',
        ],

        'manage currencies' => [
            'display_name' => 'read',
            'category'     => 'Currencies',
        ],
        'create currencies' => [
            'display_name' => 'create',
            'category'     => 'Currencies',
        ],
        'edit currencies'   => [
            'display_name' => 'update',
            'category'     => 'Currencies',
        ],
        'delete currencies' => [
            'display_name' => 'delete',
            'category'     => 'Currencies',
        ],

        'manage tax' => [
            'display_name' => 'read',
            'category'     => 'Tax Settings',
        ],


        // Sending Tools Module

        'view sending_servers' => [
            'display_name' => 'read',
            'category'     => 'Sending Servers',
        ],

        'create sending_servers' => [
            'display_name' => 'create',
            'category'     => 'Sending Servers',
        ],

        'edit sending_servers' => [
            'display_name' => 'update',
            'category'     => 'Sending Servers',
        ],

        'delete sending_servers' => [
            'display_name' => 'delete',
            'category'     => 'Sending Servers',
        ],

        'view keywords' => [
            'display_name' => 'read',
            'category'     => 'Keywords',
        ],

        'create keywords' => [
            'display_name' => 'create',
            'category'     => 'Keywords',
        ],

        'edit keywords' => [
            'display_name' => 'update',
            'category'     => 'Keywords',
        ],

        'delete keywords' => [
            'display_name' => 'delete',
            'category'     => 'Keywords',
        ],

        /*Version 3.5 Templates*/
        'view templates'  => [
            'display_name' => 'read',
            'category'     => 'Templates',
        ],

        'create templates' => [
            'display_name' => 'create',
            'category'     => 'Templates',
        ],

        'edit templates' => [
            'display_name' => 'update',
            'category'     => 'Templates',
        ],

        'delete templates' => [
            'display_name' => 'delete',
            'category'     => 'Templates',
        ],
        /*Version 3.5 End Templates*/

        'view tags' => [
            'display_name' => 'read',
            'category'     => 'Template Tags',
        ],

        'create tags' => [
            'display_name' => 'create',
            'category'     => 'Template Tags',
        ],

        'edit tags' => [
            'display_name' => 'update',
            'category'     => 'Template Tags',
        ],

        'delete tags' => [
            'display_name' => 'delete',
            'category'     => 'Template Tags',
        ],

        // Security Module

        'view sender_id' => [
            'display_name' => 'read',
            'category'     => 'Sender ID',
        ],

        'create sender_id' => [
            'display_name' => 'create',
            'category'     => 'Sender ID',
        ],

        'edit sender_id' => [
            'display_name' => 'update',
            'category'     => 'Sender ID',
        ],

        'delete sender_id' => [
            'display_name' => 'delete',
            'category'     => 'Sender ID',
        ],

        'view phone_numbers' => [
            'display_name' => 'read',
            'category'     => 'Phone Numbers',
        ],

        'create phone_numbers' => [
            'display_name' => 'create',
            'category'     => 'Phone Numbers',
        ],

        'edit phone_numbers' => [
            'display_name' => 'update',
            'category'     => 'Phone Numbers',
        ],

        'delete phone_numbers' => [
            'display_name' => 'delete',
            'category'     => 'Phone Numbers',
        ],

        'view blacklist' => [
            'display_name' => 'read',
            'category'     => 'Blacklist',
        ],

        'create blacklist' => [
            'display_name' => 'create',
            'category'     => 'Blacklist',
        ],

        'edit blacklist' => [
            'display_name' => 'update',
            'category'     => 'Blacklist',
        ],

        'delete blacklist' => [
            'display_name' => 'delete',
            'category'     => 'Blacklist',
        ],

        'view spam_word' => [
            'display_name' => 'read',
            'category'     => 'Spam Word',
        ],

        'create spam_word' => [
            'display_name' => 'create',
            'category'     => 'Spam Word',
        ],

        'edit spam_word' => [
            'display_name' => 'update',
            'category'     => 'Spam Word',
        ],

        'delete spam_word' => [
            'display_name' => 'delete',
            'category'     => 'Spam Word',
        ],

        'view block_senderid' => [
            'display_name' => 'read',
            'category'     => 'Block Sender ID',
        ],

        'create block_senderid' => [
            'display_name' => 'create',
            'category'     => 'Block Sender ID',
        ],

        'edit block_senderid' => [
            'display_name' => 'update',
            'category'     => 'Block Sender ID',
        ],

        'delete block_senderid' => [
            'display_name' => 'delete',
            'category'     => 'Block Sender ID',
        ],

        // Administrator Module

        'view administrator' => [
            'display_name' => 'read',
            'category'     => 'Administrator',
        ],

        'create administrator' => [
            'display_name' => 'create',
            'category'     => 'Administrator',
        ],

        'edit administrator' => [
            'display_name' => 'update',
            'category'     => 'Administrator',
        ],

        'delete administrator' => [
            'display_name' => 'delete',
            'category'     => 'Administrator',
        ],

        'view roles' => [
            'display_name' => 'read',
            'category'     => 'Admin Roles',
        ],

        'create roles' => [
            'display_name' => 'create',
            'category'     => 'Admin Roles',
        ],

        'edit roles' => [
            'display_name' => 'update',
            'category'     => 'Admin Roles',
        ],

        'delete roles' => [
            'display_name' => 'delete',
            'category'     => 'Admin Roles',
        ],

        //language module

        'view languages' => [
            'display_name' => 'read',
            'category'     => 'Language',
        ],

        'new languages' => [
            'display_name' => 'create',
            'category'     => 'Language',
        ],

        'manage languages' => [
            'display_name' => 'update',
            'category'     => 'Language',
        ],

        'delete languages' => [
            'display_name' => 'delete',
            'category'     => 'Language',
        ],

        // Settings Module

        'general settings' => [
            'display_name' => 'general',
            'category'     => 'Settings',
        ],

        'system_email settings' => [
            'display_name' => 'system_email',
            'category'     => 'Settings',
        ],

        'authentication settings' => [
            'display_name' => 'authentication',
            'category'     => 'Settings',
        ],

        'notifications settings' => [
            'display_name' => 'notifications',
            'category'     => 'Settings',
        ],

        'localization settings' => [
            'display_name' => 'localization',
            'category'     => 'Settings',
        ],

        'pusher settings' => [
            'display_name' => 'pusher',
            'category'     => 'Settings',
        ],

        'view background_jobs' => [
            'display_name' => 'background_jobs',
            'category'     => 'Settings',
        ],
//
//        'manage gateway_wise_billing' => [
//            'display_name' => 'gateway_wise_billing',
//            'category'     => 'Settings',
//        ],

        'manage ai_settings'      => [
            'display_name' => 'ai_settings',
            'category'     => 'Settings',
        ],
        'manage maintenance_mode' => [
            'display_name' => 'maintenance_mode',
            'category'     => 'Settings',
        ],

        'view payment_gateways' => [
            'display_name' => 'read',
            'category'     => 'Payment Gateways',
        ],

        'update payment_gateways' => [
            'display_name' => 'update',
            'category'     => 'Payment Gateways',
        ],

        'view email_templates' => [
            'display_name' => 'read',
            'category'     => 'Email Templates',
        ],

        'update email_templates' => [
            'display_name' => 'update',
            'category'     => 'Email Templates',
        ],

        // Reports Module
        'view sms_history'          => [
            'display_name' => 'read_sms_history',
            'category'     => 'Reports',
        ],

        'view invoices' => [
            'display_name' => 'invoices',
            'category'     => 'Reports',
        ],

        //Landing Page Module
        'view themes'   => [
            'display_name' => 'read',
            'category'     => 'Themes',
        ],
        'manage themes' => [
            'display_name' => 'update',
            'category'     => 'Themes',
        ],

        'view pages' => [
            'display_name' => 'read',
            'category'     => 'Pages',
        ],

        'create page' => [
            'display_name' => 'create',
            'category'     => 'Pages',
        ],

        'update page' => [
            'display_name' => 'update',
            'category'     => 'Pages',
        ],

        'delete page' => [
            'display_name' => 'delete',
            'category'     => 'Pages',
        ],

        'view price_plan' => [
            'display_name' => 'read',
            'category'     => 'Price Plan',
        ],

        'create price_plan' => [
            'display_name' => 'create',
            'category'     => 'Price Plan',
        ],

        'update price_plan' => [
            'display_name' => 'update',
            'category'     => 'Price Plan',
        ],

        'delete price_plan' => [
            'display_name' => 'delete',
            'category'     => 'Price Plan',
        ],

        'manage 404_settings' => [
            'display_name' => 'update',
            'category'     => '404 Settings',
        ],

        'manage topbar_settings' => [
            'display_name' => 'update',
            'category'     => 'Topbar Settings',
        ],

        // Business Module (RFC-001)
        'view business' => [
            'display_name' => 'read',
            'category'     => 'Business',
        ],

        'edit business' => [
            'display_name' => 'update',
            'category'     => 'Business',
        ],

        // Workspace Module (RFC-003 Milestone 5 — read-only admin inspection)
        'view workspace' => [
            'display_name' => 'read',
            'category'     => 'Workspace',
        ],

        // Workspace Plans Module (RFC-004 Milestone 3)
        'view workspace plans' => [
            'display_name' => 'read',
            'category'     => 'Workspace Plans',
        ],

        'manage workspace plans' => [
            'display_name' => 'update',
            'category'     => 'Workspace Plans',
        ],

        // Opportunity Module (RFC-002)
        'view opportunities' => [
            'display_name' => 'read',
            'category'     => 'Opportunities',
        ],

        'edit opportunities' => [
            'display_name' => 'update',
            'category'     => 'Opportunities',
        ],

        // Appearance Module (Design System Milestone 2)
        'manage theme' => [
            'display_name' => 'update',
            'category'     => 'Appearance',
        ],

    ];
