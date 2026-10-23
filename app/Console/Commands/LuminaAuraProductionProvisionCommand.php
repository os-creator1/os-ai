<?php

namespace App\Console\Commands;

use App\Library\Migration\Ghl\GhlImportPlanner;
use App\Library\Migration\Ghl\GhlImportWriter;
use App\Library\Migration\Ghl\GhlSnapshotExporter;
use App\Library\Migration\Lumina\LuminaAuraProvisioner;
use App\Library\Migration\Lumina\LuminaProductionGuard;
use App\Library\Migration\Lumina\LuminaWebsiteBuilder;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Creates the REAL Lumina Aura Business under an EXISTING production owner and imports the GHL snapshot.
 *
 * Non-destructive and additive only: it creates a new Workspace/Business/Location for the owner you name and inserts
 * records; it never edits or deletes any existing row, never touches the owner's login/profile, never creates users,
 * sends no invitation, mail or SMS (queue/mail/notifications faked, every outbound HTTP call refused), starts no
 * billing (an optional complimentary plan creates no Stripe object) and publishes no workflow.
 *
 * Without --apply it only inspects and reports what it WOULD do.
 */
class LuminaAuraProductionProvisionCommand extends Command
{
    protected $signature = 'lumina:production-provision
        {--owner-email= : The EXISTING production owner login that will administer Lumina Aura}
        {--confirm-database= : Must equal the connected database name exactly}
        {--backup-reference= : Id/path of a verified database backup taken before this run}
        {--confirm-phrase= : Exactly "CREATE LUMINA AURA IN PRODUCTION"}
        {--snapshot= : Directory written by ghl:export-snapshot (contains personal data; keep it off git)}
        {--assets= : Directory of the customer\'s original images, for the website}
        {--no-plan : Do not assign the complimentary plan (the Business then has no entitlements until one is assigned)}
        {--allow-incomplete : Import a snapshot even though its _report.json does not prove it complete (partial data; only after reading the failures)}
        {--apply : Actually write. Without it nothing is changed}';

    protected $description = 'PRODUCTION: create the real Lumina Aura Business under an existing owner and import its GoHighLevel history. Guarded, additive, sends nothing.';

    public function handle(LuminaProductionGuard $guard, LuminaAuraProvisioner $provisioner, GhlImportWriter $writer, LuminaWebsiteBuilder $site): int
    {
        $email = strtolower(trim((string) $this->option('owner-email')));

        try {
            $state = $guard->assertMayProvision($this->option('confirm-database'), $this->option('backup-reference'), $this->option('confirm-phrase'), $email);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $owner = User::query()->where('email', $email)->firstOrFail();
        $existing = Workspace::query()->where('owner_user_id', $owner->id)->where('name', LuminaAuraProvisioner::WORKSPACE_NAME)->first();
        $business = $existing === null ? null : Business::query()->where('workspace_id', $existing->id)->first();

        $this->info("Target: {$state['app_host']} / database {$state['db_name']} / owner #{$owner->id}");
        $this->line($business === null
            ? 'Lumina Aura does not exist yet: it would be CREATED (Workspace, Business, Location, pack, import, website).'
            : "Lumina Aura already exists (Business #{$business->id}): the run would only FILL IN what is missing (idempotent).");

        // A partial export must never be imported by accident: refuse BEFORE anything is written unless explicitly overridden.
        if ($snapshotDir = $this->option('snapshot')) {
            $incomplete = GhlSnapshotExporter::incompleteInDirectory((string) $snapshotDir);
            if ($incomplete !== []) {
                $message = 'The snapshot is INCOMPLETE / UNVERIFIED for: '.implode(', ', $incomplete).'. See _report.json for the failures; re-run ghl:export-snapshot until it succeeds.';
                if (! $this->option('allow-incomplete')) {
                    $this->error($message.' Refusing (pass --allow-incomplete only if you have accepted that data is missing).');

                    return self::FAILURE;
                }
                $this->warn($message.' Continuing because --allow-incomplete was passed: the imported data is PARTIAL.');
            }
        }

        if (! $this->option('apply')) {
            // A real dry run of the mapping: read the snapshot and print exactly what --apply would import. Pure computation;
            // no write, no queue, no network.
            if ($snapshotDir = $this->option('snapshot')) {
                $snapshot = [];
                foreach (glob(rtrim((string) $snapshotDir, '/').'/*.json') ?: [] as $file) {
                    $snapshot[basename($file, '.json')] = json_decode((string) file_get_contents($file), true) ?? [];
                }
                $plan = (new GhlImportPlanner())->plan($snapshot);
                $this->line('PLAN counts : '.json_encode($plan['counts']));
                $codes = array_count_values(array_column($plan['issues'], 'code'));
                ksort($codes);
                $this->line('PLAN issues : '.json_encode($codes));
                $this->line('Existing rows are matched by GHL id and skipped, so a repeat run adds nothing.');
            }
            $this->warn('Dry inspection only — nothing was written. Re-run with --apply to write.');

            return self::SUCCESS;
        }

        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::fake(fn () => Http::response('Blocked: production provisioning makes no external calls.', 503));
        Http::preventStrayRequests();

        $result = $provisioner->provision($email, null, [], adoptExistingOwner: true, complimentaryPlan: ! $this->option('no-plan'));
        $ctx = $result['context'];
        $this->line("Workspace {$ctx->workspace->uid} · Business {$ctx->business->uid} · pack components installed this run: {$result['report']['pack_components_installed']}");

        if ($result['violations'] !== []) {
            $this->error('Safety audit FAILED: '.json_encode($result['violations']));

            return self::FAILURE;
        }
        $this->info('Safety audit clean: no messaging number, sender, mail account, token, domain or published workflow.');

        if ($snapshotDir = $this->option('snapshot')) {
            $snapshot = [];
            foreach (glob(rtrim((string) $snapshotDir, '/').'/*.json') as $file) {
                $snapshot[basename($file, '.json')] = json_decode((string) file_get_contents($file), true) ?? [];
            }
            $plan = (new GhlImportPlanner())->plan($snapshot);
            $this->line('DRY RUN : '.json_encode($writer->import($plan, $ctx->business, $ctx->location, LuminaAuraProvisioner::PHONE, true)['counts']));
            $this->info('IMPORTED: '.json_encode($writer->import($plan, $ctx->business, $ctx->location, LuminaAuraProvisioner::PHONE, false)['counts']));
        }

        if ($assets = $this->option('assets')) {
            $built = $site->build($ctx->business, $ctx->location, (int) $owner->id, (string) $assets, base_path('docs/migrations/lumina-aura/website-manifest.json'), base_path('docs/migrations/lumina-aura/source'));
            foreach ($built['report'] as $k => $v) {
                $this->line(str_pad("  website {$k}", 28).$v);
            }
            $this->line($built['missing'] === [] ? '' : 'Assets not found: '.implode(', ', $built['missing']));
        }

        return self::SUCCESS;
    }
}
