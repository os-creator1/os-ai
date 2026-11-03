<?php

namespace App\Library\Settings;

use App\Library\Settings\Exceptions\EnvironmentFileNotWritableException;

use function App\Helpers\load_env_from_file;
use function App\Helpers\write_env;

/**
 * B3 Simplified Platform Settings — the one safe, exact-key .env writer
 * every B3 write path (Platform/Email/Sign-in & Security/AI) goes
 * through, replacing AppConfig::setEnv()'s substring-key-matching,
 * unescaped raw file rewrite for these settings.
 *
 * Wraps the repository's existing, already exact-key/associative
 * write_env() helper (app/Helpers/namespaced_helpers.php) — hardened
 * alongside this class to always quote/escape its value and to clear the
 * config cache on every write — and additionally refreshes the
 * in-process config() repository immediately, mirroring the pattern
 * BrandingUploadService (Design System M2 Platform Branding contract
 * §6.3) already established: a value written this request must be
 * visible to config() for the remainder of this same request/process,
 * not only after the next full bootstrap.
 *
 * Deliberately not a general configuration framework: it has exactly one
 * method, is stateless, and every call site names its own literal .env
 * key and config() key.
 */
class PlatformSettingsEnvWriter
{
    /**
     * Write $value to the .env key $envKey, then — when $configKey is
     * given — immediately mirror it into the in-process config()
     * repository under that dotted key. Pass a null $configKey for an
     * env key with no live config() binding (e.g. the legacy MAIL_DRIVER
     * key, which config/mail.php no longer reads).
     */
    public function set(string $envKey, ?string $configKey, mixed $value): void
    {
        // write_env() ignores a failed file write, so an unwritable .env used to look like a successful save. Refuse
        // up front, and confirm afterwards that the key really is in the file.
        $path = app()->environmentFilePath();
        if (! is_file($path) || ! is_writable($path)) {
            throw EnvironmentFileNotWritableException::for($envKey);
        }

        write_env($envKey, (string) $value);

        if (! array_key_exists($envKey, load_env_from_file($path))) {
            throw EnvironmentFileNotWritableException::for($envKey);
        }

        if ($configKey !== null) {
            config([$configKey => $value]);
        }
    }
}
