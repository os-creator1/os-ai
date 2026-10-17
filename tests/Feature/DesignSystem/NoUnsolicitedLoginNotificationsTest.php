<?php

namespace Tests\Feature\DesignSystem;

use App\Library\Feedback\PageToasts;
use App\Models\User;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

/**
 * Customers never get an automatic welcome-back or "no active subscription"
 * popup after login, refresh or navigation. Subscription enforcement and
 * action-result notifications are untouched.
 */
class NoUnsolicitedLoginNotificationsTest extends TestCase
{
    private function sessionWith(array $data): Store
    {
        $store = new Store('t', new ArraySessionHandler(10));
        $store->put($data);

        return $store;
    }

    public function test_the_check_subscription_flash_never_produces_a_toast(): void
    {
        $user = new User(['is_customer' => true]);
        $user->active_portal = 'customer';

        $toasts = PageToasts::forPage($this->sessionWith(['check_subscription' => true]), null, [], $user);

        $this->assertSame([], $toasts);
    }

    public function test_action_result_flashes_still_toast(): void
    {
        $toasts = PageToasts::forPage($this->sessionWith(['status' => 'success', 'message' => 'Member added']), null, [], null);

        $this->assertSame([['variant' => 'success', 'title' => null, 'message' => 'Member added']], $toasts);
    }

    public function test_login_code_no_longer_flashes_a_welcome_or_subscription_check_for_customers(): void
    {
        $login = (string) file_get_contents(app_path('Http/Controllers/Auth/LoginController.php'));
        $account = (string) file_get_contents(app_path('Http/Controllers/User/AccountController.php'));
        $toasts = (string) file_get_contents(app_path('Library/Feedback/PageToasts.php'));

        $this->assertStringNotContainsString("flash('check_subscription')", $login);
        $this->assertStringNotContainsString('check_subscription', $toasts);
        $this->assertStringNotContainsString('no_active_subscription', $toasts);
        $this->assertStringContainsString('isCustomerPortal($user)', $login, 'Only the admin portal keeps its greeting.');
        $this->assertMatchesRegularExpression("/route\('user\.home'\);/", $account);
        $this->assertDoesNotMatchRegularExpression("/route\('user\.home'\)->with\(\[[^\]]*welcome_come_back/s", $account);
        $this->assertDoesNotMatchRegularExpression("/route\('user\.home'\)->with\(\[[^\]]*welcome_come_back/s", $login);
    }
}
