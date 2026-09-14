<?php

namespace Tests\Feature\Business;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * Owner product decision — Results cleanup: operational messaging metrics
 * (sent/failed/processing, carrier delivery health) moved off Business
 * Results and onto Settings -> Text messaging -> Delivery & usage. This
 * proves the relocated content actually renders here, with the same
 * plain-language framing AnalyticsResultsExperienceTest used to prove on
 * Results itself (see that file's own now-trimmed assertions).
 */
class TextMessagingDeliveryUsageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    private function deliveryUsage($workspace, $business)
    {
        return $this->get(route('customer.workspaces.businesses.text-messaging.delivery-usage', [$workspace->uid, $business->uid]));
    }

    public function test_delivery_and_usage_shows_the_outcome_breakdown_with_its_plain_language_meaning(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $u = $business->customer_id;
        $this->report($business, $u, ['customer_status' => 'Delivered']);
        $this->report($business, $u, ['customer_status' => 'Delivered|abc123']);
        $this->report($business, $u, ['customer_status' => 'Undelivered']);
        $this->report($business, $u, ['customer_status' => 'Queued']);
        $this->report($business, $u, ['direction' => 'incoming']);
        $this->authenticateAsUser($customer->user, ['view_numbers']);

        $response = $this->deliveryUsage($workspace, $business);

        $response->assertOk();
        $response->assertSeeInOrder(['Sent', 'Failed', 'Processing']);
        $response->assertSee('Accepted by the messaging provider.');
        $response->assertSee("It doesn't confirm the message reached the phone.");
        $this->assertSame('2', trim(strip_tags($this->extract($response->getContent(), 'delivery-sent'))));
        $this->assertSame('1', trim(strip_tags($this->extract($response->getContent(), 'delivery-failed'))));
        $this->assertSame('1', trim(strip_tags($this->extract($response->getContent(), 'delivery-processing'))));
    }

    public function test_delivery_and_usage_is_not_shown_on_business_results(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $u = $business->customer_id;
        $this->report($business, $u, ['customer_status' => 'Delivered']);
        $this->authenticateAsCustomer($customer);

        $response = $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertDontSee('data-role="results-messages"', false);
        $response->assertDontSee('data-role="outcome-breakdown"', false);
        $response->assertDontSee('data-role="chart-message-volume"', false);
    }

    public function test_business_a_cannot_view_business_bs_delivery_and_usage(): void
    {
        [$customerA] = $this->tenant('America/New_York', 'Business A');
        [, $businessB, $workspaceB] = $this->tenant('America/New_York', 'Business B');
        $this->authenticateAsUser($customerA->user, ['view_numbers']);

        $this->deliveryUsage($workspaceB, $businessB)->assertNotFound();
    }

    /**
     * @return string the raw HTML fragment inside the first element carrying
     *                data-role="$role"
     */
    private function extract(string $html, string $role): string
    {
        preg_match('/data-role="' . preg_quote($role, '/') . '"[^>]*>(.*?)<\//s', $html, $matches);

        return $matches[1] ?? '';
    }
}
