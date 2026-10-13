<?php

namespace Tests\Feature\Documents;

use App\Events\DocumentSent;
use App\Models\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * The inherited Ultimate SMS notification feed is gone from every
 * customer-visible surface; MotionGrove's own document/payment activity stays,
 * re-authorized as before, and the bell's number matches what it lists.
 */
class TopbarNotificationsCleanupTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    private function legacy(int $userId, string $message = 'Old SMS platform message'): Notifications
    {
        return Notifications::create([
            'user_id' => $userId, 'notification_for' => 'customer', 'notification_type' => 'chatbox', 'message' => $message,
        ]);
    }

    private function home(array $tenant): string
    {
        $this->authenticateAs($tenant['customer']);

        return $this->get(route('user.home'))->assertOk()->getContent();
    }

    public function test_a_user_with_only_legacy_rows_sees_an_empty_bell(): void
    {
        $tenant = $this->sendableTenant();
        $this->legacy($tenant['customer']->user_id);
        $this->legacy($tenant['customer']->user_id, 'Another old one');

        $html = $this->home($tenant);

        $this->assertStringContainsString('data-role="topbar-notifications"', $html);
        $this->assertStringContainsString('No new notifications.', $html);
        $this->assertStringNotContainsString('data-role="notification-unread-count"', $html);
        $this->assertStringNotContainsString('data-role="notification-unread-dot"', $html);
        $this->assertStringNotContainsString('Old SMS platform message', $html);
        $this->assertStringNotContainsString('Another old one', $html);
        $this->assertSame(2, Notifications::where('user_id', $tenant['customer']->user_id)->count(), 'history is preserved');
    }

    public function test_a_user_with_only_current_activity_sees_it_with_a_matching_count(): void
    {
        $tenant = $this->sendableTenant();
        DocumentSent::dispatch($this->draftDocument($tenant)->id, 1, 1);

        $html = $this->home($tenant);

        $this->assertStringContainsString('Your proposal was sent.', $html);
        $this->assertStringContainsString('data-role="notification-unread-count" data-count="1"', $html);
        $this->assertStringContainsString('data-role="notification-unread-dot"', $html);
        $this->assertStringNotContainsString('No new notifications.', $html);
    }

    public function test_mixed_records_show_only_current_activity_and_count_only_it(): void
    {
        $tenant = $this->sendableTenant();
        $this->legacy($tenant['customer']->user_id);
        $this->legacy($tenant['customer']->user_id, 'Legacy two');
        $this->legacy($tenant['customer']->user_id, 'Legacy three');
        DocumentSent::dispatch($this->draftDocument($tenant)->id, 1, 1);

        $html = $this->home($tenant);

        $this->assertStringContainsString('data-role="notification-unread-count" data-count="1"', $html);
        $this->assertSame(1, substr_count($html, 'Your proposal was sent.'));
        $this->assertStringNotContainsString('Old SMS platform message', $html);
        $this->assertStringNotContainsString('Legacy two', $html);
        $this->assertStringContainsString('1 new', $html);
    }

    public function test_zero_notifications_shows_the_empty_state_and_no_badge(): void
    {
        $html = $this->home($this->sendableTenant());

        $this->assertStringContainsString('No new notifications.', $html);
        $this->assertStringContainsString('0 new', $html);
        $this->assertStringNotContainsString('data-role="notification-unread-dot"', $html);
    }

    public function test_the_count_always_equals_the_number_of_rows_listed(): void
    {
        $tenant = $this->sendableTenant();

        for ($i = 0; $i < 12; $i++) {
            DocumentSent::dispatch($this->draftDocument($tenant, ['title' => "Proposal $i"])->id, 1, 1);
        }

        $html = $this->home($tenant);

        $this->assertSame(10, substr_count($html, 'Your proposal was sent.'));
        $this->assertStringContainsString('data-role="notification-unread-count" data-count="10"', $html);
    }

    public function test_no_notification_leaks_between_accounts(): void
    {
        $mine = $this->sendableTenant('Mine Studio');
        $theirs = $this->sendableTenant('Theirs Studio');
        $this->legacy($theirs['customer']->user_id, 'THEIR LEGACY');
        DocumentSent::dispatch($this->draftDocument($theirs, ['title' => 'Their secret proposal'])->id, 1, 1);

        $html = $this->home($mine);

        $this->assertStringContainsString('No new notifications.', $html);
        $this->assertStringNotContainsString('Their secret proposal', $html);
        $this->assertStringNotContainsString('THEIR LEGACY', $html);
        $this->assertStringNotContainsString('data-role="notification-unread-count"', $html);
    }

    public function test_the_profile_page_no_longer_offers_the_legacy_notification_list(): void
    {
        $tenant = $this->sendableTenant();
        $this->legacy($tenant['customer']->user_id);
        $this->authenticateAs($tenant['customer']);

        $html = $this->get(route('user.account'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="notification-tab"', $html);
        $this->assertStringNotContainsString('bulk-read', $html);
        $this->assertStringNotContainsString('Old SMS platform message', $html);
    }

    public function test_the_retired_legacy_endpoints_list_and_change_nothing_and_cannot_touch_others_rows(): void
    {
        $mine = $this->sendableTenant('Mine Studio');
        $theirs = $this->sendableTenant('Theirs Studio');
        $mineRow = $this->legacy($mine['customer']->user_id);
        $theirRow = $this->legacy($theirs['customer']->user_id, 'THEIRS');
        $this->authenticateAs($mine['customer']);

        $this->postJson(route('user.account.notifications'), ['draw' => 3, 'start' => 0, 'length' => 10, 'order' => [['column' => 3, 'dir' => 'asc']]])
            ->assertOk()->assertJsonPath('recordsTotal', 0)->assertJsonPath('data', []);
        $this->postJson(route('user.account.notifications.toggle', $theirRow->uid))->assertOk()->assertJsonPath('status', 'error');
        $this->postJson(route('user.account.notifications.delete', $mineRow->uid))->assertOk()->assertJsonPath('status', 'error');
        $this->postJson(route('user.account.notifications.batch_action'), ['action' => 'destroy', 'ids' => [$mineRow->uid, $theirRow->uid]])
            ->assertOk()->assertJsonPath('status', 'error');

        $this->assertSame(0, (int) $mineRow->fresh()->mark_read);
        $this->assertSame(0, (int) $theirRow->fresh()->mark_read);
        $this->assertSame(2, Notifications::count(), 'nothing was deleted');
    }
}
