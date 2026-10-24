<?php

namespace App\Console\Commands;

use App\Library\Migration\CustomerSites\CustomerSiteGuard;
use App\Library\Migration\CustomerSites\CustomerWebsiteBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * PRODUCTION: builds one existing customer's photo booth website from docs/migrations/customer-websites/<key>/website-content.json.
 *
 * Website-side only and idempotent: it never creates a Business/Workspace/Location, never edits a contact, opportunity,
 * pipeline, location or plan assignment, installs no Business Pack/blueprint, sends no invitation/mail/SMS (queue, mail and
 * notifications are faked and every outbound HTTP call is refused), activates no automation, touches no billing, DNS or custom
 * domain, and publishes to the platform preview path only.
 *
 * Without --apply it is a read-only dry run that proves the target and prints the plan.
 */
class CustomerWebsitesBuildCommand extends Command
{
    protected $signature = 'customer-websites:build
        {key : Customer key (a directory under docs/migrations/customer-websites)}
        {--assets= : Directory of the customer\'s downloaded original images (verified by SHA-256)}
        {--confirm-database= : Must equal the connected database name exactly}
        {--backup-reference= : Path of a verified database backup file taken before this run}
        {--confirm-phrase= : Exactly "BUILD CUSTOMER WEBSITES IN PRODUCTION"}
        {--apply : Actually write. Without it nothing is changed}';

    protected $description = 'PRODUCTION: build an existing customer\'s website from reviewed content. Guarded, additive, idempotent, sends nothing.';

    public function handle(CustomerSiteGuard $guard, CustomerWebsiteBuilder $builder): int
    {
        $key = (string) $this->argument('key');
        $apply = (bool) $this->option('apply');
        $path = base_path("docs/migrations/customer-websites/{$key}/website-content.json");
        if (preg_match('/^[a-z0-9-]+$/', $key) !== 1 || ! is_file($path)) {
            $this->error("Unknown customer key [{$key}].");

            return self::FAILURE;
        }

        try {
            $state = $apply
                ? $guard->assertMayWrite($this->option('confirm-database'), $this->option('backup-reference'), $this->option('confirm-phrase'))
                : $guard->assertTarget();
            $content = $builder->load($path);
            $business = $builder->business($content);
            $location = $builder->location($business);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Target OK: env={$state['app_env']} host={$state['app_host']} database={$state['db_name']}");

        $assets = $this->option('assets');
        $plan = $builder->plan($business, $content, $assets === null ? null : (string) $assets);
        $this->line('PLAN: '.json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($assets === null || $plan['assets_missing'] !== []) {
            $this->{$apply ? 'error' : 'warn'}('Assets missing or --assets not given: '.json_encode($plan['assets_missing']));
            if ($apply) {
                $this->error('ABORT: nothing was written.');

                return self::FAILURE;
            }
        }
        if (! $apply) {
            $this->info('DRY RUN: nothing was written. Re-run with --apply (and the production confirmations) to build.');

            return self::SUCCESS;
        }

        // Nothing leaves the building: no mail, SMS, invitation, notification or outbound call.
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::fake(fn () => Http::response('Blocked: customer website builds make no external calls.', 503));
        Http::preventStrayRequests();

        $before = $builder->crmFingerprint($business);
        try {
            $built = $builder->build($business, $location, (int) $business->workspace->owner->id, (string) $assets, $content);
        } catch (Throwable $e) {
            $this->error('BUILD FAILED: '.$e->getMessage().' (re-running is safe: the build is idempotent).');

            return self::FAILURE;
        }
        $after = $builder->crmFingerprint($business);
        $this->line('REPORT: '.json_encode($built['report'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($before !== $after) {
            $this->error('CRM fingerprint CHANGED: '.json_encode(['before' => $before, 'after' => $after]).' - restore from the backup and investigate.');

            return self::FAILURE;
        }
        $this->info('CRM unchanged: '.json_encode($after));
        if ($built['missing'] !== []) {
            $this->warn('Left UNPUBLISHED: '.implode(' ', $built['missing']));
        }

        return self::SUCCESS;
    }
}
