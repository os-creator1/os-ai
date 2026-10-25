<?php

namespace App\Console\Commands;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\Domains\WebsiteDomainService;
use App\Models\WebsiteDomain;
use Illuminate\Console\Command;

/**
 * Drives every in-flight custom domain forward (DNS proof -> Forge domains + certificates -> live), with no owner
 * action and no page open. Idempotent and safe to overlap: each domain is claimed atomically and honours its own
 * backoff schedule, so running this every minute does not hammer DNS or Forge.
 */
class ProcessWebsiteDomainsCommand extends Command
{
    protected $signature = 'website-domains:process';

    protected $description = 'Advance custom website domains through DNS verification and automatic HTTPS.';

    public function handle(WebsiteDomainService $domains): int
    {
        WebsiteDomain::query()
            ->whereIn('status', [WebsiteDomainStatus::PendingVerification->value, WebsiteDomainStatus::Verified->value, WebsiteDomainStatus::Provisioning->value])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->each(fn (WebsiteDomain $domain) => $domains->advance($domain));

        return self::SUCCESS;
    }
}
