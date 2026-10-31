<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\FakeProvisioningAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The no-number chooser against the real backend responses: the page the browser script
 * enhances, the search response it swaps in place, and the Local route into verification.
 * Set CHOOSER_DUMP_DIR to also write each full HTML page for browser checks.
 */
class TextMessagingNumberChooserFlowTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private function dump(string $name, string $html): void
    {
        if (($dir = getenv('CHOOSER_DUMP_DIR')) !== false && $dir !== '') {
            file_put_contents($dir . '/' . $name . '.html', $html);
        }
    }

    public function test_the_chooser_never_presents_billing_rates_as_a_confirmed_price(): void
    {
        $this->app->instance(MessagingProvisioningAdapter::class, new FakeProvisioningAdapter());
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();
        $this->dump('choose', $html);

        $this->assertStringContainsString('data-state="choose"', $html);
        $this->assertStringContainsString('Exact price shown when you choose a number', $html);
        $this->assertStringNotContainsString('today, then', $html);
        $this->assertStringContainsString('data-flow-search', $html);
        $this->assertDoesNotMatchRegularExpression('#<button[^>]*disabled[^>]*data-role="find-toll-free"#', $html);
    }

    public function test_the_real_search_response_carries_the_fragment_the_in_place_script_swaps(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        $fake->nextSearchResults = [
            new AvailableNumberCandidate('+18445550101', \App\Enums\Messaging\PhoneNumberType::TollFree, '+18445550101', 100_000, 1_000_000, 'USD'),
            new AvailableNumberCandidate('+18445550102', \App\Enums\Messaging\PhoneNumberType::TollFree, '+18445550102', 200_000, 1_200_000, 'USD'),
        ];
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), ['number_type' => 'toll_free', 'toll_free_prefix' => '844'])
            ->assertOk();
        $html = $response->getContent();
        $this->dump('results', $html);

        $this->assertStringContainsString('data-state="results"', $html);
        // The script reads exactly this element and its children.
        $this->assertMatchesRegularExpression('#data-flow-body[^>]*>\s*<form#', $html);
        $this->assertSame(2, substr_count($html, 'data-role="number-candidate"'));
        $this->assertSame(2, preg_match_all('/name="candidate_token" value="[^"]+"/', $html));
        $this->assertStringContainsString('data-phone="+18445550101"', $html);
        $this->assertStringContainsString('+1 (844) 555-0101', $html);
        // The controls the script re-posts through the same route.
        $this->assertStringContainsString('data-flow-autosubmit', $html);
        $this->assertStringContainsString('data-role="find-toll-free-more"', $html);
    }

    public function test_local_opens_the_existing_verification_flow(): void
    {
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $show = route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]);
        $this->assertStringContainsString('registration/start-local-verification', $this->get($show)->getContent());

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.start-local-verification', [$workspace->uid, $business->uid]))
            ->assertRedirect($show)
            ->assertSessionHas('status', 'success');

        $page = $this->get($show)->assertOk()->getContent();
        $this->dump('local', $page);
        $this->assertStringContainsString('data-role="local-setup-exit"', $page);
        $this->assertStringContainsString('Business details', $page);
    }
}
