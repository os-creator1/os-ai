<?php

namespace Tests\Feature\Branding;

use Tests\TestCase;

/**
 * Design System M2 Platform Branding contract §9 item 6. Mechanical
 * source-level test, mirroring AuthDesignSystemContentTest's own
 * established pattern from Slice 2: zero remaining "Ultimate SMS"/
 * "Codeglen" literal strings across every one of this contract's own 41
 * production files (§11 items 1-41).
 */
class BrandingDesignSystemContentTest extends TestCase
{
    private const PRODUCTION_FILES = [
        // Configuration defaults (1)
        'config/app.php',
        // New bundled neutral fallback assets (3)
        'public/images/branding/default-logo.svg',
        'public/images/branding/default-logo-compact.svg',
        'public/images/branding/default-favicon.svg',
        // Domain/storage extension (1)
        'app/Models/AppConfig.php',
        // Upload validation and safe storage (3)
        'app/Rules/ValidBrandingImageRule.php',
        'app/Library/Branding/BrandingUploadService.php',
        'app/Library/Branding/Exceptions/InvalidBrandingAssetException.php',
        // Rendering presenter and components (5)
        'app/Library/Branding/BrandingPresenter.php',
        'resources/views/components/branding-logo.blade.php',
        'resources/views/components/branding-favicon.blade.php',
        'resources/views/components/branding-illustration.blade.php',
        'resources/views/components/branding-footer.blade.php',
        // HTTP surface (2)
        'app/Http/Controllers/Admin/SettingsController.php',
        'app/Http/Requests/Settings/PostGeneralRequest.php',
        // Admin settings view (1)
        'resources/views/admin/settings/AllSettings/_general.blade.php',
        // Shared-chrome adoption (7)
        'resources/views/panels/sidebar.blade.php',
        'resources/views/panels/navbar.blade.php',
        'resources/views/panels/horizontalMenu.blade.php',
        'resources/views/panels/footer.blade.php',
        'resources/views/layouts/fullLayoutMaster.blade.php',
        'resources/views/layouts/detachedLayoutMaster.blade.php',
        'resources/views/layouts/contentLayoutMaster.blade.php',
        // Errors module (7)
        'resources/views/errors/401.blade.php',
        'resources/views/errors/403.blade.php',
        'resources/views/errors/404.blade.php',
        'resources/views/errors/419.blade.php',
        'resources/views/errors/429.blade.php',
        'resources/views/errors/500.blade.php',
        'resources/views/errors/503.blade.php',
        // Auth/Installer adoption (11)
        'resources/views/auth/login.blade.php',
        'resources/views/auth/register.blade.php',
        'resources/views/auth/verify.blade.php',
        'resources/views/auth/twoFactor.blade.php',
        'resources/views/auth/twoFactorBackUp.blade.php',
        'resources/views/auth/passwords/email.blade.php',
        'resources/views/auth/passwords/reset.blade.php',
        'resources/views/auth/subAccount/acceptInvitation.blade.php',
        'resources/views/Installer/welcome.blade.php',
        'resources/views/Installer/update/welcome.blade.php',
        'resources/views/Installer/update/overview.blade.php',
    ];

    public function test_exactly_41_production_files_are_tracked(): void
    {
        $this->assertCount(41, self::PRODUCTION_FILES);
        $this->assertCount(41, array_unique(self::PRODUCTION_FILES), 'No duplicate path.');
    }

    public function test_every_production_file_exists(): void
    {
        foreach (self::PRODUCTION_FILES as $path) {
            $this->assertFileExists(base_path($path), "Missing: {$path}");
        }
    }

    /**
     * Substrings marking lines that are confirmed, out-of-scope,
     * non-branding uses of "codeglen"/"ultimate sms" within otherwise-
     * authorized files -- excluded from the sweep below with a named
     * reason each, never silently dropped:
     *  - 'maintenance_secret_path': a maintenance-mode bypass secret in
     *    config/app.php, coincidentally the literal string "codeglen";
     *    changing a secret path is a real behavior change, not branding.
     *  - 'ultimatesms.codeglen.com': the application's own real,
     *    functional license-verification/update-check API endpoint in
     *    SettingsController.php; rewriting it would break license
     *    verification and update checking, exactly the same
     *    out-of-scope reasoning as the contract's own _license.blade.php
     *    exclusion (§3.7/§8) -- not a rendered branding string at all.
     */
    private const KNOWN_NON_BRANDING_LINE_MARKERS = [
        'maintenance_secret_path',
        'ultimatesms.codeglen.com',
    ];

    public function test_zero_legacy_branding_strings_across_every_production_file(): void
    {
        foreach (self::PRODUCTION_FILES as $path) {
            $source = $this->sourceExcludingKnownNonBrandingLines($path);

            $this->assertStringNotContainsStringIgnoringCase(
                'Ultimate SMS',
                $source,
                "Found 'Ultimate SMS' in {$path}"
            );
            $this->assertStringNotContainsStringIgnoringCase(
                'Codeglen',
                $source,
                "Found 'Codeglen' in {$path}"
            );
        }
    }

    private function sourceExcludingKnownNonBrandingLines(string $path): string
    {
        $lines = file(base_path($path));

        return implode('', array_filter(
            $lines,
            function ($line) {
                foreach (self::KNOWN_NON_BRANDING_LINE_MARKERS as $marker) {
                    if (str_contains($line, $marker)) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    public function test_svg_is_never_an_accepted_upload_extension(): void
    {
        // §6.3: SVG is rejected unconditionally for every owner-upload
        // field -- the rule's own accepted-extension return values are
        // exactly png/jpg/webp, never svg. The word "SVG" legitimately
        // appears in this file's own rejection comments/messages, so
        // this checks the actual returned extension literals, not the
        // word's mere presence.
        $rule = file_get_contents(base_path('app/Rules/ValidBrandingImageRule.php'));

        $this->assertMatchesRegularExpression("/return 'png';/", $rule);
        $this->assertMatchesRegularExpression("/return 'jpg';/", $rule);
        $this->assertMatchesRegularExpression("/return 'webp';/", $rule);
        $this->assertDoesNotMatchRegularExpression("/return 'svg';/", $rule);
    }

    public function test_deferred_files_are_deliberately_untouched_by_this_contract(): void
    {
        // §3.7/§8: named, deferred exclusions -- confirmed present and
        // still carrying the legacy strings this contract's own audit
        // found, proving they were not silently swept up by mistake.
        $license = file_get_contents(base_path('resources/views/admin/settings/AllSettings/_license.blade.php'));
        $this->assertStringContainsStringIgnoringCase('Ultimate SMS', $license);
    }
}
