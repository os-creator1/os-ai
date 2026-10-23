<?php

/*
 * GoHighLevel -> MotionGrove migration support (read-only source access).
 *
 * The token is a scoped Private Integration Token supplied ONLY through the
 * environment (never committed, never pasted into chat). Nothing here ever
 * writes to GoHighLevel: GhlApiClient exposes GET only.
 */
return [
    'base_url' => env('GHL_API_BASE_URL', 'https://services.leadconnectorhq.com'),
    'api_version' => '2021-07-28',
    'token' => env('GHL_PRIVATE_INTEGRATION_TOKEN'),
    'location_id' => env('GHL_LOCATION_ID'),
    // Cloudflare rejects anonymous library signatures; identify ourselves honestly.
    'user_agent' => 'MotionGrove-GHL-Migration/1.0 (read-only)',
    'max_attempts' => 5,
    'page_size' => 100,
];
