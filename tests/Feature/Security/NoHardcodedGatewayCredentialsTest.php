<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Security Remediation Slice 0 §16.A.1 (D-24) — guards against the 29
 * hardcoded gateway definitions (two carrying provider credentials, one
 * carrying a third party's bank routing number, account number,
 * beneficiary name and support email address) being reintroduced by a
 * revert or a copy-paste of the deleted controller. No PHP file may exist
 * under app/Http/Controllers/Debug/ at all.
 */
class NoHardcodedGatewayCredentialsTest extends TestCase
{
    public function test_the_debug_controller_directory_contains_no_php_file(): void
    {
        $directory = app_path('Http/Controllers/Debug');

        $this->assertDirectoryDoesNotExist($directory);
    }

    /**
     * A seeder once shipped an upstream demo Stripe test key pair. Seeded rows can be enabled by an
     * operator, so a committed literal key is a credential that could be activated on any install.
     * Stripe keys belong in the environment (STRIPE_*), never in source, config, seeders or views.
     */
    public function test_no_stripe_key_literal_is_committed_in_application_code(): void
    {
        $roots = [app_path(), config_path(), database_path(), base_path('routes'), resource_path('views')];
        $matches = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (! in_array($file->getExtension(), ['php', 'json', 'env'], true)) {
                    continue;
                }

                if (preg_match('/\b(?:sk|pk|rk)_(?:test|live)_[A-Za-z0-9]{10,}/', (string) file_get_contents($file->getPathname()))) {
                    $matches[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $matches, 'A Stripe key literal is committed; move it to the environment.');
    }

    public function test_no_php_source_file_anywhere_declares_a_debug_controller_class(): void
    {
        $matches = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (str_contains($contents, 'class DebugController')) {
                $matches[] = $file->getPathname();
            }
        }

        $this->assertSame([], $matches, 'No file may declare a DebugController class.');
    }
}
