<?php

namespace App\Library\Migration\Toronto;

use App\Library\Migration\Ghl\GhlSnapshotExporter;

/**
 * Pure, database-free validation of everything the Toronto import reads from disk. It is run before any write and
 * every check fails closed: a missing, unreadable, incomplete or mismatched input is an error, never a warning.
 */
final class TorontoImportPreflight
{
    /** Snapshot datasets that must exist as JSON files holding a list/object (see GhlSnapshotExporter::REQUIRED). */
    private const DATASETS = ['location', 'customFields', 'tags', 'pipelines', 'contacts', 'opportunities', 'conversations', 'messages'];

    /**
     * @return array{snapshot: array<string, mixed>, errors: list<string>}
     */
    public function snapshot(?string $dir): array
    {
        $errors = [];
        if ($dir === null || trim($dir) === '' || ! is_dir($dir)) {
            return ['snapshot' => [], 'errors' => ['The snapshot directory does not exist.']];
        }
        $dir = rtrim($dir, '/');

        $reportFile = $dir.'/_report.json';
        $report = is_file($reportFile) ? json_decode((string) file_get_contents($reportFile), true) : null;
        if (! is_array($report)) {
            return ['snapshot' => [], 'errors' => ['_report.json is missing or not valid JSON: the snapshot is not proven complete.']];
        }
        $incomplete = GhlSnapshotExporter::incompleteDatasets($report);
        if ($incomplete !== []) {
            $errors[] = 'The snapshot is INCOMPLETE / UNVERIFIED for: '.implode(', ', $incomplete).'.';
        }

        $snapshot = [];
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            if ($name === '_report') {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                $errors[] = "Snapshot file {$name}.json is not valid JSON.";

                continue;
            }
            $snapshot[$name] = $decoded;
        }
        foreach (self::DATASETS as $name) {
            if (! array_key_exists($name, $snapshot)) {
                $errors[] = "Required snapshot dataset {$name}.json is missing.";
            }
        }

        $locationId = $snapshot['location']['id'] ?? null;
        if ($locationId !== TorontoProvisioner::SOURCE_LOCATION) {
            $errors[] = 'The snapshot is not from GHL location '.TorontoProvisioner::SOURCE_LOCATION.' (found '.json_encode($locationId).').';
        }

        // The report's counts must describe the files actually present, so a stale or edited report cannot vouch for them.
        foreach (['contacts', 'opportunities', 'conversations', 'customFields', 'tags', 'pipelines'] as $name) {
            if (isset($snapshot[$name], $report[$name]['count']) && (int) $report[$name]['count'] !== count($snapshot[$name])) {
                $errors[] = "Report count for {$name} ({$report[$name]['count']}) does not match the file (".count($snapshot[$name]).').';
            }
        }
        if (isset($snapshot['messages'], $report['messages']['count'])) {
            $messages = array_sum(array_map(static fn ($rows) => is_array($rows) ? count($rows) : 0, $snapshot['messages']));
            if ((int) $report['messages']['count'] !== $messages) {
                $errors[] = "Report count for messages ({$report['messages']['count']}) does not match the file ({$messages}).";
            }
        }

        if (($snapshot['contacts'] ?? []) === []) {
            $errors[] = 'The snapshot has no contacts: nothing to import.';
        }
        if (($snapshot['pipelines'] ?? []) === []) {
            $errors[] = 'The snapshot has no pipelines.';
        }

        return ['snapshot' => $snapshot, 'errors' => $errors];
    }

    /**
     * Every image the website content references must exist in the assets directory with a matching SHA-256.
     *
     * @return list<string>
     */
    public function assets(?string $assetsDir, string $contentPath): array
    {
        if (! is_file($contentPath)) {
            return ['The website content file is missing.'];
        }
        $content = json_decode((string) file_get_contents($contentPath), true);
        if (! is_array($content) || ! is_array($content['assets'] ?? null) || $content['assets'] === [] || ! is_array($content['pages'] ?? null) || $content['pages'] === []) {
            return ['The website content file is invalid or has no assets/pages.'];
        }
        if ($assetsDir === null || trim($assetsDir) === '' || ! is_dir($assetsDir)) {
            return ['The website assets directory does not exist.'];
        }

        $present = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($assetsDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $present[hash_file('sha256', $file->getPathname())] = true;
            }
        }

        $errors = [];
        foreach ($content['assets'] as $role => $entry) {
            $hash = is_array($entry) ? ($entry['sha256'] ?? null) : null;
            if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                $errors[] = "Asset {$role} has no valid sha256 in the content file.";
            } elseif (! isset($present[$hash])) {
                $errors[] = "Asset {$role} is missing or corrupt in the assets directory (expected sha256 {$hash}).";
            }
        }

        return $errors;
    }
}
