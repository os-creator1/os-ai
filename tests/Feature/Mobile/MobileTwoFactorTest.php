<?php

namespace Tests\Feature\Mobile;

use App\Library\Mobile\MobileTwoFactorChallenge;
use App\Models\MobileDevice;
use App\Models\User;
use App\Notifications\MobileTwoFactorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Mobile two-factor sign-in: a correct password for a 2FA account yields a single-use, expiring challenge (never a
 * token); `auth/two-factor` exchanges it plus the delivered code, or a web backup code, for the normal mobile token.
 */
class MobileTwoFactorTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';
    private const BACKUP = ['111111', '222222', '333333'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Notification::fake();

        [$customer] = $this->tenant(businessName: 'Alpha Studio', workspaceName: 'Alpha WS');
        $customer->forceFill(['permissions' => json_encode($this->allCustomerPermissions())])->save();
        $this->user = $customer->user;
        $this->user->forceFill([
            'password' => Hash::make(self::PASSWORD),
            'two_factor' => true,
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
            'two_factor_backup_code' => json_encode(array_map('intval', self::BACKUP)),
        ])->save();
    }

    private function url(string $path): string
    {
        return '/api/mobile/v1/' . $path;
    }

    private function startChallenge(string $install = 'install-2fa-0001'): string
    {
        $res = $this->postJson($this->url('auth/login'), [
            'email' => $this->user->email, 'password' => self::PASSWORD,
            'device' => ['installation_id' => $install, 'platform' => 'android', 'name' => 'Pixel', 'app_version' => '0.1.0'],
        ])->assertStatus(202);

        $res->assertJsonPath('data.two_factor_required', true)->assertJsonPath('data.methods', ['code', 'backup_code']);
        $this->assertNotNull($res->json('data.expires_at'));
        $this->assertNull($res->json('data.token'));

        return (string) $res->json('data.challenge');
    }

    private function lastCode(): string
    {
        $sent = Notification::sent($this->user, MobileTwoFactorCode::class);
        $this->assertNotEmpty($sent, 'no code was delivered');

        return $sent->last()->code();
    }

    private function verify(string $challenge, string $code, ?string $method = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url('auth/two-factor'), array_filter(['challenge' => $challenge, 'code' => $code, 'method' => $method]));
    }

    private function wrongCode(string $right): string
    {
        return $right === '000000' ? '000001' : '000000';
    }

    private function tokenCount(): int
    {
        return DB::table('personal_access_tokens')->where('tokenable_id', $this->user->id)->count();
    }

    public function test_password_alone_issues_no_token_and_sends_a_code_without_touching_the_web_code_column(): void
    {
        $challenge = $this->startChallenge();

        $this->assertSame(64, strlen($challenge));
        $this->assertSame(0, $this->tokenCount());
        $this->assertSame(0, MobileDevice::query()->count());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->lastCode());
        Notification::assertSentToTimes($this->user, MobileTwoFactorCode::class, 1);

        // An open web session of the same person must not be pushed to the web verify page.
        $this->user->refresh();
        $this->assertNull($this->user->two_factor_code);
        $this->assertNull($this->user->two_factor_expires_at);
    }

    public function test_the_right_code_exchanges_the_challenge_for_the_normal_mobile_token(): void
    {
        $challenge = $this->startChallenge();

        $res = $this->verify($challenge, $this->lastCode())->assertOk();

        $res->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.user.email', $this->user->email);
        $row = DB::table('personal_access_tokens')->where('tokenable_id', $this->user->id)->first();
        $this->assertSame(['mobile'], json_decode($row->abilities, true));
        $this->assertNotNull($row->expires_at);
        $device = MobileDevice::query()->where('user_id', $this->user->id)->sole();
        $this->assertSame('install-2fa-0001', $device->installation_id);
        $this->assertSame('android', $device->platform);

        // The token works.
        $this->withHeaders(['Authorization' => 'Bearer ' . $res->json('data.token')])
            ->getJson($this->url('businesses'))->assertOk();
    }

    public function test_a_challenge_is_single_use(): void
    {
        $challenge = $this->startChallenge();
        $code = $this->lastCode();
        $this->verify($challenge, $code)->assertOk();

        $this->verify($challenge, $code)->assertStatus(401)->assertJsonPath('error.code', 'two_factor_challenge_invalid');
        $this->assertSame(1, $this->tokenCount());
    }

    public function test_a_wrong_code_is_refused_and_the_challenge_dies_after_five_wrong_guesses(): void
    {
        $challenge = $this->startChallenge();
        $code = $this->lastCode();

        for ($i = 1; $i < MobileTwoFactorChallenge::MAX_ATTEMPTS; $i++) {
            $this->verify($challenge, $this->wrongCode($code))->assertStatus(422)->assertJsonPath('error.code', 'invalid_two_factor_code');
        }
        // The last allowed wrong guess burns the challenge.
        $this->verify($challenge, $this->wrongCode($code))->assertStatus(401)->assertJsonPath('error.code', 'two_factor_challenge_invalid');

        // Even the right code is useless now.
        $this->verify($challenge, $code)->assertStatus(401)->assertJsonPath('error.code', 'two_factor_challenge_invalid');
        $this->assertSame(0, $this->tokenCount());
    }

    public function test_an_expired_challenge_is_refused_even_with_the_right_code(): void
    {
        $challenge = $this->startChallenge();
        $code = $this->lastCode();

        $this->travel(MobileTwoFactorChallenge::TTL_MINUTES + 1)->minutes();

        $this->verify($challenge, $code)->assertStatus(401)->assertJsonPath('error.code', 'two_factor_challenge_invalid');
        $this->assertSame(0, $this->tokenCount());
    }

    public function test_an_unknown_challenge_and_bad_input_are_refused(): void
    {
        $this->verify(str_repeat('a', 64), '123456')->assertStatus(401)->assertJsonPath('error.code', 'two_factor_challenge_invalid');
        $this->verify('short', '123456')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->verify(str_repeat('a', 64), '12345')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->verify(str_repeat('a', 64), 'abcdef')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->verify(str_repeat('a', 64), '123456', 'totp')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, $this->tokenCount());
    }

    public function test_a_wrong_password_never_reaches_the_second_step(): void
    {
        $this->postJson($this->url('auth/login'), [
            'email' => $this->user->email, 'password' => 'wrong-wrong',
            'device' => ['installation_id' => 'install-2fa-0002', 'platform' => 'ios'],
        ])->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');

        Notification::assertNothingSent();
    }

    public function test_a_web_backup_code_works_once_and_is_consumed(): void
    {
        $challenge = $this->startChallenge();
        $this->verify($challenge, self::BACKUP[1], 'backup_code')->assertOk();

        $this->user->refresh();
        $left = array_map('strval', json_decode($this->user->two_factor_backup_code, true));
        $this->assertSame([self::BACKUP[0], self::BACKUP[2]], $left);

        // The same backup code cannot be replayed on a new sign-in.
        $again = $this->startChallenge('install-2fa-0003');
        $this->verify($again, self::BACKUP[1], 'backup_code')->assertStatus(422)->assertJsonPath('error.code', 'invalid_two_factor_code');
        $this->assertSame(1, $this->tokenCount());
    }

    public function test_the_delivered_code_is_not_accepted_as_a_backup_code_and_vice_versa(): void
    {
        $challenge = $this->startChallenge();
        $this->verify($challenge, $this->lastCode(), 'backup_code')->assertStatus(422);
        $this->verify($challenge, self::BACKUP[0])->assertStatus(422);
        $this->assertSame(0, $this->tokenCount());
    }

    public function test_resend_replaces_the_code_and_is_bounded(): void
    {
        $challenge = $this->startChallenge();
        $first = $this->lastCode();

        $this->postJson($this->url('auth/two-factor/resend'), ['challenge' => $challenge])->assertOk()->assertJsonPath('data.sent', true);
        $second = $this->lastCode();
        Notification::assertSentToTimes($this->user, MobileTwoFactorCode::class, 2);

        if ($first !== $second) {
            $this->verify($challenge, $first)->assertStatus(422);
        }

        for ($i = 1; $i < MobileTwoFactorChallenge::MAX_RESENDS; $i++) {
            $this->postJson($this->url('auth/two-factor/resend'), ['challenge' => $challenge])->assertOk();
        }
        $this->postJson($this->url('auth/two-factor/resend'), ['challenge' => $challenge])->assertStatus(429);

        $this->verify($challenge, $this->lastCode())->assertOk();
        $this->travel(61)->seconds(); // past the route throttle (5 resends/minute)
        $this->postJson($this->url('auth/two-factor/resend'), ['challenge' => $challenge])
            ->assertStatus(401)->assertJsonPath('error.code', 'two_factor_challenge_invalid');
    }

    public function test_wrong_guesses_are_limited_per_account_across_fresh_challenges(): void
    {
        $limit = \App\Http\Controllers\Mobile\MobileAuthController::TWO_FACTOR_FAILURES;
        $perChallenge = MobileTwoFactorChallenge::MAX_ATTEMPTS - 1; // stay below the per-challenge burn
        $guesses = 0;
        $install = 0;

        while ($guesses < $limit) {
            $challenge = $this->startChallenge('install-2fa-1' . str_pad((string) ++$install, 3, '0', STR_PAD_LEFT));
            $code = $this->lastCode();
            for ($i = 0; $i < $perChallenge && $guesses < $limit; $i++, $guesses++) {
                $this->verify($challenge, $this->wrongCode($code))->assertStatus(422);
            }
        }

        // Locked: the right code on a fresh challenge is refused too, so guessing cannot continue by re-logging in.
        $challenge = $this->startChallenge('install-2fa-1999');
        $this->verify($challenge, $this->lastCode())->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
        $this->assertSame(0, $this->tokenCount());
    }

    public function test_challenges_per_account_are_rate_limited(): void
    {
        for ($i = 0; $i < \App\Http\Controllers\Mobile\MobileAuthController::CHALLENGES_PER_WINDOW; $i++) {
            $this->startChallenge('install-2fa-2' . $i . '00');
        }

        $this->postJson($this->url('auth/login'), [
            'email' => $this->user->email, 'password' => self::PASSWORD,
            'device' => ['installation_id' => 'install-2fa-2999', 'platform' => 'ios'],
        ])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
        Notification::assertSentToTimes($this->user, MobileTwoFactorCode::class, \App\Http\Controllers\Mobile\MobileAuthController::CHALLENGES_PER_WINDOW);
    }

    public function test_disabling_the_account_mid_challenge_ends_it(): void
    {
        $challenge = $this->startChallenge();
        $code = $this->lastCode();
        $this->user->forceFill(['status' => false])->save();

        $this->verify($challenge, $code)->assertStatus(401)->assertJsonPath('error.code', 'two_factor_challenge_invalid');
        $this->assertSame(0, $this->tokenCount());
    }

    public function test_accounts_without_two_factor_still_sign_in_in_one_step(): void
    {
        $this->user->forceFill(['two_factor' => false])->save();

        $this->postJson($this->url('auth/login'), [
            'email' => $this->user->email, 'password' => self::PASSWORD,
            'device' => ['installation_id' => 'install-2fa-3000', 'platform' => 'ios'],
        ])->assertOk()->assertJsonPath('data.token_type', 'Bearer');

        Notification::assertNothingSent();
        $this->assertSame(1, $this->tokenCount());
    }
}
