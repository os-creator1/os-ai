<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PR #460 review, P1: tests/Browser/AskMotionGrove/reset.sh truncates tables, so it must validate the database its MySQL command really
 * uses, through Tests\Support\TestDatabaseSafety, BEFORE any destructive SQL. A stub `mysql` that reports a chosen SELECT DATABASE()
 * and logs every statement it is given lets this be proven without a database: the log shows whether destructive SQL was ever sent.
 */
class BrowserResetScriptSafetyTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/reset-safety-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/fakemysql', <<<'SH'
#!/bin/sh
# Stub for `mysql`: answers SELECT DATABASE() with $FAKE_DB (empty line = no database selected), logs every other statement.
for a in "$@"; do
  case "$a" in
    "SELECT DATABASE()") if [ "${FAKE_DB:-}" = "NULL" ]; then echo NULL; else echo "${FAKE_DB:-}"; fi; exit 0 ;;
  esac
done
echo "$*" >> "$FAKE_LOG"
SH);
        chmod($this->dir . '/fakemysql', 0755);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /** @return array{int, string, string} exit code, stderr, statements the stub received */
    private function reset(?string $reportedDb, ?string $mysqlCmd = null, array $env = []): array
    {
        $script = dirname(__DIR__, 2) . '/Browser/AskMotionGrove/reset.sh';
        $log = $this->dir . '/log';
        $envVars = array_merge(['FAKE_DB' => $reportedDb ?? '', 'FAKE_LOG' => $log, 'PATH' => getenv('PATH')], $env);
        if ($mysqlCmd !== null) {
            $envVars['MYSQL_CMD'] = $mysqlCmd;
        }

        $proc = proc_open(['sh', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $envVars);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $code = proc_close($proc);

        return [$code, $stderr, is_file($log) ? (string) file_get_contents($log) : ''];
    }

    #[DataProvider('permitted')]
    public function test_a_validated_disposable_database_is_reset(string $db): void
    {
        [$code, , $sent] = $this->reset($db, $this->dir . '/fakemysql -uroot ' . $db);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('TRUNCATE assistant_messages', $sent);
    }

    /** @return array<string, array{string}> */
    public static function permitted(): array
    {
        return [
            'canonical' => ['ultimatesms_testing'],
            'isolated sibling' => ['ultimatesms_testing_lane_e'],
            'sibling with digits' => ['ultimatesms_testing_historical_7_ab'],
        ];
    }

    #[DataProvider('refused')]
    public function test_an_unsafe_or_ambiguous_target_is_refused_before_any_destructive_sql(?string $db): void
    {
        [$code, $stderr, $sent] = $this->reset($db, $this->dir . '/fakemysql -uroot whatever');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('nothing was modified', $stderr);
        $this->assertSame('', $sent, 'No statement at all — and certainly no TRUNCATE/DELETE — reached MySQL.');
    }

    /** @return array<string, array{?string}> */
    public static function refused(): array
    {
        return [
            'production name' => ['ultimatesms'],
            'sms database' => ['ultimatesms_sms'],
            'production-shaped sibling' => ['ultimatesms_testing_prod'],
            'live segment' => ['ultimatesms_testing_live'],
            'looks like a test but is not ours' => ['acme_test_live'],
            'unsafe characters' => ['ultimatesms_testing;DROP'],
            'no database selected' => [''],
            'NULL database' => ['NULL'],
        ];
    }

    public function test_the_default_target_is_validated_too_and_a_bad_override_never_falls_back_to_it(): void
    {
        // The override resolves a production-shaped database: refused, nothing run against the default either.
        [$code, , $sent] = $this->reset('ultimatesms_sms', $this->dir . '/fakemysql -uroot ultimatesms_sms');
        $this->assertNotSame(0, $code);
        $this->assertSame('', $sent);
    }

    public function test_the_database_actually_used_must_match_expected_test_database_when_one_is_declared(): void
    {
        [$code, $stderr, $sent] = $this->reset('ultimatesms_testing', $this->dir . '/fakemysql -uroot x', ['EXPECTED_TEST_DATABASE' => 'ultimatesms_testing_lane_e']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('EXPECTED_TEST_DATABASE', $stderr);
        $this->assertSame('', $sent);

        [$code, , $sent] = $this->reset('ultimatesms_testing_lane_e', $this->dir . '/fakemysql -uroot x', ['EXPECTED_TEST_DATABASE' => 'ultimatesms_testing_lane_e']);
        $this->assertSame(0, $code);
        $this->assertNotSame('', $sent);
    }

    public function test_a_failing_mysql_command_aborts_without_destructive_sql(): void
    {
        [$code, , $sent] = $this->reset('ultimatesms_testing', '/nonexistent/mysql -uroot ultimatesms_testing');
        $this->assertNotSame(0, $code);
        $this->assertSame('', $sent);
    }
}
