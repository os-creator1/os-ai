<?php

namespace App\Console\Commands;

use App\Library\Demo\BrightFrame\DemoProvisioningGuard;
use App\Library\Migration\Ghl\GhlImportPlanner;
use App\Library\Migration\Ghl\GhlImportWriter;
use App\Library\Migration\Lumina\LuminaAuraProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Provisions the Lumina Aura staging Business and imports the GHL snapshot into it.
 * Guarded exactly like demo:provision-brightframe (opt-in env var, allowed APP_URL
 * host, --confirm-database, no production-shaped database). The process fakes the
 * queue, mail and notifications and refuses every outbound HTTP call.
 */
class LuminaAuraProvisionCommand extends Command
{
    protected $signature = 'lumina:provision
        {--inspect : Print the target host/database and change nothing}
        {--confirm-database= : Must equal the connected database name exactly}
        {--owner-email=luminauraevents@gmail.com : Owner login}
        {--snapshot= : Directory written by ghl:export-snapshot}
        {--business-number=+17205028306 : The conversation "from" number for historical threads}
        {--dry-run : Provision nothing further; print what the import WOULD do (needs an already-provisioned Business)}
        {--skip-import : Provision account + pack only}';

    protected $description = 'Provision Lumina Aura Events on an isolated staging database and import its GoHighLevel history (no messages sent).';

    public function handle(DemoProvisioningGuard $guard, LuminaAuraProvisioner $provisioner, GhlImportWriter $writer): int
    {
        if ($this->option('inspect')) {
            foreach ($guard->inspect() as $key => $value) {
                $this->line(str_pad($key, 14).' '.(is_array($value) ? implode(', ', $value) : var_export($value, true)));
            }

            return self::SUCCESS;
        }

        try {
            $state = $guard->assertMayProvision($this->option('confirm-database'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::fake(fn () => Http::response('Blocked: Lumina provisioning makes no external calls.', 503));
        Http::preventStrayRequests();

        $this->info("Target: {$state['app_host']} / database {$state['db_name']}");

        $people = [];
        if ($this->option('snapshot') && is_file($users = rtrim((string) $this->option('snapshot'), '/').'/users.json')) {
            foreach (json_decode(file_get_contents($users), true) ?? [] as $u) {
                if (empty($u['deleted']) && ! empty($u['email'])) {
                    $people[] = ['first' => (string) ($u['firstName'] ?? ''), 'last' => (string) ($u['lastName'] ?? ''), 'email' => (string) $u['email']];
                }
            }
        }

        $password = Str::password(24, symbols: false);
        $result = $provisioner->provision(strtolower(trim((string) $this->option('owner-email'))), $password, $people);
        $ctx = $result['context'];

        $this->line('  Workspace : '.$ctx->workspace->uid);
        $this->line('  Business  : '.$ctx->business->uid.' ('.$ctx->business->timezone.')');
        $this->line('  Location  : '.$ctx->location->name);
        foreach ($result['report'] as $k => $v) {
            $this->line('  '.str_pad($k, 26).$v);
        }
        if ($result['violations'] !== []) {
            $this->error('Safety audit FAILED: '.json_encode($result['violations']));

            return self::FAILURE;
        }
        $this->info('  Safety audit: no messaging number, sender, e-mail account, token, domain or published workflow.');
        if ($result['password_set']) {
            $this->warn('Owner password (shown ONCE, not stored or logged): '.$password);
        }

        if ($this->option('skip-import') || ! $this->option('snapshot')) {
            return self::SUCCESS;
        }

        $dir = rtrim((string) $this->option('snapshot'), '/');
        $snapshot = [];
        foreach (glob("{$dir}/*.json") as $file) {
            $snapshot[basename($file, '.json')] = json_decode(file_get_contents($file), true) ?? [];
        }
        $plan = (new GhlImportPlanner())->plan($snapshot);

        $first = $writer->import($plan, $ctx->business, $ctx->location, (string) $this->option('business-number'), true);
        $this->line('DRY RUN: '.json_encode($first['counts']));
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $applied = $writer->import($plan, $ctx->business, $ctx->location, (string) $this->option('business-number'), false);
        $this->info('IMPORTED: '.json_encode($applied['counts']));

        return self::SUCCESS;
    }
}
