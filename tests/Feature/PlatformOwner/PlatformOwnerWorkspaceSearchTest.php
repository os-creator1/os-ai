<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Platform Owner / Admin V1 §3 — find an account from whatever the customer
 * quotes to support, with bounded, paginated, tenant-safe results and a flat
 * query count.
 */
class PlatformOwnerWorkspaceSearchTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
    }

    private function names(string $query): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('admin.workspaces.index', ['search' => $query]))->assertOk();
    }

    public function test_search_finds_a_workspace_by_name_uid_owner_email_business_name_and_business_uid(): void
    {
        [$alphaOwner, $alphaBusiness, $alpha] = $this->tenant(WorkspacePlanTier::Core, 'Alpha Plumbing', 'Alpha Workspace');
        [, , $bravo] = $this->tenant(WorkspacePlanTier::Core, 'Bravo Bakery', 'Bravo Workspace');
        $alphaOwner->user->forceFill(['email' => 'alpha.owner@example.test'])->save();
        $this->actingAsPlatformOwner();

        // Workspace name
        $this->names('Alpha Workspace')->assertSee('Alpha Workspace')->assertDontSee('Bravo Workspace');
        // Workspace uid
        $this->names($alpha->uid)->assertSee('Alpha Workspace')->assertDontSee('Bravo Workspace');
        // Owner email
        $this->names('alpha.owner@example.test')->assertSee('Alpha Workspace')->assertDontSee('Bravo Workspace');
        // Business name
        $this->names('Alpha Plumbing')->assertSee('Alpha Workspace')->assertDontSee('Bravo Workspace');
        // Business uid
        $this->names($alphaBusiness->uid)->assertSee('Alpha Workspace')->assertDontSee('Bravo Workspace');
    }

    public function test_search_finds_a_workspace_by_a_members_email(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Charlie Cafe', 'Charlie Workspace');
        $this->tenant(WorkspacePlanTier::Core, 'Delta Dental', 'Delta Workspace');
        $memberCustomer = $this->createCustomer();
        $memberCustomer->user->forceFill(['email' => 'member.charlie@example.test'])->save();
        $this->member($workspace, $memberCustomer->user, WorkspaceMembershipRole::Staff);
        $this->actingAsPlatformOwner();

        $this->names('member.charlie@example.test')->assertSee('Charlie Workspace')->assertDontSee('Delta Workspace');
    }

    public function test_search_finds_a_workspace_by_its_exact_stripe_customer_or_subscription_id(): void
    {
        $subscribed = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $other = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $subscribed['workspace']->forceFill(['name' => 'Subscribed Workspace'])->save();
        $other['workspace']->forceFill(['name' => 'Other Subscribed Workspace'])->save();
        $this->actingAsPlatformOwner();

        $subscriptionId = $subscribed['subscription']->provider_subscription_id;
        $customerId = $subscribed['subscription']->provider_customer_id;
        $this->assertNotEmpty($subscriptionId);
        $this->assertNotEmpty($customerId);

        $this->names($subscriptionId)->assertSee('Subscribed Workspace')->assertDontSee('Other Subscribed Workspace');
        $this->names($customerId)->assertSee('Subscribed Workspace')->assertDontSee('Other Subscribed Workspace');

        // Exact only: a fragment of the id is not a match.
        $this->names(substr($subscriptionId, 0, 8))->assertDontSee('Subscribed Workspace');
    }

    public function test_like_wildcards_in_the_search_term_are_literal(): void
    {
        $this->tenant(WorkspacePlanTier::Core, 'Echo Electric', 'Echo Workspace');
        $this->tenant(WorkspacePlanTier::Core, 'Foxtrot Florist', 'Foxtrot Workspace');
        $this->actingAsPlatformOwner();

        $this->names('%')->assertDontSee('Echo Workspace')->assertDontSee('Foxtrot Workspace');
        $this->names('_')->assertDontSee('Echo Workspace')->assertDontSee('Foxtrot Workspace');
    }

    public function test_the_index_shows_the_plan_and_the_subscription_status_for_each_row(): void
    {
        $subscribed = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->actingAsPlatformOwner();

        $html = $this->get(route('admin.workspaces.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="po-index-plan"', $html);
        $this->assertStringContainsString((string) $subscribed['subscription']->status->value, $html);
    }

    public function test_results_are_paginated_and_the_page_size_is_bounded(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->tenant(WorkspacePlanTier::Core, "Biz {$i}", sprintf('Pagination WS %02d', $i));
        }
        $this->actingAsPlatformOwner();

        $default = $this->get(route('admin.workspaces.index'))->assertOk();
        $this->assertSame(25, substr_count($default->getContent(), 'Pagination WS'));

        $small = $this->get(route('admin.workspaces.index', ['per_page' => 5]))->assertOk();
        $this->assertSame(5, substr_count($small->getContent(), 'Pagination WS'));

        // The ceiling is validated, not merely clamped away silently.
        $this->get(route('admin.workspaces.index', ['per_page' => 1000]))->assertSessionHasErrors('per_page');

        $second = $this->get(route('admin.workspaces.index', ['page' => 2]))->assertOk();
        $this->assertSame(5, substr_count($second->getContent(), 'Pagination WS'));
    }

    public function test_foreign_and_forged_identifiers_fail_closed_with_a_plain_404(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Golf Garage', 'Golf Workspace');
        $this->actingAsPlatformOwner();

        $missingUuid = (string) Str::uuid();

        $this->get(route('admin.workspaces.show', $missingUuid))->assertNotFound();
        $this->get(url(config('app.admin_path') . '/workspaces/' . $workspace->id))->assertNotFound();
        $this->get(route('admin.businesses.show', 'does-not-exist'))->assertNotFound();
        $this->post(route('admin.platform-owner.workspaces.restore-access', $missingUuid), ['reason' => 'x', 'confirm' => 1])->assertNotFound();

        // A Business uid from ANOTHER Workspace is not found inside this one,
        // and the response says nothing about whether that Business exists.
        [, $foreignBusiness] = $this->tenant(WorkspacePlanTier::Core, 'Hotel Hardware', 'Hotel Workspace');
        $response = $this->get(route('admin.workspaces.show', ['workspace' => $workspace, 'business_uid' => $foreignBusiness->uid, 'feature_key' => 'crm']));
        $response->assertNotFound();
        $response->assertDontSee('Hotel Hardware');
    }

    public function test_workspace_index_query_count_is_flat_as_rows_are_added(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->tenant(WorkspacePlanTier::Core, "Few {$i}", "Few WS {$i}");
        }
        $this->actingAsPlatformOwner();

        DB::enableQueryLog();
        $this->get(route('admin.workspaces.index'))->assertOk();
        $few = count(DB::getQueryLog());
        DB::flushQueryLog();

        for ($i = 1; $i <= 15; $i++) {
            $this->tenant(WorkspacePlanTier::Core, "Many {$i}", "Many WS {$i}");
        }

        DB::flushQueryLog();
        $this->get(route('admin.workspaces.index'))->assertOk();
        $many = count(DB::getQueryLog());

        $this->assertSame($few, $many, 'The Workspace index must not run more queries when it lists more Workspaces.');
        $this->assertLessThanOrEqual(25, $many, 'The Workspace index must stay within a small fixed query budget.');
    }
}
