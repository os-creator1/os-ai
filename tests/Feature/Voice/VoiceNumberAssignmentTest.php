<?php

namespace Tests\Feature\Voice;

use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingProvisioningService;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Library\Voice\VoiceNumberAssigner;
use App\Models\BusinessMessagingNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

class VoiceNumberAssignmentTest extends TestCase
{
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    private const CONN = 'conn_fixture_1';
    private const REF = 'num_ref_1';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'messaging.voice.connection_id' => self::CONN,
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
        ]);
    }

    private function number(PhoneNumberType $type = PhoneNumberType::Local, ?string $ref = self::REF): BusinessMessagingNumber
    {
        [, , $number] = $this->managedBusiness();
        $number->forceFill(['number_type' => $type->value, 'provider_number_reference' => $ref])->save();

        return $number->fresh();
    }

    private function patched(string $connection = self::CONN): void
    {
        Http::fake(['api.telnyx.com/v2/phone_numbers/*' => Http::response(['data' => ['id' => self::REF, 'connection_id' => $connection]])]);
    }

    public function test_a_local_number_is_assigned_and_recorded_only_after_telnyx_confirms(): void
    {
        $this->patched();

        $number = app(VoiceNumberAssigner::class)->assign($this->number());

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->url() === 'https://api.telnyx.com/v2/phone_numbers/' . self::REF
            && $r['connection_id'] === self::CONN);
        $this->assertSame(self::CONN, $number->voice_connection_id);
        $this->assertNotNull($number->voice_assigned_at);
        $this->assertNull($number->voice_assignment_failure_reason);
    }

    public function test_an_already_confirmed_number_makes_no_request(): void
    {
        $this->patched();
        $assigner = app(VoiceNumberAssigner::class);
        $number = $assigner->assign($this->number());

        Http::fake();
        $assigner->assign($number);

        Http::assertNothingSent();
    }

    public function test_a_2xx_that_does_not_show_the_connection_is_not_recorded_as_assigned(): void
    {
        Http::fake(['api.telnyx.com/v2/phone_numbers/*' => Http::response(['data' => ['id' => self::REF, 'connection_id' => 'someone_else']])]);

        $number = app(VoiceNumberAssigner::class)->assign($this->number());

        $this->assertNull($number->voice_assigned_at);
        $this->assertNull($number->voice_connection_id);
        $this->assertSame('unconfirmed', $number->voice_assignment_failure_reason);
    }

    public function test_a_telnyx_error_is_recorded_and_not_thrown(): void
    {
        Http::fake(['api.telnyx.com/v2/phone_numbers/*' => Http::response(['errors' => [['code' => '10010']]], 422)]);

        $number = app(VoiceNumberAssigner::class)->assign($this->number());

        $this->assertNull($number->voice_assigned_at);
        $this->assertSame('http_422', $number->voice_assignment_failure_reason);
    }

    public function test_a_failed_assignment_can_be_retried_to_success(): void
    {
        Http::fake(['api.telnyx.com/v2/phone_numbers/*' => Http::sequence()
            ->push([], 500)
            ->push(['data' => ['id' => self::REF, 'connection_id' => self::CONN]])]);
        $assigner = app(VoiceNumberAssigner::class);
        $number = $assigner->assign($this->number());
        $this->assertSame('http_500', $number->voice_assignment_failure_reason);

        $number = $assigner->assign($number);

        $this->assertNotNull($number->voice_assigned_at);
        $this->assertNull($number->voice_assignment_failure_reason);
    }

    public function test_toll_free_inactive_unreferenced_and_unconfigured_numbers_are_skipped(): void
    {
        $this->patched();
        $assigner = app(VoiceNumberAssigner::class);

        $assigner->assign($this->number(PhoneNumberType::TollFree));
        $assigner->assign($this->number(PhoneNumberType::Local, null));
        config(['messaging.voice.connection_id' => '']);
        $assigner->assign($this->number());

        Http::assertNothingSent();
    }

    public function test_purchase_assigns_voice_to_local_numbers_but_a_failure_never_breaks_the_purchase(): void
    {
        $this->app->instance(MessagingProvisioningAdapter::class, new FakeProvisioningAdapter());
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => true,
        ]);
        Http::fake(['api.telnyx.com/v2/phone_numbers/*' => Http::response([], 503)]);

        $number = app(BusinessMessagingProvisioningService::class)
            ->provisionNumber($this->makeBusiness(), new AvailableNumberCandidate('+14155550101', PhoneNumberType::Local, 'cand-1'));

        $this->assertTrue($number->isActive(), 'The SMS purchase stands even though voice assignment failed.');
        $this->assertNull($number->voice_assigned_at);
        $this->assertSame('http_503', $number->voice_assignment_failure_reason);
    }

    public function test_purchase_of_a_toll_free_number_attempts_no_voice_assignment(): void
    {
        $this->app->instance(MessagingProvisioningAdapter::class, new FakeProvisioningAdapter());
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true]);
        Http::fake();

        $number = app(BusinessMessagingProvisioningService::class)
            ->provisionNumber($this->makeBusiness(), new AvailableNumberCandidate('+18005550100', PhoneNumberType::TollFree, 'cand-2'));

        Http::assertNothingSent();
        $this->assertNull($number->voice_assigned_at);
    }
}
