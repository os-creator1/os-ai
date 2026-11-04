<?php

namespace App\Library\Migration\CustomerSites;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fail-closed gate for building customer websites in the PRODUCTION application. A dry run needs only the target facts;
 * a write additionally needs every separate human act below, so none can pass by accident:
 *
 *  - CUSTOMER_SITES_PRODUCTION_WRITE=true on that one command line (a process env var, never .env)
 *  - APP_ENV=production, APP_URL host exactly `getmotiongrove.com` and the connected database exactly `GetMotionGrove`
 *  - --confirm-database equals the connected database; --backup-reference is a readable, non-empty backup file
 *  - the exact confirmation phrase
 */
class CustomerSiteGuard
{
    public const OPT_IN_ENV = 'CUSTOMER_SITES_PRODUCTION_WRITE';

    public const HOST = 'getmotiongrove.com';

    public const DATABASE = 'GetMotionGrove';

    public const PHRASE = 'BUILD CUSTOMER WEBSITES IN PRODUCTION';

    /** @return array{app_env: string, app_host: string, db_name: string} */
    public function inspect(): array
    {
        return [
            'app_env' => (string) config('app.env'),
            'app_host' => strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: '')),
            'db_name' => (string) DB::connection()->getDatabaseName(),
        ];
    }

    /** @return array{app_env: string, app_host: string, db_name: string} */
    public function assertTarget(): array
    {
        $state = $this->inspect();
        if ($state['app_env'] !== 'production') {
            throw new RuntimeException("Refusing: APP_ENV is [{$state['app_env']}], not production.");
        }
        if ($state['app_host'] !== self::HOST) {
            throw new RuntimeException("Refusing: APP_URL host is [{$state['app_host']}], not exactly ".self::HOST.'.');
        }
        if ($state['db_name'] !== self::DATABASE) {
            throw new RuntimeException("Refusing: connected database is [{$state['db_name']}], not exactly ".self::DATABASE.'.');
        }

        return $state;
    }

    /** @return array{app_env: string, app_host: string, db_name: string} */
    public function assertMayWrite(?string $confirmedDatabase, ?string $backupReference, ?string $phrase, ?bool $optedIn = null): array
    {
        $optedIn ??= filter_var(getenv(self::OPT_IN_ENV) ?: false, FILTER_VALIDATE_BOOLEAN);
        if (! $optedIn) {
            throw new RuntimeException('Refusing: set '.self::OPT_IN_ENV.'=true for this one command.');
        }
        $state = $this->assertTarget();
        if ($confirmedDatabase === null || ! hash_equals($state['db_name'], $confirmedDatabase)) {
            throw new RuntimeException("Refusing: --confirm-database must equal the connected database exactly (connected: [{$state['db_name']}]).");
        }
        if ($backupReference === null || ! is_file($backupReference) || filesize($backupReference) < 1024) {
            throw new RuntimeException('Refusing: --backup-reference must be the path of a verified, non-trivial backup file taken before this run.');
        }
        if ($phrase !== self::PHRASE) {
            throw new RuntimeException('Refusing: --confirm-phrase must be exactly "'.self::PHRASE.'".');
        }

        return $state;
    }
}
