<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Messaging\Registration\RegistrationProgress;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Presentation of the text messaging progress screen and the compact
 * "viewing as" banner. Backend lifecycle is owned by the 10DLC tests; this file
 * proves only what is rendered for a given saved state.
 */
class TextMessagingProgressScreenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;
    use CreatesBusinessTestData;

    private function render(array $state, ?string $phoneNumber = null, ?string $anchor = null): string
    {
        $registration = (new BusinessMessagingRegistration)->forceFill($state);

        return view('customer.settings.text-messaging._registration-progress', [
            'progress' => RegistrationProgress::for($registration),
            'registration' => $registration,
            'phoneNumber' => $phoneNumber,
            'campaignAssignmentStatus' => null,
            'numberPickerAnchor' => $anchor,
            'workspaceUid' => 'w1',
            'businessUid' => 'b1',
        ])->render();
    }

    public function test_brand_processing_shows_a_pending_step_one_and_no_failure(): void
    {
        $html = $this->render(['status' => 'not_started', 'number_type' => 'local', 'provider_brand_id' => 'B1', 'brand_status' => 'processing']);

        $this->assertStringContainsString('Step 1 of 3', $html);
        $this->assertMatchesRegularExpression('#data-role="stage-brand"\s+data-state="processing"#', $html);
        $this->assertMatchesRegularExpression('#data-role="stage-campaign"\s+data-state="not_started"#', $html);
        $this->assertStringNotContainsString('Failed', $html);
        $this->assertStringNotContainsString('Registration failed', $html);
    }

    public function test_verified_brand_with_pending_campaign_is_step_two_with_the_brand_id(): void
    {
        $html = $this->render(['status' => 'pending', 'number_type' => 'local', 'provider_brand_id' => 'B1', 'provider_brand_tcr_id' => 'BBBBBB1', 'brand_status' => 'verified', 'provider_campaign_id' => 'C1']);

        $this->assertStringContainsString('Step 2 of 3', $html);
        $this->assertStringContainsString('BBBBBB1', $html);
        $this->assertStringContainsString('Business verified', $html);
        $this->assertStringContainsString('In review', $html);
        $this->assertStringContainsString('Submitted and waiting for the carrier. This usually takes a few business days.', $html);
        $this->assertStringContainsString('You can pick a number once the campaign is approved.', $html);
        $this->assertStringContainsString('Not chosen yet', $html);
        $this->assertStringContainsString('Pending review', $html);
        $this->assertStringNotContainsString('data-role="choose-number"', $html);
        $this->assertMatchesRegularExpression('#data-role="can-send"[^>]*>\s*<span class="sr-chip">Not yet#', $html);
    }

    public function test_rejected_campaign_is_a_correction_not_a_pending_review(): void
    {
        $html = $this->render(['status' => 'rejected', 'number_type' => 'local', 'provider_brand_id' => 'B1', 'brand_status' => 'verified', 'provider_campaign_id' => 'C1', 'rejection_reason' => 'Website mismatch.']);

        $this->assertStringContainsString('Needs correction', $html);
        $this->assertStringContainsString('Website mismatch.', $html);
        $this->assertStringNotContainsString('Step 3 of 3', $html);
    }

    public function test_approved_campaign_opens_number_setup_and_never_claims_texting_works(): void
    {
        $html = $this->render(['status' => 'approved', 'number_type' => 'local', 'provider_brand_id' => 'B1', 'brand_status' => 'verified', 'provider_campaign_id' => 'C1'], null, '#choose-number');

        $this->assertStringContainsString('Step 3 of 3', $html);
        $this->assertStringContainsString('data-role="choose-number" href="#choose-number"', $html);
        $this->assertStringContainsString('Approved', $html);
        $this->assertMatchesRegularExpression('#data-role="can-send"[^>]*>\s*<span class="sr-chip">Not yet#', $html);

        $withNumber = $this->render(['status' => 'approved', 'number_type' => 'local', 'provider_brand_id' => 'B1', 'brand_status' => 'verified', 'provider_campaign_id' => 'C1'], '+14155550100');
        $this->assertStringContainsString('+14155550100', $withNumber);
        $this->assertStringNotContainsString('Not chosen yet', $withNumber);
    }

    public function test_an_existing_pending_campaign_renders_the_progress_screen_without_any_carrier_call_or_misleading_copy(): void
    {
        Http::fake();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        BusinessMessagingRegistration::create(array_merge($this->registrationPayload(), [
            'business_id' => $business->id, 'number_type' => 'local', 'status' => 'pending',
            'provider_brand_id' => 'B1', 'provider_brand_tcr_id' => 'BBBBBB1', 'brand_status' => 'verified',
            'provider_campaign_id' => 'C1', 'campaign_status_checked_at' => now()->subMinutes(5),
        ]));
        $this->authenticateAs($customer);

        $html = str_replace('&#039;', "'", $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk()->getContent());

        Http::assertNothingSent();
        $this->assertStringContainsString('Carrier review', $html);
        $this->assertStringContainsString('Step 2 of 3', $html);
        $this->assertStringContainsString('Last checked', $html);
        foreach (['Nothing has been charged', 'Registration failed', 'data-role="submit-business"', 'data-role="submit-campaign"', 'data-role="choose-toll-free-instead"', 'data-role="back-to-number-choices"'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
        $this->assertStringContainsString('data-role="refresh-status"', $html);
        $this->assertStringNotContainsString('data-role="impersonation-banner"', $html);
    }

    public function test_a_normal_customer_never_sees_the_view_as_banner_or_the_old_alert(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="impersonation-banner"', $html);
        $this->assertStringNotContainsString('Return to Platform Owner', $html);
        $this->assertStringNotContainsString('Attention!!', $html);
    }

    public function test_the_platform_owner_sees_the_compact_banner_with_the_live_business_name_and_the_existing_exit(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->withSession(['admin_user_id' => 1, 'admin_user_name' => 'Platform Owner', 'temp_user_id' => $customer->id])
            ->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="impersonation-banner"', $html);
        $this->assertStringContainsString('<strong data-role="impersonation-name">' . e($customer->user->displayName()) . '</strong>', $html);
        $this->assertStringContainsString('Changes you make may apply to their account.', $html);
        $this->assertMatchesRegularExpression('#<form method="POST" action="' . preg_quote(route('logout'), '#') . '"[^>]*>\s*<input type="hidden" name="_token"[^>]*>\s*<button type="submit" class="va-bar__exit"[^>]*>Return to Platform Owner</button>#', $html);
        $this->assertStringNotContainsString('<a class="va-bar__exit"', $html);
        $this->assertStringNotContainsString('Attention!!', $html);
        $this->assertStringNotContainsString('Lumina Aura', $html);
    }

    public function test_the_banner_exit_restores_the_platform_owner_session(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $owner = \App\Models\User::create([
            'first_name' => 'Platform', 'last_name' => 'Owner', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->authenticateAs($customer);

        $this->withSession(['admin_user_id' => $owner->id, 'admin_user_name' => 'Platform Owner', 'temp_user_id' => $customer->user->id])
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertAuthenticatedAs($owner);
        $this->assertNull(session('admin_user_id'));
        $this->assertNull(session('temp_user_id'));
    }

    public function test_the_banner_exit_restores_the_parent_account_session(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $parent = $this->createCustomer()->user;
        $parent->forceFill(['email_verified_at' => now()])->save();
        $this->authenticateAs($customer);

        $this->withSession(['parent_user_id' => $parent->id, 'parent_user_name' => $parent->displayName(), 'temp_user_id' => $customer->user->id])
            ->post(route('logout'))
            ->assertRedirect(route('user.home'));

        $this->assertAuthenticatedAs($parent);
        $this->assertNull(session('parent_user_id'));
        $this->assertNull(session('temp_user_id'));
    }

    public function test_an_approved_local_registration_without_a_number_shows_step_three_on_the_existing_number_picker(): void
    {
        Http::fake();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        BusinessMessagingRegistration::create(array_merge($this->registrationPayload(), [
            'business_id' => $business->id, 'number_type' => 'local', 'status' => 'approved', 'approved_at' => now(),
            'provider_brand_id' => 'B1', 'provider_brand_tcr_id' => 'BBBBBB1', 'brand_status' => 'verified', 'provider_campaign_id' => 'C1',
        ]));
        $this->authenticateAs($customer);

        $url = route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]);
        $html = $this->get($url)->assertOk()->getContent();

        Http::assertNothingSent();
        $this->assertStringContainsString('Step 3 of 3', $html);
        $this->assertStringContainsString('data-role="registration-progress"', $html);
        // The CTA is an in-page anchor to the picker, never a link back to this same page.
        $this->assertStringContainsString('data-role="choose-number" href="#choose-number"', $html);
        $this->assertStringNotContainsString('href="' . $url . '"', $html);
        $this->assertStringContainsString('id="choose-number"', $html);
        // The existing search form (no purchase) is still the only action offered here.
        $this->assertStringContainsString(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), $html);
        $this->assertStringNotContainsString('data-role="verified-banner"', $html);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'legal_business_name' => 'Harbor Lane Studios LLC',
            'entity_type' => 'ein',
            'ein' => '12-3456789',
            'address_line_1' => '1 Harbor Lane',
            'city' => 'Portland',
            'region' => 'OR',
            'postal_code' => '97201',
            'website_url' => 'https://harborlane.example',
            'contact_email' => 'owner@harborlane.example',
            'contact_phone' => '+15035550100',
            'use_case' => 'appointment_reminders',
            'opt_in_method' => 'Customers check a box on our booking form.',
            'campaign_description' => 'Harbor Lane sends appointment confirmations and reminders to customers who booked.',
            'consent_confirmations' => array_keys(\App\Library\Messaging\Registration\RegistrationTemplateCatalog::CONSENT_ITEMS),
            'sample_message_1' => 'Harbor Lane: your appointment is confirmed. Reply STOP to unsubscribe.',
            'sample_message_2' => 'Harbor Lane: reminder, your appointment is tomorrow.',
            'privacy_policy_url' => 'https://harborlane.example/privacy',
            'terms_url' => 'https://harborlane.example/terms', 'support_contact' => 'help@fixture.example', 'support_contact_confirmed' => '1', 'support_contact_confirmed_for' => 'help@fixture.example', 'support_contact_confirmed_at' => '2026-01-01 00:00:00',
            'contact_first_name' => 'Pat', 'contact_last_name' => 'Owner', 'message_volume' => '1,000', 'opt_in_image_url' => 'https://example.com/opt-in.png', 'brand_vertical' => 'ENTERTAINMENT',
        ], $overrides);
    }

    public function test_toll_free_carrier_review_shows_the_four_stage_timeline_summary_and_port_out(): void
    {
        $number = (new \App\Models\BusinessMessagingNumber)->forceFill(['phone_number' => '+18446980765', 'monthly_cost_micro' => 1_100_000, 'cost_currency' => 'USD']);
        $number->id = 7;
        $registration = (new BusinessMessagingRegistration)->forceFill(['status' => 'pending', 'number_type' => 'toll_free', 'campaign_status_checked_at' => now()->subMinutes(5)]);

        $html = view('customer.settings.text-messaging._tollfree-review', [
            'registration' => $registration,
            'phoneNumber' => '+18446980765',
            'retainedNumbers' => collect([$number]),
            'portOutRequestsByNumberId' => [],
            'workspaceUid' => 'w1',
            'businessUid' => 'b1',
        ])->render();

        $this->assertStringContainsString('Step 3 of 4', $html);
        foreach (['Toll-free number', 'Toll-free verification', 'Carrier review', 'Ready to text', 'Purchased · +1 (844) 698-0765', 'Pending review', 'Toll-free', 'USD 1.10', 'Billing', 'Last checked 5 minutes ago', 'Refresh status', 'Port this number out any time.', 'Request to port this number out'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertMatchesRegularExpression('#data-role="can-send"[^>]*>\s*<span class="sr-chip">Not yet#', $html);
        $this->assertStringContainsString('name="number_id" value="7"', $html);
    }
}
