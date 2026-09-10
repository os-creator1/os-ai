<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * Gives one test application its own disposable copy of the environment
 * file, installed BEFORE the console kernel bootstraps, and reads values
 * back out of that same copy.
 *
 * WHY THIS EXISTS — reproduced on this branch, at the base commit,
 * before a line of it was written.
 *
 * 1. THE SUITE REWROTE THE DEVELOPER'S OWN ENVIRONMENT FILES.
 *    Every platform-settings save, the branding upload service and the
 *    demo-mode toggle end at App\Helpers\write_env(), which rewrites
 *    the active environment file wholesale — `.env.testing` under
 *    APP_ENV=testing. Running `tests/Feature/Settings` against a
 *    pristine checkout changed `.env.testing` from
 *    APP_NAME="AI Business OS" to APP_NAME="Test App", set
 *    MAIL_DRIVER="smtp", deleted every blank line, and appended the
 *    suite's own fixture values, secret-shaped ones included.
 *
 * 2. WHY ACTIVATION HAD TO MOVE BEFORE BOOTSTRAP.
 *    An earlier revision activated this trait from Tests\TestCase::setUp(),
 *    after parent::setUp(). That is too late. Laravel's
 *    Illuminate\Foundation\Testing\TestCase::setUpTheTestEnvironment()
 *    calls refreshApplication() — which creates the Application and runs
 *    `$app->make(Kernel::class)->bootstrap()` — and only then runs
 *    setUpTraits(), where RefreshDatabase performs migrate:fresh.
 *
 *    So configuration bootstrap and every migration ran while the
 *    application still pointed at the repository's own files. Three
 *    migrations write the environment file directly, and `migrate:fresh`
 *    alone was measured moving the real `.env` mtime.
 *
 *    Installation therefore happens inside Tests\CreatesApplication,
 *    between `require bootstrap/app.php` and the kernel bootstrap. Every
 *    later stage — LoadEnvironmentVariables, LoadConfiguration,
 *    RefreshDatabase, the migrations, the test body — sees only the
 *    disposable copy.
 *
 * 3. NO SNAPSHOT-AND-RESTORE FALLBACK EXISTS, AND MUST NOT RETURN.
 *    An earlier revision contained the second writer by snapshotting the
 *    real `.env` and restoring it at teardown. That was withdrawn:
 *    restoring damage is not isolation. Two concurrent processes can
 *    write and restore the same shared file in conflicting orders; a
 *    kill, fatal or power loss leaves the damage in place because
 *    teardown never runs; and another process can read the test's values
 *    out of the real file during the window before restoration. Every
 *    production writer now resolves the active environment path instead,
 *    so this trait never opens a repository environment file for
 *    writing and holds no snapshot of one.
 *
 * 4. THE READ SIDE ADDRESSED A DIFFERENT FILE, AND PARSED IT NAIVELY.
 *    The shared helper read `base_path('.env')` — the file the writers
 *    never touch under APP_ENV=testing — and trimmed with
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
     * Bounded retry for the final rmdir — see removeDirectory(). Twenty
     * attempts, 10ms apart, is ~200ms worst case and only ever elapses
     * when the directory genuinely refuses to go.
     */
    private const REMOVE_ATTEMPTS = 20;

    private const REMOVE_RETRY_MICROSECONDS = 10_000;

    /**
     * PRE-BOOT ENTRY POINT. Called by Tests\CreatesApplication after the
     * Application object exists and before the console kernel
     * bootstraps.
     *
     * The application is not booted yet, so `$app->environmentPath()` is
     * still the repository root and `$app->environmentFile()` is still
     * `.env`. Which file Laravel *would* have loaded is decided by
     * Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables, so that
     * decision is reproduced here rather than assumed (see
     * frameworkSelectedEnvironmentFile()), the selected file is copied
     * under its own name, and the application is pointed at the copy.
     *
     * Setting the filename as well as the directory matters: once
     * `environmentFile()` is `.env.testing`, the bootstrapper's own
     * re-check looks for `.env.testing.testing`, does not find it, and
     * leaves the selection alone. The copy is what gets loaded.
     *
     * @return string the absolute path of the disposable copy
     */
    protected function installTemporaryEnvironmentFile(Application $app): string
    {
        // A previous application instance in this same test must not
        // leak its copy — refreshApplication() can run more than once.
        if ($this->temporaryEnvironmentDirectory !== null) {
            $this->discardTemporaryEnvironmentDirectory();
        }

        self::registerProcessShutdownSweep();

        $sourceDirectory = $app->environmentPath();
        $selected = self::frameworkSelectedEnvironmentFile($app, $sourceDirectory, $app->environmentFile());

        $this->originalEnvironmentPath = $sourceDirectory;
        $this->originalEnvironmentFile = $selected;

        $sourcePath = $sourceDirectory . DIRECTORY_SEPARATOR . $selected;

        $this->temporaryEnvironmentDirectory = self::makeTemporaryEnvironmentDirectory();

        // Native filesystem calls, not the File facade: this runs
        // BEFORE the container is bootstrapped, so no facade root exists
        // yet.
        if (! is_dir($this->temporaryEnvironmentDirectory)) {
            mkdir($this->temporaryEnvironmentDirectory, 0777, true);
        }

        $targetPath = $this->temporaryEnvironmentDirectory . DIRECTORY_SEPARATOR . $selected;

        // The copy carries exactly the bytes Laravel would otherwise
        // have loaded. A missing source yields an empty copy, which
        // Dotenv's safeLoad() tolerates identically — and the real file
        // is never created.
        file_put_contents($targetPath, is_file($sourcePath) ? (string) file_get_contents($sourcePath) : '');

        $app->useEnvironmentPath($this->temporaryEnvironmentDirectory);
        $app->loadEnvironmentFrom($selected);

        return $targetPath;
    }

    /**
     * Re-activate from inside a test, after the application has booted.
     *
     * Restores first, so the new copy is seeded from the repository's
     * own file rather than from the copy already in force. That is what
     * makes one test's writes unable to reach the next.
     *
     * @return string the absolute path of the new disposable copy
     */
    protected function useTemporaryEnvironmentFile(): string
    {
        $this->restoreEnvironmentFile();

        return $this->installTemporaryEnvironmentFile($this->app);
    }

    /**
     * Hands the application back the path and filename the bootstrap
     * left it with, and deletes the disposable directory.
     *
     * Always runs — after a pass, after a failed assertion and after an
     * uncaught exception. Safe to call twice: every step is guarded and
     * every field cleared, so a second call is a no-op.
     */
    protected function restoreEnvironmentFile(): void
    {
        if ($this->originalEnvironmentPath !== null) {
            $this->app?->useEnvironmentPath($this->originalEnvironmentPath);
        }

        if ($this->originalEnvironmentFile !== null) {
            $this->app?->loadEnvironmentFrom($this->originalEnvironmentFile);
        }

        $this->discardTemporaryEnvironmentDirectory();

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
     * The repository file this copy was seeded from — used by tests that
     * assert the real file was not touched, and never written to.
     */
    protected function realEnvironmentFilePath(): ?string
    {
        if ($this->originalEnvironmentPath === null || $this->originalEnvironmentFile === null) {
            return null;
        }

        return $this->originalEnvironmentPath
            . DIRECTORY_SEPARATOR
            . $this->originalEnvironmentFile;
    }

    /**
     * Read one key back out of the environment file currently in force —
     * the same file every corrected writer writes — decoding the quoting
     * that App\Helpers\format_dotenv_value() actually produces.
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
        $lines = preg_split("/\r\n|\n|\r/", (string) file_get_contents($path)) ?: [];

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
     * Which environment file Laravel itself would load from $directory.
     *
     * A faithful reproduction of
     * Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::checkForSpecificEnvironmentFile():
     * a console `--env` option wins, otherwise the process-level APP_ENV
     * is appended to the base filename, and in both cases the suffixed
     * file is only chosen when it actually exists. Anything else keeps
     * the base name.
     *
     * Deliberately not guessed at: reading APP_ENV through
     * Illuminate\Support\Env uses the same repository and adapter chain
     * the bootstrapper uses, so a value supplied by phpunit.xml's
     * `<server>` element resolves identically here.
     */
    private static function frameworkSelectedEnvironmentFile(
        Application $app,
        string $directory,
        string $baseFile,
    ): string {
        $exists = static fn (string $candidate): bool => is_file($directory . DIRECTORY_SEPARATOR . $candidate);

        if ($app->runningInConsole()) {
            $input = new ArgvInput();

            if ($input->hasParameterOption('--env')) {
                $candidate = $baseFile . '.' . $input->getParameterOption('--env');

                if ($exists($candidate)) {
                    return $candidate;
                }
            }
        }

        $environment = Env::get('APP_ENV');

        if ($environment) {
            $candidate = $baseFile . '.' . $environment;

            if ($exists($candidate)) {
                return $candidate;
            }
        }

        return $baseFile;
    }

    /**
     * A last-resort sweep, registered once per process.
     *
     * tearDown() handles a pass, a failed assertion and an exception
     * thrown from the test body. It does NOT handle an exception thrown
     * from setUp(): PHPUnit skips tearDown() entirely in that case, and
     * the application has already been created — so its disposable
     * directory would outlive the process. A full-suite run left exactly
     * one such orphan behind.
     *
     * The sweep removes only directories carrying THIS process's pid, so
     * it can never disturb a concurrent process's copy. It runs at normal
     * shutdown, including after a fatal error; a process killed with
     * SIGKILL runs no shutdown function at all, which is precisely why
     * these directories live outside the repository and why the
     * forced-termination test cleans up after itself.
     */
    private static function registerProcessShutdownSweep(): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        $registered = true;

        $pattern = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aibos-env-' . getmypid() . '-*';

        register_shutdown_function(static function () use ($pattern): void {
            foreach (glob($pattern) ?: [] as $directory) {
                self::removeDirectory($directory);
            }
        });
    }

    /**
     * Recursive delete with native calls only, for the same pre-boot
     * reason the create side avoids the File facade. The directory this
     * removes holds exactly one file, but the walk is written generally
     * so a stray artifact can never keep it alive.
     *
     * THE FINAL rmdir IS RETRIED, AND THAT IS THE POINT.
     *
     * A single suppressed `@rmdir()` left an EMPTY directory behind
     * roughly one repeated forced-termination run in eight on Windows:
     * the contained file was gone, but the directory itself briefly
     * refused to go because a just-released handle had not been reaped
     * yet. Retrying over a short bounded window closes that race without
     * changing what may be deleted.
     *
     * The boundary is unchanged and deliberately narrow: this only ever
     * operates on the exact directory it is handed — one this trait
     * created under sys_get_temp_dir(), named with this process's own
     * pid. It never widens to a parent, never globs, never shells out,
     * and a directory that survives every attempt is left in place
     * rather than reported as removed. The callers' own assertions still
     * decide whether that is acceptable.
     */
    private static function removeDirectory(string $directory): bool
    {
        clearstatcache(true, $directory);

        if (! is_dir($directory)) {
            return true;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }

        // Deterministic bound: at most self::REMOVE_ATTEMPTS tries with
        // self::REMOVE_RETRY_MICROSECONDS between them — about a fifth of
        // a second in total, far longer than the handle race needs and
        // short enough never to be felt in a suite.
        for ($attempt = 1; $attempt <= self::REMOVE_ATTEMPTS; $attempt++) {
            if (@rmdir($directory)) {
                return true;
            }

            clearstatcache(true, $directory);

            // Something else removed it, or it was never really there.
            if (! is_dir($directory)) {
                return true;
            }

            if ($attempt < self::REMOVE_ATTEMPTS) {
                usleep(self::REMOVE_RETRY_MICROSECONDS);
            }
        }

        clearstatcache(true, $directory);

        // Never claim success for a directory that is still present.
        return ! is_dir($directory);
    }

    private function discardTemporaryEnvironmentDirectory(): void
    {
        if ($this->temporaryEnvironmentDirectory !== null && is_dir($this->temporaryEnvironmentDirectory)) {
            self::removeDirectory($this->temporaryEnvironmentDirectory);
        }

        $this->temporaryEnvironmentDirectory = null;
    }

    /**
     * The exact inverse of App\Helpers\format_dotenv_value().
     *
     * That writer escapes in a fixed order — backslash, then double
     * quote, then newline — and always wraps the result in double
     * quotes. Decoding therefore has to be a single left-to-right scan
     * that consumes each escape sequence whole.
     *
     * A pass of str_replace(['\\n', '\\"', '\\\\'], …) is NOT equivalent
     * and is wrong: the encoding of the six characters `C:\new` is
     * `C:\\new`, and a leading `\n` replacement matches the SECOND
     * backslash together with the `n`, decoding it to `C:` + backslash +
     * a real newline + `ew`. The scan below cannot do that, because it
     * consumes `\\` as one unit before it ever looks at the `n`.
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
     * directory and a single process recreating its application never
     * reuses one.
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
