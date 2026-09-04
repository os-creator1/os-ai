<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * Design System M2 Slice 2 contract §5/§8 items 6, 7. Mechanical proof,
 * run directly against the 24 rendered Slice 2 view SOURCE files (not
 * rendered HTML -- `<x-ds-icon>` compiles away into an inline `<svg>`,
 * so only the Blade source itself can prove the seam was actually
 * adopted): zero remaining static `data-feather="..."` markup, zero
 * hardcoded hex color literals, zero `font-family` declarations. The
 * one known, explicitly out-of-scope exception (§3.9 of the contract)
 * is `profile/index.blade.php`'s own JS-embedded
 * `feather.icons['trash'].toSvg(...)` call, which this test's regex is
 * deliberately scoped to exclude by matching only the static HTML
 * attribute convention.
 *
 * The original 26-file scope included the legacy first-run Installer's
 * two Slice 2 views (`Installer/welcome.blade.php`,
 * `Installer/update/welcome.blade.php`); both were removed by the Safe
 * Legacy / Dead-Surface Deletion Sweep (2026-09) along with the rest of
 * the vendor-distribution Installer/Updater machinery, dropping the
 * scope to 24.
 */
class AuthDesignSystemContentTest extends TestCase
{
    private const SLICE_2_VIEWS = [
        'resources/views/auth/login.blade.php',
        'resources/views/auth/register.blade.php',
        'resources/views/auth/verify.blade.php',
        'resources/views/auth/twoFactor.blade.php',
        'resources/views/auth/twoFactorBackUp.blade.php',
        'resources/views/auth/passwords/email.blade.php',
        'resources/views/auth/passwords/reset.blade.php',
        'resources/views/auth/subAccount/acceptInvitation.blade.php',
        'resources/views/auth/termsOfUses.blade.php',
        'resources/views/auth/privacyPolicy.blade.php',
        'resources/views/auth/loggedAs.blade.php',
        'resources/views/auth/profile/index.blade.php',
        'resources/views/auth/profile/_accounts.blade.php',
        'resources/views/auth/profile/_security.blade.php',
        'resources/views/auth/profile/_notifications.blade.php',
        'resources/views/auth/profile/_information.blade.php',
        'resources/views/auth/profile/_webhook.blade.php',
        'resources/views/auth/profile/_two_factor_authentication.blade.php',
        'resources/views/auth/profile/_dlt_entity_id.blade.php',
        'resources/views/auth/profile/_dlt_telemarketer_id.blade.php',
        'resources/views/auth/profile/_update_avatar.blade.php',
        'resources/views/auth/profile/_announcements.blade.php',
        'resources/views/auth/profile/_update_two_factor_auth.blade.php',
        'resources/views/auth/profile/_view_announcement.blade.php',
    ];

    public function test_no_slice_2_view_contains_a_static_data_feather_attribute(): void
    {
        foreach (self::SLICE_2_VIEWS as $view) {
            $source = file_get_contents(base_path($view));

            $this->assertDoesNotMatchRegularExpression(
                '/data-feather="[a-z-]+"/',
                $source,
                "{$view} still contains a static data-feather attribute."
            );
        }
    }

    public function test_no_slice_2_view_contains_a_hardcoded_hex_color_literal(): void
    {
        foreach (self::SLICE_2_VIEWS as $view) {
            $source = file_get_contents(base_path($view));

            $this->assertDoesNotMatchRegularExpression(
                '/(?:color|background|border)(?:-color)?\s*:\s*#[0-9A-Fa-f]{3,8}/',
                $source,
                "{$view} still contains a hardcoded hex color literal in a style declaration."
            );
        }
    }

    public function test_no_slice_2_view_contains_a_font_family_declaration(): void
    {
        foreach (self::SLICE_2_VIEWS as $view) {
            $source = file_get_contents(base_path($view));

            $this->assertDoesNotMatchRegularExpression(
                '/font-family\s*:/i',
                $source,
                "{$view} still contains a font-family declaration."
            );
        }
    }

    public function test_migrated_views_actually_adopt_the_canonical_ds_icon_seam(): void
    {
        $migratedViews = [
            'resources/views/auth/login.blade.php',
            'resources/views/auth/register.blade.php',
            'resources/views/auth/profile/index.blade.php',
            'resources/views/auth/profile/_information.blade.php',
        ];

        foreach ($migratedViews as $view) {
            $source = file_get_contents(base_path($view));

            $this->assertStringContainsString(
                '<x-ds-icon',
                $source,
                "{$view} was expected to adopt the canonical <x-ds-icon> seam but does not."
            );
        }
    }
}
