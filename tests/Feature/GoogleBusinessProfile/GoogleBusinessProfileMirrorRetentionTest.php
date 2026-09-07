<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleComparisonStatus;
use App\Jobs\GoogleBusinessProfile\PurgeExpiredGoogleBusinessProfileMirrors;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileComparator;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileMirrorService;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileRetention;
use App\Models\BusinessGoogleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * GBP Slice A contract §13 / §21 / §22 / §32.6 / §32.7 — the mandatory
 * retention correction (Appendix A, A-1) and comparison correctness.
 *
 * Google's API policy caps stored Content at 30 CALENDAR DAYS, requires
 * secure storage, and forbids Content being "manipulated or aggregated in
 * any way".
 */
class GoogleBusinessProfileMirrorRetentionTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    /**
     * T-RET-1 / contract §13.1 (C-8) — NO configured value, however
     * absurd, may produce a mirror_expires_at more than 30 calendar days
     * after mirror_fetched_at.
     */
    public function test_no_configuration_can_push_the_mirror_ttl_past_thirty_days(): void
    {
        foreach ([1, 7, 30, 31, 365, 9999] as $configured) {
            config(['google_business_profile.mirror.retention_days' => $configured]);

            $binding = $this->boundLocation('locations/L' . $configured);
            app(GoogleBusinessProfileMirrorService::class)->refresh($binding, $binding->connection);
            $binding->refresh();

            if ($configured > GoogleBusinessProfileRetention::MAX_MIRROR_RETENTION_DAYS) {
                // Correction pass item 8 — an above-ceiling value fails
                // CLOSED to a zero TTL, and a zero TTL persists no reusable
                // Content at all. There is therefore no window to measure,
                // which is the strongest possible form of "not more than
                // 30 days".
                $this->assertNull($binding->mirror_fetched_at, "A configured retention of {$configured} must persist nothing.");
                $this->assertNull($binding->mirror_expires_at);
                $this->assertNull($binding->profile_mirror);

                continue;
            }

            $this->assertNotNull($binding->mirror_fetched_at);
            $this->assertNotNull($binding->mirror_expires_at);

            $days = $binding->mirror_fetched_at->diffInDays($binding->mirror_expires_at);

            $this->assertLessThanOrEqual(
                GoogleBusinessProfileRetention::MAX_MIRROR_RETENTION_DAYS,
                $days,
                "A configured retention of {$configured} produced a {$days}-day window.",
            );
        }
    }

    /**
     * Correction pass item 8 — with a ZERO effective TTL nothing reusable
     * is persisted by ANY path, including bind, and the refresh result
     * reports that it was not persisted so the caller can render it
     * ephemerally.
     */
    public function test_a_zero_ttl_persists_no_reusable_google_content(): void
    {
        config(['google_business_profile.mirror.retention_days' => null]);

        $binding = $this->boundLocation();

        $result = app(GoogleBusinessProfileMirrorService::class)->refresh($binding, $binding->connection);

        $this->assertFalse($result->persisted);

        // The fetched Content exists ONLY in the returned result.
        $this->assertNotNull($result->mirror()['title']);

        $binding->refresh();

        $this->assertNull($binding->profile_mirror);
        $this->assertNull($binding->mirror_fetched_at);
        $this->assertNull($binding->mirror_expires_at);
        $this->assertNull($binding->bound_title_snapshot);
        $this->assertFalse($binding->mirrorIsFresh());

        // Non-Content operational state IS still recorded.
        $this->assertNotNull($binding->last_synced_at);
        $this->assertNotNull($binding->verification_state);

        // ...and nothing in the ledger carries the Content.
        $ledger = (string) json_encode(DB::table('business_google_operations')->get());
        $this->assertStringNotContainsString('Snap Booth Co', $ledger);
    }

    /**
     * T-RET-2 — retention FAILS CLOSED toward purging. Absent, blank,
     * non-digit, zero, negative and above-ceiling all yield an effective
     * TTL of zero, so every mirror is treated as expired.
     *
     * This deliberately INVERTS the direction of
     * PurgeExpiredWebhookPayloads, whose fail-closed means "do not purge"
     * because purging destroys evidence. Here, keeping Google Content too
     * long breaches Google's terms.
     */
    public function test_retention_fails_closed_toward_purging(): void
    {
        $retention = app(GoogleBusinessProfileRetention::class);

        foreach ([null, '', 'abc', 0, -1, '0', 31, 9999] as $invalid) {
            config(['google_business_profile.mirror.retention_days' => $invalid]);

            $this->assertSame(0, $retention->mirrorRetentionDays(), var_export($invalid, true) . ' must fail closed to zero days.');
        }

        foreach ([1, 7, '14', 30] as $valid) {
            config(['google_business_profile.mirror.retention_days' => $valid]);

            $this->assertSame((int) $valid, $retention->mirrorRetentionDays());
        }
    }

    /**
     * Contract §13.3 — an expired mirror NEVER continues rendering as
     * current. Freshness is computed at read time; nothing derived is
     * stored.
     */
    public function test_an_expired_mirror_is_suppressed_at_read_time(): void
    {
        config(['google_business_profile.mirror.retention_days' => 7]);

        $binding = $this->boundLocation();
        app(GoogleBusinessProfileMirrorService::class)->refresh($binding, $binding->connection);
        $binding->refresh();

        $this->assertTrue($binding->mirrorIsFresh());
        $this->assertNotSame([], $binding->freshMirror());

        // Move past the window; nothing on the row changes, but the
        // read-time answer does.
        $this->travel(8)->days();

        $this->assertFalse($binding->mirrorIsFresh());
        $this->assertSame([], $binding->freshMirror());

        $rows = app(GoogleBusinessProfileComparator::class)->compare(
            $binding->business,
            $binding->businessLocation,
            $binding,
        );

        foreach ($rows as $row) {
            $this->assertSame(GoogleComparisonStatus::NotComparable, $row->status);
            $this->assertNull($row->googleValue);
        }

        $this->travelBack();
    }

    /**
     * T-RET-3 — the hourly purge job nulls the mirror and the three
     * bind-time snapshot fields, writes a mirror_purged ledger row, and
     * leaves the operational binding identifiers alone (contract §13.7).
     */
    public function test_the_purge_job_removes_expired_google_content_but_keeps_the_binding(): void
    {
        config(['google_business_profile.mirror.retention_days' => 1]);

        $binding = $this->boundLocation();
        app(GoogleBusinessProfileMirrorService::class)->refresh($binding, $binding->connection);
        $binding->refresh();

        $this->assertNotNull($binding->profile_mirror);
        $this->assertNotNull($binding->bound_title_snapshot);

        $this->travel(2)->days();

        app(PurgeExpiredGoogleBusinessProfileMirrors::class)->handle(app(GoogleBusinessProfileMirrorService::class));

        $binding->refresh();

        $this->assertNull($binding->profile_mirror);
        $this->assertNull($binding->bound_title_snapshot);
        $this->assertNull($binding->bound_locality_snapshot);
        $this->assertNull($binding->bound_region_code_snapshot);
        $this->assertNull($binding->mirror_fetched_at);
        $this->assertNull($binding->mirror_expires_at);

        // Operational binding metadata survives — it is not a
        // Google-content archive.
        $this->assertSame('accounts/A1', $binding->provider_account_resource_name);
        $this->assertSame('locations/L1', $binding->provider_location_resource_name);
        $this->assertNotNull($binding->business_location_id);

        $this->assertDatabaseHas('business_google_operations', [
            'business_id' => $binding->business_id,
            'operation_type' => 'mirror_purged',
            'status' => 'succeeded',
        ]);

        $this->travelBack();
    }

    /**
     * T-RET-4 — the comparison is NEVER persisted. No GBP table carries a
     * comparison-shaped column, and rendering the comparison repeatedly
     * writes nothing.
     */
    public function test_no_comparison_result_is_ever_persisted(): void
    {
        config(['google_business_profile.mirror.retention_days' => 7]);

        foreach (['business_google_connections', 'business_google_locations', 'business_google_operations'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                foreach (['comparison', 'is_stale', 'stale', 'expired', 'mismatch', 'match_status', 'diff'] as $forbidden) {
                    $this->assertStringNotContainsString(
                        $forbidden,
                        $column,
                        "{$table}.{$column} looks like a persisted derived comparison/freshness result.",
                    );
                }
            }
        }

        $binding = $this->boundLocation();
        app(GoogleBusinessProfileMirrorService::class)->refresh($binding, $binding->connection);
        $binding->refresh();

        $before = DB::table('business_google_locations')->where('id', $binding->id)->first();
        $ledgerBefore = DB::table('business_google_operations')->count();

        $comparator = app(GoogleBusinessProfileComparator::class);

        for ($i = 0; $i < 5; $i++) {
            $comparator->compare($binding->business, $binding->businessLocation, $binding);
        }

        // T-RET-5 — no Google-content history accumulates either.
        $this->assertEquals($before, DB::table('business_google_locations')->where('id', $binding->id)->first());
        $this->assertSame($ledgerBefore, DB::table('business_google_operations')->count());
    }

    /**
     * T-SYNC-1 / T-SYNC-2 / security criterion G-14 — a refresh leaves
     * `businesses` and `business_locations` BYTE-IDENTICAL. Slice A never
     * overwrites platform data.
     */
    public function test_a_refresh_never_modifies_platform_data(): void
    {
        config(['google_business_profile.mirror.retention_days' => 7]);

        $binding = $this->boundLocation();

        // A deliberately DIVERGENT Google payload: every comparable field
        // disagrees with the platform.
        $this->fakeGoogle->rawLocations['locations/L1'] = $this->rawLocationPayload([
            'title' => 'Completely Different Name',
            'phoneNumbers' => ['primaryPhone' => '+44 20 7946 0000'],
            'websiteUri' => 'https://different.test',
        ]);

        $businessesBefore = DB::table('businesses')->orderBy('id')->get()->toJson();
        $locationsBefore = DB::table('business_locations')->orderBy('id')->get()->toJson();

        app(GoogleBusinessProfileMirrorService::class)->refresh($binding, $binding->connection);

        $this->assertSame($businessesBefore, DB::table('businesses')->orderBy('id')->get()->toJson());
        $this->assertSame($locationsBefore, DB::table('business_locations')->orderBy('id')->get()->toJson());
    }

    /**
     * T-CMP-1 / T-CMP-2 / T-CMP-3 / T-CMP-4 / T-CMP-5 / T-CMP-6 /
     * T-CMP-8 — comparison correctness, including the rule implementers
     * get wrong: two absences are NOT a Match.
     */
    public function test_comparison_status_resolution_is_correct(): void
    {
        config(['google_business_profile.mirror.retention_days' => 7]);

        $binding = $this->boundLocation();

        $this->fakeGoogle->rawLocations['locations/L1'] = $this->rawLocationPayload([
            // Same number, different formatting -> Match (T-CMP-8).
            'phoneNumbers' => ['primaryPhone' => '+1 (555) 010-1234'],
            // Same host, different SCHEME -> Mismatch (T-CMP-3).
            'websiteUri' => 'https://example.test',
        ]);

        DB::table('businesses')->where('id', $binding->business_id)->update([
            'website_url' => 'http://example.test',
            'name' => 'Snap Booth Co',
        ]);

        app(GoogleBusinessProfileMirrorService::class)->refresh($binding, $binding->connection);
        $binding->refresh();

        $rows = collect(app(GoogleBusinessProfileComparator::class)->compare(
            $binding->business->fresh(),
            $binding->businessLocation,
            $binding,
        ))->keyBy('field');

        $this->assertSame(GoogleComparisonStatus::Match, $rows['Business name']->status);
        $this->assertSame(GoogleComparisonStatus::Match, $rows['Phone']->status, 'Phone normalization must ignore formatting.');
        $this->assertSame(GoogleComparisonStatus::Mismatch, $rows['Website']->status, 'http vs https must be a Mismatch.');

        // T-CMP-4 — industry is never compared to a Google category.
        $this->assertSame(GoogleComparisonStatus::NotComparable, $rows['Primary category']->status);

        // T-CMP-5 — radius and service-area cities are never comparable.
        $this->assertSame(GoogleComparisonStatus::NotComparable, $rows['Service radius']->status);
        $this->assertSame(GoogleComparisonStatus::NotComparable, $rows['Service areas']->status);

        // T-CMP-6 — hours are absent from the platform.
        $this->assertSame(GoogleComparisonStatus::NotSetOnPlatform, $rows['Opening hours']->status);

        // T-CMP-2 — platform empty AND Google empty is NOT a Match.
        DB::table('businesses')->where('id', $binding->business_id)->update(['phone' => null]);
        $this->fakeGoogle->rawLocations['locations/L1']['phoneNumbers'] = ['primaryPhone' => null];

        app(GoogleBusinessProfileMirrorService::class)->refresh($binding->fresh(), $binding->connection);

        $rows = collect(app(GoogleBusinessProfileComparator::class)->compare(
            $binding->business->fresh(),
            $binding->businessLocation,
            $binding->fresh(),
        ))->keyBy('field');

        $this->assertSame(
            GoogleComparisonStatus::NotSetOnPlatform,
            $rows['Phone']->status,
            'Two absences are two absences, never agreement.',
        );

        // T-CMP-1 — a Google value with no platform counterpart.
        DB::table('businesses')->where('id', $binding->business_id)->update(['website_url' => null]);

        $rows = collect(app(GoogleBusinessProfileComparator::class)->compare(
            $binding->business->fresh(),
            $binding->businessLocation,
            $binding->fresh(),
        ))->keyBy('field');

        $this->assertSame(GoogleComparisonStatus::NotSetOnPlatform, $rows['Website']->status);
    }

    /**
     * Contract §21.2 — the mirror carries EXACTLY the contracted key set
     * and nothing else. No hours, no attributes, no description, no
     * reviews, no metrics, no street address.
     */
    public function test_the_mirror_key_set_is_closed(): void
    {
        config(['google_business_profile.mirror.retention_days' => 7]);

        $binding = $this->boundLocation();
        app(GoogleBusinessProfileMirrorService::class)->refresh($binding, $binding->connection);

        $keys = array_keys($binding->fresh()->profile_mirror);
        sort($keys);

        $expected = [
            'additional_category_ids', 'additional_category_names', 'latitude', 'locality',
            'longitude', 'maps_uri', 'new_review_uri', 'phone_primary', 'primary_category_id',
            'primary_category_name', 'region_code', 'service_area_place_count', 'service_area_type',
            'store_code', 'title', 'website_uri',
        ];
        sort($expected);

        $this->assertSame($expected, $keys);
    }

    // -----------------------------------------------------------------

    /**
     * CORRECTION PASS ITEM 8 — with a zero effective TTL, the manual
     * refresh RESPONSE renders the freshly fetched data, and the following
     * GET correctly asks for a refresh again.
     *
     * Previously the refresh stored an already-expired mirror and then
     * redirected, so the redirected request could never show what had been
     * fetched — the documented default made the feature unusable.
     */
    public function test_a_zero_ttl_manual_refresh_renders_fresh_data_but_the_next_request_does_not(): void
    {
        config(['google_business_profile.mirror.retention_days' => null]);

        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business, true);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation(['title' => 'Ephemeral Only Co']);

        $binding = BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        $this->authenticateAsCustomer($customer);

        $refresh = $this->post(route('customer.workspaces.businesses.gbp.refresh', [$workspace->uid, $business->uid]));

        $refresh->assertOk();
        $refresh->assertSee('Ephemeral Only Co', false);
        $refresh->assertSee('live view of what Google returned just now', false);

        // Nothing reusable was written...
        $this->assertNull(DB::table('business_google_locations')->where('business_id', $business->id)->value('profile_mirror'));

        // ...and nothing carried it out of band either.
        $this->assertNull(session('message'));
        $ledger = (string) json_encode(DB::table('business_google_operations')->get());
        $this->assertStringNotContainsString('Ephemeral Only Co', $ledger);

        // The NEXT request asks for a refresh again.
        $next = $this->get(route('customer.workspaces.businesses.gbp.comparison', [$workspace->uid, $business->uid, $binding->uid]));

        $next->assertOk();
        $next->assertDontSee('Ephemeral Only Co', false);
        $next->assertSee('Refresh to compare', false);
    }

    /**
     * Item 8 — with a POSITIVE TTL the manual refresh still redirects, and
     * the following request renders the stored mirror normally.
     */
    public function test_a_positive_ttl_manual_refresh_still_redirects_and_persists(): void
    {
        config(['google_business_profile.mirror.retention_days' => 7]);

        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business, true);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation(['title' => 'Persisted Co']);

        $binding = BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.refresh', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));

        $this->assertNotNull(DB::table('business_google_locations')->where('business_id', $business->id)->value('profile_mirror'));

        $this->get(route('customer.workspaces.businesses.gbp.comparison', [$workspace->uid, $business->uid, $binding->uid]))
            ->assertOk()
            ->assertSee('Persisted Co', false);
    }

    /**
     * provider_location_resource_name is UNIQUE PLATFORM-WIDE (C-3), so a
     * test that builds several tenants must give each its own Google
     * location. Defaults to locations/L1 for single-tenant tests.
     */
    private function boundLocation(string $locationResourceName = 'locations/L1'): BusinessGoogleLocation
    {
        [, $business] = $this->entitledTenant();
        $location = $this->createLocation($business, true);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation(['name' => $locationResourceName]);

        return BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => $locationResourceName,
        ])->fresh();
    }
}
