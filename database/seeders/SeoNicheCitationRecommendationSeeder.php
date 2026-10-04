<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Citations V1 — the niche recommendations shipped with the platform.
 *
 * Niche key = businesses.industry (BusinessIndustry value), the same key the
 * Website questionnaire and templates use. A row only REFERENCES a catalog
 * directory (SeoCitationDirectorySeeder); the Platform Owner edits these from
 * the admin Niche Recommendations screen without code changes.
 *
 * PHOTO BOOTH (verified October 2026 against each vendor's own pages; the
 * sources considered and rejected are listed in the Citations contract):
 *  - WeddingWire & The Knot — one WeddingPro vendor program; photo booth is a
 *    listed category with prices and quote requests; a free plan exists.
 *  - GigSalad — a dedicated photo booth category; free limited listing.
 *  - Eventective — event marketplace with a free listing (Equipment Rentals
 *    includes photo booths).
 *  - Bark — inbound quote requests; free to join, paid credits (Optional).
 *  - The Bash — party/entertainment marketplace with a photo booth page;
 *    pricing not clearly documented (Optional).
 * Not seeded for lack of a verifiable sign-up page or fit: Thumbtack, Zola,
 * PartySlate (paid tiers, no self-serve form), Peerspace, Cvent, Alignable.
 *
 * Idempotent: keyed on (niche_key, directory).
 */
class SeoNicheCitationRecommendationSeeder extends Seeder
{
    /**
     * @var array<string, array<int, array{directory: string, importance: ?string, sort_order: int, guidance: ?string}>>
     */
    public const RECOMMENDATIONS = [
        'photo_booth_service' => [
            ['directory' => 'weddingwire_the_knot', 'importance' => 'recommended', 'sort_order' => 10, 'guidance' => 'Couples search here for photo booths specifically. Finish one profile before touching the second site.'],
            ['directory' => 'gigsalad', 'importance' => 'recommended', 'sort_order' => 20, 'guidance' => 'The strongest non-wedding channel for birthdays, corporate and school events.'],
            ['directory' => 'eventective', 'importance' => 'recommended', 'sort_order' => 30, 'guidance' => 'A long-running event marketplace; the free listing shows your prices.'],
            ['directory' => 'bark', 'importance' => 'optional', 'sort_order' => 40, 'guidance' => 'Lead-based and credit-funded — set a small budget and track which leads book.'],
            ['directory' => 'the_bash', 'importance' => 'optional', 'sort_order' => 50, 'guidance' => 'Likely paid; set up the free listings first.'],
        ],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::RECOMMENDATIONS as $nicheKey => $rows) {
            foreach ($rows as $row) {
                $directoryId = DB::table('seo_citation_directories')->where('key', $row['directory'])->whereNull('business_id')->value('id');

                if ($directoryId === null) {
                    continue;
                }

                $values = [
                    'importance' => $row['importance'],
                    'sort_order' => $row['sort_order'],
                    'guidance' => $row['guidance'],
                    'updated_at' => $now,
                ];

                $existing = DB::table('seo_niche_citation_recommendations')
                    ->where('niche_key', $nicheKey)->where('seo_citation_directory_id', $directoryId)->first();

                if ($existing !== null) {
                    // Idempotent re-seed refreshes the shipped copy but keeps an
                    // operator's enable/disable choice.
                    DB::table('seo_niche_citation_recommendations')->where('id', $existing->id)->update($values);

                    continue;
                }

                DB::table('seo_niche_citation_recommendations')->insert($values + [
                    'uid' => (string) Str::uuid(),
                    'niche_key' => $nicheKey,
                    'seo_citation_directory_id' => $directoryId,
                    'is_enabled' => true,
                    'created_at' => $now,
                ]);
            }
        }
    }
}
