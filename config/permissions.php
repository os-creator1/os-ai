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

        'view purchase_code' => [
            'display_name' => 'purchase_code',
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

        'manage update_application' => [
            'display_name' => 'update_application',
            'category'     => 'Settings',
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

        //Plugins Module
        'view plugins'  => [
            'display_name' => 'read',
            'category'     => 'Plugins',
        ],

        'install plugins' => [
            'display_name' => 'install',
            'category'     => 'Plugins',
        ],

        'update plugins' => [
            'display_name' => 'update',
            'category'     => 'Plugins',
        ],

        'delete plugins' => [
            'display_name' => 'delete',
            'category'     => 'Plugins',
        ],


        // Support Module
        'view tickets'   => [
            'display_name' => 'read',
            'category'     => 'Support Tickets',
        ],

        'create tickets' => [
            'display_name' => 'create',
            'category'     => 'Support Tickets',
        ],

        'edit tickets' => [
            'display_name' => 'update',
            'category'     => 'Support Tickets',
        ],

        'delete tickets' => [
            'display_name' => 'delete',
            'category'     => 'Support Tickets',
        ],

        'assign tickets' => [
            'display_name' => 'assign_agent',
            'category'     => 'Support Tickets',
        ],

        'reply tickets' => [
            'display_name' => 'create_reply',
            'category'     => 'Support Tickets',
        ],

        'edit ticket_replies' => [
            'display_name' => 'edit_reply',
            'category'     => 'Support Tickets',
        ],

        'delete ticket_replies' => [
            'display_name' => 'delete_reply',
            'category'     => 'Support Tickets',
        ],

        'delete ticket_attachments' => [
            'display_name' => 'delete_attachment',
            'category'     => 'Support Tickets',
        ],


        'view ticket_tags' => [
            'display_name' => 'read',
            'category'     => 'Support Ticket Tags',
        ],

        'create ticket_tags' => [
            'display_name' => 'create',
            'category'     => 'Support Ticket Tags',
        ],

        'edit ticket_tags' => [
            'display_name' => 'update',
            'category'     => 'Support Ticket Tags',
        ],

        'delete ticket_tags' => [
            'display_name' => 'delete',
            'category'     => 'Support Ticket Tags',
        ],


        'manage support_settings' => [
            'display_name' => 'update',
            'category'     => 'Support Settings',
        ],

        'view support_agents' => [
            'display_name' => 'read',
            'category'     => 'Support Agents',
        ],

        'create support_agents' => [
            'display_name' => 'create',
            'category'     => 'Support Agents',
        ],

        'edit support_agents' => [
            'display_name' => 'update',
            'category'     => 'Support Agents',
        ],

        'delete support_agents' => [
            'display_name' => 'delete',
            'category'     => 'Support Agents',
        ],

        'view support_categories' => [
            'display_name' => 'read',
            'category'     => 'Support Categories',
        ],

        'create support_categories' => [
            'display_name' => 'create',
            'category'     => 'Support Categories',
        ],

        'edit support_categories' => [
            'display_name' => 'update',
            'category'     => 'Support Categories',
        ],

        'delete support_categories' => [
            'display_name' => 'delete',
            'category'     => 'Support Categories',
        ],

        'view support_articles' => [
            'display_name' => 'read',
            'category'     => 'Support Articles',
        ],

        'create support_articles' => [
            'display_name' => 'create',
            'category'     => 'Support Articles',
        ],

        'edit support_articles' => [
            'display_name' => 'update',
            'category'     => 'Support Articles',
        ],

        'delete support_articles' => [
            'display_name' => 'delete',
            'category'     => 'Support Articles',
        ],

        'view support_analytics' => [
            'display_name' => 'read',
            'category'     => 'Support Analytics',
        ],


        'view faq_categories' => [
            'display_name' => 'read',
            'category'     => 'FAQ Categories',
        ],

        'create faq_category' => [
            'display_name' => 'create',
            'category'     => 'FAQ Categories',
        ],

        'update faq_category' => [
            'display_name' => 'update',
            'category'     => 'FAQ Categories',
        ],

        'delete faq_category' => [
            'display_name' => 'delete',
            'category'     => 'FAQ Categories',
        ],


        'view faqs' => [
            'display_name' => 'read',
            'category'     => 'FAQs',
        ],

        'create faq' => [
            'display_name' => 'create',
            'category'     => 'FAQs',
        ],

        'update faq' => [
            'display_name' => 'update',
            'category'     => 'FAQs',
        ],

        'delete faq'    => [
            'display_name' => 'delete',
            'category'     => 'FAQs',
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

        'view blogs' => [
            'display_name' => 'read',
            'category'     => 'Blogs',
        ],

        'create blog' => [
            'display_name' => 'create',
            'category'     => 'Blogs',
        ],

        'update blog' => [
            'display_name' => 'update',
            'category'     => 'Blogs',
        ],

        'delete blog' => [
            'display_name' => 'delete',
            'category'     => 'Blogs',
        ],

        'view blog_categories' => [
            'display_name' => 'read',
            'category'     => 'Blog Categories',
        ],

        'create blog_category' => [
            'display_name' => 'create',
            'category'     => 'Blog Categories',
        ],

        'update blog_category' => [
            'display_name' => 'update',
            'category'     => 'Blog Categories',
        ],

        'delete blog_category' => [
            'display_name' => 'delete',
            'category'     => 'Blog Categories',
        ],

        'view blog_tags' => [
            'display_name' => 'read',
            'category'     => 'Blog Tags',
        ],

        'create blog_tag' => [
            'display_name' => 'create',
            'category'     => 'Blog Tags',
        ],

        'update blog_tag' => [
            'display_name' => 'update',
            'category'     => 'Blog Tags',
        ],

        'delete blog_tag' => [
            'display_name' => 'delete',
            'category'     => 'Blog Tags',
        ],

        'manage blog_settings' => [
            'display_name' => 'update',
            'category'     => 'Blog Settings',
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

        'view brands' => [
            'display_name' => 'read',
            'category'     => 'Brands',
        ],

        'create brand' => [
            'display_name' => 'create',
            'category'     => 'Brands',
        ],

        'update brand' => [
            'display_name' => 'update',
            'category'     => 'Brands',
        ],

        'delete brand' => [
            'display_name' => 'delete',
            'category'     => 'Brands',
        ],

        'view testimonials' => [
            'display_name' => 'read',
            'category'     => 'Testimonials',
        ],

        'create testimonial' => [
            'display_name' => 'create',
            'category'     => 'Testimonials',
        ],

        'update testimonial' => [
            'display_name' => 'update',
            'category'     => 'Testimonials',
        ],

        'delete testimonial' => [
            'display_name' => 'delete',
            'category'     => 'Testimonials',
        ],

        'manage widget_builder' => [
            'display_name' => 'update',
            'category'     => 'Widget Builder',
        ],

        'view menu' => [
            'display_name' => 'read',
            'category'     => 'Menu Manage',
        ],

        'create menu' => [
            'display_name' => 'create',
            'category'     => 'Menu Manage',
        ],

        'update menu' => [
            'display_name' => 'update',
            'category'     => 'Menu Manage',
        ],

        'delete menu' => [
            'display_name' => 'delete',
            'category'     => 'Menu Manage',
        ],

        'manage 404_settings' => [
            'display_name' => 'update',
            'category'     => '404 Settings',
        ],

        'manage topbar_settings' => [
            'display_name' => 'update',
            'category'     => 'Topbar Settings',
        ],

    ];
