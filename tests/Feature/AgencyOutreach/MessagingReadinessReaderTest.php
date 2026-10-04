<?php

namespace Tests\Feature\AgencyOutreach;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Http\Controllers\Customer\Business\TextMessagingController;
use App\Library\AgencyOutreach\MessagingReadinessReader;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * MessagingReadinessReader is TextMessagingController::situation() extracted
 * verbatim: same four states, and the controller delegates to it.
 */
class MessagingReadinessReaderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private function registration($business, string $status, string $type = 'local'): BusinessMessagingRegistration
    {
        return BusinessMessagingRegistration::create([
            'business_id' => $business->id, 'number_type' => $type, 'status' => $status, 'approved_at' => $status === 'approved' ? now() : null,
            'legal_business_name' => 'Parity LLC', 'entity_type' => 'ein', 'ein' => '12-3456789', 'address_line_1' => '1 Main St',
            'city' => 'Portland', 'region' => 'OR', 'postal_code' => '97201', 'website_url' => 'https://example.com',
            'contact_email' => 'o@example.com', 'contact_phone' => '+15035550100', 'use_case' => 'customer_care',
            'opt_in_method' => 'Form.', 'sample_message_1' => 'Hi. Reply STOP.', 'sample_message_2' => 'Hello. Reply STOP.',
            'privacy_policy_url' => 'https://example.com/p', 'terms_url' => 'https://example.com/t',
        ]);
    }

    private function situationFor($business): array
    {
        $reader = app(MessagingReadinessReader::class)->situation($business);

        $controller = app(TextMessagingController::class);
        $method = new ReflectionMethod($controller, 'situation');
        $method->setAccessible(true);
        $viaController = $method->invoke($controller, $business);

        $this->assertEquals($reader['state'], $viaController['state'], 'The controller must delegate to the reader.');
        $this->assertSame($reader['phoneNumber'], $viaController['phoneNumber']);
        $this->assertSame($reader['textingAvailable'], $viaController['textingAvailable']);

        return $reader;
    }

    public function test_no_number_and_nothing_else(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Agency);

        $s = $this->situationFor($business);

        $this->assertSame('no_number', $s['state']);
        $this->assertNull($s['phoneNumber']);
        $this->assertFalse($s['textingAvailable']);
    }

    public function test_verified_local_registration_without_a_number_needs_a_number_and_unverified_needs_registration(): void
    {
        [, $approvedBusiness] = $this->tenant(WorkspacePlanTier::Agency, 'Approved Biz', 'Approved WS');
        $this->registration($approvedBusiness, 'approved');
        $this->assertSame('number_required', $this->situationFor($approvedBusiness)['state']);

        [, $pendingBusiness] = $this->tenant(WorkspacePlanTier::Agency, 'Pending Biz', 'Pending WS');
        $this->registration($pendingBusiness, 'pending');
        $this->assertSame('registration_required', $this->situationFor($pendingBusiness)['state']);
    }

    public function test_a_number_without_an_approved_registration_is_registration_required_and_with_one_is_ready(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Agency);
        $this->attachNumber($this->attachIdentity($business), '+14155550777');

        $s = $this->situationFor($business);
        $this->assertSame('registration_required', $s['state']);
        $this->assertSame('+14155550777', $s['phoneNumber']);
        $this->assertFalse($s['textingAvailable']);

        $this->registration($business, 'approved');
        $s = $this->situationFor($business);
        $this->assertSame('ready', $s['state']);
        $this->assertTrue($s['textingAvailable']);
        $this->assertTrue($s['mediaAvailable']);
        $this->assertArrayHasKey('retainedNumbers', $s);
        $this->assertArrayHasKey('portOutRequestsByNumberId', $s);
    }

    public function test_the_reader_is_scoped_to_the_business_it_is_given(): void
    {
        [, $ready] = $this->tenant(WorkspacePlanTier::Agency, 'Ready Biz', 'Ready WS');
        $this->attachNumber($this->attachIdentity($ready), '+14155550888');
        $this->registration($ready, 'approved');
        [, $other] = $this->tenant(WorkspacePlanTier::Agency, 'Other Biz', 'Other WS');

        $this->assertSame('ready', $this->situationFor($ready)['state']);
        $this->assertSame('no_number', $this->situationFor($other)['state']);
    }
}
