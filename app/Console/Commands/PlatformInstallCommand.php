<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * THE one documented path from an empty database to a working Business OS
 * instance. Every step is an existing, individually-idempotent command; this
 * only fixes their ORDER (the order is the part that used to be tribal
 * knowledge):
 *
 *   1. migrate                      schema + the seeded plan catalog (a migration)
 *   2. db:seed --class=UserSeeder   the `administrator` role the owner command needs
 *   3. platform:create-owner        the first Platform Owner (interactive; the
 *                                   password is never an argument). Currency
 *                                   seeding needs that first user to exist.
 *   4. db:seed                      config, countries, languages, currencies,
 *                                   email templates, plans, theme presets,
 *                                   website templates, question packs
 *   5. blueprint:seed-photo-booth   the niche Blueprint (publishes as the owner)
 *   6. documents:seed-photo-booth-templates   its proposal/contract templates
 *
 * Re-running is safe: migrate and the seeders are idempotent, an existing
 * administrator skips step 3, and the Blueprint commands no-op once published.
 * Pass --skip-owner in automation where the owner is created separately; steps
 * 5 and 6 then run only if an administrator already exists.
 */
class PlatformInstallCommand extends Command
{
    protected $signature = 'platform:install
        {--owner-email= : Email for the first Platform Owner (otherwise asked)}
        {--skip-owner : Do not create an owner (CI / provisioning that creates it separately)}';

    protected $description = 'Take an empty database to a working Business OS instance (migrate, seed, first owner, niche Blueprint and templates)';

    public function handle(): int
    {
        $steps = [
            ['migrate', ['--force' => true]],
            ['db:seed', ['--class' => 'UserSeeder', '--force' => true]],
        ];

        foreach ($steps as [$command, $arguments]) {
            if (! $this->runStep($command, $arguments)) {
                return self::FAILURE;
            }
        }

        if (! User::query()->where('is_admin', true)->exists()) {
            if ($this->option('skip-owner')) {
                // The remaining seeders need the first user to exist (currencies
                // belong to it), so there is nothing safe to run yet.
                $this->warn('--skip-owner: schema and the administrator role are in place. Create the owner (`php artisan platform:create-owner`), then run `php artisan platform:install` again to finish seeding.');

                return self::SUCCESS;
            } else {
                $arguments = array_filter(['--email' => $this->option('owner-email')]);

                if (! $this->runStep('platform:create-owner', $arguments)) {
                    return self::FAILURE;
                }
            }
        } else {
            $this->line('An administrator already exists; skipping owner creation.');
        }

        if (! $this->runStep('db:seed', ['--force' => true])) {
            return self::FAILURE;
        }

        if (! User::query()->where('is_admin', true)->exists()) {
            $this->warn('Install finished without an owner. Run `php artisan platform:create-owner`, then `php artisan platform:install` again to publish the niche Blueprint and templates.');

            return self::SUCCESS;
        }

        foreach (['blueprint:seed-photo-booth', 'documents:seed-photo-booth-templates'] as $command) {
            if (! $this->runStep($command, [])) {
                return self::FAILURE;
            }
        }

        $this->info('Business OS is installed. Sign in as the Platform Owner at /login.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runStep(string $command, array $arguments): bool
    {
        $this->line("→ {$command}");

        $exit = $this->call($command, $arguments);

        if ($exit !== self::SUCCESS) {
            $this->error("{$command} failed (exit {$exit}); install stopped. Fix the cause and re-run `php artisan platform:install` — every step is safe to repeat.");

            return false;
        }

        return true;
    }
}
