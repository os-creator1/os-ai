<?php

namespace App\Library\Migration\Lumina;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The gate for creating/importing the real Lumina Aura account in the PRODUCTION application. Fail-closed: every
 * condition must hold, and each one is a separate human act or a fact about the target, so it cannot pass by accident
 * or from a staging/test database.
 *
 *  - LUMINA_PRODUCTION_PROVISIONING=true on that one command line (a process env var, never .env)
 *  - APP_ENV=production and APP_URL host exactly `getmotiongrove.com` (staging.getmotiongrove.com is refused)
 *  - --confirm-database equals the connected database exactly; the name is not test/staging/local/dev/demo/sandbox-shaped
 *  - a database backup reference (non-empty) and the exact confirmation phrase
 *  - the owner user already exists (this tool never creates the production owner)
 */
class LuminaProductionGuard
{
    public const OPT_IN_ENV = 'LUMINA_PRODUCTION_PROVISIONING';

    public const HOST = 'getmotiongrove.com';

    public const PHRASE = 'CREATE LUMINA AURA IN PRODUCTION';

    private const NOT_PRODUCTION_SHAPED = '/(^|_|-)(test|testing|staging|stage|local|dev|demo|sandbox)(_|-|$)/i';

    /** @return array{app_env: string, app_host: string, db_name: string, db_host: string} */
    public function inspect(): array
    {
        $connection = (string) config('database.default');

        return [
            'app_env' => (string) config('app.env'),
            'app_host' => strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: '')),
            'db_name' => (string) DB::connection()->getDatabaseName(),
            'db_host' => (string) config("database.connections.{$connection}.host", ''),
        ];
    }

    public function assertMayProvision(?string $confirmedDatabase, ?string $backupReference, ?string $phrase, ?string $ownerEmail, ?bool $optedIn = null): array
    {
        $state = $this->inspect();
        $optedIn ??= filter_var(getenv(self::OPT_IN_ENV) ?: false, FILTER_VALIDATE_BOOLEAN);

        if (! $optedIn) {
            throw new RuntimeException('Refusing: set '.self::OPT_IN_ENV.'=true for this one command.');
        }
        if ($state['app_env'] !== 'production') {
            throw new RuntimeException("Refusing: APP_ENV is [{$state['app_env']}], not production.");
        }
        if ($state['app_host'] !== self::HOST) {
            throw new RuntimeException("Refusing: APP_URL host is [{$state['app_host']}], not exactly ".self::HOST.'.');
        }
        if ($state['db_name'] === '' || preg_match(self::NOT_PRODUCTION_SHAPED, $state['db_name']) === 1) {
            throw new RuntimeException("Refusing: database [{$state['db_name']}] is empty or looks like a test/staging database.");
        }
        if ($confirmedDatabase === null || ! hash_equals($state['db_name'], $confirmedDatabase)) {
            throw new RuntimeException("Refusing: --confirm-database must equal the connected database exactly (connected: [{$state['db_name']}]).");
        }
        if ($backupReference === null || trim($backupReference) === '') {
            throw new RuntimeException('Refusing: --backup-reference is required (the id/path of a verified backup taken before this run).');
        }
        if ($phrase !== self::PHRASE) {
            throw new RuntimeException('Refusing: --confirm-phrase must be exactly "'.self::PHRASE.'".');
        }
        if ($ownerEmail === null || ! DB::table('users')->where('email', strtolower($ownerEmail))->exists()) {
            throw new RuntimeException('Refusing: the owner user must already exist in production; this tool never creates it.');
        }

        return $state;
    }
}
