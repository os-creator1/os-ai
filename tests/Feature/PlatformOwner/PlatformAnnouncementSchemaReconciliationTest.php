<?php

namespace Tests\Feature\PlatformOwner;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Automations\Concerns\UsesFreshSchema;
use Tests\TestCase;

/**
 * A database that already holds the EARLIER Platform Owner shape of platform_announcements is upgraded
 * in place to the canonical shape: every row survives, converted. Uses the repo's fresh-schema pattern
 * because the test performs real DDL (which would implicitly commit a wrapping transaction).
 */
class PlatformAnnouncementSchemaReconciliationTest extends TestCase
{
    use UsesFreshSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFreshSchema();
    }

    private function reconcile(): void
    {
        (include base_path('database/migrations/2026_11_05_100001_reconcile_platform_announcements_schema.php'))->up();
    }

    private function recreateLegacyShape(): int
    {
        $adminId = DB::table('users')->insertGetId([
            'uid' => uniqid(), 'first_name' => 'Pat', 'last_name' => 'Owner', 'email' => 'legacy-owner@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::dropIfExists('platform_announcement_receipts');
        Schema::dropIfExists('platform_announcements');
        Schema::create('platform_announcements', function ($table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('title', 160);
            $table->text('body');
            $table->string('status', 16)->default('draft');
            $table->string('audience', 16)->default('all');
            $table->json('audience_tiers')->nullable();
            $table->json('channels');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivery_ref', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
        });

        return $adminId;
    }

    public function test_a_legacy_shaped_table_is_converted_in_place_and_no_row_is_lost(): void
    {
        $admin = $this->recreateLegacyShape();
        $at = now()->addDay()->startOfMinute();

        foreach ([
            ['uid' => 'a0000000-0000-4000-8000-000000000001', 'title' => 'Everyone, in app + email', 'body' => 'b1', 'status' => 'published',
                'audience' => 'all', 'audience_tiers' => null, 'channels' => json_encode(['in_app', 'email']), 'scheduled_at' => null,
                'published_at' => now(), 'created_by' => $admin, 'delivery_ref' => 'ref-1', 'created_at' => now(), 'updated_at' => now()],
            ['uid' => 'a0000000-0000-4000-8000-000000000002', 'title' => 'Two plans, scheduled', 'body' => 'b2', 'status' => 'scheduled',
                'audience' => 'tiers', 'audience_tiers' => json_encode(['growth', 'agency']), 'channels' => json_encode(['email']), 'scheduled_at' => $at,
                'created_by' => $admin, 'created_at' => now(), 'updated_at' => now()],
            ['uid' => 'a0000000-0000-4000-8000-000000000003', 'title' => 'Cancelled draft', 'body' => 'b3', 'status' => 'cancelled',
                'audience' => 'all', 'channels' => json_encode(['in_app']), 'cancelled_at' => now(), 'created_by' => $admin, 'created_at' => now(), 'updated_at' => now()],
        ] as $legacyRow) {
            DB::table('platform_announcements')->insert($legacyRow);
        }

        $this->reconcile();

        $this->assertSame(3, DB::table('platform_announcements')->count(), 'no row is dropped');
        foreach (['severity', 'publish_at', 'recipients_total', 'created_by_user_id', 'delivery_ref'] as $column) {
            $this->assertTrue(Schema::hasColumn('platform_announcements', $column), $column);
        }
        foreach (['audience_tiers', 'scheduled_at', 'created_by', 'updated_by', 'cancelled_at'] as $retired) {
            $this->assertFalse(Schema::hasColumn('platform_announcements', $retired), $retired);
        }

        $rows = DB::table('platform_announcements')->orderBy('id')->get()->keyBy('title');

        $everyone = $rows['Everyone, in app + email'];
        $this->assertSame(['kind' => 'everyone'], json_decode($everyone->audience, true));
        $this->assertSame(['banner', 'notification', 'email'], json_decode($everyone->channels, true));
        $this->assertSame('published', $everyone->status);
        $this->assertSame('ref-1', $everyone->delivery_ref, 'the delivery reference survives');
        $this->assertSame($admin, (int) $everyone->created_by_user_id);

        $scheduled = $rows['Two plans, scheduled'];
        $this->assertSame(['kind' => 'tiers', 'tiers' => ['growth', 'agency']], json_decode($scheduled->audience, true));
        $this->assertSame(['email'], json_decode($scheduled->channels, true));
        $this->assertSame($at->format('Y-m-d H:i:s'), (string) $scheduled->publish_at, 'scheduled_at became publish_at');

        $this->assertSame('cancelled', $rows['Cancelled draft']->status);
    }

    public function test_the_conversion_is_idempotent_and_a_canonical_table_is_left_alone(): void
    {
        $this->recreateLegacyShape();
        $this->reconcile();
        $before = DB::select('show create table platform_announcements')[0]->{'Create Table'};

        $this->reconcile();   // second run: already canonical

        $this->assertSame($before, DB::select('show create table platform_announcements')[0]->{'Create Table'});
    }

    public function test_the_runtime_works_on_a_converted_table(): void
    {
        $admin = $this->recreateLegacyShape();
        DB::table('platform_announcements')->insert([
            'uid' => 'a0000000-0000-4000-8000-0000000000aa', 'title' => 'Old row', 'body' => 'b', 'status' => 'draft', 'audience' => 'all',
            'channels' => json_encode(['in_app']), 'created_by' => $admin, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->reconcile();
        // The receipts ledger is created by the canonical migration when it is absent.
        if (! Schema::hasTable('platform_announcement_receipts')) {
            Schema::create('platform_announcement_receipts', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('announcement_id');
                $table->unsignedBigInteger('user_id');
                $table->string('channel', 16);
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamps();
                $table->unique(['announcement_id', 'user_id', 'channel'], 'parc_unique');
            });
        }

        $model = \App\Models\PlatformAnnouncement::query()->where('title', 'Old row')->sole();

        $this->assertSame(\App\Enums\PlatformOwner\PlatformAnnouncementStatus::Draft, $model->status);
        $this->assertSame('all', $model->audienceMode());
        $this->assertSame(['in_app'], $model->uiChannels());
    }
}
