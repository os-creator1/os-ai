<?php

namespace Tests\Feature\Workspace\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates and tears down a single-use, uniquely named
 * ultimatesms_testing_historical_<pid>_<hex> database for RFC-003 M1B
 * Slice 4A's historical-test isolation (§10.2, §10.6, §23). Test-only
 * infrastructure — deliberately lives under tests/, never app/, and is
 * never referenced by production code.
 *
 * The primary ultimatesms_testing database is only ever read from, to
 * verify it is the base connection before creating a temporary database,
 * and to issue the final DROP DATABASE — it is never migrated, dropped,
 * renamed or reconfigured itself.
 */
class TemporaryTestDatabase
{
    public const NAMED_CONNECTION = 'mysql_historical_temp';

    private const NAME_PATTERN = '/^ultimatesms_testing_historical_[0-9]+_[0-9a-f]{8}$/';

    private const BASE_DATABASE = 'ultimatesms_testing';

    public static function isValidHistoricalName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Creates a disposable historical database, registers it as the
     * NAMED_CONNECTION, invokes $callback with (databaseName,
     * connectionName), and guarantees the database is dropped afterward —
     * regardless of whether $callback returns normally or throws.
     */
    public static function withHistoricalDatabase(callable $callback): mixed
    {
        $baseDatabase = DB::connection()->getDatabaseName();

        if ($baseDatabase !== self::BASE_DATABASE) {
            throw new RuntimeException(
                'Refusing to create a historical temporary database: base connection resolved to '
                . "[{$baseDatabase}], expected [" . self::BASE_DATABASE . '].'
            );
        }

        $name = self::generateName();

        DB::statement('CREATE DATABASE ' . self::quoteIdentifier($name));

        try {
            self::registerNamedConnection($name);

            return $callback($name, self::NAMED_CONNECTION);
        } finally {
            self::dropDatabase($name);
        }
    }

    private static function generateName(): string
    {
        $name = sprintf('ultimatesms_testing_historical_%d_%s', getmypid(), bin2hex(random_bytes(4)));

        if (! self::isValidHistoricalName($name)) {
            // Unreachable under the sprintf format above — defense in
            // depth so a future edit to the format can never silently
            // produce an unvalidated name.
            throw new RuntimeException("Generated historical database name failed its own validation: {$name}");
        }

        return $name;
    }

    private static function registerNamedConnection(string $name): void
    {
        self::assertValidName($name);

        $config = config('database.connections.mysql');
        $config['database'] = $name;

        config(['database.connections.' . self::NAMED_CONNECTION => $config]);

        DB::purge(self::NAMED_CONNECTION);
    }

    private static function dropDatabase(string $name): void
    {
        self::assertValidName($name);

        DB::purge(self::NAMED_CONNECTION);
        config(['database.connections.' . self::NAMED_CONNECTION => null]);

        // Drop through a connection explicitly re-verified as
        // ultimatesms_testing — never through the (now purged) named
        // connection that pointed at the database being removed.
        DB::purge('mysql');
        $baseDatabase = DB::connection('mysql')->getDatabaseName();

        if ($baseDatabase !== self::BASE_DATABASE) {
            throw new RuntimeException(
                "Refusing to drop historical database [{$name}]: base connection unexpectedly resolved to "
                . "[{$baseDatabase}] during cleanup, not [" . self::BASE_DATABASE . '].'
            );
        }

        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS ' . self::quoteIdentifier($name));
    }

    private static function assertValidName(string $name): void
    {
        if (! self::isValidHistoricalName($name)) {
            throw new RuntimeException("Refusing to operate on an invalid historical database name: {$name}");
        }
    }

    private static function quoteIdentifier(string $name): string
    {
        self::assertValidName($name);

        return '`' . $name . '`';
    }
}
