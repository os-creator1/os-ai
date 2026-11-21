<?php

namespace App\Console\Commands;

use App\Library\NicheBlueprint\PhotoBoothBlueprintProvisioner;
use App\Library\Support\RequestScopedCache;
use App\Models\Business;
use Illuminate\Console\Command;

/**
 * Dry-run-first repair for Photo Booth Businesses (typically Agency clients) that never
 * received the photo_booth niche Blueprint. See PhotoBoothBlueprintProvisioner for the
 * cause and the safety rules.
 *
 * DRY RUN BY DEFAULT: without --apply nothing is written. With --apply only Businesses
 * reported `eligible_missing_blueprint` are installed, through the existing idempotent
 * installer. Websites, answers, customizations and published content are never touched;
 * a Business that already holds any installation record is never modified. Safe to
 * re-run.
 */
class RepairPhotoBoothBlueprintCommand extends Command
{
    protected $signature = 'blueprint:repair-photo-booth
        {--business= : Restrict to one Business, by numeric id or uid}
        {--apply : Install the Blueprint for eligible Businesses (default is a read-only report)}';

    protected $description = 'Report (dry run) or install (--apply) the missing photo_booth Blueprint for existing Photo Booth Businesses.';

    public function handle(PhotoBoothBlueprintProvisioner $provisioner, RequestScopedCache $requestCache): int
    {
        $apply = (bool) $this->option('apply');
        $selector = $this->option('business');
        $query = Business::query()->orderBy('id');

        if ($selector !== null) {
            $selector = trim((string) $selector);
            $query = ctype_digit($selector) ? $query->whereKey((int) $selector) : $query->where('uid', $selector);
        } else {
            // Only Photo Booth Businesses can ever be eligible; everything else is skipped without a read of its records.
            $query->where('industry', 'photo_booth_service');
        }

        $rows = [];
        $counts = [];
        $blocked = 0;

        foreach ($query->cursor() as $business) {
            // Each Business is its own unit of work (entitlement inputs are memoized per request).
            $requestCache->flush();

            $result = $apply ? $provisioner->repair($business) : $provisioner->assess($business);

            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            $blocked += str_starts_with($result['status'], 'blocked_') ? 1 : 0;
            $rows[] = [$business->id, $business->uid, $business->name, $business->workspace_id, $result['status'], $result['detail']];
        }

        if ($selector !== null && $rows === []) {
            $this->error('No Business matches the supplied --business selector.');

            return self::FAILURE;
        }

        $this->table(['id', 'uid', 'name', 'workspace', 'status', 'detail'], $rows);
        $this->line($apply ? 'MODE: APPLY' : 'MODE: DRY RUN (nothing written; re-run with --apply to install)');
        ksort($counts);

        foreach ($counts as $status => $count) {
            $this->line("{$status}={$count}");
        }

        return self::SUCCESS;
    }
}
