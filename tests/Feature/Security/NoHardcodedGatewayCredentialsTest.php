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
