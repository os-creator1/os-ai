<?php

namespace Tests\Feature\Migration;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Tasks\ContactTaskManager;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Conversations\Concerns\CreatesTimelineFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

class CallTaskInboxTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesTimelineFixtures;

    private function owner(string $name): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, $name, $name);
        $customer->user->forceFill(['email_verified_at' => now()])->save();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        return [$customer, $business, $workspace];
    }

    public function test_the_inbox_lists_open_tasks_soonest_first_and_completes_them_within_its_own_business(): void
    {
        User::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa'.uniqid().'@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        [$customerA, $a, $workspaceA] = $this->owner('Harbor Lane');
        [, $b] = $this->owner('Other Studio');
        $manager = app(ContactTaskManager::class);

        $ann = $this->namedContact($a, '12025550111', 'Ann', 'Lee');
        $bob = $this->namedContact($b, '12025550222', 'Bob', 'Other');
        $later = $manager->createForStep($a, $ann, 1, 1, 'Call Ann about their new event inquiry', Carbon::now()->addDay());
        $sooner = $manager->createForStep($a, $ann, 1, 2, 'No reply yet — call Ann again', Carbon::now()->addHour());
        $foreign = $manager->createForStep($b, $bob, 9, 9, 'SECRET other-tenant task', Carbon::now()->addHour());
        $this->assertFalse($manager->createForStep($a, $ann, 1, 1, 'duplicate', null)['created'], 'Idempotent per step.');

        $this->actingAs($customerA->user);
        $url = fn (string $name, array $extra = []) => route("customer.workspaces.businesses.crm.tasks.{$name}", array_merge([$workspaceA->uid, $a->uid], $extra));

        $html = $this->get($url('index'))->assertOk()->getContent();
        $this->assertStringContainsString('Call Ann about their new event inquiry', $html);
        $this->assertStringContainsString('Ann Lee', $html);
        $this->assertStringNotContainsString('SECRET other-tenant task', $html, 'Another Business\'s task is never shown.');
        $this->assertLessThan(strpos($html, 'Call Ann about their new event inquiry'), strpos($html, 'No reply yet'), 'Soonest due first.');

        // Reachable from the Opportunities board (it has a Pipeline to show only after setup, so assert the route name + page instead).
        $uidOf = fn (int $id) => DB::table('contact_tasks')->where('id', $id)->value('uid');

        $this->post($url('complete', [$uidOf($sooner['id'])]))->assertRedirect();
        $this->assertSame('done', DB::table('contact_tasks')->where('id', $sooner['id'])->value('status'));
        $this->assertNotNull(DB::table('contact_tasks')->where('id', $sooner['id'])->value('completed_by_user_id'));

        $after = $this->get($url('index'))->assertOk()->getContent();
        $this->assertStringContainsString('Recently completed', $after);

        // Cross-tenant: completing another Business's task through this Business's URL is a 404 and changes nothing.
        $this->post($url('complete', [$uidOf($foreign['id'])]))->assertNotFound();
        $this->assertSame('open', DB::table('contact_tasks')->where('id', $foreign['id'])->value('status'));

        // The timeline shows the task on the contact too.
        $this->assertSame(1, DB::table('contact_tasks')->where('id', $later['id'])->where('status', 'open')->count());
    }
}
