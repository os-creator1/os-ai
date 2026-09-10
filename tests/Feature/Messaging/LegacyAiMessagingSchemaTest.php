<?php

namespace Tests\Feature\Messaging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Legacy Messaging Schema Completion — proves a migration-built database
 * now matches what executable production code already writes.
 *
 * WHAT THIS GUARDS. Three schema pieces are written by live production
 * paths and were created by no migration, so a clean migrate could not run
 * those paths at all:
 *
 *   chat_boxes.ai_replied   DLRController::inboundDLR()'s attributed branch
 *   chat_boxes.ai_stage     EloquentCampaignRepository::campaignBuilder()'s
 *   ai_box_campaign_map     legacy AI-prospecting branch
 *
 * These assertions deliberately reproduce the exact column sets those two
 * writers use, rather than restating the migration. A test that mirrors the
 * migration proves only that the file was read; a test that replays the
 * production write proves the gap is actually closed. Neither producer is
 * imported or executed here — both are stop-listed for B5 and belong to a
 * separate contract — so the writes below are byte-for-byte copies of their
 * column sets, kept deliberately close so a drift in either producer shows
 * up here.
 *
 * Deliberately does NOT use RefreshDatabase: this suite asserts against the
 * real migrated schema, and every row it creates is removed in tearDown.
 */
class LegacyAiMessagingSchemaTest extends TestCase
{
    private array $createdUserIds = [];
    private array $createdCampaignIds = [];
    private array $createdChatBoxIds = [];

    protected function tearDown(): void
    {
        if ($this->createdChatBoxIds !== []) {
            DB::table('ai_box_campaign_map')->whereIn('box_id', $this->createdChatBoxIds)->delete();
            DB::table('chat_boxes')->whereIn('id', $this->createdChatBoxIds)->delete();
        }

        if ($this->createdCampaignIds !== []) {
            DB::table('ai_box_campaign_map')->whereIn('campaign_id', $this->createdCampaignIds)->delete();
            DB::table('campaigns')->whereIn('id', $this->createdCampaignIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Schema shape
    // -----------------------------------------------------------------

    public function test_chat_boxes_carries_both_legacy_ai_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumn('chat_boxes', 'ai_replied'),
            'DLRController::inboundDLR() writes chat_boxes.ai_replied on every attributed inbound message.'
        );

        $this->assertTrue(
            Schema::hasColumn('chat_boxes', 'ai_stage'),
            'EloquentCampaignRepository::campaignBuilder() writes chat_boxes.ai_stage when enrolling a contact.'
        );
    }

    public function test_ai_replied_defaults_to_not_replied_and_is_not_nullable(): void
    {
        $column = $this->columnDefinition('chat_boxes', 'ai_replied');

        $this->assertSame('NO', $column->IS_NULLABLE, 'ai_replied mirrors reply_by_customer, which is not nullable.');
        $this->assertSame('0', (string) $column->COLUMN_DEFAULT, 'An existing chat box has not been AI-replied to.');
        $this->assertSame('tinyint(1)', $column->COLUMN_TYPE, 'A 0/1 flag, exactly like reply_by_customer.');
    }

    public function test_ai_stage_is_nullable_because_not_every_chat_box_is_in_the_state_machine(): void
    {
        $column = $this->columnDefinition('chat_boxes', 'ai_stage');

        $this->assertSame('YES', $column->IS_NULLABLE, 'Inbound-created chat boxes never enter the AI state machine.');
        $this->assertNull($column->COLUMN_DEFAULT, 'A default would invent a stage for every pre-existing row.');
        $this->assertStringStartsWith('tinyint', $column->COLUMN_TYPE, 'Documented stage values are 1-6 and 99.');
    }

    public function test_the_mapping_table_exists_with_exactly_the_columns_its_writer_supplies(): void
    {
        $this->assertTrue(Schema::hasTable('ai_box_campaign_map'));

        foreach (['id', 'box_id', 'campaign_id', 'created_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('ai_box_campaign_map', $column),
                "ai_box_campaign_map.{$column} is required by the campaignBuilder insert or its own primary key."
            );
        }

        $this->assertFalse(
            Schema::hasColumn('ai_box_campaign_map', 'updated_at'),
            'The writer supplies only created_at; an updated_at nothing writes would be invented schema.'
        );
    }

    // -----------------------------------------------------------------
    // Indexes and foreign keys
    // -----------------------------------------------------------------

    public function test_the_mapping_table_carries_both_cascading_foreign_keys(): void
    {
        $constraints = DB::table('information_schema.KEY_COLUMN_USAGE as kcu')
            ->join('information_schema.REFERENTIAL_CONSTRAINTS as rc', function ($join): void {
                $join->on('rc.CONSTRAINT_NAME', '=', 'kcu.CONSTRAINT_NAME')
                    ->on('rc.CONSTRAINT_SCHEMA', '=', 'kcu.TABLE_SCHEMA');
            })
            ->where('kcu.TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('kcu.TABLE_NAME', 'ai_box_campaign_map')
            ->whereNotNull('kcu.REFERENCED_TABLE_NAME')
            ->get(['kcu.COLUMN_NAME', 'kcu.REFERENCED_TABLE_NAME', 'rc.DELETE_RULE'])
            ->keyBy('COLUMN_NAME');

        $this->assertTrue($constraints->has('box_id'), 'box_id must reference chat_boxes.');
        $this->assertSame('chat_boxes', $constraints['box_id']->REFERENCED_TABLE_NAME);
        $this->assertSame('CASCADE', $constraints['box_id']->DELETE_RULE, 'ClearChatbox and CustomerController hard-delete chat boxes.');

        $this->assertTrue($constraints->has('campaign_id'), 'campaign_id must reference campaigns.');
        $this->assertSame('campaigns', $constraints['campaign_id']->REFERENCED_TABLE_NAME);
        $this->assertSame('CASCADE', $constraints['campaign_id']->DELETE_RULE, 'campaignBuilder deletes the campaign after inserting map rows.');
    }

    public function test_the_mapping_table_is_indexed_for_the_join_it_exists_to_serve(): void
    {
        $indexedColumns = collect(DB::select('SHOW INDEX FROM ai_box_campaign_map'))
            ->pluck('Column_name')
            ->unique()
            ->values()
            ->all();

        $this->assertContains('box_id', $indexedColumns, 'The reader joined chat_boxes.id = map.box_id.');
        $this->assertContains('campaign_id', $indexedColumns, 'The reader filtered on map.campaign_id.');
    }

    // -----------------------------------------------------------------
    // The production writes themselves
    // -----------------------------------------------------------------

    public function test_the_inbound_dlr_update_succeeds_against_the_migrated_schema(): void
    {
        $boxId = $this->createChatBox();

        // Byte-for-byte the update in DLRController::inboundDLR().
        DB::table('chat_boxes')->where('id', $boxId)->update([
            'reply_by_customer' => 1,
            'ai_replied' => 0,
        ]);

        $row = DB::table('chat_boxes')->where('id', $boxId)->first();

        $this->assertSame(1, (int) $row->reply_by_customer);
        $this->assertSame(0, (int) $row->ai_replied);
    }

    public function test_the_campaign_builder_enrolment_write_succeeds_against_the_migrated_schema(): void
    {
        $userId = $this->createUser();
        $campaignId = $this->createCampaign($userId);

        // Byte-for-byte the column set campaignBuilder() inserts.
        $boxId = DB::table('chat_boxes')->insertGetId([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'to' => '15551230001',
            'from' => 'SENDER',
            'ai_stage' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdChatBoxIds[] = $boxId;

        DB::table('ai_box_campaign_map')->insert([
            ['box_id' => $boxId, 'campaign_id' => $campaignId, 'created_at' => now()],
        ]);

        $this->assertSame(1, (int) DB::table('chat_boxes')->where('id', $boxId)->value('ai_stage'));
        $this->assertSame(
            1,
            DB::table('ai_box_campaign_map')->where('box_id', $boxId)->where('campaign_id', $campaignId)->count()
        );
    }

    public function test_a_chat_box_outside_the_state_machine_keeps_a_null_stage(): void
    {
        $boxId = $this->createChatBox();

        $this->assertNull(
            DB::table('chat_boxes')->where('id', $boxId)->value('ai_stage'),
            'An inbound-created chat box is not in the AI state machine, which is not the same as stage 0.'
        );
        $this->assertSame(
            0,
            (int) DB::table('chat_boxes')->where('id', $boxId)->value('ai_replied'),
            'A new chat box has not been AI-replied to.'
        );
    }

    // -----------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------

    public function test_deleting_a_campaign_removes_only_its_own_mapping_rows(): void
    {
        $userId = $this->createUser();
        $doomedCampaignId = $this->createCampaign($userId);
        $survivingCampaignId = $this->createCampaign($userId);

        $doomedBoxId = $this->createChatBox($userId);
        $survivingBoxId = $this->createChatBox($userId);

        DB::table('ai_box_campaign_map')->insert([
            ['box_id' => $doomedBoxId, 'campaign_id' => $doomedCampaignId, 'created_at' => now()],
            ['box_id' => $survivingBoxId, 'campaign_id' => $survivingCampaignId, 'created_at' => now()],
        ]);

        // campaignBuilder() deletes the campaign AFTER inserting map rows
        // when it finds no sendable subscribers. A restricted key would
        // turn that existing path into a constraint violation.
        DB::table('campaigns')->where('id', $doomedCampaignId)->delete();

        $this->assertSame(0, DB::table('ai_box_campaign_map')->where('campaign_id', $doomedCampaignId)->count());
        $this->assertSame(1, DB::table('ai_box_campaign_map')->where('campaign_id', $survivingCampaignId)->count());

        // The unrelated chat boxes themselves are untouched by a campaign delete.
        $this->assertSame(1, DB::table('chat_boxes')->where('id', $doomedBoxId)->count());
        $this->assertSame(1, DB::table('chat_boxes')->where('id', $survivingBoxId)->count());
    }

    public function test_deleting_a_chat_box_removes_only_its_own_mapping_rows(): void
    {
        $userId = $this->createUser();
        $campaignId = $this->createCampaign($userId);

        $doomedBoxId = $this->createChatBox($userId);
        $survivingBoxId = $this->createChatBox($userId);

        DB::table('ai_box_campaign_map')->insert([
            ['box_id' => $doomedBoxId, 'campaign_id' => $campaignId, 'created_at' => now()],
            ['box_id' => $survivingBoxId, 'campaign_id' => $campaignId, 'created_at' => now()],
        ]);

        // ClearChatbox and CustomerController both hard-delete chat boxes.
        DB::table('chat_boxes')->where('id', $doomedBoxId)->delete();

        $this->assertSame(0, DB::table('ai_box_campaign_map')->where('box_id', $doomedBoxId)->count());
        $this->assertSame(1, DB::table('ai_box_campaign_map')->where('box_id', $survivingBoxId)->count());

        // The campaign survives its mapped box being removed.
        $this->assertSame(1, DB::table('campaigns')->where('id', $campaignId)->count());
    }

    public function test_unrelated_chat_box_rows_are_unaffected_by_the_new_columns(): void
    {
        $userId = $this->createUser();
        $untouchedBoxId = $this->createChatBox($userId);

        $before = (array) DB::table('chat_boxes')->where('id', $untouchedBoxId)->first();

        // A second box goes through the full AI-prospecting lifecycle.
        $campaignId = $this->createCampaign($userId);
        $activeBoxId = $this->createChatBox($userId);
        DB::table('chat_boxes')->where('id', $activeBoxId)->update(['ai_stage' => 4, 'ai_replied' => 1]);
        DB::table('ai_box_campaign_map')->insert([
            ['box_id' => $activeBoxId, 'campaign_id' => $campaignId, 'created_at' => now()],
        ]);
        DB::table('chat_boxes')->where('id', $activeBoxId)->delete();

        $after = (array) DB::table('chat_boxes')->where('id', $untouchedBoxId)->first();

        $this->assertSame($before, $after, 'Neighbouring chat-box rows must survive the whole lifecycle byte-identical.');
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function columnDefinition(string $table, string $column): object
    {
        $definition = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->first(['COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT']);

        $this->assertNotNull($definition, "{$table}.{$column} must exist.");

        return $definition;
    }

    private function createUser(): int
    {
        $id = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Legacy',
            'last_name' => 'Schema',
            'email' => 'legacy-schema-' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createdUserIds[] = $id;

        return $id;
    }

    private function createCampaign(int $userId): int
    {
        $id = DB::table('campaigns')->insertGetId([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'campaign_name' => 'Legacy Schema Campaign ' . uniqid(),
            'sms_type' => 'plain',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createdCampaignIds[] = $id;

        return $id;
    }

    private function createChatBox(?int $userId = null): int
    {
        $userId ??= $this->createUser();

        $id = DB::table('chat_boxes')->insertGetId([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'to' => '1555123' . random_int(1000, 9999),
            'from' => 'SENDER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createdChatBoxIds[] = $id;

        return $id;
    }
}
