<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single place that decides whether a database name is a disposable
 * test database this repository is allowed to create, migrate, write to
 * and drop.
 *
 * WHY THIS EXISTS. Several suites used to compare the resolved database
 * name against the literal string 'ultimatesms_testing'. That guard was
 * correct about the danger — a destructive suite must never run against
 * anything but a throwaway database — but it also made the canonical
 * database the ONLY runnable one, so two lanes working at the same time
 * silently fought over it. That was reproduced in practice: one lane's
 * DROP DATABASE / CREATE DATABASE raced another lane's live migration,
 * and both lanes then trusted results the other lane was mutating.
 *
 * The replacement keeps the safety property and removes the collision:
 * a name is permitted only when it is the canonical test database or a
 * clearly-derived disposable sibling of it.
 *
 *   ultimatesms_testing                 accepted (canonical)
 *   ultimatesms_testing_lane_c          accepted (isolated)
 *   ultimatesms_testing_historical_7_ab accepted (isolated)
 *   ultimatesms_sms                     rejected (not a test database)
 *   ultimatesms                         rejected (not a test database)
 *   ""                                  rejected
 *   ultimatesms_testing_prod            rejected (production-shaped)
 *   ultimatesms_testing;DROP            rejected (unsafe characters)
 *
 * Nothing here relaxes a destructive-test safeguard. A suite that may
 * drop or truncate still has to prove it is pointed at a disposable
 * database first — it simply no longer has to be pointed at *one
 * specific* disposable database.
 */
final class TestDatabaseSafety
{
    /**
     * The canonical disposable test database. Every permitted name is
     * either exactly this, or this plus a suffix.
     */
    public const CANONICAL = 'ultimatesms_testing';

    /**
     * MySQL's own identifier limit. A longer name could not be created
     * anyway, and refusing early gives a better message than a driver
     * error mid-suite.
     */
    private const MAX_LENGTH = 64;

    /**
     * Lowercase letters, digits and underscore only, in one or more
     * underscore-separated segments. Deliberately narrow: no dots (which
     * could cross a schema boundary), no dashes, no backticks, quotes,
     * semicolons, spaces or backslashes, so a permitted name can never
     * carry SQL or a path.
     */
    private const SUFFIX_PATTERN = '/^(?:_[a-z0-9]+)+$/';

    /**
     * Words that must never appear as a whole suffix segment. A
     * disposable database is never described as production, live,
     * staging, a backup or the main one.
     *
     * Matched per segment rather than as a substring on purpose:
     * "ultimatesms_testing_domain" is a perfectly ordinary isolated
     * database and must not be refused just because "main" happens to sit
     * inside the word "domain".
     */
    private const FORBIDDEN_SUFFIX_SEGMENTS = [
        'prod',
        'production',
        'live',
        'staging',
        'backup',
        'master',
        'main',
        'real',
    ];

    public static function isSafeTestDatabaseName(mixed $name): bool
    {
        return self::rejectionReason($name) === null;
    }

    /**
     * @throws RuntimeException with a message naming the exact reason.
     */
    public static function assertSafeTestDatabaseName(mixed $name): void
    {
        $reason = self::rejectionReason($name);

        if ($reason !== null) {
            throw new RuntimeException(
                'Refusing to use database [' . self::describe($name) . ']: ' . $reason
                . ' Permitted names are "' . self::CANONICAL . '" and "' . self::CANONICAL . '_<safe suffix>".'
            );
        }
    }

    /**
     * The database this process is actually connected to, proven safe.
     *
     * @throws RuntimeException
     */
    public static function activeTestDatabase(): string
    {
        $name = DB::connection()->getDatabaseName();

        self::assertSafeTestDatabaseName($name);

        return (string) $name;
    }

    /**
     * The guard a spawned test subprocess runs before its first write.
     *
     * $expected is the database the PARENT is using, handed down
     * explicitly. Inheriting the environment is not enough on its own:
     * this proves the child resolved the very same disposable database,
     * so a child can never write into a different one — including the
     * canonical one — while the parent asserts against its own.
     *
     * @throws RuntimeException
     */
    public static function assertMatchesActiveTestDatabase(?string $expected = null): string
    {
        $resolved = self::activeTestDatabase();

        if ($expected !== null && $expected !== '' && $resolved !== $expected) {
            throw new RuntimeException(sprintf(
                'Refusing to run: resolved database is [%s], but the parent process is using [%s]. '
                . 'Aborting before any database write.',
                $resolved,
                $expected
            ));
        }

        return $resolved;
    }

    /**
     * Every permitted name, given the currently active one — used by
     * suites that create their own derived disposable database.
     */
    public static function derivedName(string $purpose, ?string $base = null): string
    {
        $base ??= self::activeTestDatabase();
        $candidate = $base . '_' . $purpose;

        self::assertSafeTestDatabaseName($candidate);

        return $candidate;
    }

    private static function rejectionReason(mixed $name): ?string
    {
        if (! is_string($name)) {
            return 'it is not a string.';
        }

        if ($name === '') {
            return 'an empty database name is never permitted.';
        }

        if (strlen($name) > self::MAX_LENGTH) {
            return 'it exceeds MySQL\'s ' . self::MAX_LENGTH . '-character identifier limit.';
        }

        if ($name === self::CANONICAL) {
            return null;
        }

        if (! str_starts_with($name, self::CANONICAL . '_')) {
            return 'it is not the canonical test database or a suffixed sibling of it.';
        }

        $suffix = substr($name, strlen(self::CANONICAL));

        if (preg_match(self::SUFFIX_PATTERN, $suffix) !== 1) {
            return 'its suffix may contain only lowercase letters, digits and underscores.';
        }

        foreach (array_filter(explode('_', $suffix), static fn (string $s): bool => $s !== '') as $segment) {
            if (in_array($segment, self::FORBIDDEN_SUFFIX_SEGMENTS, true)) {
                return 'its suffix contains the segment "' . $segment . '", which must never name a disposable database.';
            }
        }

        return null;
    }

    private static function describe(mixed $name): string
    {
        if (is_string($name)) {
            return $name;
        }

        return get_debug_type($name);
    }
}
