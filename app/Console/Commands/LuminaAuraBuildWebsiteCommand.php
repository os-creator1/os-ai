<?php

namespace App\Console\Commands;

use App\Library\Demo\BrightFrame\DemoProvisioningGuard;
use App\Library\Migration\Lumina\LuminaAuraProvisioner;
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

class LuminaAuraBuildWebsiteCommand extends Command
{
    protected $signature = 'lumina:build-website
        {--confirm-database= : Must equal the connected database name exactly}
        {--owner-email=luminauraevents@gmail.com}
        {--assets= : Directory holding the downloaded original images (any depth)}';

    protected $description = 'Build and publish (platform preview path only) the Lumina Aura website on an isolated staging database. No custom domain, no DNS, no AI.';

    public function handle(DemoProvisioningGuard $guard, LuminaWebsiteBuilder $builder): int
    {
        try {
            $guard->assertMayProvision($this->option('confirm-database'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::fake(fn () => Http::response('Blocked.', 503));
        Http::preventStrayRequests();

        $owner = User::query()->where('email', strtolower((string) $this->option('owner-email')))->firstOrFail();
        $workspace = Workspace::query()->where('owner_user_id', $owner->id)->where('name', LuminaAuraProvisioner::WORKSPACE_NAME)->firstOrFail();
        $business = Business::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $location = $business->primaryLocation()->firstOrFail();

        try {
            $result = $builder->build(
            $business, $location, (int) $owner->id,
            (string) $this->option('assets'),
            base_path('docs/migrations/lumina-aura/website-manifest.json'),
            base_path('docs/migrations/lumina-aura/source'),
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->error('Validation failed: '.json_encode($e->errors()));

            return self::FAILURE;
        }

        foreach ($result['report'] as $k => $v) {
            $this->line(str_pad($k, 22).$v);
        }
        if ($result['missing'] !== []) {
            $this->warn('Assets not found in --assets: '.implode(', ', $result['missing']));
        }

        return self::SUCCESS;
    }
}
