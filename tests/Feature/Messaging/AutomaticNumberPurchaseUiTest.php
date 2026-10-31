<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\NumberOrderCheckResult;
use App\Library\Messaging\Exceptions\MessagingPlatformFundsException;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingNumberOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The customer-facing half of automatic number purchase: one click on "Get this
 * number" ends in the verification form whether Telnyx answers `success` at once or
 * `pending` first — with no error, no second order and no refresh. Fake provisioning
 * adapter (no HTTP); the Telnyx wire behaviour is covered in TelnyxProvisioningAdapterTest.
 */
class AutomaticNumberPurchaseUiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private const PHONE = '+18885550142';

    private FakeProvisioningAdapter $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $this->fake);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        $this->fake->queueSearchResult(new AvailableNumberCandidate(self::PHONE, PhoneNumberType::TollFree, 'candidate-ref-1', 250_000, 1_100_000, 'USD'));
    }

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: string} */
    private function tenantWithToken(): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), ['number_type' => 'toll_free'])
            ->assertOk()->getContent();
        preg_match('/name="candidate_token" value="([^"]+)"/', $html, $m);

        return [$customer, $business, $workspace, $m[1] ?? ''];
    }

    private function orderUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]);
    }

    private function showUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]);
    }

    private function statusUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.text-messaging.number.order-status', [$workspace->uid, $business->uid]);
    }

    public function test_immediate_success_still_goes_straight_to_verification(): void
    {
        [, $business, $workspace, $token] = $this->tenantWithToken();

        $this->post($this->orderUrl($workspace, $business), ['candidate_token' => $token])->assertSessionHas('status', 'success');

        $this->assertSame(1, BusinessMessagingNumber::query()->where('phone_number', self::PHONE)->count());
        $this->get($this->showUrl($workspace, $business))->assertOk()->assertSee(self::PHONE)->assertSee('Business details');
    }

    public function test_pending_order_shows_setting_up_then_moves_to_verification_without_refresh_and_never_orders_twice(): void
    {
        $this->fake->provisionOutcome = 'processing';
        [, $business, $workspace, $token] = $this->tenantWithToken();

        // One click: no error, lands on the progress page.
        $this->post($this->orderUrl($workspace, $business), ['candidate_token' => $token])
            ->assertRedirect($this->showUrl($workspace, $business))
            ->assertSessionMissing('status');

        $page = $this->get($this->showUrl($workspace, $business))->assertOk();
        $page->assertSee('Setting up your number');
        $page->assertSee(self::PHONE);
        $page->assertSee('data-status-url="' . $this->statusUrl($workspace, $business) . '"', false);
        $page->assertDontSee('Get this number');

        // Double click / retry / refresh: still exactly one carrier order, and no error.
        $this->post($this->orderUrl($workspace, $business), ['candidate_token' => $token])
            ->assertRedirect($this->showUrl($workspace, $business))->assertSessionMissing('status');
        $this->get($this->showUrl($workspace, $business))->assertOk()->assertSee('Setting up your number');
        $this->assertCount(1, $this->fake->provisionedOrders);

        // First poll: carrier still pending → processing.
        $this->getJson($this->statusUrl($workspace, $business))->assertOk()->assertJson(['state' => 'processing', 'phone_number' => self::PHONE]);

        // The carrier finishes. The next poll completes the purchase and points at verification.
        $this->fake->numberOrderOutcomes[self::PHONE] = [new NumberOrderCheckResult(NumberOrderCheckResult::SUCCESS, '3081436796672803842', '2026-10-30T12:00:00Z', null)];
        BusinessMessagingNumberOrder::query()->update(['last_checked_at' => now()->subMinute()]);

        $this->getJson($this->statusUrl($workspace, $business))->assertOk()
            ->assertJson(['state' => 'completed', 'redirect_url' => $this->showUrl($workspace, $business)]);

        $number = BusinessMessagingNumber::query()->where('phone_number', self::PHONE)->sole();
        $this->assertSame('3081436796672803842', $number->provider_number_reference);

        // The existing verification form, with the purchased number — and no purchase form.
        $this->get($this->showUrl($workspace, $business))->assertOk()->assertSee(self::PHONE)->assertSee('Business details')->assertDontSee('Setting up your number');
        $this->assertCount(1, $this->fake->provisionedOrders);
    }

    public function test_leaving_and_returning_shows_the_current_state_not_another_purchase(): void
    {
        $this->fake->provisionOutcome = 'processing';
        [, $business, $workspace, $token] = $this->tenantWithToken();
        $this->post($this->orderUrl($workspace, $business), ['candidate_token' => $token]);

        foreach (range(1, 3) as $_) {
            $this->get($this->showUrl($workspace, $business))->assertOk()->assertSee('Setting up your number')->assertDontSee('Find a toll-free number');
        }
    }

    public function test_a_definitive_carrier_failure_is_explained_and_the_customer_can_choose_again(): void
    {
        $this->fake->provisionOutcome = 'processing';
        [, $business, $workspace, $token] = $this->tenantWithToken();
        $this->post($this->orderUrl($workspace, $business), ['candidate_token' => $token]);

        $this->fake->numberOrderOutcomes[self::PHONE] = [new NumberOrderCheckResult(NumberOrderCheckResult::FAILED, null, null, 'order_failed')];
        $this->getJson($this->statusUrl($workspace, $business))->assertOk()->assertJson(['state' => 'failed']);

        // No second order was placed, no number attached; the no-number page explains it once.
        $this->assertSame(0, BusinessMessagingNumber::query()->count());
        $this->get($this->showUrl($workspace, $business))->assertOk()
            ->assertSee("We couldn't finish " . self::PHONE)
            ->assertSee('Find a toll-free number');
    }

    public function test_platform_carrier_funds_problem_is_an_operator_issue_not_a_try_another_number_message(): void
    {
        $this->fake->provisionThrows = new MessagingPlatformFundsException('platform out of funds');
        [, $business, $workspace, $token] = $this->tenantWithToken();

        $this->post($this->orderUrl($workspace, $business), ['candidate_token' => $token])
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'temporarily unavailable') && str_contains($m, 'rather than choosing a different number'));

        $this->assertSame(0, BusinessMessagingNumberOrder::query()->count());
        $this->assertSame(0, BusinessMessagingNumber::query()->count());
    }

    public function test_another_business_cannot_see_or_poll_this_businesss_order(): void
    {
        $this->fake->provisionOutcome = 'processing';
        [, $business, $workspace, $token] = $this->tenantWithToken();
        $this->post($this->orderUrl($workspace, $business), ['candidate_token' => $token]);

        [$otherCustomer, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Other Studio', 'Other Workspace');
        $this->authenticateAs($otherCustomer);

        // Their own page/endpoint see nothing of ours.
        $this->getJson($this->statusUrl($otherWorkspace, $otherBusiness))->assertOk()->assertJson(['state' => 'none'])->assertDontSee(self::PHONE);
        $this->get($this->showUrl($otherWorkspace, $otherBusiness))->assertOk()->assertDontSee(self::PHONE)->assertDontSee('Setting up your number');

        // And ours cannot be reached through their access.
        $this->getJson($this->statusUrl($workspace, $business))->assertStatus(404)->assertDontSee(self::PHONE);
    }
}
