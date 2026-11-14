<?php

namespace App\Console\Commands;

use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Models\AgencySite;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * READ-ONLY. Finds the White Label site(s) whose brand matches a name, so the owner can set MARKETING_LANDING_DOGS_SITE
 * to the right PUBLIC ID instead of anyone guessing a workspace or site id. Writes nothing and prints no private data
 * beyond what the owner already sees in the editor (workspace uid, brand, status, mode, calendar readiness).
 */
class AgencySiteFind extends Command
{
    protected $signature = 'agency-site:find {name : Part of the brand or legal name, e.g. "Daniel Black"}';

    protected $description = 'Read-only: list White Label sites matching a brand name (for MARKETING_LANDING_DOGS_SITE).';

    public function handle(AgencySiteBookingService $booking): int
    {
        $needle = trim((string) $this->argument('name'));
        $like = '%'.addcslashes($needle, '%_\\').'%';

        $brands = AgencyWhiteLabelSetting::query()
            ->where(fn ($q) => $q->where('display_name', 'like', $like)->orWhere('legal_name', 'like', $like))
            ->get();

        $rows = [];

        foreach ($brands as $brand) {
            $agency = Workspace::query()->find($brand->agency_workspace_id);
            $site = $agency === null ? null : AgencySite::query()->where('agency_workspace_id', $agency->id)->first();

            if ($site === null) {
                continue;
            }

            $values = AgencySiteManager::values($site);
            $ready = $booking->readiness($agency, $values['x.booking_type_uid'] ?? null)['ready'];

            $rows[] = [
                $brand->display_name, $agency->uid, $site->public_id, $site->status, $site->isLive() ? 'yes' : 'no',
                $values['x.goal'] ?? 'both', $values['x.niche'] ?? 'general', $ready ? 'yes' : 'NO',
            ];
        }

        if ($rows === []) {
            $this->warn('No White Label site matches "'.$needle.'".');

            return self::FAILURE;
        }

        $this->table(['Brand', 'Workspace uid', 'Site public id', 'Status', 'Live', 'Saved goal', 'Niche', 'Calendar ready'], $rows);
        $this->line('Set MARKETING_LANDING_DOGS_SITE to the "Site public id" of the live row (the landing page needs Live = yes and Calendar ready = yes).');

        return self::SUCCESS;
    }
}
