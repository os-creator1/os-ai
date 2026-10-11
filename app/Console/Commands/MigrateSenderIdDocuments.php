<?php

namespace App\Console\Commands;

use App\Library\Uploads\SenderIdDocumentStore;
use App\Models\Senderid;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
 *  - rows that share one legacy file (the old names were time()-based) are repointed together in ONE
 *    transaction, and the original is only deleted/moved after every one of them is saved;
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

        Senderid::query()->whereNotNull('document')->where('document', 'not like', SenderIdDocumentStore::PREFIX . '%')
            ->eachById(function (Senderid $row) use ($apply, &$moved, &$quarantined, &$missing, &$failed) {
                $name = basename((string) parse_url($row->document, PHP_URL_PATH));
                $path = public_path(SenderIdDocumentStore::LEGACY_DIR . '/' . $name);

                if (! is_file($path)) {
                    $missing++;
                    $this->line("missing     #{$row->id} {$name}");

                    return;
                }

                // Every row that references this one file is handled together, in ONE transaction, and the
                // public original is only touched after all of them are safely saved.
                $siblings = Senderid::query()->where('document', 'like', '%' . $name)
                    ->where('document', 'not like', SenderIdDocumentStore::PREFIX . '%')->get()
                    ->filter(fn (Senderid $r) => basename((string) parse_url($r->document, PHP_URL_PATH)) === $name)
                    ->keyBy('id');
                $siblings->put($row->id, $row);
                $originals = $siblings->map(fn (Senderid $r) => $r->document)->all();
                $ids = $siblings->keys()->implode(',');

                $upload = new UploadedFile($path, $name, null, null, true);
                $ext = preg_match('/^[A-Za-z0-9_\-]+\.(pdf|jpg|jpeg|png|doc|docx)$/i', $name) ? SenderIdDocumentStore::safeExtension($upload) : null;
                $disk = Storage::disk('local');

                if ($ext === null) {
                    $this->warn("quarantine  #{$ids} {$name}");
                    if ($apply) {
                        if (! $disk->makeDirectory('senderid-quarantine')) {
                            $failed++;
                            $this->error("Cannot prepare private quarantine for #{$ids}; original file and database rows preserved.");

                            return;
                        }

                        $quarantinePath = $disk->path('senderid-quarantine/row-' . $row->id . '-' . Str::random(16) . '.bin');
                        if (! File::move($path, $quarantinePath)) {
                            $failed++;
                            $this->error("Cannot move #{$ids} into quarantine; original file and database rows preserved.");

                            return;
                        }

                        try {
                            $this->repoint($siblings, null);
                        } catch (\Throwable $e) {
                            $failed++;
                            if (File::move($quarantinePath, $path)) {
                                $this->error("Database update failed for #{$ids} ({$e->getMessage()}); file restored to its original location, rows unchanged.");
                            } else {
                                $this->error("Database update failed for #{$ids} AND the file could not be restored. Recover it from: {$quarantinePath} (original: {$path}).");
                            }

                            return;
                        }
                    }

                    $quarantined++;

                    return;
                }

                $this->line("move        #{$ids} {$name} -> private ({$ext})");
                if ($apply) {
                    $relative = SenderIdDocumentStore::DIR . '/' . Str::random(40) . '.' . $ext;
                    if ($disk->put($relative, File::get($path)) !== true) {
                        $failed++;
                        $this->error("Private storage write failed for #{$ids}; original file and database rows preserved.");

                        return;
                    }

                    try {
                        $this->repoint($siblings, SenderIdDocumentStore::PREFIX . $relative);
                    } catch (\Throwable $e) {
                        // The transaction rolled every row back; the public original was never touched.
                        $disk->delete($relative);
                        $failed++;
                        $this->error("Database update failed for #{$ids} ({$e->getMessage()}); rolled back, original and rows preserved.");

                        return;
                    }

                    if (! File::delete($path)) {
                        // Restore the legacy references so the public original stays authoritative and a later run retries.
                        try {
                            $this->restore($siblings, $originals);
                            $disk->delete($relative);
                            $this->error("Could not delete public original for #{$ids}: {$path}. Rolled back; rows still on their legacy references.");
                        } catch (\Throwable $e) {
                            $this->error("Could not delete public original for #{$ids}: {$path}, and rollback failed ({$e->getMessage()}). Rows point at the private copy {$relative}; delete the public file manually.");
                        }
                        $failed++;

                        return;
                    }
                }

                $moved++;
            });

        $this->info(($apply ? 'Applied' : 'Dry run') . ": {$moved} moved, {$quarantined} quarantined, {$missing} missing, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Update every sibling row atomically; any failure rolls all of them back. */
    private function repoint($siblings, ?string $value): void
    {
        DB::transaction(function () use ($siblings, $value) {
            foreach ($siblings as $r) {
                $r->forceFill(['document' => $value])->save();
            }
        });
    }

    private function restore($siblings, array $originals): void
    {
        DB::transaction(function () use ($siblings, $originals) {
            foreach ($siblings as $id => $r) {
                $r->forceFill(['document' => $originals[$id]])->save();
            }
        });
    }
}
