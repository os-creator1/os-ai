<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseSafety;

/**
 * The permanent unit coverage for the single database-name validation
 * authority, deferred by the mainline baseline-reliability remediation
 * (`docs/automation/MAINLINE-BASELINE-RELIABILITY-REMEDIATION.md` §4.2 —
 * that branch proved the helper with a temporary suite it deliberately
 * did not commit, because the file was outside its own allowlist).
 *
 * WHY THIS IS A PLAIN PHPUnit TestCase, NOT Tests\TestCase. The three
 * pure name-policy entry points — isSafeTestDatabaseName(),
 * assertSafeTestDatabaseName() and derivedName() with an explicit base —
 * are total functions of their input. They touch no container, no
 * configuration and no connection. Booting Laravel to exercise them would
 * add a dependency the behaviour does not have, and would make this suite
 * unrunnable exactly when it is most needed: when the database is
 * misconfigured. The two connection-dependent entry points
 * (activeTestDatabase() and assertMatchesActiveTestDatabase()) are
 * therefore covered where they actually live — end to end, against the
 * real spawned runners, in the nine guarded Usage tests and in the two
 * merged Conversations/Slot Agreement runners.
 *
 * This suite is a characterisation of the policy as merged. It asserts
 * what the helper does today so that a future change to the policy has to
 * be deliberate rather than accidental.
 */
class TestDatabaseSafetyTest extends TestCase
{
    // ---------------------------------------------------------------
    // Accepted names
    // ---------------------------------------------------------------

    public function test_the_canonical_database_is_accepted(): void
    {
        $this->assertTrue(TestDatabaseSafety::isSafeTestDatabaseName('ultimatesms_testing'));
    }

    public function test_the_canonical_constant_is_the_canonical_name(): void
    {
        $this->assertSame('ultimatesms_testing', TestDatabaseSafety::CANONICAL);
    }

    /**
     * @dataProvider acceptedSuffixedSiblings
     */
    public function test_a_clearly_derived_disposable_sibling_is_accepted(string $name): void
    {
        $this->assertTrue(
            TestDatabaseSafety::isSafeTestDatabaseName($name),
            "[{$name}] should be accepted as a disposable sibling."
        );
    }

    public static function acceptedSuffixedSiblings(): array
    {
        return [
            'single segment' => ['ultimatesms_testing_lane_e'],
            'documented lane' => ['ultimatesms_testing_lane_c'],
            'documented historical' => ['ultimatesms_testing_historical_7_ab'],
            'digits only' => ['ultimatesms_testing_7'],
            'many segments' => ['ultimatesms_testing_a_b_c_d_e_f'],
            'alphanumeric segment' => ['ultimatesms_testing_lane7e'],
            // The helper's own docblock calls this out: "main" sits inside
            // "domain", and per-segment matching must not refuse it.
            'forbidden word as a substring of an ordinary segment' => ['ultimatesms_testing_domain'],
            'production inside a longer word' => ['ultimatesms_testing_reproduction'],
            'live inside a longer word' => ['ultimatesms_testing_delivery'],
        ];
    }

    // ---------------------------------------------------------------
    // Rejected names
    // ---------------------------------------------------------------

    /**
     * @dataProvider rejectedNames
     */
    public function test_an_unsafe_name_is_rejected(mixed $name, string $expectedReasonFragment): void
    {
        $this->assertFalse(
            TestDatabaseSafety::isSafeTestDatabaseName($name),
            'This name should never be accepted.'
        );

        try {
            TestDatabaseSafety::assertSafeTestDatabaseName($name);
            $this->fail('assertSafeTestDatabaseName() should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedReasonFragment, $e->getMessage());
        }
    }

    public static function rejectedNames(): array
    {
        return [
            'empty string' => ['', 'an empty database name is never permitted.'],
            'not a string, null' => [null, 'it is not a string.'],
            'not a string, integer' => [1, 'it is not a string.'],
            'not a string, array' => [[], 'it is not a string.'],
            'not a string, bool' => [false, 'it is not a string.'],

            // The whole point of the policy: containing "test" is not
            // enough, and never has been.
            'merely contains test' => ['acme_test_live', 'not the canonical test database or a suffixed sibling of it.'],
            'contains testing but wrong prefix' => ['other_testing', 'not the canonical test database or a suffixed sibling of it.'],
            'the product database' => ['ultimatesms_sms', 'not the canonical test database or a suffixed sibling of it.'],
            'the bare product name' => ['ultimatesms', 'not the canonical test database or a suffixed sibling of it.'],
            'canonical prefix without the underscore separator' => ['ultimatesms_testingx', 'not the canonical test database or a suffixed sibling of it.'],

            // Production-shaped suffixes, one per forbidden segment.
            'prod' => ['ultimatesms_testing_prod', 'its suffix contains the segment "prod"'],
            'production' => ['ultimatesms_testing_production', 'its suffix contains the segment "production"'],
            'live' => ['ultimatesms_testing_live', 'its suffix contains the segment "live"'],
            'staging' => ['ultimatesms_testing_staging', 'its suffix contains the segment "staging"'],
            'backup' => ['ultimatesms_testing_backup', 'its suffix contains the segment "backup"'],
            'master' => ['ultimatesms_testing_master', 'its suffix contains the segment "master"'],
            'main' => ['ultimatesms_testing_main', 'its suffix contains the segment "main"'],
            'real' => ['ultimatesms_testing_real', 'its suffix contains the segment "real"'],
            'forbidden segment not first' => ['ultimatesms_testing_lane_prod', 'its suffix contains the segment "prod"'],

            // Unsafe characters that appear WITHOUT the underscore
            // separator never reach the suffix pattern at all — the name
            // simply is not a suffixed sibling, and that is the reason
            // reported. Characterised here as the helper actually behaves.
            'semicolon, no separator' => ['ultimatesms_testing;DROP', 'not the canonical test database or a suffixed sibling of it.'],
            'dot, no separator' => ['ultimatesms_testing.other', 'not the canonical test database or a suffixed sibling of it.'],
            'dash, no separator' => ['ultimatesms_testing-lane', 'not the canonical test database or a suffixed sibling of it.'],
            'space, no separator' => ['ultimatesms_testing lane', 'not the canonical test database or a suffixed sibling of it.'],
            'backtick, no separator' => ['ultimatesms_testing`x`', 'not the canonical test database or a suffixed sibling of it.'],
            'quote, no separator' => ["ultimatesms_testing'x", 'not the canonical test database or a suffixed sibling of it.'],
            'backslash, no separator' => ['ultimatesms_testing\\x', 'not the canonical test database or a suffixed sibling of it.'],

            // The same characters PAST the separator do reach the suffix
            // pattern, and are refused by it. Both branches matter: this
            // is what stops a permitted name ever carrying SQL or a path.
            'semicolon in suffix' => ['ultimatesms_testing_lane;DROP', 'its suffix may contain only lowercase letters, digits and underscores.'],
            'dot in suffix' => ['ultimatesms_testing_lane.other', 'its suffix may contain only lowercase letters, digits and underscores.'],
            'dash in suffix' => ['ultimatesms_testing_lane-e', 'its suffix may contain only lowercase letters, digits and underscores.'],
            'space in suffix' => ['ultimatesms_testing_lane e', 'its suffix may contain only lowercase letters, digits and underscores.'],
            'backtick in suffix' => ['ultimatesms_testing_lane`x`', 'its suffix may contain only lowercase letters, digits and underscores.'],
            'quote in suffix' => ["ultimatesms_testing_lane'x", 'its suffix may contain only lowercase letters, digits and underscores.'],
            'backslash in suffix' => ['ultimatesms_testing_lane\\x', 'its suffix may contain only lowercase letters, digits and underscores.'],
            'uppercase in suffix' => ['ultimatesms_testing_LANE', 'its suffix may contain only lowercase letters, digits and underscores.'],
            'trailing underscore only' => ['ultimatesms_testing_', 'its suffix may contain only lowercase letters, digits and underscores.'],
            'double underscore' => ['ultimatesms_testing__lane', 'its suffix may contain only lowercase letters, digits and underscores.'],
            'newline injection' => ["ultimatesms_testing_lane\ne", 'its suffix may contain only lowercase letters, digits and underscores.'],
        ];
    }

    public function test_a_name_longer_than_mysqls_identifier_limit_is_rejected(): void
    {
        $tooLong = TestDatabaseSafety::CANONICAL . '_' . str_repeat('a', 64);

        $this->assertGreaterThan(64, strlen($tooLong));
        $this->assertFalse(TestDatabaseSafety::isSafeTestDatabaseName($tooLong));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("exceeds MySQL's 64-character identifier limit.");
        TestDatabaseSafety::assertSafeTestDatabaseName($tooLong);
    }

    public function test_a_name_of_exactly_the_identifier_limit_is_accepted(): void
    {
        $prefix = TestDatabaseSafety::CANONICAL . '_';
        $exactly64 = $prefix . str_repeat('a', 64 - strlen($prefix));

        $this->assertSame(64, strlen($exactly64));
        $this->assertTrue(TestDatabaseSafety::isSafeTestDatabaseName($exactly64));
    }

    // ---------------------------------------------------------------
    // The refusal message itself
    // ---------------------------------------------------------------

    public function test_the_refusal_message_names_the_database_and_the_permitted_shape(): void
    {
        try {
            TestDatabaseSafety::assertSafeTestDatabaseName('ultimatesms_sms');
            $this->fail('assertSafeTestDatabaseName() should have thrown.');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('Refusing to use database [ultimatesms_sms]', $message);
            $this->assertStringContainsString('Permitted names are "ultimatesms_testing"', $message);
            $this->assertStringContainsString('"ultimatesms_testing_<safe suffix>"', $message);
        }
    }

    public function test_a_non_string_is_described_by_its_type_rather_than_interpolated(): void
    {
        try {
            TestDatabaseSafety::assertSafeTestDatabaseName([1, 2, 3]);
            $this->fail('assertSafeTestDatabaseName() should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to use database [array]', $e->getMessage());
        }
    }

    public function test_assert_safe_returns_silently_for_a_permitted_name(): void
    {
        TestDatabaseSafety::assertSafeTestDatabaseName('ultimatesms_testing_lane_e');

        $this->assertTrue(true, 'A permitted name must not throw.');
    }

    // ---------------------------------------------------------------
    // derivedName()
    // ---------------------------------------------------------------

    public function test_derived_name_appends_the_purpose_to_an_explicit_base(): void
    {
        $this->assertSame(
            'ultimatesms_testing_lane_e_backfill1',
            TestDatabaseSafety::derivedName('backfill1', 'ultimatesms_testing_lane_e')
        );
    }

    public function test_derived_name_validates_the_result_and_refuses_an_unsafe_purpose(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('its suffix contains the segment "prod"');

        TestDatabaseSafety::derivedName('prod', 'ultimatesms_testing');
    }

    public function test_derived_name_refuses_a_purpose_carrying_unsafe_characters(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('its suffix may contain only lowercase letters, digits and underscores.');

        TestDatabaseSafety::derivedName('x;DROP DATABASE', 'ultimatesms_testing');
    }

    public function test_derived_name_refuses_to_derive_from_an_unsafe_base(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not the canonical test database or a suffixed sibling of it.');

        TestDatabaseSafety::derivedName('lane', 'ultimatesms_sms');
    }

    public function test_derived_name_refuses_a_purpose_that_would_exceed_the_identifier_limit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("exceeds MySQL's 64-character identifier limit.");

        TestDatabaseSafety::derivedName(str_repeat('a', 64), 'ultimatesms_testing');
    }

    // ---------------------------------------------------------------
    // The policy's own shape
    // ---------------------------------------------------------------

    public function test_every_forbidden_segment_is_refused_as_a_whole_suffix(): void
    {
        $forbidden = ['prod', 'production', 'live', 'staging', 'backup', 'master', 'main', 'real'];

        foreach ($forbidden as $segment) {
            $this->assertFalse(
                TestDatabaseSafety::isSafeTestDatabaseName(TestDatabaseSafety::CANONICAL . '_' . $segment),
                "[{$segment}] must never name a disposable database."
            );

            // …but the same letters inside a longer segment are ordinary.
            $this->assertTrue(
                TestDatabaseSafety::isSafeTestDatabaseName(TestDatabaseSafety::CANONICAL . '_x' . $segment . 'y'),
                "[x{$segment}y] is an ordinary segment and must be accepted."
            );
        }
    }

    public function test_the_helper_is_final_so_the_policy_cannot_be_subclassed_away(): void
    {
        $reflection = new \ReflectionClass(TestDatabaseSafety::class);

        $this->assertTrue($reflection->isFinal(), 'The single validation authority must stay final.');
    }
}
