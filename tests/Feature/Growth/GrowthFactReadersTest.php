<?php

namespace Tests\Feature\Growth;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Growth\GrowthFactStatus;
use App\Enums\Growth\GrowthRuleOutcomeStatus as S;
use App\Library\Growth\GrowthFactSnapshotBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Growth\Concerns\CreatesGrowthFixtures;
use Tests\TestCase;

/**
 * The domain readers against REAL canonical tables: what the Growth Center
 * concludes from rows the other modules actually wrote.
 */
class GrowthFactReadersTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGrowthFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGrowthBusiness();
    }

    private function facts()
    {
        return app(GrowthFactSnapshotBuilder::class)->build($this->business->fresh());
    }

    private function outcomes(): array
    {
        return $this->evaluateGrowth()['outcomes'];
    }

    // ── CRM ──────────────────────────────────────────────────────────────

    public function test_crm_deal_is_unanswered_only_after_the_threshold_and_while_no_contact(): void
    {
        $this->unansweredDeal(5);    // too recent
        $this->unansweredDeal(30);   // counts
        $contacted = $this->unansweredDeal(40);
        $this->markDealContacted($contacted);

        $bucket = $this->facts()->set('crm')->get('by_location')[$this->primaryLocation->id]['unanswered'];

        $this->assertSame(1, $bucket['count']);
    }

    public function test_a_deal_is_counted_in_at_most_one_bucket(): void
    {
        $this->unansweredDeal(24 * 20, 90000);   // old AND unanswered AND high value

        $by = $this->facts()->set('crm')->get('by_location')[$this->primaryLocation->id];

        $this->assertSame(1, $by['unanswered']['count']);
        $this->assertSame(0, $by['stale']['count']);
        $this->assertSame(0, $by['high_value_stale']['count']);
    }

    public function test_recorded_activity_keeps_a_deal_from_going_stale(): void
    {
        $deal = $this->unansweredDeal(24 * 20, 20000);
        $this->markDealContacted($deal);
        $this->assertSame(1, $this->facts()->set('crm')->get('by_location')[$this->primaryLocation->id]['stale']['count']);

        DB::table('crm_opportunity_history')->insert([
            'business_id' => $this->business->id,
            'opportunity_id' => $deal->id,
            'event' => 'contact_status_changed',
            'created_at' => now()->subDay(),
        ]);

        $this->assertSame(0, $this->facts()->set('crm')->get('by_location')[$this->primaryLocation->id]['stale']['count']);
    }

    public function test_won_and_lost_deals_are_never_stale_or_unanswered(): void
    {
        $deal = $this->unansweredDeal(24 * 20);
        DB::table('crm_opportunities')->where('id', $deal->id)->update(['status' => 'won']);

        $this->assertSame([], $this->facts()->set('crm')->get('by_location'));
    }

    // ── Conversations ────────────────────────────────────────────────────

    public function test_conversation_awaiting_reply_is_proved_by_direction_and_time(): void
    {
        $this->conversation([['incoming', 30]]);                         // waiting
        $this->conversation([['incoming', 30], ['outgoing', 20]]);       // replied
        $this->conversation([['incoming', 2]]);                          // too recent
        $this->conversation([['incoming', 30], ['outgoing', 20, 'failed']]);   // the reply never went out
        $this->conversation([[null, 30]]);                               // direction unknown: ignored

        $bucket = $this->facts()->set('conversations')->get('by_location')[$this->primaryLocation->id];

        $this->assertSame(2, $bucket['count'], 'the unanswered one and the one whose reply failed');
        $this->assertGreaterThanOrEqual(30, $bucket['oldest_hours']);
    }

    public function test_a_later_inbound_message_reopens_a_replied_conversation(): void
    {
        $this->conversation([['incoming', 60], ['outgoing', 50], ['incoming', 30]]);

        $this->assertSame(1, $this->facts()->set('conversations')->get('by_location')[$this->primaryLocation->id]['count']);
    }

    public function test_conversations_are_scoped_to_their_business(): void
    {
        [, $other] = $this->crmTenant('Other Co', 'Other WS');
        DB::table('chat_boxes')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $other->customer_id, 'business_id' => $other->id,
            'to' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0, $this->facts()->set('conversations')->get('active_count'));
    }

    // ── Booking ──────────────────────────────────────────────────────────

    public function test_booking_type_without_staff_or_hours_is_not_ready(): void
    {
        $this->bookingType();                                   // no staff
        $withStaffNoHours = $this->bookingType();
        $this->assignStaff($withStaffNoHours, null, 0);

        $this->assertSame(2, $this->facts()->set('booking')->get('not_ready')[$this->primaryLocation->id]['count']);
    }

    public function test_a_ready_booking_type_is_not_flagged_and_capacity_is_measured(): void
    {
        $ready = $this->bookingType();
        $this->assignStaff($ready, null, 7);                    // 7 one-hour windows = 420 min a week

        $facts = $this->facts()->set('booking');

        $this->assertSame([], $facts->get('not_ready'));
        $this->assertSame(420, $facts->get('capacity')[$this->primaryLocation->id]['weekly_minutes']);
    }

    public function test_an_inactive_booking_type_is_ignored(): void
    {
        $this->bookingType(null, false);

        $this->assertSame(0, $this->facts()->set('booking')->get('active_type_count'));
    }

    // ── Documents / payments ─────────────────────────────────────────────

    public function test_documents_are_bucketed_by_what_is_actually_wrong(): void
    {
        $this->document('sent', ['sent_at' => now()->subDays(5)], [], 80000);                                  // unsigned
        $this->document('sent', ['sent_at' => now()->subDay()], [], 90000);                                    // too recent
        $this->document('signed', ['signed_at' => now()->subDays(6)], [[50000, 10]]);                          // signed, not yet due
        $this->document('signed', ['signed_at' => now()->subDays(20)], [[70000, -3]]);                         // overdue
        $this->document('paid', ['signed_at' => now()->subDays(20), 'paid_at' => now()->subDays(10)], [[40000, null, 'paid']]);

        $by = $this->facts()->set('documents')->get('by_location')[$this->primaryLocation->id];

        $this->assertSame(1, $by['unsigned']['count']);
        $this->assertSame(80000, $by['unsigned']['value_minor']);
        $this->assertSame(1, $by['signed_unpaid']['count']);
        $this->assertSame(50000, $by['signed_unpaid']['value_minor']);
        $this->assertSame(1, $by['overdue']['count']);
        $this->assertSame(70000, $by['overdue']['value_minor']);
        $this->assertSame(5, $this->facts()->set('documents')->get('sent_total'));
    }

    public function test_a_not_yet_due_balance_is_never_overdue_and_a_void_document_never_counts(): void
    {
        $this->document('signed', ['signed_at' => now()->subDays(1)], [[50000, 5]]);
        $this->document('void', [], [[50000, -5]]);
        $this->document('expired', [], [[50000, -5]]);

        $by = $this->facts()->set('documents')->get('by_location')[$this->primaryLocation->id] ?? null;

        $this->assertTrue($by === null || ($by['overdue']['count'] === 0 && $by['signed_unpaid']['count'] === 0));
    }

    public function test_a_failed_payment_attempt_on_an_unpaid_item_is_reported(): void
    {
        $documentId = $this->document('signed', ['signed_at' => now()->subDays(6)], [[30000, 3]]);
        $itemId = (int) DB::table('business_document_payment_schedule_items')->orderByDesc('id')->value('id');

        Schema::disableForeignKeyConstraints();
        DB::table('business_document_payments')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'business_id' => $this->business->id,
            'business_document_id' => $documentId, 'schedule_item_id' => $itemId, 'business_stripe_connection_id' => 1,
            'local_idempotency_key' => 'k1', 'amount_minor' => 30000, 'currency_code' => 'USD', 'status' => 'failed',
            'failure_code' => 'card_declined', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
        Schema::enableForeignKeyConstraints();

        $by = $this->facts()->set('documents')->get('by_location')[$this->primaryLocation->id];

        $this->assertSame(1, $by['failed_payment']['count']);
        $this->assertSame(30000, $by['failed_payment']['value_minor']);
    }

    // ── Reviews ──────────────────────────────────────────────────────────

    public function test_reviews_report_link_and_recent_requests_per_active_location(): void
    {
        $second = $this->secondLocation();
        $this->reviewLink();
        $this->reviewRequest(5);
        $this->reviewRequest(90);                                // outside the window

        $locations = $this->facts()->set('reviews')->get('locations');

        $this->assertTrue($locations[$this->primaryLocation->id]['has_link']);
        $this->assertSame(1, $locations[$this->primaryLocation->id]['requests_in_window']);
        $this->assertFalse($locations[$second->id]['has_link']);
    }

    public function test_an_archived_location_is_not_a_review_gap(): void
    {
        $second = $this->secondLocation();
        $this->archiveLocation($second);

        $this->assertArrayNotHasKey($second->id, $this->facts()->set('reviews')->get('locations'));
    }

    // ── Website ──────────────────────────────────────────────────────────

    public function test_website_facts_distinguish_missing_draft_published_and_external(): void
    {
        $this->assertFalse($this->facts()->set('website')->get('exists'));

        DB::table('websites')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'business_id' => $this->business->id, 'name' => 'Site', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $facts = $this->facts()->set('website');
        $this->assertTrue($facts->get('exists'));
        $this->assertFalse($facts->get('published'));

        DB::table('businesses')->where('id', $this->business->id)->update(['website_url' => 'https://example.com']);
        $this->assertTrue($this->facts()->set('website')->get('has_external_site'));
    }

    // ── SEO ──────────────────────────────────────────────────────────────

    private function publishSite(string $body): void
    {
        $websiteId = DB::table('websites')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'business_id' => $this->business->id, 'name' => 'Site', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $revisionId = DB::table('website_revisions')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'website_id' => $websiteId, 'version_number' => 1, 'schema_version' => 1,
            'created_by' => $this->business->customer_id, 'created_at' => now(),
            'snapshot' => json_encode(['pages' => [[
                'uid' => 'p1', 'slug' => 'home', 'is_home' => true, 'title' => 'Home', 'seo' => [],
                'sections' => [['type' => 'text', 'data' => ['heading' => 'Home', 'body' => $body]]],
            ]], 'assets' => []]),
        ]);
        DB::table('websites')->where('id', $websiteId)->update(['status' => 'published', 'published_revision_id' => $revisionId]);
    }

    private function keyword(string $phrase): void
    {
        DB::table('seo_keywords')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'business_id' => $this->business->id, 'business_location_id' => $this->primaryLocation->id,
            'phrase' => $phrase, 'phrase_normalized' => $phrase, 'lifecycle_state' => 'active', 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_tracked_keyword_missing_from_the_published_site_is_reported_and_a_present_one_is_not(): void
    {
        $this->publishSite('We offer photo booth rental for parties.');
        $this->keyword('photo booth rental');
        $this->keyword('neon sign hire');

        $seo = $this->facts()->set('seo');

        $this->assertTrue($seo->get('coverage_known'));
        $this->assertSame(1, $seo->get('covered_count'));
        $this->assertSame(1, $seo->get('not_covered')[$this->primaryLocation->id]['count']);
        $this->assertSame(['neon sign hire'], $seo->get('not_covered')[$this->primaryLocation->id]['phrases']);
    }

    public function test_without_a_published_site_coverage_is_unknown_not_missing(): void
    {
        $this->keyword('neon sign hire');

        $seo = $this->facts()->set('seo');

        $this->assertFalse($seo->get('coverage_known'));
        $this->assertSame([], $seo->get('not_covered'));
        $this->assertSame(S::Insufficient, $this->outcomes()['seo.keywords_not_covered:v1']->status);
    }

    // ── Citations ────────────────────────────────────────────────────────

    public function test_unchecked_directories_are_not_checked_not_mismatched(): void
    {
        $facts = $this->facts()->set('citations')->get('locations');

        $this->assertGreaterThan(0, $facts[$this->primaryLocation->id]['not_checked']);
        $this->assertSame(0, $facts[$this->primaryLocation->id]['needs_attention']);
    }

    public function test_a_needs_correction_citation_needs_attention(): void
    {
        $directoryId = (int) DB::table('seo_citation_directories')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->value('id');
        DB::table('seo_citations')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'business_id' => $this->business->id,
            'business_location_id' => $this->primaryLocation->id, 'seo_citation_directory_id' => $directoryId,
            'status' => 'needs_correction', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->facts()->set('citations')->get('locations')[$this->primaryLocation->id]['needs_attention']);
    }

    // ── Automations ──────────────────────────────────────────────────────

    public function test_no_workflow_activity_is_not_a_failure(): void
    {
        $a = $this->facts()->set('automations');

        $this->assertSame(0, $a->get('failed_steps') + $a->get('failed_runs'));
        $this->assertSame(0, $a->get('attempted'));
        $this->assertSame(S::Insufficient, $this->outcomes()['automations.repeated_failures:v1']->status);
    }

    // ── Entitlements / unavailable modules ───────────────────────────────

    public function test_search_console_is_unavailable_and_unentitled_ads_and_rank_are_never_zero(): void
    {
        $sets = $this->facts()->sets();

        $this->assertSame(GrowthFactStatus::Unavailable, $sets['search_console']->status);

        // Ads and rank have real readers now; without the module they are NOT ENTITLED (neutral), never a zero.
        foreach (['ads', 'rank'] as $domain) {
            $this->assertContains($sets[$domain]->status, [GrowthFactStatus::NotEntitled, GrowthFactStatus::Available], $domain);

            if ($sets[$domain]->status === GrowthFactStatus::NotEntitled) {
                $this->assertSame([], $sets[$domain]->data);
            }
        }
    }

    public function test_a_plan_without_a_module_excludes_its_rules_instead_of_scoring_them(): void
    {
        [, $this->business, $this->workspace] = $this->tenant(WorkspacePlanTier::Core, 'Core Co', 'Core WS');
        $this->primaryLocation = $this->growthLocation();

        $outcomes = $this->outcomes();

        $this->assertSame(S::NotApplicable, $outcomes['reviews.no_review_link:v1']->status, 'SeoModule is a Growth-tier module');
        $this->assertSame(S::NotApplicable, $outcomes['citations.directories_not_checked:v1']->status);
        $this->assertCount(0, $this->growthOpportunities('reviews.no_review_link:v1'));
    }

    // ── Query budget ─────────────────────────────────────────────────────

    public function test_fact_reading_costs_the_same_queries_however_much_data_exists(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(GrowthFactSnapshotBuilder::class)->build($this->business->fresh(), CarbonImmutable::now());
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->unansweredDeal();
        $this->conversation([['incoming', 30]]);
        $this->document('sent', ['sent_at' => now()->subDays(5)], [], 1000);
        $this->assignStaff($this->bookingType(), null, 3);
        $this->reviewLink();
        $count();   // warm the in-process entitlement caches: they are per-process, not per-row
        $small = $count();

        for ($i = 0; $i < 25; $i++) {
            $this->unansweredDeal(30 + $i, 1000 + $i);
            $this->conversation([['incoming', 30 + $i]]);
            $this->document('signed', ['signed_at' => now()->subDays(8)], [[1000, -2]], 1000);
        }

        for ($i = 0; $i < 4; $i++) {
            $location = $this->secondLocation('Extra ' . $i);
            $this->bookingType($location->id);
            $this->reviewLink($location->id);
        }

        $this->assertSame($small, $count(), 'rows, deals, documents and Locations must not add queries');
        $this->assertLessThanOrEqual(45, $small);
    }

    public function test_evaluating_the_rules_runs_no_query_at_all(): void
    {
        $this->unansweredDeal();
        $facts = $this->facts();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(\App\Library\Growth\GrowthEvaluationService::class)->evaluateRules($facts);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries, 'rules are pure over the snapshot');
    }
}
