<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Assistant\AssistantActionService;
use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantToolRegistry;
use App\Library\Navigation\CustomerContext;
use App\Library\ViewAs\ViewAsContext;
use App\Models\AssistantActionAudit;
use App\Models\AssistantActionProposal;
use App\Models\BookingType;
use App\Models\BusinessLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * PR #460 review, P1: an action is never prepared while an agency is viewing as a client, and a proposal prepared with direct
 * access must not be carried across into a view-as session. The prohibition is enforced again at approval, for every action tool.
 */
class AssistantViewAsApprovalTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    private AssistantContext $direct;

    private AssistantContext $viewAs;

    private AssistantActionService $svc;

    private $workspace;

    private $business;

    private $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();

        [$this->customer, $this->business, $this->workspace] = $this->tenant(WorkspacePlanTier::Growth, 'ViewAs Venue', 'ViewAs Account');
        $this->authenticateAs($this->customer);

        $shell = app(\App\Library\Navigation\CustomerShellComposer::class);
        $customerContext = $shell->currentContext($this->customer->user);
        $entitlements = $shell->currentMenuEntitlements($customerContext);
        $location = BusinessLocation::query()->where('business_id', $this->business->id)->firstOrFail();
        $actions = app(\App\Library\Dashboard\Home\HomeActionRouter::class)->forActor($customerContext, $this->customer->user, $entitlements, [$this->workspace->uid, $this->business->uid], $location->uid);
        $scoped = [$this->workspace->uid, $this->business->uid];

        $this->direct = new AssistantContext($this->workspace, $this->business, $this->customer->user, $customerContext, $entitlements, $actions, $scoped);

        // The SAME actor, Workspace and Business — but now inside an agency "view as client" session.
        $now = CarbonImmutable::now();
        $viewAsCustomer = new CustomerContext(
            $customerContext->userId, $customerContext->frame, $customerContext->workspaces, $customerContext->selectedWorkspace,
            $customerContext->selectedBusiness, $customerContext->source,
            new ViewAsContext(1, (string) Str::uuid(), (int) $this->customer->user_id, 'Agency Owner', (int) $this->workspace->id, $this->workspace->uid, (int) $this->business->id, $this->business->uid, (string) $this->business->name, $now, $now->addHour()),
            $customerContext->preferenceCleared,
        );
        $this->viewAs = new AssistantContext($this->workspace, $this->business, $this->customer->user, $viewAsCustomer, $entitlements, $actions, $scoped);
        $this->assertFalse($this->direct->viewingAsClient());
        $this->assertTrue($this->viewAs->viewingAsClient());

        $this->svc = app(AssistantActionService::class);
    }

    private function prepareBookingType(string $name): AssistantActionProposal
    {
        $this->modelSays(['action' => 'propose', 'tool' => 'create_booking_type', 'args' => ['name' => $name, 'duration_minutes' => 90], 'message' => 'Ready.']);
        $reply = $this->lastReply($this->say($this->workspace, $this->business, 'Create a booking type called ' . $name)->assertOk());

        return AssistantActionProposal::query()->where('uid', $reply['proposal']['uid'])->firstOrFail();
    }

    public function test_a_booking_type_prepared_with_direct_access_cannot_be_approved_after_switching_into_view_as(): void
    {
        $proposal = $this->prepareBookingType('Direct package');
        $before = BookingType::query()->count();

        $result = $this->svc->approve($this->viewAs, $proposal->uid);

        $this->assertNotSame('completed', $result['state']);
        $this->assertSame($before, BookingType::query()->count(), 'No domain effect.');
        $fresh = $proposal->fresh();
        $this->assertNotSame('completed', $fresh->status);
        $this->assertNull($fresh->executed_at);
        $this->assertNull($fresh->lease_token, 'Nothing was ever claimed for execution.');
        $this->assertStringContainsString('viewing as a client', (string) $result['message'], 'An accurate refusal, not a generic failure.');
        $this->assertSame(0, AssistantActionAudit::query()->where('proposal_id', $proposal->id)->whereIn('event', ['executed', 'handed_off', 'approved'])->count());
    }

    public function test_the_refusal_does_not_leak_the_proposal_or_widen_anything_and_a_replay_is_still_refused(): void
    {
        $proposal = $this->prepareBookingType('Leak check');
        $first = $this->svc->approve($this->viewAs, $proposal->uid);
        $again = $this->svc->approve($this->viewAs, $proposal->uid);

        $this->assertSame($first['state'], $again['state']);
        $this->assertSame(0, BookingType::query()->where('name', 'Leak check')->count());
        $this->assertStringNotContainsString('Leak check', (string) $first['message'], 'The refusal repeats nothing from the prepared action.');

        // Switching back does not resurrect it: the safe course is to ask again from the right context.
        $afterwards = $this->svc->approve($this->direct, $proposal->uid);
        $this->assertNotSame('completed', $afterwards['state']);
        $this->assertSame(0, BookingType::query()->where('name', 'Leak check')->count());
    }

    public function test_an_appointment_prepared_with_direct_access_is_not_booked_from_view_as(): void
    {
        $location = BusinessLocation::query()->where('business_id', $this->business->id)->firstOrFail();
        DB::table('contacts')->insert([
            'uid' => (string) Str::uuid(), 'customer_id' => $this->business->customer_id, 'business_id' => $this->business->id,
            'location_id' => $location->id, 'group_id' => $this->contactGroup($this->business)->id, 'phone' => '15557770000', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->modelSays(['action' => 'propose', 'tool' => 'create_appointment', 'args' => ['contact' => '15557770000', 'date' => '2026-09-14', 'time' => '10:00'], 'message' => 'Here is the booking.']);
        $reply = $this->lastReply($this->say($this->workspace, $this->business, 'Book an appointment for Monday at 10 for 15557770000')->assertOk());
        $uid = $reply['proposal']['uid'];

        $result = $this->svc->approve($this->viewAs, $uid);

        $this->assertNotSame('completed', $result['state']);
        $this->assertSame(0, DB::table('appointments')->count());
        $this->assertStringContainsString('viewing as a client', (string) $result['message']);
    }

    public function test_every_registered_action_tool_is_refused_from_view_as(): void
    {
        $registry = app(AssistantToolRegistry::class);
        $property = new \ReflectionProperty($registry, 'actions');
        $keys = array_keys($property->getValue($registry));
        $this->assertNotEmpty($keys);
        $this->assertContains('create_booking_type', $keys);
        $this->assertContains('create_appointment', $keys);
        $this->assertContains('prepare_automation', $keys);
        $this->assertContains('draft_reply', $keys);

        $counts = fn (): array => [
            'booking_types' => BookingType::query()->count(),
            'appointments' => DB::table('appointments')->count(),
            'automations' => DB::table('automations')->count(),
        ];
        $before = $counts();

        foreach ($keys as $key) {
            $p = AssistantActionProposal::create([
                'uid' => (string) Str::uuid(), 'conversation_id' => null, 'workspace_id' => $this->workspace->id, 'business_id' => $this->business->id, 'user_id' => $this->customer->user_id,
                'tool_key' => $key, 'params' => [], 'preview' => ['title' => $key], 'params_hash' => str_repeat('a', 64), 'state_fingerprint' => str_repeat('b', 64),
                'status' => 'pending', 'expires_at' => now()->addMinutes(30), 'idempotency_key' => hash('sha256', (string) Str::uuid()),
            ]);

            $result = $this->svc->approve($this->viewAs, $p->uid);

            $this->assertContains($result['state'], ['stale', 'failed', 'declined', 'expired'], "{$key} must not run from view-as (got {$result['state']}).");
            $this->assertSame(0, AssistantActionAudit::query()->where('proposal_id', $p->id)->whereIn('event', ['approved', 'executed', 'handed_off'])->count(), $key);
            $this->assertNull($p->fresh()->lease_token, $key);
            $this->assertStringContainsString('viewing as a client', (string) $result['message'], $key);
        }

        $this->assertSame($before, $counts(), 'No domain effect from any tool.');
    }

    public function test_direct_access_approval_still_works(): void
    {
        $proposal = $this->prepareBookingType('Still works');

        $this->assertSame('completed', $this->svc->approve($this->direct, $proposal->uid)['state']);
        $this->assertSame(1, BookingType::query()->where('name', 'Still works')->count());
    }
}
