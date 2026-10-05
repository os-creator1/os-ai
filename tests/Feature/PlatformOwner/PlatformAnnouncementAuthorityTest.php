<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformOwner\PlatformAnnouncementStatus;
use App\Jobs\PlatformAutomation\DeliverPlatformAnnouncementChunk;
use App\Library\PlatformAutomation\Announcements\CanonicalPlatformAnnouncementDelivery;
use App\Library\PlatformAutomation\Announcements\PlatformAnnouncementManager as CanonicalManager;
use App\Library\PlatformOwner\Announcements\PlatformAnnouncementDelivery;
use App\Library\PlatformOwner\Announcements\PlatformAnnouncementManager;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformDatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * There is exactly ONE announcement authority. The Platform Owner's manager is a façade over the
 * canonical (Platform Automations) manager, the delivery seam is bound to the canonical delivery
 * runtime, and the owner's publish / schedule / cancel actions therefore reach real customers'
 * banners and notices.
 */
class PlatformAnnouncementAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
        Queue::fake();
    }

    private function draft(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Plan change notice', 'body' => 'We are updating plans next month.',
            'audience' => 'all', 'channels' => ['in_app'],
        ];
    }

    private function runChunks(): void
    {
        Queue::pushed(DeliverPlatformAnnouncementChunk::class)->each(fn ($job) => $job->handle());
    }

    public function test_the_delivery_seam_is_bound_to_the_canonical_delivery_runtime(): void
    {
        $this->assertInstanceOf(CanonicalPlatformAnnouncementDelivery::class, app(PlatformAnnouncementDelivery::class));
    }

    public function test_there_is_one_announcement_table_in_the_canonical_shape(): void
    {
        foreach (['severity', 'channels', 'audience', 'publish_at', 'recipients_total', 'delivery_ref', 'created_by_user_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('platform_announcements', $column), $column);
        }
        foreach (['audience_tiers', 'scheduled_at', 'created_by'] as $legacy) {
            $this->assertFalse(Schema::hasColumn('platform_announcements', $legacy), "{$legacy} belongs to the retired shape");
        }
        $this->assertTrue(Schema::hasTable('platform_announcement_receipts'));
    }

    public function test_an_owner_publish_through_the_facade_reaches_the_right_customers_banner_and_notice(): void
    {
        [$growth] = $this->tenant(WorkspacePlanTier::Growth, 'Growth Biz', 'Growth WS');
        [$agency] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Biz', 'Agency WS');
        [$core] = $this->tenant(WorkspacePlanTier::Core, 'Core Biz', 'Core WS');
        $owner = $this->actingAsPlatformOwner();
        $facade = app(PlatformAnnouncementManager::class);

        $a = $facade->createDraft($owner->id, $this->draft(['audience' => 'tiers', 'audience_tiers' => ['agency', 'growth']]));
        $this->assertSame(['kind' => 'tiers', 'tiers' => ['agency', 'growth']], $a->audience);
        $this->assertSame(['banner', 'notification'], $a->channels, '"In the app" is banner + notification');

        $a = $facade->publishNow($owner->id, $a);

        $this->assertSame(PlatformAnnouncementStatus::Published, $a->status);
        $this->assertSame(2, $a->recipients_total, 'Growth and Agency owners, not Core');
        $this->assertNotNull($a->delivery_ref, 'the delivery seam returned its reference');
        $this->runChunks();

        $canonical = app(CanonicalManager::class);
        $this->assertCount(1, $canonical->bannersFor($agency->user));
        $this->assertCount(1, $canonical->bannersFor($growth->user));
        $this->assertCount(0, $canonical->bannersFor($core->user));
        $this->assertSame(2, PlatformDatabaseNotification::query()->count());
    }

    public function test_cancelling_through_the_facade_removes_the_banner_at_once(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Biz', 'WS');
        $owner = $this->actingAsPlatformOwner();
        $facade = app(PlatformAnnouncementManager::class);

        $a = $facade->publishNow($owner->id, $facade->createDraft($owner->id, $this->draft()));
        $this->runChunks();
        $canonical = app(CanonicalManager::class);
        $this->assertCount(1, $canonical->bannersFor($customer->user));

        $facade->cancel($owner->id, $a);

        $this->assertCount(0, $canonical->bannersFor($customer->user));
        $this->assertSame(PlatformAnnouncementStatus::Cancelled, $a->fresh()->status);
    }

    public function test_a_scheduled_announcement_is_published_by_the_one_sweep_through_the_same_delivery(): void
    {
        $this->tenant(WorkspacePlanTier::Growth, 'Biz', 'WS');
        $owner = $this->actingAsPlatformOwner();
        $facade = app(PlatformAnnouncementManager::class);

        $a = $facade->createDraft($owner->id, $this->draft());
        $a = $facade->schedule($owner->id, $a, now()->addHour());
        $this->assertSame(PlatformAnnouncementStatus::Scheduled, $a->status);
        $this->assertSame(0, $facade->sweepDue(), 'not due yet');

        \Illuminate\Support\Carbon::setTestNow(now()->addHours(2));
        $this->artisan('platform-announcements:sweep')->assertExitCode(0);   // the scheduler's own command
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertSame(PlatformAnnouncementStatus::Published, $a->fresh()->status);
        $this->assertSame(0, app(CanonicalManager::class)->sweep()['published'], 'idempotent');
        Queue::assertPushed(DeliverPlatformAnnouncementChunk::class);
    }

    public function test_the_platform_automations_action_and_the_owner_form_share_the_same_rows(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Biz', 'WS');
        $owner = $this->actingAsPlatformOwner(['access backend', 'view announcement']);

        app(PlatformAnnouncementManager::class)->createDraft($owner->id, $this->draft(['title' => 'From the owner form']));
        app(CanonicalManager::class)->create([
            'title' => 'From an automation', 'body' => 'b', 'severity' => 'info', 'channels' => ['banner'],
            'audience' => ['kind' => 'businesses', 'refs' => [(string) $business->id]],
        ], $owner->id);

        $this->assertSame(['From the owner form', 'From an automation'], PlatformAnnouncement::query()->orderBy('id')->pluck('title')->all());
        $this->get(route('admin.platform-announcements.index'))->assertOk()->assertSee('From the owner form')->assertSee('From an automation');
    }
}
