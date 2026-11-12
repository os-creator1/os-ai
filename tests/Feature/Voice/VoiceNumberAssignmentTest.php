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
    private const REF = 'num_ref_1';      // what the platform stores: NOT the phone-number resource id
    private const RESOURCE = '3081436796672803842'; // the real /phone_numbers resource id Telnyx returns for the E.164

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'messaging.voice.connection_id' => self::CONN,
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'services.telnyx.mode' => 'live',
        ]);
    }

    private function number(PhoneNumberType $type = PhoneNumberType::Local, ?string $ref = self::REF): BusinessMessagingNumber
    {
        [, , $number] = $this->managedBusiness();
        $number->forceFill(['number_type' => $type->value, 'provider_number_reference' => $ref])->save();

        return $number->fresh();
    }

    /** @var list<array{0:int,1:array}> PATCH responses, consumed in order (the last one repeats) */
    private array $patchQueue = [];
    private string $verifyConnection = self::CONN;
    private bool $lookupFindsNumber = true;

    /**
     * Fakes the three Telnyx calls the assigner makes: the by-E.164 lookup, the PATCH, and the verifying GET.
     *
     * @param list<array{0:int,1:array}>|null $patches
     */
    private function fakeTelnyx(?array $patches = null, string $verifyConnection = self::CONN, bool $lookupFindsNumber = true): void
    {
        $this->patchQueue = $patches ?? [[200, ['data' => ['id' => self::RESOURCE, 'connection_id' => self::CONN]]]];
        $this->verifyConnection = $verifyConnection;
        $this->lookupFindsNumber = $lookupFindsNumber;

        Http::fake(['api.telnyx.com/v2/phone_numbers*' => function (Request $r) {
            if ($r->method() === 'PATCH') {
                [$status, $body] = count($this->patchQueue) > 1 ? array_shift($this->patchQueue) : $this->patchQueue[0];

                return Http::response($body, $status);
            }
            if (str_contains($r->url(), 'filter')) {
                return Http::response(['data' => $this->lookupFindsNumber
                    ? [['id' => self::RESOURCE, 'phone_number' => $r->data()['filter[phone_number]'] ?? null, 'status' => 'active']]
                    : []]);
            }

            return Http::response(['data' => ['id' => self::RESOURCE, 'connection_id' => $this->verifyConnection]]);
        }]);
    }

    private function patched(): void
    {
        $this->fakeTelnyx();
    }

    public function test_a_local_number_is_assigned_and_recorded_only_after_telnyx_confirms(): void
    {
        $this->patched();

        $number = app(VoiceNumberAssigner::class)->assign($this->number());

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->url() === 'https://api.telnyx.com/v2/phone_numbers/' . self::RESOURCE // resolved id, never the stored reference
            && $r['connection_id'] === self::CONN);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), self::REF));
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
        $this->fakeTelnyx([[200, ['data' => ['id' => self::RESOURCE, 'connection_id' => 'someone_else']]]], 'someone_else');

        $number = app(VoiceNumberAssigner::class)->assign($this->number());

        $this->assertNull($number->voice_assigned_at);
        $this->assertNull($number->voice_connection_id);
        $this->assertSame('unconfirmed', $number->voice_assignment_failure_reason);
    }

    public function test_a_telnyx_error_is_recorded_and_not_thrown(): void
    {
        $this->fakeTelnyx([[422, ['errors' => [['code' => '10010']]]]]);

        $number = app(VoiceNumberAssigner::class)->assign($this->number());

        $this->assertNull($number->voice_assigned_at);
        $this->assertSame('http_422', $number->voice_assignment_failure_reason);
    }

    public function test_a_failed_assignment_can_be_retried_to_success(): void
    {
        $this->fakeTelnyx([[500, []], [200, ['data' => ['id' => self::RESOURCE, 'connection_id' => self::CONN]]]]);
        $assigner = app(VoiceNumberAssigner::class);
        $number = $assigner->assign($this->number());
        $this->assertSame('http_500', $number->voice_assignment_failure_reason);

        $number = $assigner->assign($number);

        $this->assertNotNull($number->voice_assigned_at);
        $this->assertNull($number->voice_assignment_failure_reason);
    }

    public function test_a_number_telnyx_cannot_resolve_to_a_resource_is_recorded_not_patched(): void
    {
        $this->fakeTelnyx(null, self::CONN, false);

        $number = app(VoiceNumberAssigner::class)->assign($this->number());

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertNull($number->voice_assigned_at);
        $this->assertSame('resource_not_found', $number->voice_assignment_failure_reason);
    }

    public function test_outside_live_mode_nothing_is_sent_and_no_attempt_is_consumed(): void
    {
        $this->fakeTelnyx();
        config(['services.telnyx.mode' => 'sandbox']);
        $assigner = app(VoiceNumberAssigner::class);

        $number = $assigner->assign($this->number());
        $this->assertSame(0, (int) $number->voice_assignment_attempts);
        $this->assertNull($number->voice_assignment_failure_reason);

        // The retry command is equally inert for an already-failed number.
        $failed = $this->failedNumber();
        $this->artisan('voice:retry-number-assignments')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(1, (int) $failed->fresh()->voice_assignment_attempts);
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
        $this->fakeTelnyx([[503, []]]);

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

    // -----------------------------------------------------------------
    // Automatic recovery: voice:retry-number-assignments
    // -----------------------------------------------------------------

    private function failedNumber(array $overrides = []): BusinessMessagingNumber
    {
        $number = $this->number();
        \DB::table('business_messaging_numbers')->where('id', $number->id)->update($overrides + [
            'voice_assignment_failure_reason' => 'http_500',
            'voice_assignment_attempts' => 1,
            'voice_assignment_next_attempt_at' => now()->subMinute(),
        ]);

        return $number->fresh();
    }

    public function test_a_failure_schedules_a_backed_off_retry_and_stops_at_the_attempt_cap(): void
    {
        config(['messaging.voice.assignment_retry.max_attempts' => 2, 'messaging.voice.assignment_retry.base_backoff_minutes' => 5]);
        $this->fakeTelnyx([[500, []]]);
        $assigner = app(VoiceNumberAssigner::class);

        $number = $assigner->assign($this->number());
        $this->assertSame(1, (int) $number->voice_assignment_attempts);
        $this->assertEqualsWithDelta(5, now()->diffInMinutes($number->voice_assignment_next_attempt_at, false), 1);

        $number = $assigner->assign($number);
        $this->assertSame(2, (int) $number->voice_assignment_attempts);
        $this->assertNull($number->voice_assignment_next_attempt_at, 'No further retry once attempts are exhausted.');
        $this->assertSame([5, 10, 20, 40], array_map(fn ($a) => VoiceNumberAssigner::backoffMinutes($a), [1, 2, 3, 4]));
    }

    public function test_the_command_retries_a_due_failed_number_to_success_once(): void
    {
        $number = $this->failedNumber();
        $this->patched();

        $this->artisan('voice:retry-number-assignments')->assertExitCode(0);
        $number = $number->fresh();
        $this->assertNotNull($number->voice_assigned_at);
        $this->assertNull($number->voice_assignment_next_attempt_at);

        // A confirmed number is never selected or patched again.
        Http::fake();
        $this->artisan('voice:retry-number-assignments')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_the_command_skips_not_due_exhausted_toll_free_and_never_attempted_numbers(): void
    {
        config(['messaging.voice.assignment_retry.max_attempts' => 3]);
        $this->patched();
        $this->failedNumber(['voice_assignment_next_attempt_at' => now()->addHour()]);             // not due
        $this->failedNumber(['voice_assignment_attempts' => 3]);                                   // exhausted
        $this->failedNumber(['number_type' => PhoneNumberType::TollFree->value]);                  // toll-free
        $this->failedNumber(['voice_assignment_failure_reason' => null]);                          // never attempted

        $this->artisan('voice:retry-number-assignments')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_a_number_already_leased_by_another_run_is_not_attempted_twice(): void
    {
        $number = $this->failedNumber();
        $this->patched();
        // Another worker has claimed it: its lease pushed the next attempt into the future.
        \DB::table('business_messaging_numbers')->where('id', $number->id)->update(['voice_assignment_next_attempt_at' => now()->addMinutes(10)]);

        $this->artisan('voice:retry-number-assignments')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_the_command_is_a_safe_no_op_without_a_voice_connection(): void
    {
        $number = $this->failedNumber();
        config(['messaging.voice.connection_id' => '']);
        Http::fake();

        $this->artisan('voice:retry-number-assignments')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(1, (int) $number->fresh()->voice_assignment_attempts);
    }
}
