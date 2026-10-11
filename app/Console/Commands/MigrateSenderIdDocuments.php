<?php

namespace App\Console\Commands;

use App\Library\Uploads\SenderIdDocumentStore;
use App\Models\Senderid;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Moves legacy public/senderid_docs files into private storage. Dry-run unless --apply.
 *
 * Safety rules (an original is never removed unless its replacement is persisted and referenced):
 *  - valid files are copied to private storage, the row is repointed, and only then is the public
 *    original deleted; if that delete fails the row and the private copy are rolled back so the
 *    row stays on its legacy reference and a later run retries it;
 *  - unsafe files are moved (never deleted) into private quarantine as row-<id>-<random>.bin, and
 *    the row is cleared only after the move succeeded;
 *  - rows that share one legacy file (the old names were time()-based) all follow the first row's
 *    outcome instead of being treated as missing;
 *  - rows are paged by id, so changing `document` cannot shift later pages.
 */
class MigrateSenderIdDocuments extends Command
{
    protected $signature = 'senderid:migrate-documents {--apply : Actually move files and update rows}';

    protected $description = 'Move legacy public sender-ID documents into private storage (dry-run by default)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $moved = $quarantined = $missing = $failed = 0;
        /** @var array<string, array{value: ?string}> legacy file name => outcome already applied this run */
        $done = [];

        Senderid::query()->whereNotNull('document')->where('document', 'not like', SenderIdDocumentStore::PREFIX . '%')
            ->eachById(function (Senderid $row) use ($apply, &$moved, &$quarantined, &$missing, &$failed, &$done) {
                $legacy = $row->document;
                $name = basename((string) parse_url($legacy, PHP_URL_PATH));

                // Another row already migrated or quarantined this very file in this run.
                if ($apply && isset($done[$name])) {
                    $row->forceFill(['document' => $done[$name]['value']])->save();
                    $this->line("shared      #{$row->id} {$name}");

                    return;
                }

                $path = public_path(SenderIdDocumentStore::LEGACY_DIR . '/' . $name);

                if (! is_file($path)) {
                    $missing++;
                    $this->line("missing     #{$row->id} {$name}");

                    return;
                }

                $upload = new UploadedFile($path, $name, null, null, true);
                $ext = preg_match('/^[A-Za-z0-9_\-]+\.(pdf|jpg|jpeg|png|doc|docx)$/i', $name) ? SenderIdDocumentStore::safeExtension($upload) : null;
                $disk = Storage::disk('local');

                if ($ext === null) {
                    $this->warn("quarantine  #{$row->id} {$name}");
                    if ($apply) {
                        if (! $disk->makeDirectory('senderid-quarantine')) {
                            $failed++;
                            $this->error("Cannot prepare private quarantine for #{$row->id}; original file and database row preserved.");

                            return;
                        }

                        if (! File::move($path, $disk->path('senderid-quarantine/row-' . $row->id . '-' . Str::random(16) . '.bin'))) {
                            $failed++;
                            $this->error("Cannot move #{$row->id} into quarantine; original file and database row preserved.");

                            return;
                        }

                        // Never detach a record from its original file unless the private move succeeded.
                        $row->forceFill(['document' => null])->save();
                        $done[$name] = ['value' => null];
                    }

                    $quarantined++;

                    return;
                }

                $this->line("move        #{$row->id} {$name} -> private ({$ext})");
                if ($apply) {
                    $relative = SenderIdDocumentStore::DIR . '/' . Str::random(40) . '.' . $ext;
                    if ($disk->put($relative, File::get($path)) !== true) {
                        $failed++;
                        $this->error("Private storage write failed for #{$row->id}; original file and database row preserved.");

                        return;
                    }

                    $private = SenderIdDocumentStore::PREFIX . $relative;
                    $row->forceFill(['document' => $private])->save();

                    if (! File::delete($path)) {
                        // Roll back so the public original stays the single source of truth and a later run retries.
                        $row->forceFill(['document' => $legacy])->save();
                        $disk->delete($relative);
                        $failed++;
                        $this->error("Could not delete public original for #{$row->id}: {$path}. Rolled back; row still on its legacy reference.");

                        return;
                    }

                    $done[$name] = ['value' => $private];
                }

                $moved++;
            });

        $this->info(($apply ? 'Applied' : 'Dry run') . ": {$moved} moved, {$quarantined} quarantined, {$missing} missing, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
