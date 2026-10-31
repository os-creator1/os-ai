<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The toll-free verification status page: one card-based Progress + Summary layout (the same tm-* system as
 * Local / 10DLC) for pending, rejected and approved-but-not-ready, with a compact port-out card. Real pages;
 * set STATUS_DUMP_DIR to also write each full HTML page for browser checks.
 */
class TextMessagingTollFreeStatusPageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private function page(string $status, array $registration = [], string $type = 'toll_free', string $phone = '+18449506346', ?int $monthly = 1_100_000): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Booths To The Max');
        $number = $this->attachNumber($this->attachIdentity($business), $phone);
        BusinessMessagingNumber::query()->whereKey($number->id)->update(['number_type' => $type, 'monthly_cost_micro' => $monthly, 'upfront_cost_micro' => 1_000_000, 'cost_currency' => 'USD']);
        $this->authenticateAs($customer);

        if ($status !== 'none') {
            BusinessMessagingRegistration::create(array_merge([
                'business_id' => $business->id, 'number_type' => $type, 'status' => $status, 'legal_business_name' => 'Booths To The Max LLC',
                'entity_type' => 'ein', 'ein' => '12-3456789', 'address_line_1' => '1 Main', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78701',
                'country_code' => 'US', 'website_url' => 'https://booths.example', 'contact_email' => 'a@booths.example', 'contact_phone' => '+15125550100',
                'use_case' => 'customer_care',
            ], $registration));
        }

        $html = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        if (($dir = getenv('STATUS_DUMP_DIR')) !== false && $dir !== '') {
            file_put_contents($dir . '/' . $status . '-' . $type . '.html', $html);
        }

        return [$html, $number, $business, $workspace];
    }

    private function assertSingleCardLayout(string $html): void
    {
        $this->assertSame(1, substr_count($html, 'class="tm-grid"'), 'exactly one progress/summary layout');
        $this->assertStringNotContainsString('pm-tracker', $html);
        $this->assertStringNotContainsString('pm-numbercard', $html);
        $this->assertStringNotContainsString('Port a number out', $html);
    }

    public function test_pending_toll_free_shows_the_vertical_progress_summary_and_compact_port_out(): void
    {
        [$html, $number, $business, $workspace] = $this->page('pending');

        $this->assertSingleCardLayout($html);
        $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES));
        foreach (['Carrier review', 'Step 3 of 4', 'Toll-free number', 'Toll-free verification', 'In review', 'Ready to text', 'Purchased · +1 (844) 950-6346',
            'Pending review', 'Not yet', 'Toll-free', 'USD 1.10', 'Refresh status', 'Move to another carrier', 'Port this number out any time.',
            "There's nothing you need to do right now."] as $expected) {
            $this->assertStringContainsString($expected, $text, $expected);
        }
        $this->assertStringContainsString('Request to port this number out', $html);
        $this->assertStringContainsString('registration/refresh', $html);
        $this->assertStringContainsString('name="number_id" value="' . $number->id . '"', $html);
        $this->assertStringContainsString('usage-billing', $html);
        // Never claims Ready, and an unrecorded check time is stated honestly.
        $this->assertStringNotContainsString('Texting enabled', $html);
        $this->assertStringContainsString('Not checked with the carrier yet', $html);
    }

    public function test_the_monthly_price_is_the_persisted_one_not_a_constant(): void
    {
        [$html] = $this->page('pending', [], 'toll_free', '+18449506347', 2_350_000);
        $this->assertStringContainsString('USD 2.35', $html);
        $this->assertStringNotContainsString('USD 1.10', $html);
    }

    public function test_last_checked_uses_persisted_status_data(): void
    {
        [$html] = $this->page('pending', ['campaign_status_checked_at' => now()->subMinutes(5)]);
        $this->assertStringContainsString('Last checked 5 minutes ago', $html);
    }

    public function test_rejected_toll_free_keeps_the_real_reason_and_the_correction_form(): void
    {
        [$html] = $this->page('rejected', ['rejection_reason' => 'Opt-in page was not publicly reachable.']);

        $this->assertSingleCardLayout($html);
        $this->assertStringContainsString('Needs correction', $html);
        $this->assertStringContainsString('Opt-in page was not publicly reachable.', $html);
        $this->assertStringContainsString('data-role="rejection-reason"', $html);
        $this->assertStringContainsString('registration/refresh', $html);
        // The correction form is still there and still posts to the draft route.
        $this->assertStringContainsString('id="registration-form"', $html);
        $this->assertStringContainsString('name="legal_business_name"', $html);
        $this->assertStringNotContainsString('Texting enabled', $html);
    }

    public function test_an_approved_toll_free_number_the_platform_confirms_ready_gets_the_existing_ready_screen(): void
    {
        // Readiness is decided by the existing rules (not this page): an approved toll-free number is Ready.
        [$html] = $this->page('approved');

        $this->assertStringNotContainsString('class="tm-grid"', $html);
        $this->assertStringContainsString('data-role="phone-number"', $html);
        $this->assertStringContainsString('Ready', $html);
    }

    public function test_the_status_partial_never_marks_ready_to_text_complete_when_approved_but_not_confirmed(): void
    {
        $number = (new BusinessMessagingNumber)->forceFill(['phone_number' => '+18449506346', 'monthly_cost_micro' => 1_100_000, 'cost_currency' => 'USD']);
        $number->id = 3;

        foreach (['requested' => 'Almost ready', 'failed' => "couldn't confirm your number is enabled"] as $assignment => $copy) {
            $registration = (new BusinessMessagingRegistration)->forceFill(['status' => 'approved', 'number_type' => 'toll_free']);
            $html = view('customer.settings.text-messaging._tollfree-review', [
                'registration' => $registration, 'phoneNumber' => '+18449506346', 'campaignAssignmentStatus' => $assignment,
                'retainedNumbers' => collect([$number]), 'portOutRequestsByNumberId' => [], 'workspaceUid' => 'w', 'businessUid' => 'b',
            ])->render();

            $this->assertStringContainsString('Step 4 of 4', $html);
            $this->assertStringContainsString($copy, $html);
            $this->assertMatchesRegularExpression('#tm-step--(active|problem)"\s+data-role="stage-ready"#', $html);
            $this->assertDoesNotMatchRegularExpression('#tm-step--done"\s+data-role="stage-ready"#', $html);
        }
    }

    public function test_before_submission_keeps_the_working_form_and_is_not_replaced_by_a_review_screen(): void
    {
        [$html] = $this->page('not_started');

        $this->assertStringNotContainsString('class="tm-grid"', $html);
        $this->assertStringNotContainsString('Step 3 of 4', $html);
        $this->assertStringContainsString('id="registration-form"', $html);
        $this->assertStringContainsString('name="opt_in_image_url"', $html);
    }

    public function test_local_registration_is_unchanged(): void
    {
        [$html] = $this->page('pending', ['provider_brand_id' => 'B1', 'provider_campaign_id' => 'C1', 'brand_status' => 'verified'], 'local', '+14155550444');

        $this->assertSame(1, substr_count($html, 'class="tm-grid"'));
        $this->assertStringContainsString('Step 2 of 3', $html);
        $this->assertStringNotContainsString('Step 3 of 4', $html);
        $this->assertStringNotContainsString('Toll-free verification', $html);
    }
}
