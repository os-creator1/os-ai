<?php

namespace App\Library\Branding;

use App\Library\Branding\Exceptions\InvalidBrandingAssetException;
use App\Models\AppConfig;
use App\Rules\ValidBrandingImageRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Design System M2 Platform Branding contract §6.3/§11 item 7. Safe
 * content-hashed filenames (never client-derived), write-then-swap-then-
 * delete replacement, fail-closed on any validation/storage/integrity
 * failure. Continues the codebase's own established public_path()
 * storage convention (§3.9) — no new Storage::disk() abstraction.
 */
class BrandingUploadService
{
    private const DEFAULT_PATHS = [
        'logo' => 'images/branding/default-logo.svg',
        'favicon' => 'images/branding/default-favicon.svg',
    ];

    /**
     * logo_compact/logo_dark are deliberately not APP_LOGO_COMPACT/
     * APP_LOGO_DARK -- AppConfig::setEnv()'s own find/replace is a
     * substring match on the key, so any .env key containing "APP_LOGO"
     * as a substring would be silently corrupted by an update to the
     * plain APP_LOGO key (confirmed directly during this contract's own
     * test development).
     */
    private const ENV_KEYS = [
        'logo' => 'APP_LOGO',
        'logo_compact' => 'APP_COMPACT_LOGO',
        'logo_dark' => 'APP_DARK_LOGO',
        'favicon' => 'APP_FAVICON',
        'auth_illustration' => 'APP_AUTH_ILLUSTRATION',
        'installer_illustration' => 'APP_INSTALLER_ILLUSTRATION',
    ];

    /**
     * @throws InvalidBrandingAssetException
     */
    public function store(UploadedFile $file, string $field): string
    {
        if (! isset(self::ENV_KEYS[$field])) {
            throw new InvalidBrandingAssetException('Unknown branding field.');
        }

        $contents = file_get_contents($file->getRealPath());

        if ($contents === false || $contents === '') {
            throw new InvalidBrandingAssetException('The uploaded file could not be read.');
        }

        // Re-validated here, independent of the FormRequest's own rule —
        // this service never trusts that validation already ran upstream.
        $extension = ValidBrandingImageRule::detectExtension($contents);

        $safeFilename = hash('sha256', $contents) . '.' . $extension;
        $directory = public_path("images/branding/{$field}");
        $destination = $directory . DIRECTORY_SEPARATOR . $safeFilename;
        $relativePath = "images/branding/{$field}/{$safeFilename}";

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new InvalidBrandingAssetException('The branding asset directory could not be created.');
        }

        if (file_put_contents($destination, $contents) === false) {
            throw new InvalidBrandingAssetException('The branding asset could not be stored.');
        }

        // Post-write integrity check — fail closed rather than point the
        // config pointer at a file that is not confirmed correct.
        $writtenContents = file_get_contents($destination);
        if ($writtenContents === false || hash('sha256', $writtenContents) !== hash('sha256', $contents)) {
            @unlink($destination);
            throw new InvalidBrandingAssetException('The branding asset failed integrity verification after storage.');
        }

        $previousPath = config("app.{$field}");

        AppConfig::setEnv(self::ENV_KEYS[$field], $relativePath);
        // Keeps the in-process config repository consistent with the
        // just-written .env file immediately — AppConfig::setEnv() only
        // rewrites the file, it does not itself update config() for the
        // remainder of the current request/process (a real gap for any
        // non-HTTP-redirect caller, e.g. a console command or queued job,
        // not merely a test-only concern).
        config(["app.{$field}" => $relativePath]);

        $this->invalidateCache();

        // Swap only after the new file is confirmed written and the
        // config pointer updated — never delete-then-write.
        $this->deletePreviousFileIfOwned($previousPath, $relativePath);

        return $relativePath;
    }

    /**
     * "Remove this branding asset" — returns the field to unconfigured,
     * falling back to the bundled default (logo/favicon) or to null
     * (every optional field).
     */
    public function delete(string $field): void
    {
        if (! isset(self::ENV_KEYS[$field])) {
            throw new InvalidBrandingAssetException('Unknown branding field.');
        }

        $previousPath = config("app.{$field}");
        $resetValue = self::DEFAULT_PATHS[$field] ?? null;

        AppConfig::setEnv(self::ENV_KEYS[$field], (string) $resetValue);
        config(["app.{$field}" => $resetValue]);

        $this->invalidateCache();

        $this->deletePreviousFileIfOwned($previousPath, (string) $resetValue);
    }

    private function deletePreviousFileIfOwned(?string $previousRelativePath, string $newRelativePath): void
    {
        if (empty($previousRelativePath) || $previousRelativePath === $newRelativePath) {
            return;
        }

        // Only ever deletes a file this service itself owns (under the
        // per-field images/branding/{field}/ directory) — never the
        // bundled default assets, never a path outside its own tree.
        if (! str_starts_with($previousRelativePath, 'images/branding/')) {
            return;
        }
        if (in_array($previousRelativePath, self::DEFAULT_PATHS, true)) {
            return;
        }

        $previousFullPath = public_path($previousRelativePath);
        if (is_file($previousFullPath)) {
            @unlink($previousFullPath);
        }
    }

    private function invalidateCache(): void
    {
        DB::afterCommit(function () {
            Cache::forget(BrandingPresenter::CACHE_KEY);
        });
    }
}
