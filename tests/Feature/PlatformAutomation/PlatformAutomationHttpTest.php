<?php

namespace Tests\Feature\PlatformAutomation;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/** The Platform Owner UI: access boundary and the create / edit / enable / duplicate / announce journeys. */
class PlatformAutomationHttpTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
        Queue::fake();
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Welcome nudge',
            'description' => 'Say hello',
            'trigger_type' => 'workspace.created',
            'steps' => [['action' => 'send_in_app_notification', 'params' => ['recipient' => 'workspace_owner', 'title' => 'Welcome', 'message' => 'Hi {{user.first_name}}']]],
        ];
    }

    public function test_only_a_platform_owner_reaches_these_pages(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Biz', 'WS');
        $this->authenticateAs($customer);

        foreach (['platform-automations', 'platform-automations/create', 'platform-announcements', 'platform-announcements/create'] as $path) {
            $this->assertContains($this->get(url(config('app.admin_path') . '/' . $path))->status(), [401, 403, 404], $path);
        }
        $this->assertContains($this->post(url(config('app.admin_path') . '/platform-automations'), $this->payload())->status(), [401, 403, 404]);
        $this->assertSame(0, PlatformAutomation::query()->count());
    }

    public function test_the_owner_journey_create_edit_enable_duplicate_and_the_four_tabs(): void
    {
        $this->actingAsPlatformOwner();

        foreach (['automations', 'recipes', 'runs', 'failures'] as $tab) {
            $this->get(route('admin.platform-automations.index', ['tab' => $tab]))->assertOk();
        }

        $this->post(route('admin.platform-automations.store'), $this->payload())->assertRedirect();
        $automation = PlatformAutomation::query()->sole();
        $this->assertSame('draft', $automation->status->value);

        $this->get(route('admin.platform-automations.edit', $automation))->assertOk()->assertSee('Welcome nudge');

        $this->put(route('admin.platform-automations.update', $automation), $this->payload(['name' => 'Welcome nudge v2']))->assertRedirect();
        $this->assertSame('Welcome nudge v2', $automation->fresh()->name);

        // An invalid definition is refused with its reasons, and nothing is saved.
        $this->from(route('admin.platform-automations.edit', $automation))
            ->put(route('admin.platform-automations.update', $automation), $this->payload(['steps' => [['action' => 'webhook', 'params' => ['url' => 'http://insecure.example']]]]))
            ->assertSessionHasErrors('definition');
        $this->assertSame('send_in_app_notification', $automation->fresh()->definition['steps'][0]['action']);

        $this->post(route('admin.platform-automations.enable', $automation))->assertRedirect();
        $this->assertSame('enabled', $automation->fresh()->status->value);

        $this->post(route('admin.platform-automations.duplicate', $automation))->assertRedirect();
        $this->assertSame(2, PlatformAutomation::query()->count());
        $copy = PlatformAutomation::query()->where('id', '!=', $automation->id)->sole();
        $this->assertSame('draft', $copy->status->value);

        $this->post(route('admin.platform-automations.disable', $automation))->assertRedirect();
        $this->assertSame('disabled', $automation->fresh()->status->value);
    }

    public function test_a_recipe_is_copied_not_run_and_the_unavailable_one_is_refused(): void
    {
        $this->actingAsPlatformOwner();

        $this->post(route('admin.platform-automations.recipes.use', 'trial_ending_reminder'))->assertRedirect();
        $automation = PlatformAutomation::query()->sole();
        $this->assertSame('draft', $automation->status->value);
        $this->assertSame('trial_ending_reminder', $automation->recipe_key);

        $this->from(route('admin.platform-automations.index', ['tab' => 'recipes']))
            ->post(route('admin.platform-automations.recipes.use', 'invitation_reminder'))
            ->assertSessionHasErrors('recipe');
        $this->assertSame(1, PlatformAutomation::query()->count());
    }

    public function test_announcement_journey_draft_schedule_publish_and_cancel(): void
    {
        $this->tenant(WorkspacePlanTier::Core, 'Biz', 'WS');
        $this->actingAsPlatformOwner();
        $form = fn (array $o = []) => $o + [
            'title' => 'Heads up', 'body' => 'Maintenance Sunday.', 'severity' => 'info', 'channels' => ['banner', 'notification'],
            'audience' => ['kind' => 'tier', 'tier' => 'core'],
        ];

        $this->get(route('admin.platform-announcements.create'))->assertOk();

        $this->post(route('admin.platform-announcements.store'), $form(['mode' => 'draft']))->assertRedirect(route('admin.platform-announcements.index'));
        $draft = PlatformAnnouncement::query()->sole();
        $this->assertSame('draft', $draft->status);

        $this->put(route('admin.platform-announcements.update', $draft), $form(['mode' => 'schedule', 'publish_at' => now()->addDay()->format('Y-m-d\TH:i')]))->assertRedirect();
        $this->assertSame('scheduled', $draft->fresh()->status);

        $this->post(route('admin.platform-announcements.store'), $form(['mode' => 'publish', 'title' => 'Now']))->assertRedirect();
        $now = PlatformAnnouncement::query()->where('title', 'Now')->sole();
        $this->assertSame('published', $now->status);
        $this->assertSame(1, $now->recipients_total);

        // Scheduling without a time is refused.
        $this->from(route('admin.platform-announcements.create'))
            ->post(route('admin.platform-announcements.store'), $form(['mode' => 'schedule', 'title' => 'No time']))
            ->assertSessionHasErrors('publish_at');

        $this->post(route('admin.platform-announcements.cancel', $now))->assertRedirect();
        $this->assertSame('cancelled', $now->fresh()->status);
        $this->get(route('admin.platform-announcements.index'))->assertOk()->assertSee('Heads up');
    }
}
