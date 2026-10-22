<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Library\Support\RequestScopedCache;
use App\Models\Customer;
use App\Models\Workspace;
use App\Notifications\Auth\BrandedResetPassword;
use App\Notifications\Auth\BrandedVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * White Label — the Agency's brand reaches every customer-facing surface through the shared layers (design tokens,
 * layout identity, error pages, account mail) without leaking to anyone else.
 */
class AgencyBrandPropagationTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private bool $brandingDirExisted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->brandingDirExisted = is_dir(public_path('images/branding/agency'));
    }

    protected function tearDown(): void
    {
        if (! $this->brandingDirExisted && is_dir(public_path('images/branding/agency'))) {
            File::deleteDirectory(public_path('images/branding/agency'));
        }

        parent::tearDown();
    }

    /** @return array{agency: Workspace, owner: Customer, client: Workspace, clientOwner: Customer} */
    private function managed(string $name = 'Northwind Agency', array $brand = []): array
    {
        [$owner, , $agency] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);
        $fixture = $this->createAgencyManagedClient($agency, 'Alpha Business', 'Alpha');
        $this->assignTier($fixture['clientWorkspace'], WorkspacePlanTier::Growth);

        app(AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $agency, array_merge([
            'display_name' => $name . ' Digital', 'accent_color' => '#1a73e8', 'secondary_color' => '#0f172a',
            'support_email' => 'help@' . strtolower(str_replace(' ', '', $name)) . '.example', 'support_url' => 'https://help.example.test', 'is_enabled' => '1',
        ], $brand));

        return ['agency' => $agency, 'owner' => $owner, 'client' => $fixture['clientWorkspace']->fresh(), 'clientOwner' => $fixture['clientOwner']];
    }

    private function page(Customer $customer): string
    {
        $this->authenticateAs($customer);
        app(RequestScopedCache::class)->flush();

        return $this->get(route('user.home'))->assertOk()->getContent();
    }

    public function test_a_managed_client_gets_the_agencys_colours_as_the_shared_design_tokens(): void
    {
        $m = $this->managed();
        $html = $this->page($m['clientOwner']);

        $this->assertStringContainsString('<style id="agency-theme-overrides">', $html);
        $style = substr($html, strpos($html, 'id="agency-theme-overrides"'), 1500);
        $this->assertStringContainsString('--color-primary:#1a73e8;', $style);
        $this->assertStringContainsString('--color-button-primary-bg:#1a73e8;', $style);
        $this->assertStringContainsString('--color-secondary:#0f172a;', $style);
        $this->assertStringContainsString('--color-button-text:', $style);

        // Semantic colours are never brand colours.
        foreach (['color-status-danger', 'color-status-warning', 'color-status-success', 'color-status-info', 'color-text-primary', 'color-surface'] as $semantic) {
            $this->assertStringNotContainsString('--' . $semantic . ':', $style, "{$semantic} must stay platform-controlled");
        }
    }

    public function test_the_brand_never_reaches_the_agency_itself_a_direct_customer_or_another_agencys_client(): void
    {
        $a = $this->managed('Agency A', ['accent_color' => '#1a73e8']);
        $b = $this->managed('Agency B', ['accent_color' => '#0a7d3b']);
        [$direct] = $this->tenant(WorkspacePlanTier::Core, 'Solo Business', 'Solo');

        $this->assertStringNotContainsString('agency-theme-overrides', $this->page($direct));
        $this->assertStringNotContainsString('agency-theme-overrides', $this->page($a['owner']), 'the Agency uses the platform chrome');

        $htmlB = $this->page($b['clientOwner']);
        $this->assertStringContainsString('--color-primary:#0a7d3b;', $htmlB);
        $this->assertStringNotContainsString('#1a73e8', $htmlB);
        $this->assertStringNotContainsString('Agency A Digital', $htmlB);
    }

    public function test_a_disabled_brand_or_an_ended_relationship_returns_the_client_to_the_platform_theme(): void
    {
        $m = $this->managed();
        $this->assertStringContainsString('agency-theme-overrides', $this->page($m['clientOwner']));

        app(AgencyWhiteLabelManager::class)->save((int) $m['owner']->user_id, $m['agency'], ['display_name' => 'Northwind Agency Digital', 'is_enabled' => '0']);
        $this->assertStringNotContainsString('agency-theme-overrides', $this->page($m['clientOwner']));
    }

    public function test_a_pale_brand_colour_keeps_links_readable_and_buttons_legible(): void
    {
        $m = $this->managed('Pale Agency', ['accent_color' => '#ffe28a', 'secondary_color' => '']);
        $html = $this->page($m['clientOwner']);
        $style = substr($html, strpos($html, 'id="agency-theme-overrides"'), 1500);

        preg_match('/--color-link:(#[0-9a-f]{6});/', $style, $link);
        $this->assertNotEmpty($link);
        $this->assertGreaterThanOrEqual(4.5, \App\Library\AgencySite\SiteRenderer::contrastWithWhite($link[1]), 'links on white stay readable');
        $this->assertStringContainsString('--color-primary:#ffe28a;', $style, 'the brand colour itself is kept for fills');
        preg_match('/--color-button-text:(#[0-9a-f]{6})/i', $style, $text);
        $this->assertNotSame('#ffffff', strtolower($text[1] ?? ''), 'dark text on a pale button');
    }

    public function test_the_signed_in_chrome_uses_the_agencys_name_title_and_favicon_and_support(): void
    {
        $m = $this->managed('Chrome Agency');
        app(AgencyWhiteLabelManager::class)->save((int) $m['owner']->user_id, $m['agency'], ['display_name' => 'Chrome Agency Digital', 'is_enabled' => '1', 'support_email' => 'help@chrome.example', 'support_url' => 'https://help.example.test'], null, false, [
            'favicon' => UploadedFile::fake()->image('f.png', 32, 32),
        ]);
        $html = $this->page($m['clientOwner']);

        $this->assertStringContainsString('Chrome Agency Digital', $html);
        $this->assertMatchesRegularExpression('#<title>[^<]*Chrome Agency Digital#', $html);
        $this->assertMatchesRegularExpression('#rel="shortcut icon"[^>]*images/branding/agency/#', $html);
        $this->assertStringContainsString('help@chrome.example', $html);
    }

    public function test_error_pages_for_a_managed_client_carry_the_agencys_name_and_colour(): void
    {
        $m = $this->managed('Error Agency');
        $this->authenticateAs($m['clientOwner']);
        app(RequestScopedCache::class)->flush();

        $response = $this->get(route('customer.workspaces.agency-plan.show', '00000000-0000-4000-8000-000000000000'));
        $response->assertNotFound();
        $html = $response->getContent();
        $this->assertStringContainsString('Error Agency Digital', $html);
        $this->assertStringContainsString('agency-theme-overrides', $html);

        auth()->logout();
        $this->assertStringNotContainsString('Error Agency Digital', $this->get('/definitely-not-a-page')->getContent());
    }

    public function test_account_mail_for_an_agency_customer_is_branded_replies_to_the_agency_and_never_spoofs_the_sender(): void
    {
        $m = $this->managed('Mail Agency');
        $direct = $this->createCustomer();
        $fromAddress = config('mail.from.address');

        foreach ([BrandedResetPassword::class => fn ($user) => $user->sendPasswordResetNotification('tok'), BrandedVerifyEmail::class => fn ($user) => $user->sendEmailVerificationNotification()] as $class => $send) {
            \Illuminate\Support\Facades\Notification::fake();
            $send($m['clientOwner']->user);

            \Illuminate\Support\Facades\Notification::assertSentTo($m['clientOwner']->user, $class, function ($notification) use ($m, $fromAddress) {
                $mail = $notification->toMail($m['clientOwner']->user);
                $body = (string) $mail->render();

                $this->assertSame($fromAddress, $mail->from[0], 'sender address is the platform\'s authenticated one');
                $this->assertSame('Mail Agency Digital', $mail->from[1]);
                $this->assertSame('help@mailagency.example', $mail->replyTo[0][0]);
                $this->assertStringContainsString('Mail Agency Digital', $body);
                $this->assertStringContainsString('#1a73e8', $body);

                return true;
            });

            // A direct platform customer is never given an Agency's identity.
            \Illuminate\Support\Facades\Notification::fake();
            $send($direct->user);
            \Illuminate\Support\Facades\Notification::assertSentTo($direct->user, $class, function ($notification) use ($direct) {
                $mail = $notification->toMail($direct->user);

                $this->assertEmpty($mail->replyTo);
                $this->assertStringNotContainsString('Mail Agency', (string) $mail->render());

                return true;
            });
        }
    }

    public function test_agency_support_details_in_mail_are_validated_not_trusted(): void
    {
        $brand = \App\Library\Mail\MailBrand::agency('Safe Agency', '#1a73e8', null, "evil@x.example\r\nBcc: a@b.c", 'javascript:alert(1)');

        $this->assertNull($brand->replyTo);
        $this->assertStringNotContainsString('javascript', implode(' ', $brand->footerLines));
    }
}
