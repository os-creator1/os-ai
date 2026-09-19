<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Implementation Contract 18 §8.5 / OD-3 — the platform-owned citation
 * directory reference list.
 *
 * SEEDING RULE (contract): at most ten universally applicable directories;
 * every claim_url is verified against the directory's OWN site at
 * implementation time; no affiliate or tracking parameters; entries and URLs
 * are never invented. Google Business Profile is deliberately absent — it
 * appears in Citations as a synthetic, read-only row derived from the GBP
 * read model, never as a stored directory.
 *
 * VERIFIED 2026-09-19 by loading each URL in a browser and reading the
 * directory's own page:
 *
 *  - bing_places — https://www.bingplaces.com/ redirects to
 *    https://www.bing.com/forbusiness/ ("Bing Places for Business", "190+
 *    countries"); that page's own "Get started" call to action links to
 *    https://www.bing.com/forbusiness/management, used here.
 *  - facebook_pages — https://www.facebook.com/pages/create loads Facebook's
 *    own "Create a Page" screen for a business or brand.
 *
 * CONSIDERED AND DELIBERATELY NOT SEEDED (each needs a human decision, not a
 * guess): Yelp — its claim page (https://biz.yelp.com/claim) was verified,
 * but Yelp does not operate in every country and this seeder has no verified
 * country list, so it cannot honestly be marked universal. Apple Business
 * Connect — its former address now redirects to https://business.apple.com/,
 * whose landing page describes device/team management and shows no
 * place-listing claim flow, so no claim URL could be verified. Foursquare and
 * Trustpilot — no clean, parameter-free claim URL could be verified (a
 * guessed Foursquare path returned "page not found"; Trustpilot's calls to
 * action carry locale/tracking parameters).
 *
 * Idempotent and keyed on `key`: re-running updates the name/URL/order but
 * never duplicates a row and never changes a row's uid.
 */
class SeoCitationDirectorySeeder extends Seeder
{
    /**
     * @var array<int, array{key: string, name: string, claim_url: string, country_scope: ?string, sort_order: int}>
     */
    public const DIRECTORIES = [
        [
            'key' => 'bing_places',
            'name' => 'Bing Places for Business',
            'claim_url' => 'https://www.bing.com/forbusiness/management',
            'country_scope' => null,
            'sort_order' => 10,
        ],
        [
            'key' => 'facebook_pages',
            'name' => 'Facebook Business Page',
            'claim_url' => 'https://www.facebook.com/pages/create',
            'country_scope' => null,
            'sort_order' => 20,
        ],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::DIRECTORIES as $directory) {
            $existing = DB::table('seo_citation_directories')->where('key', $directory['key'])->first();

            if ($existing !== null) {
                DB::table('seo_citation_directories')->where('id', $existing->id)->update([
                    'name' => $directory['name'],
                    'claim_url' => $directory['claim_url'],
                    'country_scope' => $directory['country_scope'],
                    'sort_order' => $directory['sort_order'],
                    'updated_at' => $now,
                ]);

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
