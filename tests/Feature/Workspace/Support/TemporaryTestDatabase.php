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
 * The active base database — the canonical ultimatesms_testing database or
 * any Tests\Support\TestDatabaseSafety-validated disposable sibling
 * (e.g. ultimatesms_testing_lane_e) — is only ever read from, to verify it
 * is a validated base connection before creating a temporary database, and
 * to issue the final DROP DATABASE; it is never migrated, dropped, renamed
 * or reconfigured itself. Every generated historical/enforcement name is
 * derived from that active base (`{base}_historical_<pid>_<hex>` /
 * `{base}_enforcement_<pid>_<hex>`), so two lanes running against distinct
 * validated siblings can never collide on the same generated name.
 * TestDatabaseSafety remains the single authority on what counts as a
 * valid base — this class adds no competing database-name policy, only
 * the purpose-specific suffix shape its own two temporary-database kinds
 * require.
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

    /**
     * `{validated base}_historical_<pid>_<hex>`. The base portion is
     * intentionally not re-validated character-by-character here — it is
     * always exactly whatever TestDatabaseSafety::isSafeTestDatabaseName()
     * already approved in withGeneratedDatabase() below, so re-deriving a
     * second, competing regex for "what is a safe base" would be exactly
     * the second authority this class is documented not to create.
     */
    private const HISTORICAL_NAME_PATTERN = '/^(?<base>.+)_historical_[0-9]+_[0-9a-f]{8}$/';

    private const ENFORCEMENT_NAME_PATTERN = '/^(?<base>.+)_enforcement_[0-9]+_[0-9a-f]{8}$/';

    public static function isValidHistoricalName(string $name): bool
    {
        return self::isValidGeneratedName($name, self::HISTORICAL_NAME_PATTERN);
    }

    public static function isValidEnforcementName(string $name): bool
    {
        return self::isValidGeneratedName($name, self::ENFORCEMENT_NAME_PATTERN);
    }

    /**
     * A generated name is valid only when it matches the purpose-specific
     * suffix shape AND its own captured base portion is itself a name
     * TestDatabaseSafety independently approves AND the *complete* name is
     * itself independently approved by TestDatabaseSafety.
     *
     * The third check is not redundant with the second. A base can pass
     * TestDatabaseSafety entirely on its own — safe characters, no
     * forbidden segment, within the length limit — and still, once this
     * class's own `_historical_<pid>_<hex>` / `_enforcement_<pid>_<hex>`
     * suffix is appended, produce a complete name that exceeds MySQL's
     * 64-character identifier limit. Checking only the base let such a
     * name through as "valid" even though TestDatabaseSafety itself would
     * refuse the exact string this class was about to create, migrate or
     * drop — and `concurrent_backfill_runner.php` trusts
     * isValidHistoricalName() as an authorization path over an
     * externally-supplied EXPECTED_TEST_DATABASE value, so that gap was a
     * real bypass of the repository's single database-name authority, not
     * merely a theoretical one.
     *
     * No competing policy is introduced by this: both calls below go
     * through TestDatabaseSafety::isSafeTestDatabaseName() — the same,
     * single, unmodified authority every other caller in this repository
     * uses. This class still owns nothing but the purpose-specific suffix
     * shape (the `preg_match()` below).
     */
    private static function isValidGeneratedName(string $name, string $namePattern): bool
    {
        if (preg_match($namePattern, $name, $matches) !== 1) {
            return false;
        }

        if (! TestDatabaseSafety::isSafeTestDatabaseName($matches['base'])) {
            return false;
        }

        return TestDatabaseSafety::isSafeTestDatabaseName($name);
    }

    /**
     * Creates a disposable `{active validated base}_historical_<pid>_<hex>`
     * database, registers it as HISTORICAL_NAMED_CONNECTION, invokes
     * $callback with (databaseName, connectionName), and guarantees the
     * database is dropped afterward — regardless of whether $callback
     * returns normally or throws.
     */
    public static function withHistoricalDatabase(callable $callback): mixed
    {
        return self::withGeneratedDatabase(
            'historical',
            self::HISTORICAL_NAME_PATTERN,
            self::HISTORICAL_NAMED_CONNECTION,
            $callback
        );
    }

    /**
     * Creates a disposable `{active validated base}_enforcement_<pid>_<hex>`
     * database, registers it as ENFORCEMENT_NAMED_CONNECTION, invokes
     * $callback with (databaseName, connectionName), and guarantees the
     * database is dropped afterward — regardless of whether $callback
     * returns normally or throws.
     */
    public static function withEnforcementDatabase(callable $callback): mixed
    {
        return self::withGeneratedDatabase(
            'enforcement',
            self::ENFORCEMENT_NAME_PATTERN,
            self::ENFORCEMENT_NAMED_CONNECTION,
            $callback
        );
    }

    private static function withGeneratedDatabase(
        string $purpose,
        string $namePattern,
        string $namedConnection,
        callable $callback
    ): mixed {
        $baseDatabase = DB::connection()->getDatabaseName();

        if (! TestDatabaseSafety::isSafeTestDatabaseName($baseDatabase)) {
            throw new RuntimeException(
                'Refusing to create a temporary database: base connection resolved to '
                . "[{$baseDatabase}], which is not the canonical test database or a validated disposable sibling of it."
            );
        }

        $name = self::generateName($baseDatabase, $purpose, $namePattern);

        DB::statement('CREATE DATABASE ' . self::quoteIdentifier($name, $namePattern));

        try {
            self::registerNamedConnection($name, $namePattern, $namedConnection);

            return $callback($name, $namedConnection);
        } finally {
            self::dropDatabase($name, $namePattern, $namedConnection);
        }
    }

    private static function generateName(string $baseDatabase, string $purpose, string $namePattern): string
    {
        $name = sprintf('%s_%s_%d_%s', $baseDatabase, $purpose, getmypid(), bin2hex(random_bytes(4)));

        if (! self::isValidGeneratedName($name, $namePattern)) {
            // $baseDatabase was already proven safe by withGeneratedDatabase()
            // before this method was ever called, so reaching here means the
            // combination of that base with this class's own purpose/pid/hex
            // suffix is what TestDatabaseSafety refuses — almost always
            // because the *combined* length exceeds MySQL's identifier
            // limit (a short, safe base can still overflow once suffixed;
            // see isValidGeneratedName()'s own docblock). Delegate to
            // TestDatabaseSafety::assertSafeTestDatabaseName() purely for
            // its descriptive reason string — the authorization decision
            // itself was already made by isValidGeneratedName() above, the
            // exact same check every other caller in this class goes
            // through, so no second, competing length/safety policy is
            // introduced here.
            TestDatabaseSafety::assertSafeTestDatabaseName($name);

            // Unreachable: assertSafeTestDatabaseName() always throws when
            // isValidGeneratedName() returned false, because the only way
            // isValidGeneratedName() can fail past the shape check is a
            // TestDatabaseSafety refusal on the base or the complete name,
            // and assertSafeTestDatabaseName() re-derives the same
            // rejection. Defense in depth so a future edit can never
            // silently return an unvalidated name.
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

        // Drop through a connection explicitly re-verified as a validated
        // disposable test database — never through the (now purged) named
        // connection that pointed at the database being removed.
        DB::purge('mysql');
        $baseDatabase = DB::connection('mysql')->getDatabaseName();

        if (! TestDatabaseSafety::isSafeTestDatabaseName($baseDatabase)) {
            throw new RuntimeException(
                "Refusing to drop temporary database [{$name}]: base connection unexpectedly resolved to "
                . "[{$baseDatabase}] during cleanup, which is not the canonical test database or a validated disposable sibling of it."
            );
        }

        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS ' . self::quoteIdentifier($name, $namePattern));
    }

    private static function assertValidName(string $name, string $namePattern): void
    {
        if (! self::isValidGeneratedName($name, $namePattern)) {
            throw new RuntimeException("Refusing to operate on an invalid temporary database name: {$name}");
        }
    }

    private static function quoteIdentifier(string $name, string $namePattern): string
    {
        self::assertValidName($name, $namePattern);

        return '`' . $name . '`';
    }
}
