<?php

namespace Tests\Feature\DesignSystem;

use App\Library\Feedback\PageToasts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * Customer notification cleanup — customer-facing pages use one Business OS
 * toast (<x-toast-region>) instead of the inherited Toastr notifications:
 * no "Oops..!!" / "Success!!" titles, no Toastr styling, and never the same
 * message as both a toast and an inline alert. The admin portal keeps Toastr.
 */
class CustomerNotificationsTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    private const GOOGLE_UNAVAILABLE_COPY = "Google connections aren't available right now. This is something we need to fix on our side — we've been notified.";

    private const LEGACY_WORDING = ['Oops', 'Opps', '..!!', 'Success!!', 'Cancelled!!'];

    private const TOASTR_ASSETS = ['toastr.min.js', 'toastr.min.css', 'ext-component-toastr.css'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    // -----------------------------------------------------------------
    // Google Business Profile connection failure
    // -----------------------------------------------------------------

    public function test_a_google_connection_failure_keeps_its_status_message_and_redirect(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        config(['services.google_business_profile.client_id' => null]);

        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message', self::GOOGLE_UNAVAILABLE_COPY)
            ->assertSessionHas('message_title', 'Google connection unavailable');
    }

    public function test_a_google_connection_failure_is_explained_once_in_the_page_in_business_os_copy(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        config(['services.google_business_profile.client_id' => null]);

        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]));
        $page = $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))->assertOk();
        $html = (string) $page->getContent();

        $this->assertMatchesRegularExpression(
            '/<div class="alert alert-danger ds-alert[^"]*"[^>]*data-role="flash-message"[^>]*role="alert">\s*<svg[^>]*>.*?<\/svg>\s*<div class="flex-grow-1">\s*<strong class="d-block">Google connection unavailable<\/strong>\s*' . preg_quote(e(self::GOOGLE_UNAVAILABLE_COPY), '/') . '\s*<\/div>/s',
            $html,
            'The page explains the failure inline, with a calm heading.',
        );
        $this->assertSame(1, substr_count($html, e(self::GOOGLE_UNAVAILABLE_COPY)), 'The message is shown exactly once.');
        $this->assertSame([], $this->pageToasts($page), 'No toast repeats the inline explanation.');
        $this->assertNoLegacyNotifications($page);
    }

    public function test_a_google_success_message_is_shown_once_and_announced_as_status(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $page = $this->withSession(['status' => 'success', 'message' => 'Google location linked.'])
            ->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertOk();

        $this->assertMatchesRegularExpression('/<div class="alert alert-success ds-alert[^"]*"[^>]*data-role="flash-message"[^>]*role="status">/', (string) $page->getContent());
        $this->assertSame(1, substr_count((string) $page->getContent(), 'Google location linked.'));
        $this->assertSame([], $this->pageToasts($page));
    }

    public function test_access_to_another_tenants_google_page_is_still_not_found(): void
    {
        [$customer] = $this->entitledTenant();
        [, $foreignBusiness, $foreignWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.connect', [$foreignWorkspace->uid, $foreignBusiness->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.gbp.index', [$foreignWorkspace->uid, $foreignBusiness->uid]))->assertNotFound();
    }

    // -----------------------------------------------------------------
    // The customer shell toast
    // -----------------------------------------------------------------

    public function test_a_flashed_error_in_the_customer_shell_is_a_business_os_toast_without_a_legacy_title(): void
    {
        [$customer, , $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $page = $this->withSession(['status' => 'error', 'message' => 'We couldn\'t complete that action. Please try again.'])
            ->get(route('customer.workspaces.show', $workspace->uid))
            ->assertOk();

        $this->assertSame([['variant' => 'error', 'title' => null, 'message' => 'We couldn\'t complete that action. Please try again.']], $this->pageToasts($page));
        $this->assertNoLegacyNotifications($page);
    }

    public function test_a_success_toast_is_compact_and_lands_in_a_polite_live_region(): void
    {
        [$customer, , $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $page = $this->withSession(['status' => 'success', 'message' => 'Member added'])
            ->get(route('customer.workspaces.show', $workspace->uid))
            ->assertOk();
        $html = (string) $page->getContent();

        $this->assertSame([['variant' => 'success', 'title' => null, 'message' => 'Member added']], $this->pageToasts($page));
        $this->assertStringContainsString('<div class="ds-toast-region" data-role="toast-region" aria-live="polite" aria-relevant="additions"></div>', $html);
        $this->assertStringContainsString("toast.setAttribute('role', SPOKEN_PREFIX[variant] ? 'alert' : 'status');", $html, 'Errors and warnings are alerts; success and info are status.');
        $this->assertStringContainsString("var DURATION = { success: 5000, info: 6000, warning: 10000, error: 10000 };", $html, 'Errors stay twice as long as a success.');
        $this->assertStringContainsString("close.setAttribute('aria-label', 'Dismiss notification');", $html);
        $this->assertStringContainsString('window.toastr = {', $html, 'Legacy page scripts render through the Business OS toast.');
        $this->assertStringContainsString('return show({ variant: variant, message: message });', $html, 'The title a legacy script passes is never shown.');
        $this->assertNoLegacyNotifications($page);
    }

    public function test_a_signed_out_page_shows_its_flash_inline_only(): void
    {
        $this->ensureRequiredAppConfigRowsExist();

        $page = $this->withSession(['status' => 'error', 'message' => 'These credentials do not match our records.'])
            ->get(route('login'))
            ->assertOk();

        $this->assertMatchesRegularExpression('/id="auth-flash"[^>]*data-role="auth-flash"[^>]*role="alert"/', (string) $page->getContent());
        $this->assertSame(1, substr_count((string) $page->getContent(), 'These credentials do not match our records.'));
        $this->assertSame([], $this->pageToasts($page));
        $this->assertNoLegacyNotifications($page);
    }

    public function test_a_validation_error_is_not_toasted_when_the_page_lists_it(): void
    {
        [$customer] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $page = $this->withSession(['errors' => $this->errorBag(['name' => 'The name field is required.'])])
            ->get(route('customer.business.edit'))
            ->assertOk();

        $this->assertStringContainsString('data-role="validation-summary"', (string) $page->getContent());
        $this->assertSame(1, substr_count((string) $page->getContent(), 'The name field is required.'));
        $this->assertSame([], $this->pageToasts($page));
    }

    public function test_a_validation_error_the_page_does_not_show_is_still_toasted(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $page = $this->withSession(['errors' => $this->errorBag(['location' => 'Choose a location.'])])
            ->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertOk();

        $this->assertSame([['variant' => 'error', 'title' => null, 'message' => 'Choose a location.']], $this->pageToasts($page));
    }

    // -----------------------------------------------------------------
    // Admin portal: unchanged
    // -----------------------------------------------------------------

    public function test_the_admin_portal_keeps_toastr(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
            'email_verified_at' => now(),
        ]);
        $this->withSession(['permissions' => collect(['access backend', 'view business']), 'status' => 'error', 'message' => 'Admin-side failure']);
        $this->actingAs($admin);

        $html = (string) $this->get(route('admin.businesses.index'))->assertOk()->getContent();

        $this->assertStringContainsString('toastr.min.js', $html);
        $this->assertStringContainsString('toastr.min.css', $html);
        $this->assertStringContainsString('toastr[\'error\']("Admin-side failure"', $html);
        $this->assertStringNotContainsString('data-role="toast-region"', $html);
    }

    // -----------------------------------------------------------------
    // Which toasts a page shows (PageToasts, directly)
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function inlineMarkupProvider(): array
    {
        return [
            'x-input field error' => ['<input class="form-control transition-fast is-invalid" name="name">', true],
            'sign-in field error' => ['<input id="email" aria-invalid="true">', true],
            'validation summary' => ['<div class="alert" data-role="validation-summary"><ul><li>x</li></ul></div>', true],
            'no inline error' => ['<input class="form-control" name="name"><p>is-invalid is only a word here</p>', false],
        ];
    }

    /**
     * @dataProvider inlineMarkupProvider
     */
    public function test_the_validation_toast_is_left_out_only_when_the_page_shows_field_errors(string $content, bool $shownInline): void
    {
        $toasts = PageToasts::forPage($this->sessionStore([]), $this->errorBag(['name' => 'The name field is required.']), ['content' => $content], null);

        $this->assertSame($shownInline ? [] : [['variant' => 'error', 'title' => null, 'message' => 'The name field is required.']], $toasts);
    }

    public function test_the_flash_toast_is_left_out_when_the_page_shows_the_flash_and_scripts_do_not_count(): void
    {
        $session = $this->sessionStore(['status' => 'warning', 'message' => 'Check your details', 'message_title' => 'Almost there']);

        $this->assertSame([['variant' => 'warning', 'title' => 'Almost there', 'message' => 'Check your details']], PageToasts::forPage($session, null, ['content' => '<p>Page</p>', 'page-script' => '<script>el.dataset.role = \'data-role="flash-message"\';</script>'], null));
        $this->assertSame([], PageToasts::forPage($session, null, ['content' => '<div data-role="flash-message">Check your details</div>'], null));
        $this->assertSame([], PageToasts::forPage($session, null, ['content' => '<div id="auth-flash" data-role="auth-flash">Check your details</div>'], null));
    }

    public function test_unknown_statuses_and_empty_messages_show_no_toast_and_markup_is_reduced_to_text(): void
    {
        $this->assertSame([], PageToasts::forPage($this->sessionStore(['status' => 'celebrate', 'message' => 'Hi']), null, [], null));
        $this->assertSame([], PageToasts::forPage($this->sessionStore(['status' => 'error', 'message' => '   ']), null, [], null));
        $this->assertSame(
            [['variant' => 'success', 'title' => null, 'message' => 'Go to Billing & subscribe']],
            PageToasts::forPage($this->sessionStore(['message' => 'Go to <strong>Billing</strong> &amp; subscribe']), null, [], null),
            'A message without a status is a success, as before.',
        );
    }

    public function test_business_os_toasts_apply_to_signed_out_and_customer_portal_users_only(): void
    {
        $this->assertTrue(PageToasts::appliesTo(null));
        $this->assertTrue(PageToasts::appliesTo((new User())->forceFill(['is_customer' => true, 'active_portal' => 'customer'])));
        $this->assertFalse(PageToasts::appliesTo((new User())->forceFill(['is_customer' => true, 'is_admin' => true, 'active_portal' => 'admin'])));
        $this->assertFalse(PageToasts::appliesTo((new User())->forceFill(['is_customer' => false, 'is_admin' => true, 'active_portal' => 'admin'])));
    }

    // -----------------------------------------------------------------
    // No inherited wording left in customer-facing views
    // -----------------------------------------------------------------

    /**
     * Hard-coded inherited wording is gone from customer-facing views. Legacy
     * page scripts still pass translated Toastr titles ("Attention", "Opps...")
     * as a second argument; the toast ignores it (asserted in
     * test_a_success_toast_is_compact_and_lands_in_a_polite_live_region).
     */
    public function test_no_customer_facing_view_hard_codes_the_inherited_notification_wording(): void
    {
        $offenders = [];

        foreach (['customer', 'auth', 'components', 'layouts'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/' . $directory), \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                foreach (['Oops', '..!!', 'Success!!', 'Cancelled!!'] as $wording) {
                    if (str_contains($contents, $wording)) {
                        $offenders[] = $directory . '/' . str_replace('\\', '/', $files->getSubPathname()) . ' — ' . $wording;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return list<array{variant: string, title: ?string, message: string}>
     */
    private function pageToasts(TestResponse $response): array
    {
        $matched = preg_match('/<script type="application\/json" data-role="toast-initial">(.*?)<\/script>/s', (string) $response->getContent(), $payload);
        $this->assertSame(1, $matched, 'A customer-facing page carries the Business OS toast region.');

        return json_decode($payload[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertNoLegacyNotifications(TestResponse $response): void
    {
        $html = (string) $response->getContent();

        foreach (array_merge(self::LEGACY_WORDING, self::TOASTR_ASSETS) as $legacy) {
            $this->assertStringNotContainsString($legacy, $html);
        }
    }

    /**
     * @param  array<string, string>  $messages
     */
    private function errorBag(array $messages): ViewErrorBag
    {
        return (new ViewErrorBag())->put('default', new MessageBag(array_map(fn (string $message): array => [$message], $messages)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sessionStore(array $data): Store
    {
        $session = new Store('test', new ArraySessionHandler(10));
        $session->put($data);

        return $session;
    }
}
