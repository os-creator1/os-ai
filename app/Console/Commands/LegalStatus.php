<?php

namespace App\Console\Commands;

use App\Library\Legal\LegalRegistry;
use Illuminate\Console\Command;

/**
 * Release-gate report for the Legal Center, and the one place a release is sealed.
 *
 *   php artisan legal:status           what blocks each document
 *   php artisan legal:status --seal    record the sha256 of every document whose status is "approved"
 *
 * Sealing writes resources/legal/hashes.json (slug@version => sha256). It does not approve anything: status,
 * approved_by, approved_at and effective_date are set deliberately in config/legal.php, and a document with unresolved
 * [VERIFY] items or missing facts is never sealed.
 */
class LegalStatus extends Command
{
    protected $signature = 'legal:status {--seal : Seal the hashes of approved, complete documents}';

    protected $description = 'Show which legal documents are published and what blocks the rest; optionally seal approved ones';

    public function handle(LegalRegistry $registry): int
    {
        $ledger = $registry->ledger();
        $rows = [];

        foreach ($registry->all() as $doc) {
            $sealable = $doc->status === 'approved' && $doc->approvedBy && $doc->approvedAt && $doc->effectiveDate && $doc->unresolved === [];
            if ($this->option('seal') && $sealable) {
                $ledger[$doc->slug.'@'.$doc->version] = $doc->hash;
            }
            $rows[] = [$doc->slug, $doc->version, $doc->status, substr($doc->hash, 0, 12), $doc->isPublished() ? 'PUBLISHED' : implode('; ', $doc->blockers())];
        }

        if ($this->option('seal')) {
            ksort($ledger);
            file_put_contents(rtrim((string) config('legal.path'), '/').'/hashes.json', json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->info('Sealed approved documents. Re-run without --seal to confirm.');
        }

        $this->table(['Document', 'Version', 'Status', 'Hash', 'State / blockers'], $rows);

        return self::SUCCESS;
    }
}
