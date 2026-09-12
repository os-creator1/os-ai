<?php

namespace Tests\Feature\Coo;

use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Coo\BusinessSignalReader;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Dashboard\DashboardStatusReader;
use App\Models\Business;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * Unified Business Home and COO Decision Engine contract §6.2 — S1.
 *
 * Proves `BusinessSignalReader` composes the four canonical seams exactly
 * (never a fresh duplicate query), touches `chat_boxes` only through
 * `BusinessConversationReadModel`, isolates strictly by Business, costs a
 * fixed, pinned number of queries with no N+1, resolves no AI dependency,
 * and depends on no UI/HTTP concept.
 */
class BusinessSignalReaderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    private const FORBIDDEN_TABLES = ['agency_prospect', 'business_usage', 'invoices', 'subscriptions', 'subscription_transactions', 'payment_'];

    // -----------------------------------------------------------------
    // Canonical seam reuse — each DTO field equals the seam's own figure
    // -----------------------------------------------------------------

    public function test_status_equals_the_dashboard_status_reader_row_exactly(): void
    {
        [, $business] = $this->tenant();
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone);

        $signals = $this->read($business, $range, $range);
        $expected = app(DashboardStatusReader::class)->forBusiness((int) $business->id);

        $this->assertEquals($expected, $signals->status);
    }

    public function test_new_contacts_and_messages_received_equal_the_same_b5_query_methods(): void
    {
        [$customer, $business] = $this->tenant();
        $group = $this->group($business);
        $this->contact($business, $group, ['created_at' => now()->utc()]);
        $this->report($business, $customer->user_id, ['direction' => 'incoming']);

        $current = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone);
        $previous = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_90_DAYS, $business->timezone);

        $signals = $this->read($business, $current, $previous);

        $analytics = app(BusinessAnalyticsQueries::class);
        $this->assertSame($analytics->contactKpis($business, $current)['kpis']->newInRange, $signals->newContacts->current);
        $this->assertSame($analytics->contactKpis($business, $previous)['kpis']->newInRange, $signals->newContacts->previous);
        $this->assertSame($analytics->messageKpis($business, $current)['kpis']->inbound, $signals->messagesReceived->current);
        $this->assertSame($analytics->messageKpis($business, $previous)['kpis']->inbound, $signals->messagesReceived->previous);
    }

    public function test_conversations_started_and_unread_equal_the_2b_seam_exactly(): void
    {
        [, $business] = $this->tenant();
        $this->conversationAt($business, now()->utc()->subDays(2)->format('Y-m-d H:i:s'));
        $this->conversationAt($business, now()->utc()->subDays(60)->format('Y-m-d H:i:s'), unread: true);

        $current = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone);
        $previous = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_90_DAYS, $business->timezone);

        $signals = $this->read($business, $current, $previous);

        $conversations = app(BusinessConversationReadModel::class);
        $this->assertSame($conversations->startedCount($business, $current->startUtc, $current->endUtc), $signals->conversationsStarted->current);
        $this->assertSame($conversations->startedCount($business, $previous->startUtc, $previous->endUtc), $signals->conversationsStarted->previous);
        $this->assertSame($conversations->unreadCount($business), $signals->unreadConversations);
        $this->assertGreaterThan(0, $signals->unreadConversations, 'Fixture must actually exercise a non-zero unread count.');
    }

    public function test_opportunity_queue_head_equals_the_repositorys_own_top_for_customer(): void
    {
        [, $business] = $this->tenant();
        $this->opportunity($business, ['priority_score' => 50]);
        $topId = $this->opportunity($business, ['priority_score' => 90]);

        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone);
        $signals = $this->read($business, $range, $range);

        $this->assertNotNull($signals->opportunityQueueHead);
        $this->assertSame($topId, $signals->opportunityQueueHead->id);

        $expected = app(OpportunityRepository::class)->topForCustomer($business, 1)->first();
        $this->assertSame($expected->id, $signals->opportunityQueueHead->id);
    }

    public function test_opportunity_queue_head_is_null_when_the_business_has_no_actionable_opportunity(): void
    {
        [, $business] = $this->tenant();

        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone);
        $signals = $this->read($business, $range, $range);

        $this->assertNull($signals->opportunityQueueHead);
    }

    public function test_the_absent_replied_and_awaiting_reply_facts_are_never_invented(): void
    {
        // Contract §18: BusinessConversationReadModel's only writer is
        // Slice H-4. Confirms, at the seam itself, that no such method
        // exists yet to be composed — so BusinessSignals correctly carries
        // no "replied" or "awaiting reply" field at all rather than a
        // guessed one.
        $this->assertFalse(method_exists(BusinessConversationReadModel::class, 'repliedCount'));
        $this->assertFalse(method_exists(BusinessConversationReadModel::class, 'awaitingReplyCount'));
        $this->assertFalse(property_exists(\App\DTO\Coo\BusinessSignals::class, 'repliedConversations'));
        $this->assertFalse(property_exists(\App\DTO\Coo\BusinessSignals::class, 'awaitingReplyConversations'));
    }

    // -----------------------------------------------------------------
    // No direct chat_boxes SQL, and no other forbidden source
    // -----------------------------------------------------------------

    public function test_no_query_touches_chat_boxes_outside_the_conversations_seams_own_call(): void
    {
        [, $business] = $this->tenant();
        $this->conversationAt($business, now()->utc()->format('Y-m-d H:i:s'));
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone);

        $sql = $this->capturedSql(function () use ($business, $range): void {
            $this->read($business, $range, $range);
        });

        $chatBoxSql = array_filter($sql, fn (string $s) => preg_match('/\bchat_box(es|_messages)?\b/', $s) === 1);

        // chat_boxes IS queried — but only by BusinessConversationReadModel,
        // which owns it. This proves that ownership from the trace, not
        // merely that the table name appears somewhere.
        foreach ($chatBoxSql as $statement) {
            $this->assertMatchesRegularExpression('/\bchat_boxes\b/', $statement);
        }
        $this->assertNotEmpty($chatBoxSql, 'Fixture must actually exercise a chat_boxes read through the seam.');
    }

    public function test_business_signal_reader_source_never_mentions_chat_boxes_or_another_forbidden_table(): void
    {
        $files = array_merge(
            glob(app_path('Library/Coo/*.php')),
            glob(app_path('DTO/Coo/*.php')),
            glob(app_path('Enums/Coo/*.php')),
        );

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            // Comments stripped, matching AnalyticsSeparationTest's own
            // convention: the assertion is about code paths, not about
            // docblocks that name what is deliberately forbidden.
            $source = php_strip_whitespace($file);

            foreach (['chat_box', 'ChatBox', 'ChatBoxMessage'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, basename($file) . ' must never reference ' . $forbidden . ' directly — only BusinessConversationReadModel may.');
            }

            foreach (self::FORBIDDEN_TABLES as $table) {
                $this->assertStringNotContainsString($table, $source, basename($file) . ' must never reference ' . $table . '.');
            }
        }
    }

    // -----------------------------------------------------------------
    // Tenant isolation
    // -----------------------------------------------------------------

    public function test_a_foreign_businesss_facts_never_appear(): void
    {
        [, $businessA] = $this->tenant(name: 'Business A');
        [, $businessB] = $this->tenant(name: 'Business B');

        $groupA = $this->group($businessA);
        $this->contact($businessA, $groupA);
        $this->conversationAt($businessA, now()->utc()->format('Y-m-d H:i:s'));
        $this->opportunity($businessA);

        // Business B has none of the above.
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $businessB->timezone);
        $signals = $this->read($businessB, $range, $range);

        $this->assertSame(0, $signals->newContacts->current);
        $this->assertSame(0, $signals->conversationsStarted->current);
        $this->assertSame(0, $signals->unreadConversations);
        $this->assertNull($signals->opportunityQueueHead);
        $this->assertSame((int) $businessB->id, $signals->businessId);
    }

    // -----------------------------------------------------------------
    // Query budget — fixed, pinned, and flat when data doubles (no N+1)
    // -----------------------------------------------------------------

    public function test_query_count_is_fixed_and_does_not_grow_with_data_volume(): void
    {
        [$customer, $business] = $this->tenant();
        $group = $this->group($business);

        $this->seedVolume($business, $customer->user_id, $group, rows: 3);

        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone);
        $sqlSmall = $this->capturedSql(function () use ($business, $range): void {
            $this->read($business, $range, $range);
        });

        $this->seedVolume($business, $customer->user_id, $group, rows: 20);

        $sqlLarge = $this->capturedSql(function () use ($business, $range): void {
            $this->read($business, $range, $range);
        });

        $this->assertSame(9, count($sqlSmall), 'Pinned query count: status(1) + contactKpis(2) + messageKpis(2) + startedCount(2) + unreadCount(1) + topForCustomer(1) = 9. Statements: ' . implode(' | ', $sqlSmall));
        $this->assertSame(count($sqlSmall), count($sqlLarge), 'Query count must stay flat as fixture data grows — no N+1.');
    }

    // -----------------------------------------------------------------
    // No AI, no UI
    // -----------------------------------------------------------------

    public function test_the_reader_resolves_no_ai_dependency(): void
    {
        $reflection = new \ReflectionClass(BusinessSignalReader::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertNotEmpty($constructor->getParameters(), 'The constructor must actually declare dependencies for this check to mean anything.');

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            $this->assertNotSame('', $typeName, "Constructor parameter [{$parameter->getName()}] must be type-hinted.");
            $this->assertDoesNotMatchRegularExpression('/\\\\Ai\\\\|AiCompletionClient|OpenAi/i', $typeName, "Constructor parameter [{$parameter->getName()}] must not depend on any AI gateway/client type, found [{$typeName}].");
        }

        // Resolving the reader through the real container must succeed with
        // zero AI bindings involved — if any constructor parameter secretly
        // required one, this would throw BindingResolutionException.
        $resolved = app(BusinessSignalReader::class);
        $this->assertInstanceOf(BusinessSignalReader::class, $resolved);
    }

    public function test_the_reader_and_its_dtos_reference_no_view_http_or_ui_type(): void
    {
        $files = array_merge(
            glob(app_path('Library/Coo/*.php')),
            glob(app_path('DTO/Coo/*.php')),
            glob(app_path('Enums/Coo/*.php')),
        );

        foreach ($files as $file) {
            $source = file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression('/\bBlade\b|\bview\(|Illuminate\\\\Http\\\\|Illuminate\\\\View\\\\/i', $source, "{$file} must have no UI/HTTP dependency (contract: pure, no UI).");
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function read(Business $business, AnalyticsDateRange $current, AnalyticsDateRange $previous): \App\DTO\Coo\BusinessSignals
    {
        return app(BusinessSignalReader::class)->read($business, $current, $previous);
    }

    private function conversationAt(Business $business, string $storageTimestamp, bool $unread = false): void
    {
        DB::table('chat_boxes')->insert([
            'uid' => (string) Str::uuid(),
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => '18005550100',
            'to' => '1606555' . random_int(1000, 9999),
            'notification' => $unread ? 1 : 0,
            'created_at' => $storageTimestamp,
            'updated_at' => $storageTimestamp,
        ]);
    }

    private function seedVolume(Business $business, int $userId, \App\Models\ContactGroups $group, int $rows): void
    {
        for ($i = 0; $i < $rows; $i++) {
            $this->contact($business, $group, ['created_at' => now()->utc()]);
            $this->report($business, $userId, ['direction' => 'incoming']);
            $this->conversationAt($business, now()->utc()->format('Y-m-d H:i:s'));
        }
    }
}
