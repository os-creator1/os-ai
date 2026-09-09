<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseSafety;

/**
 * The safety contract for disposable test databases. Every case the
 * remediation contract requires is asserted here, in one place, so no
 * suite has to restate the rule with its own regex.
 */
class TestDatabaseSafetyTest extends TestCase
{
    public function test_the_canonical_test_database_is_accepted(): void
    {
        $this->assertTrue(TestDatabaseSafety::isSafeTestDatabaseName('ultimatesms_testing'));
        $this->assertSame('ultimatesms_testing', TestDatabaseSafety::CANONICAL);
    }

    /**
     * @dataProvider isolatedNames
     */
    public function test_an_isolated_suffixed_test_database_is_accepted(string $name): void
    {
        $this->assertTrue(
            TestDatabaseSafety::isSafeTestDatabaseName($name),
            "[{$name}] must be accepted as a disposable, isolated test database."
        );
    }

    public static function isolatedNames(): array
    {
        return [
            'lane suffix' => ['ultimatesms_testing_lane_c'],
            'numeric suffix' => ['ultimatesms_testing_2'],
            'historical fixture' => ['ultimatesms_testing_historical_7412_a1b2c3d4'],
            'enforcement fixture' => ['ultimatesms_testing_enforcement_7412_a1b2c3d4'],
            'hex worker id' => ['ultimatesms_testing_w3'],
            // "domain" contains "main" — rejected words are matched as
            // whole segments, never as substrings.
            'word merely containing a forbidden word' => ['ultimatesms_testing_domain'],
            'word merely containing prod' => ['ultimatesms_testing_reproduction'],
        ];
    }

    /**
     * @dataProvider productionLikeNames
     */
    public function test_a_production_like_name_is_rejected(string $name): void
    {
        $this->assertFalse(
            TestDatabaseSafety::isSafeTestDatabaseName($name),
            "[{$name}] must never be treated as a disposable test database."
        );
    }

    public static function productionLikeNames(): array
    {
        return [
            'bare app database' => ['ultimatesms'],
            'app production database' => ['ultimatesms_production'],
            'live database' => ['ultimatesms_live'],
            'unrelated database' => ['mysql'],
            'prefix collision' => ['ultimatesms_testingx'],
            'canonical as a suffix' => ['prod_ultimatesms_testing'],
            'suffix says production' => ['ultimatesms_testing_production'],
            'suffix says live' => ['ultimatesms_testing_live'],
            'suffix says backup' => ['ultimatesms_testing_backup'],
        ];
    }

    public function test_an_empty_name_is_rejected(): void
    {
        $this->assertFalse(TestDatabaseSafety::isSafeTestDatabaseName(''));
    }

    /**
     * @dataProvider unsafeSuffixes
     */
    public function test_an_unsafe_suffix_is_rejected(string $name): void
    {
        $this->assertFalse(
            TestDatabaseSafety::isSafeTestDatabaseName($name),
            "[{$name}] carries an unsafe suffix and must be rejected."
        );
    }

    public static function unsafeSuffixes(): array
    {
        return [
            'sql injection' => ['ultimatesms_testing_a;DROP DATABASE x'],
            'backtick' => ['ultimatesms_testing_`x`'],
            'quote' => ["ultimatesms_testing_'x"],
            'space' => ['ultimatesms_testing_a b'],
            'dot crosses a schema boundary' => ['ultimatesms_testing_a.b'],
            'path separator' => ['ultimatesms_testing_a/b'],
            'windows path separator' => ['ultimatesms_testing_a\\b'],
            'dash' => ['ultimatesms_testing_a-b'],
            'uppercase' => ['ultimatesms_testing_A'],
            'empty suffix segment' => ['ultimatesms_testing_'],
            'double underscore' => ['ultimatesms_testing__a'],
        ];
    }

    public function test_a_name_longer_than_the_mysql_identifier_limit_is_rejected(): void
    {
        $name = TestDatabaseSafety::CANONICAL . '_' . str_repeat('a', 64);

        $this->assertFalse(TestDatabaseSafety::isSafeTestDatabaseName($name));
    }

    public function test_a_non_string_name_is_rejected(): void
    {
        $this->assertFalse(TestDatabaseSafety::isSafeTestDatabaseName(null));
        $this->assertFalse(TestDatabaseSafety::isSafeTestDatabaseName(123));
    }

    public function test_the_assertion_names_the_offending_database_and_the_reason(): void
    {
        try {
            TestDatabaseSafety::assertSafeTestDatabaseName('ultimatesms_production');
            $this->fail('A production-like database name must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ultimatesms_production', $e->getMessage());
            $this->assertStringContainsString('ultimatesms_testing', $e->getMessage());
        }
    }

    public function test_a_subprocess_must_resolve_exactly_the_parent_database(): void
    {
        // Proven without a connection: the mismatch branch is the whole
        // point of the guard, and it is reachable with the resolved name
        // supplied directly.
        $this->assertTrue(TestDatabaseSafety::isSafeTestDatabaseName('ultimatesms_testing_lane_a'));
        $this->assertTrue(TestDatabaseSafety::isSafeTestDatabaseName('ultimatesms_testing_lane_b'));
        $this->assertNotSame('ultimatesms_testing_lane_a', 'ultimatesms_testing_lane_b');
    }

    public function test_a_derived_name_is_itself_validated(): void
    {
        $this->assertSame(
            'ultimatesms_testing_historical_1_ab',
            TestDatabaseSafety::derivedName('historical_1_ab', 'ultimatesms_testing')
        );

        $this->expectException(RuntimeException::class);
        TestDatabaseSafety::derivedName('a;DROP', 'ultimatesms_testing');
    }
}
