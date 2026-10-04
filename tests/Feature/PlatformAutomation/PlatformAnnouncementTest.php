<?php

namespace Tests\Feature\PlatformAutomation;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\PlatformAutomation\DeliverPlatformAnnouncementChunk;
use App\Library\PlatformAutomation\Announcements\PlatformAnnouncementAudience;
use App\Library\PlatformAutomation\Announcements\PlatformAnnouncementManager;
use App\Mail\PlatformAutomationMail;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformAnnouncementReceipt;
use App\Models\PlatformDatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

class PlatformAnnouncementTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function manager(): PlatformAnnouncementManager
    {
        return app(PlatformAnnouncementManager::class);
    }

    /** @param array<string, mixed> $overrides */
    private function announcement(array $overrides = []): PlatformAnnouncement
    {
        return $this->manager()->create($overrides + [
            'title' => 'Scheduled maintenance',
            'body' => 'We will be down briefly on Sunday.',
            'severity' => 'warning',
            'channels' => ['banner', 'notification', 'email'],
            'audience' => ['kind' => 'everyone'],
        ], $this->platformAdminId());
    }

    private function deliverAll(PlatformAnnouncement $announcement): void
    {
        Queue::assertPushed(DeliverPlatformAnnouncementChunk::class);
        Queue::pushed(DeliverPlatformAnnouncementChunk::class)->each(fn ($job) => $job->handle());
    }

    public function test_validation_refuses_an_unusable_announcement(): void
    {
        foreach ([
            ['title' => ''], ['body' => ''], ['channels' => []], ['severity' => 'loud'],
            ['audience' => ['kind' => 'tier', 'tier' => 'platinum']], ['audience' => ['kind' => 'workspaces', 'refs' => '']],
            ['publish_at' => '2030-01-02 10:00', 'expires_at' => '2030-01-01 10:00'],
        ] as $bad) {
            try {
                $this->announcement($bad);
                $this->fail('should have been refused: ' . json_encode($bad));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_audiences_resolve_to_real_customer_owners_only(): void
    {
        [$core, , ] = $this->tenant(WorkspacePlanTier::Core, 'Core Biz', 'Core WS');
        [$agency, , ] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Biz', 'Agency WS');
        $ids = fn (array $audience) => PlatformAnnouncementAudience::query($audience)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();

        $this->assertSame([$core->user_id, $agency->user_id], $ids(['kind' => 'everyone']), 'everyone is customers only: no Platform Owner or staff');
        $this->assertSame([$core->user_id], $ids(['kind' => 'tier', 'tier' => 'core']));
        $this->assertSame([$agency->user_id], $ids(['kind' => 'tier', 'tier' => 'agency']));
        $this->assertSame([], $ids(['kind' => 'tier', 'tier' => 'growth']));
        $this->assertSame([$core->user_id, $agency->user_id], $ids(['kind' => 'workspace_owners']));

        DB::table('platform_subscriptions')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'workspace_id' => DB::table('workspaces')->where('owner_user_id', $core->user_id)->value('id'),
            'workspace_plan_catalog_id' => DB::table('workspace_plan_catalog')->value('id'), 'local_idempotency_key' => 'k1', 'billing_cycle_snapshot' => 'monthly',
            'status' => 'trialing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame([$core->user_id], $ids(['kind' => 'trial']));

        $workspaceUid = DB::table('workspaces')->where('owner_user_id', $agency->user_id)->value('uid');
        $this->assertSame([$agency->user_id], $ids(['kind' => 'workspaces', 'refs' => $workspaceUid]));
    }

    public function test_publishing_delivers_each_channel_once_to_each_user_and_a_retried_chunk_never_doubles(): void
    {
        Mail::fake();
        [$a] = $this->tenant(WorkspacePlanTier::Growth, 'A Biz', 'A WS');
        [$b] = $this->tenant(WorkspacePlanTier::Growth, 'B Biz', 'B WS');
        $announcement = $this->manager()->publishNow($this->announcement());

        $this->assertSame('published', $announcement->status);
        $this->assertSame(2, $announcement->recipients_total);
        $this->deliverAll($announcement);
        $this->deliverAll($announcement); // the same chunks run again (a retry)

        $this->assertSame(6, PlatformAnnouncementReceipt::query()->count(), '2 users x 3 channels, once each');
        $this->assertSame(2, PlatformDatabaseNotification::query()->count());
        Mail::assertSent(PlatformAutomationMail::class, 2);
        $this->assertSame(2, $announcement->fresh()->recipients_done);
        $this->assertSame([$a->user_id, $b->user_id], PlatformDatabaseNotification::query()->pluck('notifiable_id')->map(fn ($i) => (int) $i)->sort()->values()->all());
    }

    public function test_a_large_audience_is_delivered_in_chunks(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->tenant(WorkspacePlanTier::Core, "Biz {$i}", "WS {$i}");
        }
        $this->assertSame(200, PlatformAnnouncementManager::CHUNK);

        $this->manager()->publishNow($this->announcement(['channels' => ['banner']]));

        Queue::assertPushed(DeliverPlatformAnnouncementChunk::class, 1);
        Queue::pushed(DeliverPlatformAnnouncementChunk::class)->each(fn ($job) => $this->assertCount(3, $job->userIds));
    }

    public function test_cancelling_stops_further_delivery_and_the_banner(): void
    {
        [$a] = $this->tenant(WorkspacePlanTier::Growth, 'A Biz', 'A WS');
        $announcement = $this->manager()->publishNow($this->announcement(['channels' => ['banner']]));
        $this->deliverAll($announcement);
        $this->assertCount(1, $this->manager()->bannersFor($a->user));

        $this->manager()->cancel($announcement);

        $this->assertCount(0, $this->manager()->bannersFor($a->user));
        $this->expectException(ValidationException::class);
        $this->manager()->cancel($announcement->fresh()); // already finished
    }

    public function test_banners_are_for_the_audience_only_dismissible_and_expire(): void
    {
        [$core] = $this->tenant(WorkspacePlanTier::Core, 'Core Biz', 'Core WS');
        [$agency] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Biz', 'Agency WS');
        $announcement = $this->manager()->publishNow($this->announcement([
            'channels' => ['banner'], 'audience' => ['kind' => 'tier', 'tier' => 'core'], 'expires_at' => now()->addDay(),
        ]));
        $this->deliverAll($announcement);

        $this->assertCount(1, $this->manager()->bannersFor($core->user));
        $this->assertCount(0, $this->manager()->bannersFor($agency->user), 'not in the audience');

        $this->assertTrue($this->manager()->dismiss($core->user, $announcement->uid));
        $this->assertCount(0, $this->manager()->bannersFor($core->user));

        // A different user cannot dismiss someone else's banner.
        $this->assertFalse($this->manager()->dismiss($agency->user, $announcement->uid));

        // Lapsed announcements expire on the next sweep.
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(['published' => 0, 'expired' => 1], $this->manager()->sweep());
        $this->assertSame('expired', $announcement->fresh()->status);
    }

    public function test_the_banner_disappears_the_moment_it_expires_without_waiting_for_the_sweep_and_the_feed_agrees(): void
    {
        [$a] = $this->tenant(WorkspacePlanTier::Growth, 'A Biz', 'A WS');
        $announcement = $this->manager()->publishNow($this->announcement(['channels' => ['banner'], 'expires_at' => now()->addMinutes(10)]));
        $this->deliverAll($announcement);
        $this->authenticateAs($a);

        $this->assertCount(1, $this->getJson(route('customer.platform-notices.feed'))->json('banners'));

        Carbon::setTestNow(now()->addMinutes(11));   // expiry passes; no sweep has run

        $this->assertSame('published', $announcement->fresh()->status, 'the sweep has not touched it');
        $this->assertCount(0, $this->manager()->bannersFor($a->user));
        $this->assertSame([], $this->getJson(route('customer.platform-notices.feed'))->json('banners'));
    }

    public function test_cancelling_removes_the_banner_from_the_next_feed_read(): void
    {
        [$a] = $this->tenant(WorkspacePlanTier::Growth, 'A Biz', 'A WS');
        $announcement = $this->manager()->publishNow($this->announcement(['channels' => ['banner']]));
        $this->deliverAll($announcement);
        $this->authenticateAs($a);
        $this->assertCount(1, $this->getJson(route('customer.platform-notices.feed'))->json('banners'));

        $this->manager()->cancel($announcement);

        $this->assertSame([], $this->getJson(route('customer.platform-notices.feed'))->json('banners'));
    }

    public function test_a_scheduled_announcement_publishes_when_due_and_not_before(): void
    {
        $this->tenant(WorkspacePlanTier::Core, 'Core Biz', 'Core WS');
        $announcement = $this->manager()->create([
            'title' => 'Later', 'body' => 'b', 'severity' => 'info', 'channels' => ['banner'], 'audience' => ['kind' => 'everyone'],
            'publish_at' => now()->addHours(2),
        ], $this->platformAdminId());
        $this->manager()->schedule($announcement);

        $this->assertSame(['published' => 0, 'expired' => 0], $this->manager()->sweep());
        $this->assertSame('scheduled', $announcement->fresh()->status);

        Carbon::setTestNow(now()->addHours(3));
        $this->assertSame(['published' => 1, 'expired' => 0], $this->manager()->sweep());
        $this->assertSame('published', $announcement->fresh()->status);
        $this->assertSame(['published' => 0, 'expired' => 0], $this->manager()->sweep(), 'idempotent');
    }

    public function test_the_customer_feed_returns_only_the_signed_in_users_banner_and_notices(): void
    {
        [$a] = $this->tenant(WorkspacePlanTier::Growth, 'A Biz', 'A WS');
        [$b] = $this->tenant(WorkspacePlanTier::Growth, 'B Biz', 'B WS');
        $announcement = $this->manager()->publishNow($this->announcement(['channels' => ['banner', 'notification'], 'audience' => ['kind' => 'workspaces', 'refs' => (string) DB::table('workspaces')->where('owner_user_id', $a->user_id)->value('id')]]));
        $this->deliverAll($announcement);

        $this->authenticateAs($a);
        $mine = $this->getJson(route('customer.platform-notices.feed'))->assertOk()->json();
        $this->assertSame('Scheduled maintenance', $mine['banners'][0]['title']);
        $this->assertCount(1, $mine['notices']);

        $this->authenticateAs($b);
        $theirs = $this->getJson(route('customer.platform-notices.feed'))->assertOk()->json();
        $this->assertSame([], $theirs['banners']);
        $this->assertSame([], $theirs['notices']);

        // B cannot mark A's notice read.
        $noticeId = PlatformDatabaseNotification::query()->where('notifiable_id', $a->user_id)->value('id');
        $this->postJson(route('customer.platform-notices.read', $noticeId))->assertOk();
        $this->assertNull(PlatformDatabaseNotification::query()->find($noticeId)->read_at);
    }
}
