<?php

namespace App\Console\Commands;

use App\Library\NicheBlueprint\PhotoBoothBlueprintProvisioner;
use App\Library\Support\RequestScopedCache;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use Illuminate\Support\Arr;
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
 *
 * EXPLICIT ASSIGNMENT. A Business whose general industry is something else (e.g. `photographer`)
 * can be given the Photo Booth niche ONLY by naming it with --business AND passing
 * --assign-photo-booth. That records the niche in its Knowledge Profile (never Business.industry),
 * then installs. A Business whose profile already names another niche, or that already holds
 * installation records, is never touched. No sweep ever assigns the niche.
 */
class RepairPhotoBoothBlueprintCommand extends Command
{
    protected $signature = 'blueprint:repair-photo-booth
        {--business=* : Restrict to the named Business(es), by numeric id or uid; repeatable}
        {--assign-photo-booth : Explicitly assign the Photo Booth niche to the Business(es) named by --business even though their industry is not photo_booth_service (never changes Business.industry; requires --business)}
        {--apply : Write: assign (with --assign-photo-booth) and install for eligible Businesses (default is a read-only report)}';

    protected $description = 'Report (dry run) or install (--apply) the missing photo_booth Blueprint for existing Photo Booth Businesses.';

    public function handle(PhotoBoothBlueprintProvisioner $provisioner, RequestScopedCache $requestCache): int
    {
        $apply = (bool) $this->option('apply');
        $assign = (bool) $this->option('assign-photo-booth');
        $rawSelectors = Arr::wrap($this->option('business'));
        $selectors = array_map(static fn ($v): string => trim((string) $v), $rawSelectors);

        // A supplied but blank selector must NEVER be treated like no selector.
        // Otherwise --business="$UNSET" --apply unexpectedly becomes a global sweep.
        if (in_array('', $selectors, true)) {
            $this->error('An explicitly provided --business selector is empty. Nothing was changed.');

            return self::FAILURE;
        }

        if ($assign && $selectors === []) {
            $this->error('--assign-photo-booth only ever applies to Businesses you name: pass --business=<id|uid> (repeatable). Nothing was changed.');

            return self::FAILURE;
        }

        $businesses = [];

        if ($selectors !== []) {
            // Resolve EVERY selector before any write: one unknown selector aborts the whole run.
            foreach ($selectors as $selector) {
                $found = ctype_digit($selector) ? Business::query()->whereKey((int) $selector)->first() : Business::query()->where('uid', $selector)->first();

                if ($found === null) {
                    $this->error("No Business matches the --business selector '{$selector}'. Nothing was changed.");

                    return self::FAILURE;
                }

                $businesses[$found->id] = $found;
            }
        } else {
            // Only Photo Booth Businesses (by industry, or already assigned the niche) can be eligible.
            $businesses = Business::query()->orderBy('id')
                ->where(fn ($q) => $q->where('industry', 'photo_booth_service')
                    ->orWhereIn('id', BusinessKnowledgeProfile::query()->where('vertical_key', PhotoBoothBlueprintProvisioner::NICHE_KEY)->select('business_id')))
                ->get()->all();
        }

        $rows = [];
        $counts = [];
        $blocked = 0;

        foreach ($businesses as $business) {
            // Each Business is its own unit of work (entitlement inputs are memoized per request).
            $requestCache->flush();

            $result = $apply ? $provisioner->repair($business, $assign) : $provisioner->assess($business, $assign);

            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            $blocked += str_starts_with($result['status'], 'blocked_') ? 1 : 0;
            $rows[] = [$business->id, $business->uid, $business->name, $business->workspace_id, $result['status'], $result['detail']];
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
