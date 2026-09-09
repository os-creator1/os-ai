<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * Redirects the application's environment file at a disposable copy for
 * the duration of one test, and reads values back out of that same copy.
 *
 * WHY THIS EXISTS — two compounding defects it closes.
 *
 * 1. THE SUITE WAS MUTATING THE DEVELOPER'S REAL ENV FILE.
 *    Every settings/branding write path ends at
 *    App\Helpers\write_env(), which rewrites
 *    `app()->environmentFilePath()` wholesale. Under APP_ENV=testing that
 *    path is `.env.testing`, so a full suite run permanently rewrote the
 *    developer's own testing environment: it collapsed the file's line
 *    endings and left behind whatever values the last test happened to
 *    submit. Proven directly on this branch — after one baseline run,
 *    `.env.testing` differed from its pre-run copy in NOCAPTCHA_SITEKEY
 *    and FACEBOOK_CLIENT_ID, and had lost its CRLF line endings.
 *
 *    That is a silent, cross-run isolation break: the next run — and
 *    every other lane sharing the machine — starts from an environment
 *    the previous run edited. `APP_NAME="Test App"` and
 *    `APP_TIMEZONE="America/New_York"`, the two values that made the
 *    branding and heartbeat assertions fail, are exactly the shape of
 *    residue this leaves.
 *
 * 2. THE READ SIDE LOOKED AT A DIFFERENT FILE, AND PARSED IT NAIVELY.
 *    Two test classes each carried their own private copy of
 *    `readEnvValue()`, hardcoded to `base_path('.env')` — the file the
 *    writer never touches under APP_ENV=testing — and trimmed values
 *    with `trim($v, "\"\n")`, which leaves a trailing `\r` on a CRLF
 *    file and therefore also leaves the closing quote. That produced the
 *    memorable `AI Business OS"` actual value.
 *
 * The replacement is one helper: the writer and the reader now address
 * the same disposable file, values are parsed the way dotenv actually
 * quotes them, and the real environment file is never opened for
 * writing at all.
 */
trait UsesTemporaryEnvironmentFile
{
    private ?string $temporaryEnvironmentDirectory = null;

    private ?string $originalEnvironmentPath = null;

    private ?string $originalEnvironmentFile = null;

    /**
     * Point the application at a disposable copy of the environment file
     * that is currently in force, seeded with its real contents so every
     * key a test expects to already exist still does.
     */
    protected function useTemporaryEnvironmentFile(): string
    {
        $sourcePath = $this->app->environmentFilePath();

        $this->originalEnvironmentPath = $this->app->environmentPath();
        $this->originalEnvironmentFile = basename($sourcePath);

        $this->temporaryEnvironmentDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'aibos-env-' . getmypid() . '-' . bin2hex(random_bytes(6));

        File::makeDirectory($this->temporaryEnvironmentDirectory, 0777, true, true);

        $targetPath = $this->temporaryEnvironmentDirectory . DIRECTORY_SEPARATOR . '.env.testing';

        File::put($targetPath, is_file($sourcePath) ? (string) File::get($sourcePath) : '');

        $this->app->useEnvironmentPath($this->temporaryEnvironmentDirectory);
        $this->app->loadEnvironmentFrom('.env.testing');

        return $targetPath;
    }

    /**
     * Always runs, even when the test failed, so a disposable copy can
     * never outlive the test that made it and the application is handed
     * back exactly the paths it had.
     */
    protected function restoreEnvironmentFile(): void
    {
        if ($this->originalEnvironmentPath !== null) {
            $this->app?->useEnvironmentPath($this->originalEnvironmentPath);
        }

        if ($this->originalEnvironmentFile !== null) {
            $this->app?->loadEnvironmentFrom($this->originalEnvironmentFile);
        }

        if ($this->temporaryEnvironmentDirectory !== null && is_dir($this->temporaryEnvironmentDirectory)) {
            File::deleteDirectory($this->temporaryEnvironmentDirectory);
        }

        $this->temporaryEnvironmentDirectory = null;
        $this->originalEnvironmentPath = null;
        $this->originalEnvironmentFile = null;
    }

    /**
     * Read one key back out of the environment file currently in force —
     * the same file write_env() writes — decoding the quoting
     * format_dotenv_value() actually produces.
     *
     * Returns null when the key is absent, which is a genuinely different
     * outcome from an empty value and must stay distinguishable: several
     * tests assert that submitting an empty field CLEARS a key to '',
     * rather than removing it.
     */
    protected function readEnvValue(string $key): ?string
    {
        $path = $this->app->environmentFilePath();

        if (! is_file($path)) {
            return null;
        }

        $lines = preg_split("/\r\n|\n|\r/", (string) File::get($path)) ?: [];

        foreach ($lines as $line) {
            if (! str_starts_with($line, $key . '=')) {
                continue;
            }

            return self::decodeDotenvValue(substr($line, strlen($key) + 1));
        }

        return null;
    }

    /**
     * The exact inverse of App\Helpers\format_dotenv_value(): strip the
     * surrounding quotes it always adds, then undo its escaping of
     * newlines, double quotes and backslashes — in that order, so an
     * escaped backslash cannot swallow the character after it.
     */
    private static function decodeDotenvValue(string $raw): string
    {
        $value = trim($raw);

        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        return str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $value);
    }
}
