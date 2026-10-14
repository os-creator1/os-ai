<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Assistant\AssistantActionService;
use App\Library\Assistant\AssistantContext;
use App\Models\AssistantActionProposal;
use App\Models\BookingType;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * PR #460 review: a proposal prepared for a Location must not execute once that Location was archived during the approval window,
 * even while the actor still has another active Location (so the action tool itself stays available).
 */
class AssistantBookingTypeArchivedLocationTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    public function test_approving_a_booking_type_for_a_location_archived_since_the_preview_creates_nothing(): void
    {
        $this->bootAssistant();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Arch Venue', 'Arch Account');
        $this->authenticateAs($customer);
        $first = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $second = $first->replicate();
        $second->uid = (string) Str::uuid();
        $second->name = 'Second site';
        $second->is_primary = false;
        $second->save();

        $this->modelSays(['action' => 'propose', 'tool' => 'create_booking_type', 'args' => ['name' => 'Archived-site package', 'duration_minutes' => 60, 'location' => (string) $second->name], 'message' => 'Ready.']);
        $reply = $this->lastReply($this->say($workspace, $business, 'Create a booking type at the second site')->assertOk());
        $proposal = AssistantActionProposal::query()->where('uid', $reply['proposal']['uid'])->firstOrFail();
        $this->assertSame($second->uid, $proposal->params['location_uid'] ?? null, 'The proposal targets the second location.');

        // The location is archived during the approval window; the first one stays active, so the tool is still available.
        $second->forceFill(['lifecycle_state' => 'archived', 'archived_at' => now()])->save();

        $shell = app(\App\Library\Navigation\CustomerShellComposer::class);
        $customerContext = $shell->currentContext($customer->user);
        $entitlements = $shell->currentMenuEntitlements($customerContext);
        $ctx = new AssistantContext($workspace, $business, $customer->user, $customerContext, $entitlements,
            app(\App\Library\Dashboard\Home\HomeActionRouter::class)->forActor($customerContext, $customer->user, $entitlements, [$workspace->uid, $business->uid], $first->uid),
            [$workspace->uid, $business->uid]);
        $before = BookingType::query()->count();

        $result = app(AssistantActionService::class)->approve($ctx, $proposal->uid);

        $this->assertNotSame('completed', $result['state']);
        $this->assertSame($before, BookingType::query()->count(), 'No booking type was created at the archived location.');
        $this->assertNull($proposal->fresh()->executed_at);
    }
}
