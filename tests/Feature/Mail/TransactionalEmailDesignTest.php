<?php

namespace Tests\Feature\Mail;

use App\Library\Calendar\Notifications\AppointmentNotificationDetails;
use App\Library\Mail\MailBrand;
use App\Notifications\Calendar\AppointmentCustomerNotification;
use App\Notifications\Documents\DocumentIssuedNotification;
use App\Notifications\Documents\PaymentReceiptNotification;
use App\Notifications\Workspace\ClientInvitationNotification;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Presentation only, fake data, no database and no delivery: each representative
 * email renders through the one shared layout with the right identity.
 */
class TransactionalEmailDesignTest extends TestCase
{
    private const LOGO = 'https://cdn.example.test/brands/acme.png';

    private function html(object $notification): string
    {
        return (string) $notification->toMail(new \stdClass())->render();
    }

    private function text(object $notification): string
    {
        $mail = $notification->toMail(new \stdClass());

        return (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());
    }

    private function business(): MailBrand
    {
        return MailBrand::business('Acme <b>Dental</b>', '#1a56db', self::LOGO);
    }

    private function assertSharedDesign(string $html): void
    {
        $this->assertStringContainsString('#f6f3ee', $html);
        $this->assertStringContainsString('border-radius:14px', $html);
        $this->assertStringContainsString('role="presentation"', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('var(--', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function test_password_reset_and_verification_use_platform_branding(): void
    {
        foreach (['emails.auth.reset', 'emails.auth.verify_email'] as $view) {
            $data = ['content' => 'Use the button below.', 'url' => 'https://app.example.test/t/abc'];
            $html = (string) app(Markdown::class)->render($view, $data);
            $text = (string) app(Markdown::class)->renderText($view, $data);

            $this->assertSharedDesign($html);
            $this->assertStringContainsString(MailBrand::PLATFORM_ACCENT, $html);
            $this->assertStringContainsString('href="https://app.example.test/t/abc"', $html);
            $this->assertStringContainsString('© ' . date('Y') . ' ' . config('app.name'), $html);
            $this->assertStringContainsString('https://app.example.test/t/abc', $text);
            $this->assertStringNotContainsString('<table', $text);
        }
    }

    public function test_agency_invitation_names_agency_and_falls_back_to_platform_brand(): void
    {
        $n = new ClientInvitationNotification('uid-1', 'tok-secret', 'Bright <i>Agency</i>', 'Client Co');
        $html = $this->html($n);

        $this->assertSharedDesign($html);
        $this->assertStringContainsString('invited to a new workspace', $html);
        $this->assertStringContainsString('Bright &lt;i&gt;Agency&lt;/i&gt;', $html);
        $this->assertStringContainsString('Client Co', $html);
        $this->assertStringContainsString(MailBrand::PLATFORM_ACCENT, $html);
        $this->assertStringContainsString('Accept invitation', $html);
        $this->assertStringContainsString('/client-invitations/', $html);
        $this->assertStringContainsString('Button not working?', $html);
        $this->assertStringContainsString('tok-secret', $this->text($n)); // the claim link only, in the same URL as the button
    }

    public function test_document_signing_request_uses_the_business_brand(): void
    {
        $n = new DocumentIssuedNotification('doc-1', 'tok', 'Acme <b>Dental</b>', 'Service <i>Proposal</i>', true, $this->business());
        $html = $this->html($n);

        $this->assertSharedDesign($html);
        $this->assertStringContainsString('src="' . self::LOGO . '"', $html);
        $this->assertStringContainsString('#1a56db', $html);
        $this->assertStringNotContainsString(MailBrand::PLATFORM_ACCENT, $html);
        $this->assertStringContainsString('securely delivers this notification on behalf of', $html);
        $this->assertStringContainsString('Review and sign', $html);
        $this->assertStringContainsString('Service &lt;i&gt;Proposal&lt;/i&gt;', $html);
        $this->assertStringContainsString('Acme', $this->text($n));
    }

    public function test_payment_receipt_and_appointment_use_the_business_brand(): void
    {
        $receipt = $this->html(new PaymentReceiptNotification('Acme', 'Proposal', '120.00 USD', $this->business()));
        $this->assertSharedDesign($receipt);
        $this->assertStringContainsString('120.00 USD', $receipt);
        $this->assertStringContainsString('#1a56db', $receipt);

        $details = new AppointmentNotificationDetails(
            'appt-1', 'Acme', 'Consultation', 'Monday, March 2, 2026', '9:00 AM – 9:30 AM', '9:00 AM', 'UTC', 'UTC (UTC)',
            'Main St', 'Bring ID', Carbon::parse('2026-03-02 09:00', 'UTC'), Carbon::parse('2026-03-02 09:30', 'UTC'), 0, $this->business(),
        );
        $n = new AppointmentCustomerNotification($details, false);
        $html = $this->html($n);

        $this->assertSharedDesign($html);
        $this->assertStringContainsString('You are booked', $html);
        $this->assertStringContainsString('Monday, March 2, 2026', $html);
        $this->assertStringContainsString('Add to Google Calendar', $html);
        $this->assertStringContainsString('#1a56db', $html);
        $this->assertStringContainsString('Date:', $this->text($n));
    }

    public function test_missing_or_unsafe_brand_inputs_degrade_safely(): void
    {
        $this->assertNull(MailBrand::emailSafeLogo('http://insecure.test/logo.png'));
        $this->assertNull(MailBrand::emailSafeLogo('https://cdn.test/logo.svg'));
        $this->assertNull(MailBrand::emailSafeLogo('https://cdn.test/x.png" onerror="x'));

        $brand = MailBrand::business('Zed Co', 'not-a-colour', null);
        $this->assertSame(MailBrand::PLATFORM_ACCENT, $brand->accent);
        $this->assertSame('ZC', $brand->initials());

        $html = $this->html(new PaymentReceiptNotification('Zed Co', 'Doc', '1.00 USD', $brand));
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('>ZC<', $html);
    }
}
