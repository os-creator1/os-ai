<?php

namespace Tests\Feature\Workspace\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\TestDatabaseSafety;

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

    private const HISTORICAL_PURPOSE = 'historical';

    private const ENFORCEMENT_PURPOSE = 'enforcement';



    public static function isValidHistoricalName(string $name): bool
    {
        return preg_match(self::namePattern(self::HISTORICAL_PURPOSE), $name) === 1;
    }

    public static function isValidEnforcementName(string $name): bool
    {
        return preg_match(self::namePattern(self::ENFORCEMENT_PURPOSE), $name) === 1;
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
            self::HISTORICAL_PURPOSE,
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
            self::ENFORCEMENT_PURPOSE,
            self::ENFORCEMENT_NAMED_CONNECTION,
            $callback
        );
    }

    private static function withGeneratedDatabase(
        string $purpose,
        string $namedConnection,
        callable $callback
    ): mixed {
        // Any safe, disposable test database may act as the base — the
        // canonical one or an isolated sibling. TestDatabaseSafety
        // throws, naming the offending value, when it is neither.
        TestDatabaseSafety::activeTestDatabase();

        $name = self::generateName($purpose);

        DB::statement('CREATE DATABASE ' . self::quoteIdentifier($name, $purpose));

        try {
            self::registerNamedConnection($name, $purpose, $namedConnection);

            return $callback($name, $namedConnection);
        } finally {
            self::dropDatabase($name, $purpose, $namedConnection);
        }
    }

    private static function generateName(string $purpose): string
    {
        // Derived from the ACTIVE base database, not a hardcoded
        // 'ultimatesms_testing' literal, so an isolated
        // ultimatesms_testing_<suffix> run creates its own siblings
        // instead of colliding with another lane's temporaries.
        $name = sprintf(
            '%s_%s_%d_%s',
            TestDatabaseSafety::activeTestDatabase(),
            $purpose,
            getmypid(),
            bin2hex(random_bytes(4))
        );

        if (preg_match(self::namePattern($purpose), $name) !== 1) {
            // Unreachable under the sprintf formats above — defense in
            // depth so a future edit to a format can never silently
            // produce an unvalidated name.
            throw new RuntimeException("Generated temporary database name failed its own validation: {$name}");
        }

        return $name;
    }

    private static function registerNamedConnection(string $name, string $purpose, string $namedConnection): void
    {
        self::assertValidName($name, $purpose);

        $config = config('database.connections.mysql');
        $config['database'] = $name;

        config(['database.connections.' . $namedConnection => $config]);

        DB::purge($namedConnection);
    }

    private static function dropDatabase(string $name, string $purpose, string $namedConnection): void
    {
        self::assertValidName($name, $purpose);

        DB::purge($namedConnection);
        config(['database.connections.' . $namedConnection => null]);

        // Drop through a connection explicitly re-verified as
        // ultimatesms_testing — never through the (now purged) named
        // connection that pointed at the database being removed.
        DB::purge('mysql');
        $baseDatabase = DB::connection('mysql')->getDatabaseName();

        // The DROP is still issued through a connection re-verified as a
        // safe, disposable test database — never through the purged named
        // connection that pointed at the database being removed.
        if (! TestDatabaseSafety::isSafeTestDatabaseName($baseDatabase)) {
            throw new RuntimeException(
                "Refusing to drop temporary database [{$name}]: base connection unexpectedly resolved to "
                . "[{$baseDatabase}] during cleanup, which is not a disposable test database."
            );
        }

        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS ' . self::quoteIdentifier($name, $purpose));
    }

    private static function assertValidName(string $name, string $purpose): void
    {
        if (preg_match(self::namePattern($purpose), $name) !== 1) {
            throw new RuntimeException("Refusing to operate on an invalid temporary database name: {$name}");
        }
    }

    private static function quoteIdentifier(string $name, string $purpose): string
    {
        self::assertValidName($name, $purpose);

        return '`' . $name . '`';
    }

    /**
     * The strict name shape for one purpose, anchored to the ACTIVE base
     * database. Built here rather than stored as a constant so an
     * isolated run validates against its own base instead of a
     * hardcoded canonical name.
     */
    private static function namePattern(string $purpose): string
    {
        return '/^' . preg_quote(TestDatabaseSafety::activeTestDatabase(), '/')
            . '_' . preg_quote($purpose, '/') . '_[0-9]+_[0-9a-f]{8}$/';
    }
}
