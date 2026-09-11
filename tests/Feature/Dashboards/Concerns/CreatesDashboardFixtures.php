<?php

namespace Tests\Feature\Dashboards\Concerns;

use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\Dashboard\AccountHomePresenter;
use App\Library\Dashboard\BusinessHomePresenter;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerContextResolver;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\ViewAs\ViewAsManager;
use App\Models\Automation;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Currency;
use App\Models\User;
use App\Enums\Business\BusinessServiceMode;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;

/**
 * Customer Experience Slice 4 — Dashboard fixtures on top of the Slice 1B
 * tenant fixtures: B5 source rows at explicit Business-local dates, Slice 2B
 * conversations, Advisor recommendations, and the wallet / website / Google
 * status columns the attention list reads.
 *
 * The clock is frozen at FROZEN_NOW_UTC, 11:00 in New York on 10 Sep 2026,
 * so the dashboard's two windows are the contract's own example: current
 * 12 Aug – 10 Sep, previous 13 Jul – 11 Aug (§4.2, §18 #39).
 */
trait CreatesDashboardFixtures
{
    use CreatesCustomerContextFixtures;

    protected string $frozenNowUtc = '2026-09-10 15:00:00';

    private int $dashboardSequence = 0;

    protected function freezeClock(?string $utc = null): void
    {
        $now = CarbonImmutable::parse($utc ?? $this->frozenNowUtc, 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    /** The storage-timezone timestamp of noon, Business-local, on a date. */
    protected function localNoon(string $date, string $timezone = 'America/New_York'): string
    {
        return CarbonImmutable::parse($date . ' 12:00:00', $timezone)
            ->setTimezone((string) config('app.timezone', 'UTC'))
            ->format('Y-m-d H:i:s');
    }

    /** Outbound messages on a Business-local date. */
    protected function sent(Business $business, int $count, string $localDate, string $customerStatus = 'Delivered'): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('reports')->insert([
                'uid' => uniqid('', true),
                'user_id' => $business->customer_id,
                'business_id' => $business->id,
                'from' => '18005550100',
                'to' => '12025550100',
                'message' => 'Fixture message',
                'sms_type' => 'plain',
                'status' => $customerStatus,
                'customer_status' => $customerStatus,
                'direction' => 'outgoing',
                'cost' => '1',
                'created_at' => $this->localNoon($localDate, $business->timezone),
                'updated_at' => $this->localNoon($localDate, $business->timezone),
            ]);
        }
    }

    protected function contactGroup(Business $business): ContactGroups
    {
        return ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => 'Clients ' . (++$this->dashboardSequence),
            'status' => true,
        ]);
    }

    /** New contacts created on a Business-local date. */
    protected function contactsAdded(Business $business, int $count, string $localDate, ?ContactGroups $group = null): void
    {
        $group ??= $this->contactGroup($business);

        for ($i = 0; $i < $count; $i++) {
            $contact = Contacts::create([
                'customer_id' => $group->customer_id,
                'business_id' => $business->id,
                'group_id' => $group->id,
                'phone' => '1303555' . str_pad((string) (++$this->dashboardSequence), 4, '0', STR_PAD_LEFT),
                'status' => Contacts::STATUS_SUBSCRIBE,
            ]);

            DB::table('contacts')->where('id', $contact->id)->update(['created_at' => $this->localNoon($localDate, $business->timezone)]);
        }
    }

    /** Automation runs on a Business-local date. */
    protected function automationRuns(Business $business, int $count, string $localDate, string $status = 'succeeded'): void
    {
        $automation = Automation::create([
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => 'Fixture automation',
            'status' => Automation::STATUS_ACTIVE,
            'trigger_type' => 'contact_created',
            'trigger_config' => ['contact_group_id' => null],
            'action_type' => 'update_contact_field',
            'action_config' => ['field_id' => 1, 'value' => 'x'],
        ]);
        $group = $this->contactGroup($business);

        for ($i = 0; $i < $count; $i++) {
            $contact = Contacts::create([
                'customer_id' => $group->customer_id,
                'business_id' => $business->id,
                'group_id' => $group->id,
                'phone' => '1404555' . str_pad((string) (++$this->dashboardSequence), 4, '0', STR_PAD_LEFT),
                'status' => Contacts::STATUS_SUBSCRIBE,
            ]);
            // Keep these contacts out of the "new contacts" windows.
            DB::table('contacts')->where('id', $contact->id)->update(['created_at' => '2020-01-01 00:00:00']);

            DB::table('automation_executions')->insert([
                'uid' => (string) Str::uuid(),
                'business_id' => $business->id,
                'automation_id' => $automation->id,
                'contact_id' => $contact->id,
                'trigger_type' => 'contact_created',
                'idempotency_key' => 'dashboard:' . uniqid('', true),
                'status' => $status,
                'created_at' => $this->localNoon($localDate, $business->timezone),
                'updated_at' => $this->localNoon($localDate, $business->timezone),
            ]);
        }
    }

    /** Conversations (Slice 2B chat_boxes) started on a Business-local date. */
    protected function conversationsStarted(Business $business, int $count, string $localDate): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('chat_boxes')->insert([
                'uid' => (string) Str::uuid(),
                'user_id' => $business->customer_id,
                'business_id' => $business->id,
                'from' => '18005550100',
                'to' => '1505555' . str_pad((string) (++$this->dashboardSequence), 4, '0', STR_PAD_LEFT),
                'notification' => 0,
                'created_at' => $this->localNoon($localDate, $business->timezone),
                'updated_at' => $this->localNoon($localDate, $business->timezone),
            ]);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    protected function recommendation(Business $business, array $attributes = []): int
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

    /**
     * The Business's wallet row, created when the Business has none, with the
     * given status columns.
     *
     * @param  array<string, mixed>  $columns
     */
    protected function wallet(Business $business, array $columns = []): void
    {
        $now = CarbonImmutable::now('UTC');

        if (! DB::table('business_usage_wallets')->where('business_id', $business->id)->exists()) {
            $currency = Currency::query()->where('code', 'USD')->first()
                ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

            DB::table('business_usage_wallets')->insert([
                'business_id' => $business->id,
                'currency_id' => $currency->id,
                'available_balance_micro' => 0,
                'reserved_balance_micro' => 0,
                'debt_balance_micro' => 0,
                'spend_period_key' => $now->format('Y-m'),
                'spend_period_start_utc' => $now->startOfMonth()->format('Y-m-d H:i:s'),
                'spend_period_end_utc' => $now->startOfMonth()->addMonth()->format('Y-m-d H:i:s'),
                'recharge_period_key' => $now->format('Y-m'),
                'recharge_period_start_utc' => $now->startOfMonth()->format('Y-m-d H:i:s'),
                'recharge_period_end_utc' => $now->startOfMonth()->addMonth()->format('Y-m-d H:i:s'),
                'billing_status' => 'active',
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
        }

        if ($columns !== []) {
            DB::table('business_usage_wallets')->where('business_id', $business->id)->update($columns);
        }
    }

    protected function website(Business $business, string $status): void
    {
        DB::table('websites')->updateOrInsert(['business_id' => $business->id], [
            'uid' => (string) Str::uuid(),
            'public_id' => (string) Str::uuid(),
            'name' => $business->name,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function googleConnection(Business $business, GoogleConnectionState $state): BusinessGoogleConnection
    {
        $existing = BusinessGoogleConnection::query()->where('business_id', $business->id)->first();

        if ($existing !== null) {
            $existing->forceFill(['state' => $state])->save();

            return $existing;
        }

        return BusinessGoogleConnection::create([
            'business_id' => $business->id,
            'state' => $state,
            'refresh_token_encrypted' => 'plain-refresh-token-value',
            'granted_scopes' => 'https://www.googleapis.com/auth/business.manage',
            'google_account_email' => 'owner@example.test',
            'connected_at' => now(),
        ]);
    }

    protected function googleLocation(Business $business, string $verificationState): void
    {
        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->first()
            ?? $this->googleConnection($business, GoogleConnectionState::Active);

        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'name' => 'Location ' . (++$this->dashboardSequence),
            'service_mode' => BusinessServiceMode::Storefront,
            'address_line_1' => '1 Main Street',
            'city' => 'New York',
            'region' => 'NY',
            'postal_code' => '10001',
            'country_code' => 'US',
            'public_address' => true,
            'is_primary' => false,
        ]);

        DB::table('business_google_locations')->insert([
            'uid' => (string) Str::uuid(),
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/1',
            'provider_location_resource_name' => 'locations/' . (++$this->dashboardSequence) . '-' . $business->id,
            'verification_state' => $verificationState,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The context the Dashboard request would resolve for this actor, built
     * through the one canonical resolver outside an HTTP request.
     */
    protected function resolvedContext(User $user): CustomerContext
    {
        $request = Request::create('/dashboard');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);

        $context = app(CustomerContextResolver::class)->resolve($user, $request, app(ViewAsManager::class)->current($user));
        app()->instance(CustomerContext::class, $context);

        return $context;
    }

    /** The snapshot the dashboard would render for this actor. */
    protected function dashboardFor(User $user): DashboardSnapshot
    {
        $context = $this->resolvedContext($user);

        return app(BusinessHomePresenter::class)->present($context, $user)
            ?? app(AccountHomePresenter::class)->present($context, $user);
    }

    /** Pre-builds the request's one entitlement snapshot (Slice 2A), excluded from Dashboard budgets. */
    protected function warmShellEntitlements(CustomerContext $context): void
    {
        app(CustomerShellComposer::class)->currentMenuEntitlements($context);
    }

    /**
     * Every SQL statement executed while $callback runs.
     *
     * @return array<int, string>
     */
    protected function sqlDuring(callable $callback): array
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $callback();

        return $sql;
    }

    /** The visible text inside the page's <main>, tags and scripts stripped. */
    protected function mainText(string $html): string
    {
        $start = strpos($html, '<main');
        $end = strpos($html, '</main>');
        $this->assertNotFalse($start, 'The page must render its <main>.');
        $this->assertNotFalse($end);

        $region = substr($html, $start, $end - $start);
        $region = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $region) ?? '';

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($region))) ?? '');
    }

    /** The HTML inside the page's <main>. */
    protected function mainHtml(string $html): string
    {
        $start = strpos($html, '<main');
        $end = strpos($html, '</main>');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
