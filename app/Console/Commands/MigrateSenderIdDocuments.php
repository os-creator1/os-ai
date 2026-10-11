<?php

namespace App\Console\Commands;

use App\Library\Uploads\SenderIdDocumentStore;
use App\Models\Senderid;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Moves legacy public/senderid_docs files into private storage. Dry-run unless --apply.
 * Allow-listed, content-verified files are moved and their row repointed; anything else
 * is quarantined (moved, never deleted) and the row is left without a document link.
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
            ->orderBy('id')->each(function (Senderid $row) use ($apply, &$moved, &$quarantined, &$missing, &$failed) {
                $name = basename((string) parse_url($row->document, PHP_URL_PATH));
                $path = public_path(SenderIdDocumentStore::LEGACY_DIR . '/' . $name);

                if (! is_file($path)) {
                    $missing++;
                    $this->line("missing     #{$row->id} {$name}");

                    return;
                }

                $upload = new \Illuminate\Http\UploadedFile($path, $name, null, null, true);
                $ext = preg_match('/^[A-Za-z0-9_\-]+\.(pdf|jpg|jpeg|png|doc|docx)$/i', $name) ? SenderIdDocumentStore::safeExtension($upload) : null;

                if ($ext === null) {
                    $this->warn("quarantine  #{$row->id} {$name}");
                    if ($apply) {
                        $disk = Storage::disk('local');
                        if (! $disk->makeDirectory('senderid-quarantine')) {
                            $failed++;
                            $this->error("Cannot prepare private quarantine for #{$row->id}; original file and database row preserved.");

                            return;
                        }

                        $quarantinePath = $disk->path('senderid-quarantine/' . Str::random(16) . '.bin');
                        if (! File::move($path, $quarantinePath)) {
                            $failed++;
                            $this->error("Cannot move #{$row->id} into quarantine; original file and database row preserved.");

                            return;
                        }

                        // Never detach a record from its original file unless the private move succeeded.
                        $row->forceFill(['document' => null])->save();
                    }

                    $quarantined++;

                    return;
                }

                $this->line("move        #{$row->id} {$name} -> private ({$ext})");
                if ($apply) {
                    $relative = SenderIdDocumentStore::DIR . '/' . Str::random(40) . '.' . $ext;
                    if (Storage::disk('local')->put($relative, File::get($path)) !== true) {
                        $failed++;
                        $this->error("Private storage write failed for #{$row->id}; original file and database row preserved.");

                        return;
                    }

                    // Do not touch the only original until the private copy is persisted.
                    $row->forceFill(['document' => SenderIdDocumentStore::PREFIX . $relative])->save();
                    if (! File::delete($path)) {
                        $failed++;
                        $this->error("Private copy saved for #{$row->id}, but public original could not be deleted: {$path}");

                        return;
                    }
                }

                $moved++;
            });

        $this->info(($apply ? 'Applied' : 'Dry run') . ": {$moved} moved, {$quarantined} quarantined, {$missing} missing, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
