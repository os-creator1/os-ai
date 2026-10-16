<?php

namespace App\Console\Commands;

use App\Library\Demo\BrightFrame\BrightFrameData;
use App\Library\Demo\BrightFrame\BrightFrameProvisioner;
use App\Library\Demo\BrightFrame\DemoProvisioningGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Staging-only, opt-in, idempotent provisioner for the fictional BrightFrame
 * Photo Booths sales-demo account. See DemoProvisioningGuard for the checks.
 *
 *   php artisan demo:provision-brightframe --inspect
 *   DEMO_PROVISIONING_ENABLED=true php artisan demo:provision-brightframe --confirm-database=<name>
 */
class ProvisionBrightFrameDemo extends Command
{
    protected $signature = 'demo:provision-brightframe
        {--inspect : Print the host and database this process is pointed at, change nothing}
        {--confirm-database= : The exact connected database name (see --inspect)}
        {--login-email= : Demo login email (default ' . BrightFrameData::DEFAULT_LOGIN_EMAIL . ')}
        {--password-env= : Name of an environment variable holding the demo password (never pass it as an argument)}
        {--rotate-password : Replace the demo login password}';

    protected $description = 'Provision (or top up) the fictional BrightFrame Photo Booths sales-demo account. Staging only; opt-in.';

    public function handle(DemoProvisioningGuard $guard, BrightFrameProvisioner $provisioner): int
    {
        if ($this->option('inspect')) {
            foreach ($guard->inspect() as $key => $value) {
                $this->line(str_pad($key, 14) . ' ' . (is_array($value) ? implode(', ', $value) : var_export($value, true)));
            }

            return self::SUCCESS;
        }

        try {
            $state = $guard->assertMayProvision($this->option('confirm-database'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $loginEmail = strtolower(trim((string) ($this->option('login-email') ?: BrightFrameData::DEFAULT_LOGIN_EMAIL)));
        $password = $this->resolvePassword();
        $generated = $password === null;
        // A generated password is used only if the login does not exist yet or --rotate-password is given.
        $password ??= Str::password(24, symbols: false);

        // Nothing provisioned here may reach a person or a provider: queued
        // work is swallowed, mail and notifications are faked, and any stray
        // HTTP call fails loudly instead of leaving the process.
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::fake(fn () => Http::response('Blocked: demo provisioning makes no external calls.', 503));
        Http::preventStrayRequests();

        $this->info("Provisioning on {$state['app_host']} / database {$state['db_name']} ...");

        $result = $provisioner->provision($loginEmail, $password, (bool) $this->option('rotate-password'));
        $context = $result['context'];

        $this->newLine();
        $this->info('BrightFrame demo is provisioned.');
        $this->line('  Login email : ' . $context->user->email);
        $this->line('  Workspace   : ' . $context->workspace->uid);
        $this->line('  Business    : ' . $context->business->uid);

        foreach ($context->report as $key => $value) {
            $this->line('  ' . str_pad($key, 22) . $value);
        }

        if ($result['password_set']) {
            $this->newLine();
            if ($generated) {
                $this->warn('Generated password (shown ONCE, not stored or logged): ' . $password);
            } else {
                $this->line('Password set from the environment variable you named.');
            }
        } else {
            $this->line('  Password    : unchanged');
        }

        return self::SUCCESS;
    }

    private function resolvePassword(): ?string
    {
        $name = (string) $this->option('password-env');

        if ($name === '') {
            return null;
        }

        $value = getenv($name);

        if ($value === false || strlen($value) < 14) {
            throw new \RuntimeException("Environment variable [{$name}] must be set to a password of at least 14 characters.");
        }

        return $value;
    }
}
