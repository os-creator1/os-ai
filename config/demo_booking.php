<?php

/*
|--------------------------------------------------------------------------
| MotionGrove "Book a Demo" (/book-demo)
|--------------------------------------------------------------------------
|
| /book-demo is an adapter onto the canonical public booking engine for ONE
| designated Booking Type that belongs to the platform's internal Business.
| Nothing here creates a second calendar. See docs/product/BOOK-DEMO.md.
|
| Fail closed: with no designated Booking Type, or without complete Turnstile
| configuration outside local/testing, every /book-demo route refuses.
*/

return [
    // public_booking_uuid of the internal Business's demo Booking Type.
    'booking_type_uuid' => env('DEMO_BOOKING_TYPE_UUID'),

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        // Optional comma-separated hostnames Cloudflare may report. Blank: the request host must match.
        'allowed_hostnames' => env('TURNSTILE_ALLOWED_HOSTNAMES'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        // Honoured ONLY when the app environment is local or testing.
        'bypass' => (bool) env('DEMO_BOOKING_TURNSTILE_BYPASS', false),
    ],

    // How long a guest's cancel/reschedule link stays valid after it is issued.
    'manage_link_days' => 30,
];
