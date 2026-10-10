<?php

/*
|--------------------------------------------------------------------------
| External Website Audit Mode V1 — crawler limits
|--------------------------------------------------------------------------
|
| A Business whose primary website lives elsewhere gets the SAME technical
| audit as a hosted site, fed by a safe, bounded crawl of that public site.
| This is server-side fetching of a customer-supplied address, so every limit
| below exists to make it (a) unable to reach anything private, (b) unable to
| become a provider-cost or infrastructure-abuse vector, and (c) polite to the
| customer's own server. Each value is a documented, conservative V1 choice, not
| a copied constant; change them here, never in code.
*/

return [

    // 'http' = the real crawler. 'fake' = a built-in fixture site, for tests and browser
    // acceptance only; it is refused in production (the Google/Meta Ads fake-driver rule).
    'driver' => env('EXTERNAL_SITE_AUDIT_DRIVER', 'http'),

    // Identifies the crawler to the owner's server. Also the robots.txt group name we obey.
    'user_agent' => 'MotionGroveSiteAudit/1.0',
    'robots_token' => 'MotionGroveSiteAudit',

    // At most this many PAGES per crawl. A small business site rarely has more; the cap keeps a
    // crawl cheap and bounded whatever a site (or a malicious link graph) offers.
    'max_pages' => 40,

    // At most this many bytes of ONE response body are read. An HTML page larger than this is not
    // analysed (it is recorded as too large), and nothing beyond the cap is ever buffered.
    'max_response_bytes' => 1_500_000,

    // Connect and total per-request timeouts, in seconds.
    'connect_timeout' => 5,
    'request_timeout' => 10,

    // Redirects followed per fetch, each target re-validated from scratch.
    'max_redirects' => 5,

    // A whole crawl stops after this many seconds (pages fetched so far are kept and reported).
    'max_total_seconds' => 120,

    // Pause between two page fetches, in milliseconds: one request at a time, never in parallel.
    'request_delay_ms' => 250,

    // Sitemap discovery: how many sitemap files, bytes and URLs are read.
    'max_sitemap_fetches' => 3,
    'max_sitemap_bytes' => 2_000_000,
    'max_sitemap_urls' => 200,

    // Only these destination ports are ever contacted.
    'allowed_ports' => [80, 443],

    // Re-crawl cadence for the scheduler, and how soon the owner may ask for another crawl by hand.
    'recrawl_interval_days' => 7,
    'manual_throttle_minutes' => 15,

    // Crawls kept per Business (older ones, with their pages and findings, are pruned).
    'retained_crawls' => 5,
];
