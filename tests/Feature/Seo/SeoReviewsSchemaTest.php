<?php

namespace Tests\Feature\Seo;

use App\Models\SeoLocationReviewLink;
use App\Models\SeoReviewRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Seo\Concerns\CreatesSeoReviewFixtures;
use Tests\TestCase;

/**
 * Contract 18 §8.6 / §15.F — the Reviews schema exactly as contracted, with
 * NO Google review content, rating, reviewer, message body, sentiment,
 * incentive or quota column anywhere (workflow tracking, not ingestion).
 */
class SeoReviewsSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoReviewFixtures;

    public function test_review_links_has_exactly_the_contracted_columns(): void
    {
        $this->assertSame(
            ['id', 'uid', 'business_id', 'business_location_id', 'review_url', 'set_by_user_id', 'created_at', 'updated_at'],
            Schema::getColumnListing('seo_location_review_links')
        );
    }

    public function test_review_requests_has_exactly_the_contracted_columns(): void
    {
        $this->assertSame([
            'id', 'uid', 'business_id', 'business_location_id', 'contact_id', 'crm_opportunity_id', 'channel',
            'status', 'requested_at', 'resolved_at', 'created_by_user_id', 'created_at', 'updated_at',
        ], Schema::getColumnListing('seo_review_requests'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reviewTables(): array
    {
        return ['links' => ['seo_location_review_links'], 'requests' => ['seo_review_requests']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reviewTables')]
    public function test_no_google_review_content_no_rating_reviewer_text_sentiment_incentive_or_quota_column_exists(string $table): void
    {
        foreach (Schema::getColumnListing($table) as $column) {
            $this->assertDoesNotMatchRegularExpression(
                '/rating|star|score|sentiment|satisf|reviewer|review_text|review_body|comment|body|message|content|text|author|name|email|phone|incentive|reward|discount|coupon|gift|quota|target|goal|rank|click|short|redirect|google_review/i',
                $column,
                "[{$table}.{$column}] would store review content, a person's details, or a gating/incentive/quota/tracking concept."
            );
        }
    }

    public function test_no_review_content_or_ratings_table_exists_anywhere(): void
    {
        foreach (Schema::getTableListing() as $table) {
            $this->assertDoesNotMatchRegularExpression('/^(seo_)?(google_)?(reviews?|ratings?|reviewers?|review_content|review_responses?)$/i', $table);
            $this->assertDoesNotMatchRegularExpression('/^seo_.*(rating|sentiment|incentive|quota|click)/i', $table);
        }
    }

    public function test_the_ledger_column_types_and_defaults(): void
    {
        $columns = collect(DB::select('SHOW COLUMNS FROM seo_review_requests'))->keyBy('Field');

        $this->assertSame('varchar(16)', $columns['channel']->Type);
        $this->assertSame('varchar(16)', $columns['status']->Type);
        $this->assertSame('requested', $columns['status']->Default);
        $this->assertSame('NO', $columns['business_location_id']->Null, 'business_location_id is NOT NULL.');
        $this->assertSame('YES', $columns['contact_id']->Null);
        $this->assertSame('YES', $columns['crm_opportunity_id']->Null);
        $this->assertSame('YES', $columns['resolved_at']->Null);
        $this->assertSame('NO', $columns['requested_at']->Null);

        $links = collect(DB::select('SHOW COLUMNS FROM seo_location_review_links'))->keyBy('Field');
        $this->assertSame('varchar(2048)', $links['review_url']->Type);
    }

    public function test_one_manual_link_per_location_is_enforced_by_the_database(): void
    {
        [, $business] = $this->growthTenantWithLocation();
        $location = $this->reviewLocation($business);
        $this->makeReviewLink($business, $location);

        $this->expectException(QueryException::class);

        try {
            $this->makeReviewLink($business, $location, 'https://g.page/r/other/review');
        } catch (QueryException $e) {
            $this->assertStringContainsString('seo_review_links_location_unique', $e->getMessage());

            throw $e;
        }
    }

    public function test_the_composite_foreign_key_refuses_a_link_or_request_pairing_a_location_with_another_business(): void
    {
        [, $business] = $this->growthTenantWithLocation();
        [, $other] = $this->growthTenantWithLocation();
        $foreign = $this->reviewLocation($other);

        foreach ([
            fn () => $this->makeReviewLink($business, $foreign),
            fn () => $this->makeReviewRequest($business, $foreign),
        ] as $write) {
            try {
                $write();
                $this->fail('The composite FK must refuse the pairing.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('_location_business_foreign', $e->getMessage());
            }
        }
    }

    public function test_a_location_with_review_records_cannot_be_deleted(): void
    {
        [, $business] = $this->growthTenantWithLocation();
        $location = $this->reviewLocation($business);
        $this->makeReviewRequest($business, $location);

        $this->expectException(QueryException::class);

        DB::table('business_locations')->where('id', $location->id)->delete();
    }

    public function test_deleting_a_contact_keeps_the_ledger_row_and_nulls_the_contact(): void
    {
        [, $business] = $this->growthTenantWithLocation();
        $location = $this->reviewLocation($business);
        $contact = $this->reviewContact($business, $location);
        $request = $this->makeReviewRequest($business, $location, $contact);

        DB::table('contacts')->where('id', $contact->id)->delete();

        $this->assertNull($request->fresh()->contact_id);
        $this->assertSame(1, SeoReviewRequest::query()->count());
    }

    public function test_deleting_an_opportunity_keeps_the_ledger_row_and_nulls_the_link(): void
    {
        [, $business] = $this->growthTenantWithLocation();
        $location = $this->reviewLocation($business);
        $opportunity = $this->reviewOpportunity($business);
        $request = $this->makeReviewRequest($business, $location, null, ['crm_opportunity_id' => $opportunity->id]);

        DB::table('crm_opportunity_history')->where('opportunity_id', $opportunity->id)->delete();
        DB::table('crm_opportunities')->where('id', $opportunity->id)->delete();

        $this->assertNull($request->fresh()->crm_opportunity_id);
    }

    public function test_uids_are_uuids(): void
    {
        [, $business] = $this->growthTenantWithLocation();
        $location = $this->reviewLocation($business);

        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $this->makeReviewRequest($business, $location)->uid);
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', SeoLocationReviewLink::query()->create([
            'business_id' => $business->id, 'business_location_id' => $location->id, 'review_url' => 'https://g.page/r/x',
        ])->uid);
    }
}
