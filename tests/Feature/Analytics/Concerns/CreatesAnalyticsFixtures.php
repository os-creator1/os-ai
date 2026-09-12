<?php

namespace Tests\Feature\Analytics\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\Automation;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;

/**
 * B5 Business Analytics — shared fixtures. Builds Business-scoped rows in
 * every authorized source table (reports, tracking_logs, campaigns,
 * contacts, contact_groups, opportunities, opportunity_runs,
 * automation_executions) with explicit UTC `created_at` instants so the
 * Business-timezone bucketing can be proven exactly. Nothing here reads
 * or writes chat_boxes, agency_prospect*, business_usage_* or billing
 * tables.
 */
trait CreatesAnalyticsFixtures
{
    use CreatesBusinessTestData;

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function tenant(string $timezone = 'America/New_York', string $name = 'Snap Booth Co'): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['timezone' => $timezone, 'name' => $name]));

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        return [$customer, $business->fresh(), Workspace::query()->findOrFail($business->workspace_id)];
    }

    protected function authenticateAsCustomer(Customer $customer, array $permissions = ['view_reports']): void
    {
        $this->authenticateAsUser($customer->user, $permissions);
    }

    protected function authenticateAsUser(User $user, array $permissions = ['view_reports']): void
    {
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($user);
    }

    protected function member(Workspace $workspace, User $user, WorkspaceMembershipRole $role, WorkspaceBusinessAccessScope $scope = WorkspaceBusinessAccessScope::All, bool $isActive = true): WorkspaceMembership
    {
        return WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'business_access_scope' => $scope->value,
            'is_active' => $isActive,
        ]);
    }

    protected function assign(WorkspaceMembership $membership, Business $business): void
    {
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $membership->id, 'business_id' => $business->id]);
    }

    /**
     * The timezone `created_at` values are physically stored in —
     * config('app.timezone'), which the contract fixes as UTC; read from
     * config so the fixtures agree with whatever this environment stores.
     */
    protected function storageTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /** A storage-timezone timestamp string for a Business-local wall-clock instant. */
    protected function utcFromLocal(string $localDateTime, string $timezone): string
    {
        return CarbonImmutable::parse($localDateTime, $timezone)->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s');
    }

    /** A storage-timezone timestamp string for an instant given in UTC. */
    protected function storageFromUtc(string $utcDateTime): string
    {
        return CarbonImmutable::parse($utcDateTime, 'UTC')->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s');
    }

    /** @param  array<string, mixed>  $attributes */
    protected function report(?Business $business, int $userId, array $attributes = []): int
    {
        return DB::table('reports')->insertGetId(array_merge([
            'uid' => uniqid(),
            'user_id' => $userId,
            'business_id' => $business?->id,
            'campaign_id' => null,
            'from' => '18005550100',
            'to' => '12025550100',
            'message' => 'Fixture message',
            'sms_type' => 'plain',
            'status' => 'Delivered',
            'customer_status' => 'Delivered',
            'direction' => 'outgoing',
            'cost' => '1',
            'created_at' => now()->utc()->format('Y-m-d H:i:s'),
            'updated_at' => now()->utc()->format('Y-m-d H:i:s'),
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function campaign(Business $business, array $attributes = []): Campaigns
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $campaign = Campaigns::create(array_merge([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'campaign_name' => 'Fixture campaign',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'status' => Campaigns::STATUS_DONE,
        ], $attributes));

        if ($createdAt !== null) {
            DB::table('campaigns')->where('id', $campaign->id)->update(['created_at' => $createdAt]);
        }

        return $campaign->fresh();
    }

    protected function group(Business $business, string $name = 'Clients'): ContactGroups
    {
        return ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => $name,
            'status' => true,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function contact(?Business $business, ContactGroups $group, array $attributes = []): Contacts
    {
        static $sequence = 5000;

        return Contacts::create(array_merge([
            'customer_id' => $group->customer_id,
            'business_id' => $business?->id,
            'group_id' => $group->id,
            'phone' => '1202555' . str_pad((string) (++$sequence), 4, '0', STR_PAD_LEFT),
            'status' => Contacts::STATUS_SUBSCRIBE,
        ], $attributes));
    }

    protected function trackingLog(Business $business, Campaigns $campaign, Contacts $contact, ?string $createdAt = null): int
    {
        return DB::table('tracking_logs')->insertGetId([
            'uid' => uniqid(),
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'sending_server_id' => null,
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'contact_group_id' => $contact->group_id,
            'status' => 'Delivered',
            'created_at' => $createdAt ?? now()->utc()->format('Y-m-d H:i:s'),
            'updated_at' => $createdAt ?? now()->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function opportunity(Business $business, array $attributes = []): int
    {
        $now = now()->utc()->format('Y-m-d H:i:s');

        return DB::table('opportunities')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'worker_key' => 'business_advisor',
            'type' => 'missing_website',
            'fingerprint_version' => 1,
            'fingerprint' => hash('sha256', uniqid('', true)),
            'title' => 'Add a website',
            'summary' => 'Fixture recommendation',
            'status' => 'open',
            'freshness' => 'current',
            'impact' => 3,
            'urgency' => 3,
            'effort' => 2,
            'confidence' => 0.80,
            'goal_relevance_rank' => 1,
            'evidence_freshness_rank' => 1,
            'priority_score' => 70,
            'scoring_version' => 1,
            'scored_at' => $now,
            'evidence' => json_encode([]),
            'occurrence_number' => 1,
            'last_confirmed_at' => $now,
            'first_detected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));
    }

    protected function advisorRun(Business $business, string $status, ?string $completedAt): int
    {
        $now = now()->utc()->format('Y-m-d H:i:s');

        return DB::table('opportunity_runs')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'worker_key' => 'business_advisor',
            'producer_version' => 1,
            'status' => $status,
            'started_at' => $now,
            'heartbeat_at' => $now,
            'completed_at' => $completedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function automation(Business $business): Automation
    {
        return Automation::create([
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => 'Fixture automation',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => 'contact_created',
            'trigger_config' => ['contact_group_id' => null],
            'action_type' => 'update_contact_field',
            'action_config' => ['field_id' => 1, 'value' => 'x'],
        ]);
    }

    protected function execution(Business $business, Automation $automation, Contacts $contact, string $status, string $triggerType = 'contact_created', ?string $createdAt = null): int
    {
        $at = $createdAt ?? now()->utc()->format('Y-m-d H:i:s');

        return DB::table('automation_executions')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'automation_id' => $automation->id,
            'contact_id' => $contact->id,
            'trigger_type' => $triggerType,
            'idempotency_key' => 'fixture:' . uniqid('', true),
            'status' => $status,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    protected function overview(Workspace $workspace, Business $business, array $query = []): TestResponse
    {
        return $this->get(route('customer.workspaces.businesses.analytics.overview', array_merge([$workspace->uid, $business->uid], $query)));
    }

    protected function campaignsPage(Workspace $workspace, Business $business, array $query = []): TestResponse
    {
        return $this->get(route('customer.workspaces.businesses.analytics.campaigns', array_merge([$workspace->uid, $business->uid], $query)));
    }

    protected function series(Workspace $workspace, Business $business, array $query = []): TestResponse
    {
        return $this->getJson(route('customer.workspaces.businesses.analytics.series', array_merge([$workspace->uid, $business->uid], $query)));
    }

    /**
     * Every SQL statement executed while `$callback` runs.
     *
     * @return array<int, string>
     */
    protected function capturedSql(callable $callback): array
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $callback();

        return $sql;
    }

    /**
     * capturedSql(), minus every read issued from inside
     * EntitlementManager::snapshotBusinessFeatureDecisions().
     *
     * That snapshot is Slice 2A's shell menu-entitlement check. It runs on
     * every Business-frame page, is shared page chrome rather than anything
     * Analytics issues, and is already budgeted at six queries by its own
     * tests (MenuEntitlementsRequestSnapshotTest). It is excluded here by
     * CALLER, not by SQL: its first read is `select * from businesses where
     * id = ?`, character-for-character the same statement the tenancy
     * chain's WorkspaceManager::userCanAccessBusiness() issues, so no
     * pattern over the SQL can separate the two — and matching on text
     * would silently drop the tenancy read too. Nothing else is excluded.
     *
     * @return array<int, string>
     */
    protected function analyticsOwnedSql(callable $callback): array
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['class'] ?? null) === EntitlementManager::class && ($frame['function'] ?? null) === 'snapshotBusinessFeatureDecisions') {
                    return;
                }
            }

            $sql[] = $query->sql;
        });

        $callback();

        return $sql;
    }

    protected function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }
}
