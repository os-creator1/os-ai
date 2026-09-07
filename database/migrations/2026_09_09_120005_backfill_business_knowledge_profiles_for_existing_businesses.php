<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;

    /**
     * Website Guided Generation contract §16 Slice 1, migration 5 of 5.
     * Every existing Business gets an empty business_knowledge_profiles
     * row (reviews_source = 'none', every other column left at its
     * nullable default) so BusinessKnowledgeProfileManager::getOrCreate()
     * never has to special-case a pre-existing Business. Chunked by
     * businesses.id (unaffected by inserts into a different table) and
     * written with insertOrIgnore() against the unique business_id
     * constraint (migration 1), so re-running this migration is a safe
     * no-op for every Business that already has a row -- idempotent by
     * construction, not by a manual "already ran" flag.
     *
     * down() is an intentional no-op, mirroring the established precedent
     * for this exact scenario (WorkspaceBackfillV1,
     * database/migrations/2026_07_30_120005_backfill_business_workspaces.php;
     * UsageWalletBackfillV1,
     * database/migrations/2026_08_16_120009_backfill_business_usage_wallets.php):
     * once this has run, a backfilled row cannot be safely distinguished
     * from a row a Business created afterward through ordinary
     * BusinessKnowledgeProfileManager::getOrCreate() use, so deleting rows
     * here would risk destroying real data. Full removal on a complete
     * rollback is migration 1's own down() (Schema::dropIfExists), which
     * this migration's up() never needs to duplicate.
     */
    return new class extends Migration {
        public function up(): void
        {
            DB::table('businesses')
                ->select('id')
                ->orderBy('id')
                ->chunkById(500, function ($businesses) {
                    $now = now();

                    $rows = $businesses->map(fn ($business) => [
                        'uid' => (string) Str::uuid(),
                        'business_id' => $business->id,
                        'reviews_source' => 'none',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();

                    if ($rows !== []) {
                        DB::table('business_knowledge_profiles')->insertOrIgnore($rows);
                    }
                });
        }

        public function down(): void
        {
            // Intentionally a non-destructive no-op -- see class docblock.
        }
    };
