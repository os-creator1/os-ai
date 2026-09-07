<?php

namespace Tests\Feature\Analytics;

use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * B5 — contract §7 (K1–K5), §21 "Contact identities" and the contact half
 * of "NULL-business exclusion".
 */
class AnalyticsContactKpiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    public function test_k1_ignores_the_range_k2_respects_it_k4_sums_to_k1_and_k3_sums_to_k2(): void
    {
        [, $business] = $this->tenant('America/New_York');
        $group = $this->group($business);
        $tz = 'America/New_York';

        $this->contact($business, $group, ['created_at' => $this->utcFromLocal('2025-01-15 10:00:00', $tz)]);
        $this->contact($business, $group, ['created_at' => $this->utcFromLocal('2026-06-02 10:00:00', $tz)]);
        $this->contact($business, $group, ['created_at' => $this->utcFromLocal('2026-06-02 23:30:00', $tz)]);
        $this->contact($business, $group, ['status' => Contacts::STATUS_UNSUBSCRIBE, 'created_at' => $this->utcFromLocal('2026-06-05 09:00:00', $tz)]);

        $range = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-06-01', 'end' => '2026-06-07'], $tz);
        $overview = app(BusinessAnalyticsPresenter::class)->buildOverview($business, $range);
        $k = $overview->contacts;

        $this->assertSame(4, $k->totalNow, 'K1 is a point-in-time total, never range-filtered.');
        $this->assertSame(3, $k->newInRange);
        $this->assertSame(3, $k->subscribedNow);
        $this->assertSame(1, $k->unsubscribedNow);
        $this->assertSame($k->totalNow, $k->subscribedNow + $k->unsubscribedNow);
        $this->assertSame($k->newInRange, $overview->contactGrowth->total('new_contacts'));
        $this->assertSame([0, 2, 0, 0, 1, 0, 0], $overview->contactGrowth->series['new_contacts']);
    }

    public function test_k5_counts_the_business_contact_groups_only(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->group($business, 'A');
        $this->group($business, 'B');
        ContactGroups::create(['customer_id' => $customer->user_id, 'business_id' => null, 'name' => 'Legacy list', 'status' => true]);
        [, $other] = $this->tenant();
        $this->group($other, 'Foreign');

        $overview = app(BusinessAnalyticsPresenter::class)->buildOverview($business, AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone));

        $this->assertSame(2, $overview->contacts->groupCount);
        $this->assertNotNull($workspace);
    }

    public function test_null_business_contacts_of_the_same_customer_are_excluded_and_counted_in_the_notice(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $group = $this->group($business);
        $legacyGroup = ContactGroups::create(['customer_id' => $customer->user_id, 'business_id' => null, 'name' => 'Legacy list', 'status' => true]);

        $this->contact($business, $group);
        $this->contact(null, $legacyGroup);
        $this->contact(null, $legacyGroup);

        $overview = app(BusinessAnalyticsPresenter::class)->buildOverview($business, AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone));

        $this->assertSame(1, $overview->contacts->totalNow);
        $this->assertSame(1, $overview->contacts->newInRange);
        $this->assertSame(2, $overview->coverage->unattributedContacts);

        $this->authenticateAsCustomer($customer);
        $this->overview($workspace, $business)->assertOk()->assertSee('2 contact records');
    }

    public function test_no_unsubscribe_trend_or_lead_source_is_rendered(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $response = $this->overview($workspace, $business)->assertOk();

        foreach (['unsubscribe rate', 'churn', 'lead source', 'acquisition channel'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $response->getContent());
        }
    }
}
