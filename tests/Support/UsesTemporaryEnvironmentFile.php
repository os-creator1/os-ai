<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * Gives one test its own disposable copy of the environment file, and
 * reads values back out of that same copy.
 *
 * WHY THIS EXISTS — reproduced on this branch, at this base commit,
 * before a line of it was written.
 *
 * 1. THE SUITE REWROTE THE DEVELOPER'S OWN ENVIRONMENT FILES.
 *    Every platform-settings save, the branding upload service and the
 *    demo-mode toggle end at App\Helpers\write_env(), which rewrites
 *    `app()->environmentFilePath()` wholesale — `.env.testing` under
 *    APP_ENV=testing. Running `tests/Feature/Settings` against a
 *    pristine checkout of this commit changed `.env.testing` from
 *    APP_NAME="AI Business OS" to APP_NAME="Test App", set
 *    MAIL_DRIVER="smtp", deleted every blank line, and appended the
 *    suite's own fixture values — including secret-shaped ones such as
 *    the suite's fake OPENAI_API_KEY fixture. The next run, in this lane or any
 *    other lane sharing the machine, then started from an environment
 *    the previous run had edited.
 *
 * 2. A SECOND WRITER USED TO BYPASS THAT SEAM ENTIRELY — NOW FIXED AT
 *    THE SOURCE.
 *    App\Models\AppConfig::setEnv() hardcoded `base_path('.env')`. It is
 *    reached from SettingsController (TERMS_OF_USE, PRIVACY_POLICY,
 *    MAINTENANCE_SECRET_PATH) and from BrandingUploadService (the
 *    APP_LOGO family). The same pristine run also modified `.env`,
 *    collapsing the line endings of every line it matched.
 *
 *    An earlier revision of this trait tried to contain that by
 *    snapshotting the real `.env` and restoring it at teardown. **That
 *    was withdrawn, and must not come back.** Restoring damage is not
 *    isolation: two concurrent processes can write and restore the same
 *    shared file in conflicting orders; a kill, fatal or power loss
 *    leaves the damage in place; and another process can read the test's
 *    values out of the real file during the window before restoration.
 *    The requirement is that production writers never address the real
 *    file at all.
 *
 *    `AppConfig::setEnv()` now uses `app()->environmentFilePath()`, the
 *    same seam `write_env()` has always used, so redirecting the
 *    application's environment path redirects it too. This trait
 *    therefore never opens the real environment file for writing, and
 *    holds no snapshot of it.
 *
 * 3. THE READ SIDE LOOKED AT A DIFFERENT FILE, AND PARSED IT NAIVELY.
 *    The shared helper read `base_path('.env')` — the file write_env()
 *    never touches under APP_ENV=testing — and trimmed with
 *    `trim($v, "\"\n")`, which leaves a trailing `\r` on a CRLF file and
 *    therefore also leaves the closing quote.
 */
trait UsesTemporaryEnvironmentFile
{
    private ?string $temporaryEnvironmentDirectory = null;

    private ?string $originalEnvironmentPath = null;

    private ?string $originalEnvironmentFile = null;

    /**
     * Distinguishes two activations inside a single process even if the
     * clock and the random source both repeated themselves.
     */
    private static int $temporaryEnvironmentSequence = 0;

    /**
     * Point the application at a disposable copy of the environment file
     * that is actually in force for this process, seeded with its real
     * contents so every key a test expects to already exist still does.
     *
     * @return string the absolute path of the disposable copy
     */
    protected function useTemporaryEnvironmentFile(): string
    {
        // Re-activating must never leak the previous copy, and must seed
        // the new one from the REAL environment file rather than from the
        // copy already in force. Restoring first guarantees both.
        if ($this->temporaryEnvironmentDirectory !== null) {
            $this->restoreEnvironmentFile();
        }

        $sourcePath = $this->app->environmentFilePath();

        $this->originalEnvironmentPath = $this->app->environmentPath();
        $this->originalEnvironmentFile = basename($sourcePath);

        $this->temporaryEnvironmentDirectory = self::makeTemporaryEnvironmentDirectory();

        File::makeDirectory($this->temporaryEnvironmentDirectory, 0777, true, true);

        // Keep the original basename. The application's notion of which
        // environment file is in force must be restored exactly, and a
        // test that inspects environmentFile() must see what it saw
        // before.
        $targetPath = $this->temporaryEnvironmentDirectory
            . DIRECTORY_SEPARATOR
            . $this->originalEnvironmentFile;

        File::put($targetPath, is_file($sourcePath) ? (string) File::get($sourcePath) : '');

        $this->app->useEnvironmentPath($this->temporaryEnvironmentDirectory);
        $this->app->loadEnvironmentFrom($this->originalEnvironmentFile);

        return $targetPath;
    }

    /**
     * Always runs — after a pass, after a failed assertion, and after an
     * uncaught exception — so a disposable copy can never outlive the
     * test that made it, and the application is handed back exactly the
     * paths it had.
     *
     * Safe to call twice: every step is guarded and every field is
     * cleared, so a second call is a no-op.
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
     * The disposable environment file currently in force, or null when
     * this trait is not active.
     */
    protected function temporaryEnvironmentFilePath(): ?string
    {
        if ($this->temporaryEnvironmentDirectory === null || $this->originalEnvironmentFile === null) {
            return null;
        }

        return $this->temporaryEnvironmentDirectory
            . DIRECTORY_SEPARATOR
            . $this->originalEnvironmentFile;
    }

    /**
     * Read one key back out of the environment file currently in force —
     * the same file write_env() writes — decoding the quoting that
     * App\Helpers\format_dotenv_value() actually produces.
     *
     * Returns null when the key is absent, which is a genuinely
     * different outcome from an empty value and must stay
     * distinguishable: several tests assert that submitting an empty
     * field CLEARS a key to '' rather than removing it.
     */
    protected function readActiveEnvValue(string $key): ?string
    {
        $path = $this->app->environmentFilePath();

        if (! is_file($path)) {
            return null;
        }

        // Handles LF, CRLF and lone-CR files identically, so a value on a
        // CRLF line never keeps a trailing \r — which is what used to
        // leave the closing quote attached to the decoded value.
        $lines = preg_split("/\r\n|\n|\r/", (string) File::get($path)) ?: [];

        $needle = $key . '=';

        foreach ($lines as $line) {
            if (! str_starts_with($line, $needle)) {
                continue;
            }

            return self::decodeDotenvValue(substr($line, strlen($needle)));
        }

        return null;
    }

    /**
     * The exact inverse of App\Helpers\format_dotenv_value().
     *
     * That writer escapes in a fixed order — backslash, then double
     * quote, then newline — and always wraps the result in double
     * quotes. Decoding therefore has to be a single left-to-right scan
     * that consumes each escape sequence whole.
     *
     * A pass of str_replace(['\\n', '\\"', '\\\\'], ...) is NOT
     * equivalent and is wrong: the encoding of the four characters
     * `C:\new` is `C:\\new`, and a leading `\n` replacement matches the
     * SECOND backslash together with the `n`, decoding it to
     * `C:` + backslash + a real newline + `ew`. The scan below cannot do
     * that, because it consumes `\\` as one unit before it ever looks at
     * the `n`.
     *
     * An unquoted value is returned as-is apart from surrounding
     * whitespace: dotenv applies no escaping outside quotes, so a
     * backslash in an unquoted value is a literal backslash.
     */
    private static function decodeDotenvValue(string $raw): string
    {
        $value = trim($raw);

        $isQuoted = strlen($value) >= 2
            && str_starts_with($value, '"')
            && str_ends_with($value, '"');

        if (! $isQuoted) {
            return $value;
        }

        $body = substr($value, 1, -1);
        $decoded = '';
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $character = $body[$i];

            if ($character !== '\\' || $i + 1 >= $length) {
                $decoded .= $character;

                continue;
            }

            $next = $body[$i + 1];

            $decoded .= match ($next) {
                '\\' => '\\',
                '"' => '"',
                'n' => "\n",
                // An escape this writer never emits is preserved
                // verbatim rather than silently swallowed.
                default => '\\' . $next,
            };

            $i++;
        }

        return $decoded;
    }

    /**
     * Outside the repository, unique per process AND per activation, so
     * two concurrent PHPUnit processes can never be handed the same
     * directory and a single process re-activating never reuses one.
     */
    private static function makeTemporaryEnvironmentDirectory(): string
    {
        self::$temporaryEnvironmentSequence++;

        return sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'aibos-env-'
            . getmypid()
            . '-' . self::$temporaryEnvironmentSequence
            . '-' . bin2hex(random_bytes(8));
    }

}
