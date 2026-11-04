<?php

namespace App\Console\Commands;

use App\Library\Migration\Ghl\GhlImportPlanner;
use App\Library\Migration\Ghl\GhlImportWriter;
use App\Library\Migration\Toronto\TorontoImportPreflight;
use App\Library\Migration\Toronto\TorontoProductionGuard;
use App\Library\Migration\Toronto\TorontoProvisioner;
use App\Library\Migration\Toronto\TorontoWebsiteBuilder;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Creates the Toronto Photo Booth customer in PRODUCTION and imports its verified GoHighLevel snapshot and website.
 *
 * Additive and idempotent: it creates one new Workspace/Business/Location, inserts records keyed by GHL id, and never edits
 * or deletes another customer's row. It sends no invitation, mail or SMS (queue/mail/notifications faked, every outbound
 * HTTP call refused), starts no billing, enrolls nobody, touches no phone number/provider/DNS and publishes no workflow.
 *
 * Without --apply it is a read-only dry run: it proves the target, reports whether the business already exists and prints
 * the import plan, writing nothing.
 */
class TorontoProductionProvisionCommand extends Command
{
    protected $signature = 'toronto:production-provision
        {--owner-email= : Agency-controlled login that will own the customer (never the customer\'s address)}
        {--confirm-database= : Must equal the connected database name exactly}
        {--backup-reference= : Path/id of a verified database backup taken before this run}
        {--confirm-phrase= : Exactly "CREATE TORONTO PHOTO BOOTH IN PRODUCTION"}
        {--snapshot= : Directory of the verified GHL snapshot (personal data; keep off git)}
        {--assets= : Directory of the business\'s downloaded original images}
        {--include-ghl-samples : Also import the GHL auto-generated "(Example)" demo contacts/deals (default: skipped)}
        {--skip-website : Do not build the website}
        {--apply : Actually write. Without it nothing is changed}';

    protected $description = 'PRODUCTION: create the Toronto Photo Booth customer and import its GoHighLevel history and website. Guarded, additive, sends nothing.';

    public function handle(TorontoProductionGuard $guard, TorontoImportPreflight $preflight, TorontoProvisioner $provisioner, GhlImportWriter $writer, TorontoWebsiteBuilder $site): int
    {
        $email = strtolower(trim((string) $this->option('owner-email')));
        $apply = (bool) $this->option('apply');

        try {
            $state = $apply
                ? $guard->assertMayWrite($this->option('confirm-database'), $this->option('backup-reference'), $this->option('confirm-phrase'))
                : $guard->assertTarget();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('--owner-email is required (an agency-controlled login).');

            return self::FAILURE;
        }
        $this->info("Target OK: env={$state['app_env']} host={$state['app_host']} database={$state['db_name']}");

        // Fail closed BEFORE any database read or write: every input the import needs must exist and verify.
        $snapshotDir = $this->option('snapshot');
        $assetsDir = $this->option('assets');
        $websiteWanted = ! $this->option('skip-website');
        $errors = [];
        $snapshot = [];
        if ($snapshotDir === null && ! $apply) {
            $this->warn('No --snapshot given: the import plan was not checked.');
        } else {
            ['snapshot' => $snapshot, 'errors' => $errors] = $preflight->snapshot($snapshotDir === null ? null : (string) $snapshotDir);
        }
        if ($websiteWanted && ($apply || $assetsDir !== null)) {
            $errors = array_merge($errors, $preflight->assets($assetsDir === null ? null : (string) $assetsDir, base_path('docs/migrations/toronto-photo-booth/website-content.json')));
        } elseif ($websiteWanted) {
            $this->warn('No --assets given: the website images were not checked.');
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error('PREFLIGHT: '.$error);
            }
            $this->error('ABORT: inputs failed verification; nothing was written.');

            return self::FAILURE;
        }

        // Cross-tenant check: does this business already exist anywhere, and is it where we expect?
        $ours = Workspace::query()->where('name', TorontoProvisioner::WORKSPACE_NAME)->whereHas('owner', fn ($q) => $q->where('email', $email))->first();
        $ourBusinessIds = $ours === null ? [] : Business::query()->where('workspace_id', $ours->id)->pluck('id')->all();
        $clashes = Business::query()
            ->where(fn ($q) => $q->where('email', TorontoProvisioner::EMAIL)->orWhere('website_url', 'like', '%emphotobooth.site%')->orWhere('website_url', 'like', '%photoboothcompanyoftoronto%')->orWhere('name', 'like', '%Photo Booth Company of Toronto%'))
            ->whereNotIn('id', $ourBusinessIds ?: [0])
            ->get(['id', 'name', 'workspace_id']);
        if ($clashes->isNotEmpty()) {
            $this->error('ABORT: a matching business already exists outside the expected Workspace: '.$clashes->map(fn ($b) => "#{$b->id} {$b->name} (workspace {$b->workspace_id})")->implode('; ').'. Reconcile manually; nothing was written.');

            return self::FAILURE;
        }
        $this->line($ours === null ? 'Toronto Photo Booth does not exist yet: it would be CREATED.' : "Toronto Photo Booth already exists (Workspace #{$ours->id}): only missing records are filled in (idempotent).");

        $plan = $snapshot === [] ? null : $this->plan($snapshot);
        if ($plan !== null) {
            $this->line('PLAN counts : '.json_encode($plan['counts']));
            $this->line('PLAN issues : '.json_encode(array_count_values(array_column($plan['issues'], 'code'))));
            $blocking = array_filter($plan['issues'], fn ($i) => ($i['severity'] ?? '') === 'error');
            if ($apply && $plan['contacts'] === []) {
                $this->error('ABORT: the import plan contains no contacts. Nothing was written.');

                return self::FAILURE;
            }
            if ($apply && $blocking !== []) {
                $this->error('ABORT: the import plan has '.count($blocking).' error-severity issue(s): '.json_encode(array_count_values(array_column($blocking, 'code'))).'. Nothing was written.');

                return self::FAILURE;
            }
        }

        if (! $apply) {
            $this->warn('Read-only dry run — nothing was written. Re-run with --apply (and the confirmations) to write.');

            return self::SUCCESS;
        }

        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::fake(fn () => Http::response('Blocked: production provisioning makes no external calls.', 503));
        Http::preventStrayRequests();

        $pipelines = $plan === null ? [] : array_values(array_map(
            fn ($p) => ['name' => $p['name'], 'stages' => array_column(collect($p['stages'])->sortBy('position')->values()->all(), 'name')],
            $plan['pipelines'],
        ));

        $result = $provisioner->provision($email, $pipelines);
        $ctx = $result['context'];
        $this->line("Workspace {$ctx->workspace->uid} · Business {$ctx->business->uid} · ".json_encode($result['report']));
        if ($result['password'] !== null) {
            $this->warn("TEMPORARY OWNER LOGIN (shown once; store it in the agency vault): {$email} / {$result['password']}");
        }
        if ($result['violations'] !== []) {
            $this->error('Safety audit FAILED: '.json_encode($result['violations']));

            return self::FAILURE;
        }
        $this->info('Safety audit clean: no messaging number, sender, mail account, token, domain or published workflow.');

        {
            $this->line('DRY RUN : '.json_encode($writer->import($plan, $ctx->business, $ctx->location, TorontoProvisioner::PHONE, true)['counts']));
            $this->info('IMPORTED: '.json_encode($writer->import($plan, $ctx->business, $ctx->location, TorontoProvisioner::PHONE, false)['counts']));
        }

        if ($websiteWanted) {
            $assets = (string) $assetsDir;
            try {
                $built = $site->build($ctx->business, $ctx->location, (int) $ctx->owner->id, (string) $assets, base_path('docs/migrations/toronto-photo-booth/website-content.json'));
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->error('Website validation failed: '.json_encode($e->errors()));

                return self::FAILURE;
            }
            foreach ($built['report'] as $k => $v) {
                $this->line(str_pad("  website {$k}", 30).$v);
            }
            $this->line($built['missing'] === [] ? '' : 'Assets not found in --assets: '.implode(', ', $built['missing']));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $snapshot  already verified by TorontoImportPreflight
     * @return array<string, mixed>
     */
    private function plan(array $snapshot): array
    {
        if (! $this->option('include-ghl-samples')) {
            $isSample = fn (array $c) => str_starts_with(mb_strtolower((string) ($c['contactName'] ?? $c['firstName'] ?? '')), '(example)');
            $drop = collect($snapshot['contacts'] ?? [])->filter($isSample)->pluck('id')->all();
            $snapshot['contacts'] = array_values(array_filter($snapshot['contacts'] ?? [], fn ($c) => ! in_array($c['id'], $drop, true)));
            $snapshot['opportunities'] = array_values(array_filter($snapshot['opportunities'] ?? [], fn ($o) => ! in_array($o['contactId'] ?? ($o['contact']['id'] ?? null), $drop, true)));
            $snapshot['conversations'] = array_values(array_filter($snapshot['conversations'] ?? [], fn ($c) => ! in_array($c['contactId'] ?? null, $drop, true)));
            $keep = array_column($snapshot['conversations'], 'id');
            $snapshot['messages'] = array_intersect_key($snapshot['messages'] ?? [], array_flip($keep));
            $this->line('Skipped GHL auto-generated demo records: '.count($drop).' contacts (and their deals/conversations). They remain in the archive.');
        }

        return (new GhlImportPlanner())->plan($snapshot);
    }
}
