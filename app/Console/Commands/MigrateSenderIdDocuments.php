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
        $moved = $quarantined = $missing = 0;

        Senderid::query()->whereNotNull('document')->where('document', 'not like', SenderIdDocumentStore::PREFIX . '%')
            ->orderBy('id')->each(function (Senderid $row) use ($apply, &$moved, &$quarantined, &$missing) {
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
                    $quarantined++;
                    $this->warn("quarantine  #{$row->id} {$name}");
                    if ($apply) {
                        Storage::disk('local')->makeDirectory('senderid-quarantine');
                        File::move($path, Storage::disk('local')->path('senderid-quarantine/' . Str::random(16) . '.bin'));
                        $row->forceFill(['document' => null])->save();
                    }

                    return;
                }

                $moved++;
                $this->line("move        #{$row->id} {$name} -> private ({$ext})");
                if ($apply) {
                    $relative = SenderIdDocumentStore::DIR . '/' . Str::random(40) . '.' . $ext;
                    Storage::disk('local')->put($relative, File::get($path));
                    $row->forceFill(['document' => SenderIdDocumentStore::PREFIX . $relative])->save();
                    File::delete($path);
                }
            });

        $this->info(($apply ? 'Applied' : 'Dry run') . ": {$moved} moved, {$quarantined} quarantined, {$missing} missing.");

        return self::SUCCESS;
    }
}
