<?php

namespace Tests\Feature\Entitlement;

use Tests\TestCase;

/**
 * Source-scans app/ confirming no raw query against any of the six
 * RFC-004 tables exists outside EntitlementManager and its repositories,
 * except the already-approved M1 migration/backfill historical exception
 * (RFC-004 §20/§25) — mirrors WorkspaceM1BBoundaryTest.php's own
 * phpFilesUnder()-based source-scan technique, not a new scanning
 * mechanism.
 */
class NoRawEntitlementTableQueryTest extends TestCase
{
    private const TABLES = [
        'workspace_plan_catalog',
        'workspace_plan_features',
        'workspace_plan_assignments',
        'workspace_entitlement_overrides',
        'business_feature_toggles',
        'workspace_entitlement_transitions',
    ];

    private const ALLOWED_BASENAME_SUBSTRINGS = [
        'EntitlementManager.php',
        'EloquentWorkspacePlanCatalogRepository.php',
        'EloquentWorkspacePlanFeatureRepository.php',
        'EloquentWorkspacePlanAssignmentRepository.php',
        'EloquentWorkspaceEntitlementOverrideRepository.php',
        'EloquentBusinessFeatureToggleRepository.php',
        'EloquentWorkspaceEntitlementTransitionRepository.php',
        'WorkspaceEntitlementBackfillV1.php',
        // Eloquent models declaring $table — plain ORM wiring, not a raw
        // query, and every repository already operates through these
        // models rather than the raw table name.
        'WorkspacePlanCatalog.php',
        'WorkspacePlanFeature.php',
        'WorkspacePlanAssignment.php',
        'WorkspaceEntitlementOverride.php',
        'BusinessFeatureToggle.php',
        'WorkspaceEntitlementTransition.php',
    ];

    public function test_no_raw_entitlement_table_query_exists_outside_authorized_files(): void
    {
        $offending = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $basename = basename($file);

            if (in_array($basename, self::ALLOWED_BASENAME_SUBSTRINGS, true)) {
                continue;
            }

            if (str_contains($file, DIRECTORY_SEPARATOR . 'Migration' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $contents = file_get_contents($file);

            foreach (self::TABLES as $table) {
                if (preg_match('/[\'"]' . preg_quote($table, '/') . '[\'"]/', $contents) === 1) {
                    $offending[] = "{$file} (table: {$table})";
                }
            }
        }

        $this->assertSame([], $offending, 'Unexpected raw entitlement-table reference(s): ' . implode(', ', $offending));
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }
}
