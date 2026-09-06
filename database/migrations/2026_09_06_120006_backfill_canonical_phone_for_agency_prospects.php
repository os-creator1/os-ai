<?php

    use App\Library\AgencyProspecting\AgencyProspectPhoneNormalizer;
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\DB;

    /**
     * Agency AI Prospecting runtime pass — resolves the foundation pass's
     * explicitly-deferred canonical phone representation for any prospect
     * row already persisted under the foundation's looser (unnormalized)
     * validation. Additive/backfill only — no schema change.
     *
     * Safety discipline (never violated):
     * - A phone that cannot be parsed as an explicit international number
     *   is left completely untouched — never fabricated, never guessed.
     * - If canonicalizing would collapse two different rows in the SAME
     *   Workspace onto the same digits-only value (violating the existing
     *   unique(workspace_id, phone) constraint), this migration aborts
     *   entirely before writing anything, rather than merging or deleting
     *   either row. An operator must resolve the collision manually.
     */
    return new class extends Migration {
        public function up(): void
        {
            $rows = DB::table('agency_prospects')->select(['id', 'workspace_id', 'phone'])->get();

            $updates = [];
            $canonicalByWorkspace = [];

            foreach ($rows as $row) {
                $canonical = AgencyProspectPhoneNormalizer::normalize($row->phone);

                if ($canonical === null || $canonical === $row->phone) {
                    // Unparseable (left untouched) or already canonical
                    // (no-op) — either way, nothing to write for this row.
                    continue;
                }

                $key = $row->workspace_id . ':' . $canonical;

                if (isset($canonicalByWorkspace[$key]) && $canonicalByWorkspace[$key] !== $row->id) {
                    throw new RuntimeException(
                        "Agency Prospecting phone canonicalization aborted: Workspace [{$row->workspace_id}] " .
                        "prospects [{$canonicalByWorkspace[$key]}] and [{$row->id}] would both canonicalize to " .
                        "the same phone [{$canonical}]. Resolve this collision manually before re-running this migration."
                    );
                }

                $existingCanonicalOwner = DB::table('agency_prospects')
                    ->where('workspace_id', $row->workspace_id)
                    ->where('phone', $canonical)
                    ->where('id', '!=', $row->id)
                    ->value('id');

                if ($existingCanonicalOwner !== null) {
                    throw new RuntimeException(
                        "Agency Prospecting phone canonicalization aborted: Workspace [{$row->workspace_id}] " .
                        "prospect [{$row->id}] would canonicalize to [{$canonical}], which collides with " .
                        "already-stored prospect [{$existingCanonicalOwner}]. Resolve this collision manually " .
                        "before re-running this migration."
                    );
                }

                $canonicalByWorkspace[$key] = $row->id;
                $updates[] = ['id' => $row->id, 'phone' => $canonical];
            }

            foreach ($updates as $update) {
                DB::table('agency_prospects')->where('id', $update['id'])->update(['phone' => $update['phone']]);
            }
        }

        public function down(): void
        {
            // Intentionally irreversible: the pre-canonicalization
            // representation is not retained anywhere.
        }
    };
