<?php

namespace Tests\Feature\Auth;

use App\Library\PlatformBilling\PendingVerification;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * A signed-out customer who clicks the emailed verification link is sent to sign in
 * and brought back to finish verifying - never a bare 401.
 */
class EmailVerificationContinuationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        config(['account.verify_account' => true, 'app.two_factor' => false]);
    }

    private function user(bool $verified = false): User
    {
        $user = User::create([
            'first_name' => 'Pat', 'last_name' => 'Rivera', 'email' => 'pat' . uniqid() . '@example.test',
            'password' => Hash::make('correct-horse-battery'), 'status' => true, 'is_admin' => false,
            'is_customer' => true, 'active_portal' => 'customer',
        ]);

        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        } else {
            $user->forceFill(['email_verified_at' => null])->save();
        }

        return $user->fresh();
    }

    private function link(User $user, ?\DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute('verification.verify', $expires ?? now()->addMinutes(60), [
            'id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification()),
        ]);
    }

    private function signIn(User $user)
    {
        return $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-horse-battery']);
    }

    public function test_the_registration_style_notification_carries_a_signed_link(): void
    {
        Notification::fake();
        $user = $this->user();
        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_contains($url, '/email/verify/') && str_contains($url, 'expires=') && str_contains($url, 'signature=');
        });
    }

    public function test_correct_authenticated_user_verifies_normally(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get($this->link($user))->assertRedirect();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_guest_is_sent_to_login_not_a_401_and_login_completes_verification(): void
    {
        $user = $this->user();
        $link = $this->link($user);

        $first = $this->get($link);
        $first->assertRedirect(route('login'));
        $this->assertSame('info', session('status'), 'Guest click must remember the link (info), not reject it: ' . session('message'));
        $this->assertNotNull(session(PendingVerification::KEY), 'The signed link was not remembered.');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $response = $this->signIn($user);
        $this->assertNotNull(session(PendingVerification::KEY), 'Sign-in dropped the remembered link; Location=' . $response->headers->get('Location'));
        $this->assertStringStartsWith('/email/verify/', '/' . ltrim(parse_url($response->headers->get('Location'), PHP_URL_PATH), '/'));

        $this->get($response->headers->get('Location'))->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertNull(session(PendingVerification::KEY));
    }

    public function test_session_lost_between_signup_and_click_still_completes(): void
    {
        $user = $this->user();
        $link = $this->link($user);

        // Fresh session, no remembered state at all.
        $this->flushSession();
        $this->get($link)->assertRedirect(route('login'));
        $this->get($this->signIn($user)->headers->get('Location'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_guest_who_signs_in_as_the_wrong_user_verifies_nobody(): void
    {
        $owner = $this->user();
        $other = $this->user(true);
        $link = $this->link($owner);

        $this->get($link)->assertRedirect(route('login'));
        $location = $this->signIn($other)->headers->get('Location');
        $this->get($location)->assertRedirect()->assertSessionHas('status', 'error');

        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertNull(session(PendingVerification::KEY));
    }

    public function test_wrong_authenticated_user_cannot_verify_someone_elses_email(): void
    {
        $owner = $this->user();
        $other = $this->user(true);

        $this->actingAs($other)->get($this->link($owner))->assertRedirect()->assertSessionHas('status', 'error');

        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
    }

    public function test_invalid_signature_is_handled_without_verifying_or_a_401(): void
    {
        $user = $this->user();
        $tampered = $this->link($user) . 'x';

        $this->get($tampered)->assertRedirect(route('login'))->assertSessionHas('status', 'error');
        $this->assertNull(session(PendingVerification::KEY));

        $this->actingAs($user)->get($tampered)->assertRedirect(route('verification.notice'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_signature_is_handled_without_verifying(): void
    {
        $user = $this->user();
        $expired = $this->link($user, now()->subMinute());

        $this->get($expired)->assertRedirect(route('login'));
        $this->actingAs($user)->get($expired)->assertRedirect(route('verification.notice'));

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_already_verified_user_is_not_re_verified_or_looped(): void
    {
        $user = $this->user(true);
        $at = $user->email_verified_at;

        $this->actingAs($user)->get($this->link($user))->assertRedirect();

        $this->assertEquals($at, $user->fresh()->email_verified_at);
    }

    public function test_a_pending_link_is_only_remembered_for_our_own_verification_route(): void
    {
        PendingVerification::remember('https://evil.example/email/verify/1/' . str_repeat('a', 40) . '?x=1');
        PendingVerification::remember('/dashboard');
        $this->assertNull(PendingVerification::uri());

        PendingVerification::remember('/email/verify/1/' . str_repeat('a', 40) . '?expires=1&signature=abc');
        $this->assertNotNull(PendingVerification::uri());
    }

    public function test_resend_notification_still_works(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->actingAs($user)->post(route('verification.send'))->assertSessionHas('status', 'resent');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_two_factor_is_not_bypassed_by_a_verification_link(): void
    {
        config(['app.two_factor' => true]);
        $user = $this->user();
        $user->forceFill(['two_factor' => 1, 'two_factor_code' => 123456, 'two_factor_expires_at' => now()->addMinutes(10)])->save();

        $this->actingAs($user)->get($this->link($user))->assertRedirect(route('verify.index'));

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
