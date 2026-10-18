<?php

namespace App\Library\Demo\BrightFrame;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Refuses to let the BrightFrame demo provisioner write anywhere but the one
 * place it was authorised to: the MotionGrove staging site.
 *
 * `APP_ENV` is deliberately NOT consulted. The staging server may run with
 * APP_ENV=production, and an `APP_ENV=staging` check alone would be both too
 * strict there and too weak anywhere a copied .env says "staging". Instead
 * every one of these must hold, and each is checked against what this process
 * is ACTUALLY configured with, never against what the caller claims:
 *
 *   1. the operator opted in with DEMO_PROVISIONING_ENABLED=true
 *   2. the configured application URL's host is on the allow-list
 *      (default: staging.getmotiongrove.com)
 *   3. the connected database name equals the --confirm-database the operator
 *      typed, so a wrong .env cannot be written to by accident
 *   4. that database name is not production-shaped
 *   5. the connected DB host is not a known production host (optional list)
 *
 * Nothing here creates or changes data; it only decides.
 */
final class DemoProvisioningGuard
{
    public const OPT_IN_ENV = 'DEMO_PROVISIONING_ENABLED';

    public const DEFAULT_ALLOWED_HOSTS = ['staging.getmotiongrove.com'];

    private const PRODUCTION_SHAPED = ['/(^|_)(prod|production|live|master|main|backup|bak)(_|$)/i'];

    /**
     * What this process is really pointed at — safe to print, holds no secret.
     *
     * @return array{app_env: string, app_url: string, app_host: string, db_connection: string, db_host: string, db_name: string, allowed_hosts: array<int, string>, opted_in: bool}
     */
    public function inspect(): array
    {
        $connection = (string) config('database.default');
        $dbConfig = (array) config("database.connections.{$connection}", []);
        $url = (string) config('app.url');

        return [
            'app_env' => (string) config('app.env'),
            'app_url' => $url,
            'app_host' => strtolower((string) (parse_url($url, PHP_URL_HOST) ?: '')),
            'db_connection' => $connection,
            'db_host' => (string) ($dbConfig['host'] ?? ''),
            'db_name' => (string) DB::connection()->getDatabaseName(),
            'allowed_hosts' => $this->allowedHosts(),
            'opted_in' => $this->optedIn(),
        ];
    }

    /**
     * @throws RuntimeException naming the first failed check
     */
    public function assertMayProvision(?string $confirmedDatabase): array
    {
        $state = $this->inspect();

        if (! $state['opted_in']) {
            throw new RuntimeException('Refusing: set ' . self::OPT_IN_ENV . '=true for this one command to opt in to demo provisioning.');
        }

        if ($state['app_host'] === '' || ! in_array($state['app_host'], $state['allowed_hosts'], true)) {
            throw new RuntimeException(sprintf(
                'Refusing: this site\'s APP_URL host is [%s], not one of [%s].',
                $state['app_host'] ?: '(empty)',
                implode(', ', $state['allowed_hosts'])
            ));
        }

        if ($state['db_name'] === '') {
            throw new RuntimeException('Refusing: no database is connected.');
        }

        if ($confirmedDatabase === null || $confirmedDatabase === '' || ! hash_equals($state['db_name'], $confirmedDatabase)) {
            throw new RuntimeException(sprintf(
                'Refusing: --confirm-database must equal the connected database exactly (connected: [%s]). Run with --inspect to see it.',
                $state['db_name']
            ));
        }

        foreach (self::PRODUCTION_SHAPED as $pattern) {
            if (preg_match($pattern, $state['db_name']) === 1) {
                throw new RuntimeException("Refusing: database [{$state['db_name']}] looks like a production database.");
            }
        }

        foreach ($this->forbiddenDbHosts() as $forbidden) {
            if ($forbidden !== '' && strcasecmp($forbidden, $state['db_host']) === 0) {
                throw new RuntimeException("Refusing: database host [{$state['db_host']}] is on the forbidden list.");
            }
        }

        return $state;
    }

    private function optedIn(): bool
    {
        return filter_var(env(self::OPT_IN_ENV, false), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<int, string> */
    private function allowedHosts(): array
    {
        $extra = array_filter(array_map('trim', explode(',', strtolower((string) env('DEMO_PROVISIONING_ALLOWED_HOSTS', '')))));

        return array_values(array_unique($extra === [] ? self::DEFAULT_ALLOWED_HOSTS : $extra));
    }

    /** @return array<int, string> */
    private function forbiddenDbHosts(): array
    {
        return array_filter(array_map('trim', explode(',', (string) env('DEMO_PROVISIONING_FORBIDDEN_DB_HOSTS', ''))));
    }
}
