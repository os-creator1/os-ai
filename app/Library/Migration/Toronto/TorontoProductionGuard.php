<?php

namespace App\Library\Migration\Toronto;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fail-closed gate for creating/importing the Toronto Photo Booth account in the PRODUCTION application. Every condition
 * must hold; each is a separate human act or a fact about the target, so none can pass by accident:
 *
 *  - TORONTO_PRODUCTION_PROVISIONING=true on that one command line (a process env var, never .env)
 *  - APP_ENV=production, APP_URL host exactly `getmotiongrove.com` and the connected database exactly `GetMotionGrove`
 *  - --confirm-database equals the connected database; --backup-reference is non-empty
 *  - the exact confirmation phrase
 *  - the platform administrator exists (the complimentary plan is recorded against it)
 */
class TorontoProductionGuard
{
    public const OPT_IN_ENV = 'TORONTO_PRODUCTION_PROVISIONING';

    public const HOST = 'getmotiongrove.com';

    public const DATABASE = 'GetMotionGrove';

    public const PHRASE = 'CREATE TORONTO PHOTO BOOTH IN PRODUCTION';

    /** @return array{app_env: string, app_host: string, db_name: string} */
    public function inspect(): array
    {
        return [
            'app_env' => (string) config('app.env'),
            'app_host' => strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: '')),
            'db_name' => (string) DB::connection()->getDatabaseName(),
        ];
    }

    /** Environment facts only (no opt-in/backup/phrase): enough for a read-only dry run to prove it is aimed at the right place. */
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
        if ($backupReference === null || trim($backupReference) === '') {
            throw new RuntimeException('Refusing: --backup-reference is required (the path of a verified backup taken before this run).');
        }
        if ($phrase !== self::PHRASE) {
            throw new RuntimeException('Refusing: --confirm-phrase must be exactly "'.self::PHRASE.'".');
        }
        if (! DB::table('users')->where('is_admin', true)->exists()) {
            throw new RuntimeException('Refusing: no platform administrator exists to record the complimentary plan against.');
        }

        return $state;
    }
}
