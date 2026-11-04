<?php

namespace App\Console\Commands;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\Domains\ForgeDomainProvisioner;
use App\Library\Website\Domains\WebsiteDomainService;
use App\Models\WebsiteDomain;
use Illuminate\Console\Command;

/**
 * Restarts Failed custom domains once the platform configuration has been corrected. It removes nothing and replaces
 * no reservation: each domain keeps its hostname reservations and any Forge ids, and goes back to the DNS check, so
 * ownership is re-proven (exact unique CNAME) before anything is provisioned and no Forge resource is created twice.
 */
class RecoverWebsiteDomainsCommand extends Command
{
    protected $signature = 'website-domains:recover {domain? : One hostname, e.g. example.com} {--dry-run : List what would restart}';

    protected $description = 'Restart Failed custom domains after fixing provisioning configuration.';

    public function handle(WebsiteDomainService $domains, ForgeDomainProvisioner $provisioner): int
    {
        if (! $provisioner->isConfigured() || ! $domains->ready()) {
            $this->error('Custom-domain configuration is still incomplete (FORGE_API_TOKEN, FORGE_ORGANIZATION_SLUG, FORGE_SERVER_ID, FORGE_SITE_ID, FORGE_CNAME_ZONE, FORGE_A_RECORD_IP). Nothing was changed.');

            return self::FAILURE;
        }

        $query = WebsiteDomain::query()->where('status', WebsiteDomainStatus::Failed->value);

        if ($this->argument('domain')) {
            $query->where('domain', strtolower((string) $this->argument('domain')));
        }

        $count = 0;

        $query->each(function (WebsiteDomain $domain) use ($domains, &$count): void {
            $this->line(($this->option('dry-run') ? 'Would restart ' : 'Restarting ').$domain->domain);

            if (! $this->option('dry-run')) {
                $domains->retry($domain);
            }

            $count++;
        });

        $this->info("{$count} domain(s) ".($this->option('dry-run') ? 'would be' : 'were').' restarted from the DNS check.');

        return self::SUCCESS;
    }
}
