<?php

namespace Tests\Feature\Workspace\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates and tears down single-use, uniquely named temporary databases for
 * RFC-003 M1B's isolated test suites: historical M1A tests (Slice 4A,
 * §10.2, §10.6, §23) and schema-mutating enforcement-migration tests
 * (Slice 4B, §10.6 step 5). Test-only infrastructure — deliberately lives
 * under tests/, never app/, and is never referenced by production code.
 *
 * The primary ultimatesms_testing database is only ever read from, to
 * verify it is the base connection before creating a temporary database,
 * and to issue the final DROP DATABASE — it is never migrated, dropped,
 * renamed or reconfigured itself.
 *
 * Only two closed-purpose entry points are exposed
 * (withHistoricalDatabase(), withEnforcementDatabase()) — deliberately no
 * generic raw-name creation method, so every caller's intent is explicit
 * and every generated name is validated against its own strict prefix.
 */
class TemporaryTestDatabase
{
    public const HISTORICAL_NAMED_CONNECTION = 'mysql_historical_temp';

    public const ENFORCEMENT_NAMED_CONNECTION = 'mysql_enforcement_temp';

    private const HISTORICAL_NAME_PATTERN = '/^ultimatesms_testing_historical_[0-9]+_[0-9a-f]{8}$/';

    private const ENFORCEMENT_NAME_PATTERN = '/^ultimatesms_testing_enforcement_[0-9]+_[0-9a-f]{8}$/';

    private const BASE_DATABASE = 'ultimatesms_testing';

    public static function isValidHistoricalName(string $name): bool
    {
        return preg_match(self::HISTORICAL_NAME_PATTERN, $name) === 1;
    }

    public static function isValidEnforcementName(string $name): bool
    {
        return preg_match(self::ENFORCEMENT_NAME_PATTERN, $name) === 1;
    }

    /**
     * Creates a disposable ultimatesms_testing_historical_<pid>_<hex>
     * database, registers it as HISTORICAL_NAMED_CONNECTION, invokes
     * $callback with (databaseName, connectionName), and guarantees the
     * database is dropped afterward — regardless of whether $callback
     * returns normally or throws.
     */
    public static function withHistoricalDatabase(callable $callback): mixed
    {
        return self::withGeneratedDatabase(
            'ultimatesms_testing_historical_%d_%s',
            self::HISTORICAL_NAME_PATTERN,
            self::HISTORICAL_NAMED_CONNECTION,
            $callback
        );
    }

    /**
     * Creates a disposable ultimatesms_testing_enforcement_<pid>_<hex>
     * database, registers it as ENFORCEMENT_NAMED_CONNECTION, invokes
     * $callback with (databaseName, connectionName), and guarantees the
     * database is dropped afterward — regardless of whether $callback
     * returns normally or throws.
     */
    public static function withEnforcementDatabase(callable $callback): mixed
    {
        return self::withGeneratedDatabase(
            'ultimatesms_testing_enforcement_%d_%s',
            self::ENFORCEMENT_NAME_PATTERN,
            self::ENFORCEMENT_NAMED_CONNECTION,
            $callback
        );
    }

    private static function withGeneratedDatabase(
        string $nameFormat,
        string $namePattern,
        string $namedConnection,
        callable $callback
    ): mixed {
        $baseDatabase = DB::connection()->getDatabaseName();

        if ($baseDatabase !== self::BASE_DATABASE) {
            throw new RuntimeException(
                'Refusing to create a temporary database: base connection resolved to '
                . "[{$baseDatabase}], expected [" . self::BASE_DATABASE . '].'
            );
        }

        $name = self::generateName($nameFormat, $namePattern);

        DB::statement('CREATE DATABASE ' . self::quoteIdentifier($name, $namePattern));

        try {
            self::registerNamedConnection($name, $namePattern, $namedConnection);

            return $callback($name, $namedConnection);
        } finally {
            self::dropDatabase($name, $namePattern, $namedConnection);
        }
    }

    private static function generateName(string $nameFormat, string $namePattern): string
    {
        $name = sprintf($nameFormat, getmypid(), bin2hex(random_bytes(4)));

        if (preg_match($namePattern, $name) !== 1) {
            // Unreachable under the sprintf formats above — defense in
            // depth so a future edit to a format can never silently
            // produce an unvalidated name.
            throw new RuntimeException("Generated temporary database name failed its own validation: {$name}");
        }

        return $name;
    }

    private static function registerNamedConnection(string $name, string $namePattern, string $namedConnection): void
    {
        self::assertValidName($name, $namePattern);

        $config = config('database.connections.mysql');
        $config['database'] = $name;

        config(['database.connections.' . $namedConnection => $config]);

        DB::purge($namedConnection);
    }

    private static function dropDatabase(string $name, string $namePattern, string $namedConnection): void
    {
        self::assertValidName($name, $namePattern);

        DB::purge($namedConnection);
        config(['database.connections.' . $namedConnection => null]);

        // Drop through a connection explicitly re-verified as
        // ultimatesms_testing — never through the (now purged) named
        // connection that pointed at the database being removed.
        DB::purge('mysql');
        $baseDatabase = DB::connection('mysql')->getDatabaseName();

        if ($baseDatabase !== self::BASE_DATABASE) {
            throw new RuntimeException(
                "Refusing to drop temporary database [{$name}]: base connection unexpectedly resolved to "
                . "[{$baseDatabase}] during cleanup, not [" . self::BASE_DATABASE . '].'
            );
        }

        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS ' . self::quoteIdentifier($name, $namePattern));
    }

    private static function assertValidName(string $name, string $namePattern): void
    {
        if (preg_match($namePattern, $name) !== 1) {
            throw new RuntimeException("Refusing to operate on an invalid temporary database name: {$name}");
        }
    }

    private static function quoteIdentifier(string $name, string $namePattern): string
    {
        self::assertValidName($name, $namePattern);

        return '`' . $name . '`';
    }
}
