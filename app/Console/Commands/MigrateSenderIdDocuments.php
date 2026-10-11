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

    private const PENDING_DIR = 'senderid-pending';

    protected $description = 'Move legacy public sender-ID documents into private storage (dry-run by default)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // A dry run changes nothing and needs no lock. A destructive run is serialised across every node that
        // shares this database with a MySQL/MariaDB advisory lock (GET_LOCK is held by this DB session, so it is
        // also released if the process dies). If it cannot be acquired we do nothing.
        if (! $apply) {
            return $this->migrate(false);
        }

        $name = $this->lockName();
        $acquired = false;

        try {
            $acquired = (int) (DB::selectOne('SELECT GET_LOCK(?, 0) AS l', [$name])->l ?? 0) === 1;
        } catch (\Throwable $e) {
            $this->error('Could not acquire the migration lock (' . $e->getMessage() . '); nothing was changed.');

            return self::FAILURE;
        }

        if (! $acquired) {
            $this->error('Another senderid:migrate-documents --apply is already running (database lock held); nothing was changed.');

            return self::FAILURE;
        }

        try {
            return $this->migrate(true);
        } catch (\Throwable $e) {
            $this->error('Migration aborted: ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            try {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS l', [$name]);
            } catch (\Throwable $e) {
                // the lock is tied to this DB session and disappears with it
            }
        }
    }

    private function lockName(): string
    {
        // Same database => same lock on every deployment node. MySQL caps lock names at 64 characters.
        return 'senderid-migrate:' . substr(md5((string) DB::connection()->getDatabaseName()), 0, 16);
    }

    /** Abort (before any destructive step) if this session no longer holds the lock, e.g. after a reconnect. */
    private function assertLockStillHeld(): void
    {
        $held = DB::selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS h', [$this->lockName()])->h ?? 0;
        if ((int) $held !== 1) {
            throw new \RuntimeException('the migration lock was lost (database reconnect?)');
        }
    }

    private $disk;

    private function migrate(bool $apply): int
    {
        $this->disk = Storage::disk('local');
        $moved = $quarantined = $missing = $failed = 0;
        $seen = []; // dry-run only: a dry run changes nothing, so count each shared file once

        // Finish anything an interrupted earlier run left behind (rows already on the private copy, public original still there).
        $resumed = $this->finishInterrupted($this->disk, $apply, $failed);

        Senderid::query()->whereNotNull('document')->where('document', 'not like', SenderIdDocumentStore::PREFIX . '%')
            ->eachById(function (Senderid $row) use ($apply, &$moved, &$quarantined, &$missing, &$failed, &$seen) {
                // eachById() may have loaded this row before an earlier callback migrated or quarantined the
                // file it shares; re-read it and skip anything already handled (no false "missing", no duplicate work).
                if ($apply) {
                    $this->assertLockStillHeld();
                }

                $row = $row->fresh();
                if ($row === null || $row->document === null || str_starts_with($row->document, SenderIdDocumentStore::PREFIX)) {
                    return;
                }

                $name = basename((string) parse_url($row->document, PHP_URL_PATH));
                $path = public_path(SenderIdDocumentStore::LEGACY_DIR . '/' . $name);

                if (! $apply) {
                    if (isset($seen[$name])) {
                        return;
                    }
                    $seen[$name] = true;
                }

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
                $ext = preg_match('/^[A-Za-z0-9_\-]+\.(pdf|jpg|jpeg|png|gif|doc|docx)$/i', $name) ? SenderIdDocumentStore::legacyExtension($upload) : null;
                $disk = $this->disk;

                if ($ext === null) {
                    $this->warn("quarantine  #{$ids} {$name}");
                    if ($apply) {
                        if (! $disk->makeDirectory('senderid-quarantine')) {
                            $failed++;
                            $this->error("Cannot prepare private quarantine for #{$ids}; original file and database rows preserved.");

                            return;
                        }

                        $quarantineRel = 'senderid-quarantine/row-' . $row->id . '-' . Str::random(16) . '.bin';
                        $quarantinePath = $disk->path($quarantineRel);
                        $recordPath = $this->quarantineRecordPath($name);

                        // Recovery record BEFORE the move: if the process dies between the move and the row update,
                        // the next run reconnects the rows to the quarantine instead of leaving them "missing".
                        if (! $this->writePending($disk, $recordPath, $name, $quarantineRel, $path, 'quarantine')) {
                            $failed++;
                            $this->error("Could not write the recovery record for #{$ids}; original file and database rows preserved.");

                            return;
                        }

                        if (! File::move($path, $quarantinePath)) {
                            $disk->delete($recordPath);
                            $failed++;
                            $this->error("Cannot move #{$ids} into quarantine; original file and database rows preserved.");

                            return;
                        }

                        try {
                            $this->repoint($siblings, null);
                        } catch (\Throwable $e) {
                            $failed++;
                            if (File::move($quarantinePath, $path)) {
                                $disk->delete($recordPath);
                                $this->error("Database update failed for #{$ids} ({$e->getMessage()}); file restored to its original location, rows unchanged.");
                            } else {
                                $this->error("Database update failed for #{$ids} AND the file could not be restored. Recover it from: {$quarantinePath} (original: {$path}).");
                            }

                            return;
                        }
                    }

                    if ($apply) {
                        $disk->delete($recordPath); // best effort; a stale record is cleaned up next run
                    }

                    $quarantined++;

                    return;
                }

                $this->line("move        #{$ids} {$name} -> private ({$ext})");
                if ($apply) {
                    $relative = SenderIdDocumentStore::DIR . '/' . Str::random(40) . '.' . $ext;
                    if (! $this->copyToPrivate($disk, $path, $relative)) {
                        $failed++;
                        $this->error("Private storage write failed for #{$ids}; original file and database rows preserved.");

                        return;
                    }

                    // Durable recovery record, written BEFORE the rows change: if the process dies after the commit
                    // but before the public original is removed, the next run can finish the job from this.
                    if (! $this->writePending($disk, self::PENDING_DIR . '/' . $name . '.json', $name, $relative, $path, 'move', $originals)) {
                        $disk->delete($relative);
                        $failed++;
                        $this->error("Could not write the recovery record for #{$ids}; original file and database rows preserved.");

                        return;
                    }

                    try {
                        $this->repoint($siblings, SenderIdDocumentStore::PREFIX . $relative);
                    } catch (\Throwable $e) {
                        // The transaction rolled every row back; the public original was never touched.
                        $disk->delete($relative);
                        $disk->delete(self::PENDING_DIR . '/' . $name . '.json');
                        $failed++;
                        $this->error("Database update failed for #{$ids} ({$e->getMessage()}); rolled back, original and rows preserved.");

                        return;
                    }

                    if (! File::delete($path)) {
                        // Restore the legacy references so the public original stays authoritative and a later run retries.
                        try {
                            $this->restore($siblings, $originals);
                            $disk->delete($relative);
                            $disk->delete(self::PENDING_DIR . '/' . $name . '.json');
                            $this->error("Could not delete public original for #{$ids}: {$path}. Rolled back; rows still on their legacy references.");
                        } catch (\Throwable $e) {
                            $this->error("Could not delete public original for #{$ids}: {$path}, and rollback failed ({$e->getMessage()}). Rows point at the private copy {$relative}; delete the public file manually.");
                        }
                        $failed++;

                        return;
                    }

                    $disk->delete(self::PENDING_DIR . '/' . $name . '.json'); // best effort; a stale record is cleaned up next run
                }

                $moved++;
            });

        $this->info(($apply ? 'Applied' : 'Dry run') . ": {$moved} moved, {$quarantined} quarantined, {$missing} missing, {$failed} failed, {$resumed} resumed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Stream the public original into private storage (never loading it into memory), close the handle on
     * every path, and confirm the stored size before anything else is changed. A partial copy is removed.
     */
    private function copyToPrivate($disk, string $path, string $relative): bool
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return false;
        }

        try {
            $written = $disk->put($relative, $stream) === true;
        } catch (\Throwable $e) {
            $written = false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written) {
            try {
                if ($disk->size($relative) === filesize($path)) {
                    return true;
                }
            } catch (\Throwable $e) {
                // fall through to cleanup
            }
        }

        try {
            $disk->delete($relative);
        } catch (\Throwable $e) {
            // best effort: the original is untouched either way
        }

        return false;
    }

    private function quarantineRecordPath(string $name): string
    {
        return self::PENDING_DIR . '/q-' . md5($name) . '.json';
    }

    /** Record what is needed to finish (or safely abandon) a move: legacy name, target path, size and checksum. */
    private function writePending($disk, string $recordPath, string $name, string $relative, string $publicPath, string $kind, array $rows = []): bool
    {
        $sha1 = @hash_file('sha1', $publicPath);
        $size = @filesize($publicPath);
        if ($sha1 === false || $size === false) {
            return false;
        }

        try {
            return $disk->put($recordPath, json_encode(['kind' => $kind, 'name' => $name, 'private' => $relative, 'size' => $size, 'sha1' => $sha1, 'rows' => $rows])) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resume interrupted moves. A public original is removed ONLY when: the record is well-formed; the private copy
     * exists and matches the recorded checksum; no row still references the legacy file; at least one row references
     * the private copy; and the public file is byte-identical to what was copied (so an unrelated file that merely
     * shares the name is never touched). Otherwise the public file is left alone and the record is dropped.
     */
    private function finishInterrupted($disk, bool $apply, int &$failed): int
    {
        $resumed = 0;

        foreach ($disk->files(self::PENDING_DIR) as $recordPath) {
            $record = json_decode((string) $disk->get($recordPath), true);

            if (is_array($record) && ($record['kind'] ?? null) === 'quarantine') {
                $resumed += $this->finishQuarantine($disk, $apply, $failed, $recordPath, $record);

                continue;
            }

            $name = is_array($record) ? ($record['name'] ?? null) : null;
            $private = is_array($record) ? ($record['private'] ?? null) : null;

            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9_\-]+\.(pdf|jpg|jpeg|png|gif|doc|docx)$/i', $name)
                || $recordPath !== self::PENDING_DIR . '/' . $name . '.json'
                || ! is_string($private) || ! preg_match('#^' . SenderIdDocumentStore::DIR . '/[A-Za-z0-9]{40}\.(pdf|jpg|png|gif|doc|docx)$#', $private)
                || ! is_string($record['sha1'] ?? null) || ! is_int($record['size'] ?? null)) {
                $this->error("Ignoring malformed recovery record {$recordPath}.");
                $failed++;

                continue;
            }

            $public = public_path(SenderIdDocumentStore::LEGACY_DIR . '/' . $name);
            $privateFull = $disk->path($private);
            $stillLegacy = Senderid::query()->where('document', 'like', '%' . $name)->where('document', 'not like', SenderIdDocumentStore::PREFIX . '%')->get()
                ->contains(fn (Senderid $r) => basename((string) parse_url($r->document, PHP_URL_PATH)) === $name);

            if (! is_file($public)) {
                $apply && $disk->delete($recordPath);   // already cleaned up

                continue;
            }

            $safeToDelete = ! $stillLegacy
                && is_file($privateFull) && @hash_file('sha1', $privateFull) === $record['sha1']
                && Senderid::query()->where('document', SenderIdDocumentStore::PREFIX . $private)->exists()
                && @hash_file('sha1', $public) === $record['sha1'] && @filesize($public) === $record['size'];

            if (! $safeToDelete) {
                $referencesPrivate = Senderid::query()->where('document', SenderIdDocumentStore::PREFIX . $private)->get();
                $publicIntact = @hash_file('sha1', $public) === $record['sha1'] && @filesize($public) === $record['size'];

                $privateIntact = is_file($privateFull) && @hash_file('sha1', $privateFull) === $record['sha1'];

                if ($stillLegacy || $referencesPrivate->isEmpty() || $privateIntact) {
                    // Rolled back / never repointed, nothing refers to the private copy, or the private copy is fine and the public
                    // file is merely an unrelated file with the same name: no dangling references, so leave the public file alone.
                    $apply && $disk->delete($recordPath);

                    continue;
                }

                // Rows were repointed to a private copy that is now missing or corrupt. Never leave them dangling:
                // if the public original is still intact, put the rows back on their legacy references (the ordinary
                // pass then migrates them afresh); otherwise keep the record and demand human attention.
                $legacyByRow = is_array($record['rows'] ?? null) ? $record['rows'] : [];
                $restorable = $publicIntact && $referencesPrivate->every(fn (Senderid $r) => is_string($legacyByRow[$r->id] ?? null));

                if (! $restorable) {
                    $failed++;
                    $this->error("Private copy for {$name} is missing or corrupt and the rows cannot be restored automatically; recovery record kept: {$recordPath}.");

                    continue;
                }

                $this->warn("repair      {$name}: private copy unusable; restoring rows to their legacy references");
                if ($apply) {
                    try {
                        $this->restore($referencesPrivate->keyBy('id'), $legacyByRow);
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error("Could not restore rows for {$name} ({$e->getMessage()}); recovery record kept.");

                        continue;
                    }
                    $disk->delete($recordPath);
                    $disk->delete($private);
                }

                continue;
            }

            $this->line("resume      {$name}: private copy verified, removing leftover public original");
            if ($apply) {
                if (! File::delete($public)) {
                    $failed++;
                    $this->error("Could not delete leftover public original {$public}; will retry on the next run.");

                    continue;
                }
                $disk->delete($recordPath);
            }
            $resumed++;
        }

        return $resumed;
    }

    /**
     * Finish an interrupted quarantine: the public file is gone and sits in quarantine, but rows may still point at it.
     * Rows are cleared only when the quarantined file is present and matches the recorded checksum.
     */
    private function finishQuarantine($disk, bool $apply, int &$failed, string $recordPath, array $record): int
    {
        $name = $record['name'] ?? null;
        $rel = $record['private'] ?? null;

        if (! is_string($name) || $name === '' || $name !== basename($name) || str_contains($name, "\0")
            || $recordPath !== $this->quarantineRecordPath($name)
            || ! is_string($rel) || ! preg_match('#^senderid-quarantine/row-[0-9]+-[A-Za-z0-9]{16}\.bin$#', $rel)
            || ! is_string($record['sha1'] ?? null)) {
            $this->error("Ignoring malformed recovery record {$recordPath}.");
            $failed++;

            return 0;
        }

        $public = public_path(SenderIdDocumentStore::LEGACY_DIR . '/' . $name);
        $full = $disk->path($rel);

        if (is_file($public) || ! is_file($full) || @hash_file('sha1', $full) !== $record['sha1']) {
            $apply && $disk->delete($recordPath);   // the move never happened, or there is nothing safe to reconnect

            return 0;
        }

        $siblings = Senderid::query()->where('document', 'like', '%' . $name)->where('document', 'not like', SenderIdDocumentStore::PREFIX . '%')->get()
            ->filter(fn (Senderid $r) => basename((string) parse_url($r->document, PHP_URL_PATH)) === $name)->keyBy('id');

        if ($siblings->isEmpty()) {
            $apply && $disk->delete($recordPath);

            return 0;
        }

        $this->line("resume      {$name}: file is in quarantine, clearing its dangling references");
        if ($apply) {
            try {
                $this->repoint($siblings, null);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Could not clear references for {$name} ({$e->getMessage()}); will retry on the next run.");

                return 0;
            }
            $disk->delete($recordPath);
        }

        return 1;
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
