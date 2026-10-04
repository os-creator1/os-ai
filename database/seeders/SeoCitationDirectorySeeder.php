<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Citations V1 — the platform-owned citation directory catalog.
 *
 * SEEDING RULE (contract OD-3): every claim_url is an official page of the
 * directory itself, verified by loading it, with no affiliate or tracking
 * parameters; nothing is invented. A directory whose claim page could NOT be
 * verified is still listed (so the owner knows it matters) but carries
 * claim_url = NULL and is tracked manually — we never ship an unverified URL.
 *
 * Google Business Profile is deliberately absent: it appears in Citations as
 * a synthetic, read-only row derived from the GBP read model, never as a
 * stored directory.
 *
 * VERIFICATION LOG (October 2026; each page loaded and read from the
 * directory's own site unless marked):
 *
 *  - apple_business — https://business.apple.com (Apple Business, the 2026
 *    successor of Business Connect; businessconnect.apple.com redirects to
 *    it). Claim path: "Claim your location on Maps". Partner API: approved
 *    partners only -> assisted.
 *  - bing_places — https://www.bing.com/forbusiness/management (verified in
 *    the original Contract 18E seeding; bingplaces.com redirects to
 *    bing.com/forbusiness). Bing Places API: trusted partners only -> assisted.
 *  - yelp — https://biz.yelp.com/claim (loads; step 1 needs no login). Yelp's
 *    listing APIs are reserved for contracted partners -> assisted.
 *  - facebook_pages — https://www.facebook.com/pages/create ("Create a Page",
 *    free). Pages API needs Meta app review and is not a listing-data API ->
 *    assisted.
 *  - foursquare — https://app.foursquare.com/venue/claim (venue claim form;
 *    foursquare.com/venue/claim redirects here). Places API is read/search
 *    only -> assisted.
 *  - nextdoor_business — https://business.nextdoor.com/local ("Claim your
 *    free Business Page"). No listing API found -> assisted.
 *  - bbb — https://www.bbb.org/get-listed ("request a BBB Business Profile";
 *    free profile, paid accreditation is separate and optional) -> assisted.
 *  - data_axle — https://leads.dataaxleusa.com/landing/updatelisting.aspx
 *    (Data Axle's own listing update page). An upstream data aggregator; no
 *    public API found -> assisted.
 *  - yellow_pages, mapquest — real, active US directories whose claim pages
 *    returned 403 to our verifier, so NO claim URL is shipped; manual.
 *
 * CONSIDERED AND NOT SEEDED: TomTom (US availability unverified), HERE
 * (community map editing, not owner listings), Alignable (networking),
 * Angi/Thumbtack (lead marketplaces), Manta / ChamberofCommerce.com / Waze
 * (claim URLs unverified; weak or upsell-heavy), Trustpilot (reviews, not a
 * citation). The verification fetches ran from outside the US, so every
 * claim URL is worth a periodic recheck.
 *
 * Niche-only sources (is_platform_core = false) are listed below too; they are
 * shown to a Business only where its niche recommends them
 * (SeoNicheCitationRecommendationSeeder).
 *
 * Idempotent and keyed on `key`: re-running updates the catalog fields but
 * never duplicates a row, never changes a row's uid, and never touches a
 * Business's custom directory or any citation.
 */
class SeoCitationDirectorySeeder extends Seeder
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public const DIRECTORIES = [
        [
            'key' => 'apple_business', 'name' => 'Apple Business (Apple Maps)',
            'website_url' => 'https://business.apple.com', 'claim_url' => 'https://business.apple.com',
            'category' => 'maps', 'icon' => 'map', 'importance' => 'essential', 'tracking_mode' => 'assisted',
            'country_scope' => null, 'is_platform_core' => true, 'sort_order' => 10,
            'setup_guidance' => 'Sign in with an Apple Account, claim your place on Apple Maps, and make sure the name, address and phone match your business profile.',
        ],
        [
            'key' => 'bing_places', 'name' => 'Bing Places for Business',
            'website_url' => 'https://www.bing.com/forbusiness/', 'claim_url' => 'https://www.bing.com/forbusiness/management',
            'category' => 'search', 'icon' => 'search', 'importance' => 'essential', 'tracking_mode' => 'assisted',
            'country_scope' => null, 'is_platform_core' => true, 'sort_order' => 20,
            'setup_guidance' => 'Claim your listing (or import it from Google if offered), then check every detail for accuracy.',
        ],
        [
            'key' => 'yelp', 'name' => 'Yelp for Business',
            'website_url' => 'https://business.yelp.com', 'claim_url' => 'https://biz.yelp.com/claim',
            'category' => 'reviews', 'icon' => 'star', 'importance' => 'essential', 'tracking_mode' => 'assisted',
            'country_scope' => null, 'is_platform_core' => true, 'sort_order' => 30,
            'setup_guidance' => 'Search for your business first so you claim the existing page instead of creating a duplicate, then complete the verification.',
        ],
        [
            'key' => 'facebook_pages', 'name' => 'Facebook Business Page',
            'website_url' => 'https://www.facebook.com/business/pages', 'claim_url' => 'https://www.facebook.com/pages/create',
            'category' => 'social', 'icon' => 'facebook', 'importance' => 'recommended', 'tracking_mode' => 'assisted',
            'country_scope' => null, 'is_platform_core' => true, 'sort_order' => 40,
            'setup_guidance' => 'Create or claim your Page using exactly the business name, address, phone and website you use everywhere else.',
        ],
        [
            'key' => 'data_axle', 'name' => 'Data Axle',
            'website_url' => 'https://www.data-axle.com', 'claim_url' => 'https://leads.dataaxleusa.com/landing/updatelisting.aspx',
            'category' => 'data_aggregator', 'icon' => 'database', 'importance' => 'recommended', 'tracking_mode' => 'assisted',
            'country_scope' => 'US', 'is_platform_core' => true, 'sort_order' => 50,
            'setup_guidance' => 'Find your record, verify or correct the details once, and they can flow on to directories that use Data Axle data.',
        ],
        [
            'key' => 'foursquare', 'name' => 'Foursquare',
            'website_url' => 'https://foursquare.com', 'claim_url' => 'https://app.foursquare.com/venue/claim',
            'category' => 'maps', 'icon' => 'map-pin', 'importance' => 'recommended', 'tracking_mode' => 'assisted',
            'country_scope' => null, 'is_platform_core' => true, 'sort_order' => 60,
            'setup_guidance' => 'Claim your venue. Foursquare data also feeds other apps, so an accurate listing reaches further than the site itself.',
        ],
        [
            'key' => 'nextdoor_business', 'name' => 'Nextdoor Business Page',
            'website_url' => 'https://business.nextdoor.com', 'claim_url' => 'https://business.nextdoor.com/local',
            'category' => 'social', 'icon' => 'users', 'importance' => 'recommended', 'tracking_mode' => 'assisted',
            'country_scope' => 'US', 'is_platform_core' => true, 'sort_order' => 70,
            'setup_guidance' => 'Claim the free Business Page and set the neighborhoods you serve.',
        ],
        [
            'key' => 'bbb', 'name' => 'Better Business Bureau',
            'website_url' => 'https://www.bbb.org', 'claim_url' => 'https://www.bbb.org/get-listed',
            'category' => 'trust', 'icon' => 'shield-check', 'importance' => 'recommended', 'tracking_mode' => 'assisted',
            'country_scope' => null, 'is_platform_core' => true, 'sort_order' => 80,
            'setup_guidance' => 'Request a free BBB Business Profile if you do not have one. Accreditation is separate, paid and optional.',
        ],
        [
            'key' => 'yellow_pages', 'name' => 'Yellow Pages',
            'website_url' => 'https://www.yellowpages.com', 'claim_url' => null,
            'category' => 'directory', 'icon' => 'book-open', 'importance' => 'recommended', 'tracking_mode' => 'manual',
            'country_scope' => 'US', 'is_platform_core' => true, 'sort_order' => 90,
            'setup_guidance' => 'Search yellowpages.com for your phone number to find an existing listing, then claim it from there and record what it shows.',
        ],
        [
            'key' => 'mapquest', 'name' => 'MapQuest',
            'website_url' => 'https://www.mapquest.com', 'claim_url' => null,
            'category' => 'maps', 'icon' => 'map', 'importance' => 'optional', 'tracking_mode' => 'manual',
            'country_scope' => null, 'is_platform_core' => true, 'sort_order' => 100,
            'setup_guidance' => 'Search mapquest.com for your business and record what it shows. Corrections usually go through a data partner linked from the page.',
        ],

        // ---- Niche-only sources (shown only where a niche recommends them) ----
        [
            'key' => 'weddingwire_the_knot', 'name' => 'WeddingWire & The Knot (WeddingPro)',
            'website_url' => 'https://www.weddingwire.com', 'claim_url' => 'https://pros.weddingpro.com/',
            'category' => 'wedding_events', 'icon' => 'heart', 'importance' => 'recommended', 'tracking_mode' => 'assisted',
            'country_scope' => null, 'is_platform_core' => false, 'sort_order' => 200,
            'setup_guidance' => 'One WeddingPro account covers both sites. Start with the free plan; add real prices and event photos, and answer inquiries quickly.',
        ],
        [
            'key' => 'gigsalad', 'name' => 'GigSalad',
            'website_url' => 'https://www.gigsalad.com', 'claim_url' => 'https://www.gigsalad.com/join',
            'category' => 'wedding_events', 'icon' => 'party-popper', 'importance' => 'recommended', 'tracking_mode' => 'assisted',
            'country_scope' => null, 'is_platform_core' => false, 'sort_order' => 210,
            'setup_guidance' => 'A free listing is available. Add a short video of your booth at an event and spell out what each package includes.',
        ],
        [
            'key' => 'eventective', 'name' => 'Eventective',
            'website_url' => 'https://www.eventective.com', 'claim_url' => 'https://www.eventective.com/addlisting',
            'category' => 'wedding_events', 'icon' => 'calendar', 'importance' => 'recommended', 'tracking_mode' => 'assisted',
            'country_scope' => null, 'is_platform_core' => false, 'sort_order' => 220,
            'setup_guidance' => 'Start with the free listing. Upload photos and set your availability calendar so the profile looks active.',
        ],
        [
            'key' => 'bark', 'name' => 'Bark',
            'website_url' => 'https://www.bark.com/en/us/', 'claim_url' => 'https://www.bark.com/en/us/sellers/create/',
            'category' => 'lead_marketplace', 'icon' => 'megaphone', 'importance' => 'optional', 'tracking_mode' => 'assisted',
            'country_scope' => 'US', 'is_platform_core' => false, 'sort_order' => 230,
            'setup_guidance' => 'Joining is free but responding to leads uses paid credits. Start with a small pack and reply only to leads in your service area.',
        ],
        [
            'key' => 'the_bash', 'name' => 'The Bash',
            'website_url' => 'https://www.thebash.com', 'claim_url' => 'https://www.thebash.com/signup/landing',
            'category' => 'wedding_events', 'icon' => 'party-popper', 'importance' => 'optional', 'tracking_mode' => 'assisted',
            'country_scope' => null, 'is_platform_core' => false, 'sort_order' => 240,
            'setup_guidance' => 'Check the current membership terms before signing up. It suits corporate and party work more than weddings.',
        ],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::DIRECTORIES as $directory) {
            $existing = DB::table('seo_citation_directories')->where('key', $directory['key'])->whereNull('business_id')->first();

            if ($existing !== null) {
                DB::table('seo_citation_directories')->where('id', $existing->id)->update($directory + ['updated_at' => $now]);

                continue;
            }

            DB::table('seo_citation_directories')->insert($directory + [
                'uid' => (string) Str::uuid(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
