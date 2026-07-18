<?php

return [
    'onboarding' => [
        'enabled' => env('BUSINESS_ONBOARDING_ENABLED', false),
        'require_for_new_customers' => env('BUSINESS_ONBOARDING_REQUIRE_NEW_CUSTOMERS', false),
        'analysis_queue' => env('BUSINESS_ONBOARDING_ANALYSIS_QUEUE', 'default'),
    ],
];
