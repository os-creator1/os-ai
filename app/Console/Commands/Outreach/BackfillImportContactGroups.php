<?php

namespace App\Console\Commands\Outreach;

use App\Library\AgencyOutreach\AgencyOutreachBusinessResolver;
use App\Library\AgencyOutreach\Import\ProspectGroupMaterializer;
use App\Models\AgencyProspectImportBatch;
use App\Models\ContactGroups;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * EXPLICIT, manual backfill: give CSV prospect imports made BEFORE "one import → one Contact Group" a Contact Group,
 * Contacts and Custom Field values, from the rows already stored on their import batch.
 *
 *   php artisan outreach:backfill-import-groups --workspace=<uid>             dry run (default): changes nothing
 *   php artisan outreach:backfill-import-groups --workspace=<uid> --apply     writes
 *   --batch=<uid>   only that batch
 *
 * Never scheduled, never run by a deploy. It does not touch conversations, campaigns, messages, prospect status or
 * consent: STOP / opted-out, Existing Client and blacklisted numbers get no contact, a number already owned by a Contact
 * is never moved, only BLANK custom-field values are filled. Repeating it is safe (batches that already have a group are
 * skipped; rows remember their Contact; links are unique).
 */
class BackfillImportContactGroups extends Command
{
    protected $signature = 'outreach:backfill-import-groups {--workspace= : Workspace uid (required)} {--batch= : Import batch uid} {--apply : Write changes (default is a dry run)}';

    protected $description = 'Dry-run or apply: create Contact Groups + Custom Field values for CSV prospect imports made before groups were linked';

    public function handle(ProspectGroupMaterializer $materializer): int
    {
        $workspace = Workspace::query()->where('uid', (string) $this->option('workspace'))->first();

        if ($workspace === null) {
            $this->error('Pass --workspace=<uid> of an existing Workspace.');

            return self::INVALID;
        }

        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);

        if ($business === null) {
            $this->error('This Workspace has no Outreach business, so no contact group can be created.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $batches = AgencyProspectImportBatch::query()->where('workspace_id', $workspace->id)->where('kind', 'prospects')
            ->where('status', 'imported')->whereNull('contact_group_id')
            ->when($this->option('batch'), fn ($q, $uid) => $q->where('uid', $uid))->orderBy('id')->get();

        $this->info(($apply ? 'APPLYING' : 'DRY RUN (nothing is written)') . " — {$batches->count()} import batch(es) without a contact group.");
        $rows = [];

        foreach ($batches as $batch) {
            $base = trim((string) preg_replace('/\.(csv|txt|tsv)$/i', '', (string) $batch->filename)) ?: 'Imported prospects';
            $name = ContactGroups::where('customer_id', $business->customer_id)->where('name', $base)->exists() ? $base . ' (import ' . $batch->id . ')' : $base;
            $report = $materializer->materialize($workspace, $business, $batch, $name, $apply);

            $rows[] = [$batch->uid, $report['name_conflict'] ? $name . ' (NAME CONFLICT)' : $name, $report['created'], $report['referenced'], $report['existing_in_group'],
                array_sum($report['skipped']), collect($report['fields'])->map(fn ($f) => $f['label'] . ':' . $f['action'])->implode(', ')];
        }

        $this->table(['Batch', 'Group', 'Contacts to create/created', 'Linked (number already a contact)', 'Already in group', 'Skipped (STOP/client/blocked)', 'Custom fields'], $rows);
        $this->line($apply ? 'Done. Conversations, campaigns and consent were not touched.' : 'Dry run only. Re-run with --apply to write.');

        return self::SUCCESS;
    }
}
