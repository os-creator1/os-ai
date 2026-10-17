<?php

namespace Tests\Feature\DesignSystem;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One notification card for the whole customer app: inline alerts
 * (<x-alert>, <x-flash-alert>) and toasts (<x-toast-region>) all render the
 * shared ".ds-notice" structure. Presentation only — flash keys and message
 * content are unchanged.
 */
class SharedNotificationComponentTest extends TestCase
{
    /** variant => [status class, role, glyph path] */
    public static function variants(): array
    {
        return [
            'success' => ['success', 'ds-notice--success', 'status', 'M5.5 12.5l4.25 4.25L18.5 8'],
            'error' => ['danger', 'ds-notice--danger', 'alert', 'M7 7l10 10M17 7L7 17'],
            'warning' => ['warning', 'ds-notice--warning', 'alert', 'M12 6.5v7M12 17.5h.01'],
            'information' => ['accent', 'ds-notice--info', 'status', 'M12 11v6M12 7.5h.01'],
        ];
    }

    #[DataProvider('variants')]
    public function test_each_type_uses_the_shared_component_with_the_same_structure(string $variant, string $class, string $role, string $glyph): void
    {
        $html = Blade::render('<x-alert variant="' . $variant . '" :dismissible="true" title="Heading">Detail text</x-alert>');

        $this->assertStringContainsString('class="ds-notice ' . $class . ' ds-alert"', $html);
        $this->assertStringContainsString('role="' . $role . '"', $html);
        $this->assertStringContainsString($glyph, $html, 'The status glyph follows the variant.');
        $this->assertMatchesRegularExpression('/ds-notice__icon.*?<svg.*?<\/svg>.*?ds-notice__body.*?<strong class="ds-notice__title">Heading<\/strong>.*?<div class="ds-notice__text">\s*Detail text\s*<\/div>.*?ds-notice__close/s', $html);
    }

    public function test_a_caller_supplied_role_wins_and_icon_prop_is_still_accepted(): void
    {
        $html = Blade::render('<x-alert variant="danger" icon="alert-circle" role="status">Saved</x-alert>');

        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringNotContainsString('role="alert"', $html);
    }

    public function test_dismiss_is_opt_in_so_persistent_notices_cannot_be_dismissed(): void
    {
        $persistent = Blade::render('<x-alert variant="warning">Reconnect your account</x-alert>');
        $transient = Blade::render('<x-alert variant="success" :dismissible="true">Saved</x-alert>');

        $this->assertStringNotContainsString('data-ds-notice-dismiss aria-label', $persistent);
        $this->assertStringContainsString('data-ds-notice-dismiss aria-label="Dismiss notification"', $transient);
        $this->assertStringContainsString("closest('[data-ds-notice-dismiss]')", $transient, 'A delegated handler removes the notice.');
    }

    public function test_flash_alerts_are_dismissible(): void
    {
        session(['status' => 'success', 'message' => 'Saved.']);

        $this->assertStringContainsString('data-ds-notice-dismiss', Blade::render('<x-flash-alert />'));
    }

    public function test_the_view_as_client_banner_is_not_built_on_the_dismissible_component(): void
    {
        $banner = (string) file_get_contents(resource_path('views/components/view-as-banner.blade.php'));

        $this->assertStringNotContainsString('data-ds-notice-dismiss', $banner);
        $this->assertStringNotContainsString('<x-alert', $banner);
        $this->assertStringNotContainsString('data-bs-dismiss', $banner);
    }

    public function test_no_customer_view_hardcodes_a_bootstrap_feedback_alert_for_server_messages(): void
    {
        foreach (['seo/audit', 'catalog/_messages', 'forms/_messages', 'email/show', 'website/wizard/steps/generating'] as $name) {
            $source = (string) file_get_contents(resource_path("views/customer/business/{$name}.blade.php"));

            $this->assertDoesNotMatchRegularExpression('/class="alert alert-(success|danger|warning|info)/', $source, $name);
            $this->assertStringContainsString('<x-alert', $source, $name);
        }
    }

    public function test_styles_and_dismiss_script_are_emitted_once_per_page(): void
    {
        $html = Blade::render('<x-alert variant="success">One</x-alert><x-alert variant="danger">Two</x-alert>');

        $this->assertSame(1, substr_count($html, '.ds-notice__icon {'));
        $this->assertSame(1, substr_count($html, "closest('[data-ds-notice-dismiss]')"));
    }

    public function test_icon_is_centred_by_construction(): void
    {
        $css = (string) file_get_contents(resource_path('views/components/notice-styles.blade.php'));
        $icon = $this->rule($css, '.ds-notice__icon');
        $svg = $this->rule($css, '.ds-notice__icon svg');

        foreach (['display: flex', 'align-items: center', 'justify-content: center', 'width: 1.5rem', 'height: 1.5rem', 'line-height: 0', 'border-radius: 50%'] as $declaration) {
            $this->assertStringContainsString($declaration, $icon);
        }
        foreach (['display: block', 'width: 0.875rem', 'height: 0.875rem', 'margin: 0', 'vertical-align: baseline'] as $declaration) {
            $this->assertStringContainsString($declaration, $svg);
        }
    }

    public function test_long_messages_wrap_without_moving_the_icon_or_dismiss_button(): void
    {
        $css = (string) file_get_contents(resource_path('views/components/notice-styles.blade.php'));

        $this->assertStringContainsString('min-width: 0', $this->rule($css, '.ds-notice__body'));
        $this->assertStringContainsString('overflow-wrap: anywhere', $this->rule($css, '.ds-notice__body'));
        $this->assertStringContainsString('align-items: flex-start', $this->rule($css, '.ds-notice'));
        $this->assertStringContainsString('flex: 0 0 1.5rem', $this->rule($css, '.ds-notice__icon'));
        $this->assertStringContainsString('flex: 0 0 1.5rem', $this->rule($css, '.ds-notice__close'));
    }

    public function test_message_and_title_are_always_escaped(): void
    {
        $html = Blade::render('<x-alert variant="danger" :title="$t">{{ $m }}</x-alert>', [
            't' => '<script>alert(1)</script>',
            'm' => '<img src=x onerror=alert(2)>',
        ]);

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_session_flashes_use_the_shared_card_with_unchanged_keys(): void
    {
        foreach (['success' => ['ds-notice--success', 'status'], 'error' => ['ds-notice--danger', 'alert'], 'warning' => ['ds-notice--warning', 'alert'], 'info' => ['ds-notice--info', 'status']] as $status => [$class, $role]) {
            session()->flush();
            session(['status' => $status, 'message' => 'Stripe connection updated.']);

            $html = Blade::render('<x-flash-alert />');

            $this->assertStringContainsString($class, $html);
            $this->assertStringContainsString('role="' . $role . '"', $html);
            $this->assertStringContainsString('data-role="flash-message"', $html);
            $this->assertStringContainsString('<strong class="ds-notice__title">Stripe connection updated.</strong>', $html);
            $this->assertSame(1, substr_count($html, 'Stripe connection updated.'), 'Shown once.');
        }
    }

    public function test_a_flashed_title_becomes_the_heading_and_the_message_the_description(): void
    {
        session(['status' => 'error', 'message' => 'Try again later.', 'message_title' => 'Connection failed']);

        $html = Blade::render('<x-flash-alert />');

        $this->assertStringContainsString('<strong class="ds-notice__title">Connection failed</strong>', $html);
        $this->assertMatchesRegularExpression('/ds-notice__text">\s*Try again later\.\s*<\/div>/', $html);
    }

    public function test_an_empty_flash_renders_nothing(): void
    {
        session()->flush();

        $this->assertSame('', trim(Blade::render('<x-flash-alert />')));
    }

    public function test_toasts_and_ajax_notifications_render_the_same_card(): void
    {
        $html = Blade::render('<x-toast-region :toasts="[]" />');

        $this->assertStringContainsString("'ds-notice ds-toast ds-notice--' + NOTICE_STATUS[variant]", $html);
        $this->assertStringContainsString("iconHolder.className = 'ds-notice__icon'", $html);
        $this->assertStringContainsString("close.className = 'ds-notice__close'", $html);
        $this->assertStringContainsString("close.addEventListener('click', function () { dismiss(toast); });", $html, 'The toast dismiss button works.');
        $this->assertStringContainsString("toast.setAttribute('role', SPOKEN_PREFIX[variant] ? 'alert' : 'status');", $html);
        $this->assertStringContainsString('textContent', $html, 'Text is set as text, never as HTML.');
        $this->assertStringContainsString('window.toastr = {', $html, 'Legacy AJAX toastr calls go through the same card.');
        $this->assertSame(1, substr_count($html, '.ds-notice__icon {'), 'Styles are shared, not duplicated.');
    }

    private function rule(string $css, string $selector): string
    {
        $this->assertSame(1, preg_match('/(?:^|\n)\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/', $css, $m), "No .css rule for {$selector}");

        return $m[1];
    }
}
