<?php

use App\Enums\Coo\CooInsightInvalidationReason;
use App\Enums\Coo\CooInsightOrigin;
use App\Enums\Coo\CooScope;
use App\Library\Coo\Context\AuthorizationScopeFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 19 §8, sub-slice 19.A — scope, attribution and
 * cache identity on `coo_insights`.
 *
 * Additive and new (R-13): the merged AI-3 migration is not edited.
 *
 * WHAT CHANGES, and why each part is load-bearing:
 *
 * 1. SCOPE. `scope` discriminates §5.6's three authorization contexts, and
 *    `business_id`/`workspace_id` become nullable so a future Platform row can
 *    carry no tenant at all rather than a fabricated one (R-19). 19.A writes
 *    `business` only; the writer refuses the other two until 19.H.
 *
 * 2. ATTRIBUTION. `actor_user_id` is the real acting human on a human-initiated
 *    row and NULL on a background one; `audience_user_id` is the declared
 *    audience a background generation was computed for (§5.9b R-30) and is
 *    never attribution; `view_as_session_id` records that an Agency user was
 *    really acting. All three are FK-less on purpose, mirroring
 *    `ai_usage_ledger.actor_user_id`: attribution must survive the deletion of
 *    the user it names.
 *
 * 3. CACHE IDENTITY. `authorization_scope_fingerprint` (§5.8) is what makes a
 *    cached answer belong to one authorization set. `business_location_id` is
 *    a pin, an input to the fingerprint, never the key by itself.
 *
 * 4. THE UNIQUE KEY, and why it is built the way it is. MySQL treats NULLs as
 *    DISTINCT in a UNIQUE index, so a key containing the now-nullable
 *    `business_id`, `subject_id` or `actor_user_id` would silently stop
 *    preventing duplicates for exactly the rows that need it most. The key is
 *    therefore built over NOT NULL columns only, using three STORED generated
 *    surrogates that stand in for the NULL-safe comparison MySQL's UNIQUE
 *    semantics lack. Those surrogates are index machinery: never read by
 *    application code, never a foreign key, never presented, and never a fake
 *    tenant id. Tenancy does not appear in the key because it is an INPUT to
 *    the fingerprint — two rows with different tenancy or different authorized
 *    Locations cannot share one. `policy_version` joins the key here, closing
 *    the gap where two rows differing only by policy version collided.
 *
 * 5. RETIREMENT, not a fabricated scope. Every pre-existing row was generated
 *    before authorization scope existed: it has no recorded audience, no
 *    recorded Location set and no recorded capability set, so no honest
 *    fingerprint can be computed for it. Inventing one — an empty set, or an
 *    assumed all-access set — would be exactly the fabricated authorization
 *    claim R-30 forbids. They are retired instead: invalidated with a reason,
 *    and stamped with a 64-zero sentinel that no SHA-256 of a non-empty
 *    document produces, so they are unreachable by fingerprint as well as by
 *    `invalidated_at`. The next scheduled generation repopulates at bounded
 *    cost, and Home renders deterministically in the interim (R-32).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coo_insights', function (Blueprint $table): void {
            $table->string('scope', 16)->default(CooScope::Business->value)->after('uid');
            $table->string('origin', 16)->default(CooInsightOrigin::System->value)->after('scope');
            $table->char('authorization_scope_fingerprint', 64)->nullable()->after('signal_fingerprint');
            $table->unsignedBigInteger('business_location_id')->nullable()->after('business_id');

            // Deliberately no FK on the three actor columns: attribution is
            // audit, and must survive whatever later happens to the user row
            // it names (same reasoning as ai_usage_ledger.actor_user_id).
            $table->unsignedBigInteger('actor_user_id')->nullable()->after('workspace_id');
            $table->unsignedBigInteger('audience_user_id')->nullable()->after('actor_user_id');
            $table->unsignedBigInteger('view_as_session_id')->nullable()->after('audience_user_id');
        });

        // Retirement backfill, before the fingerprint becomes NOT NULL.
        DB::table('coo_insights')
            ->whereNull('authorization_scope_fingerprint')
            ->update([
                'scope' => CooScope::Business->value,
                'origin' => CooInsightOrigin::System->value,
                'actor_user_id' => null,
                'audience_user_id' => null,
                'view_as_session_id' => null,
                'business_location_id' => null,
                'authorization_scope_fingerprint' => AuthorizationScopeFingerprint::RETIRED,
                'invalidated_at' => DB::raw('COALESCE(invalidated_at, ' . $this->quotedNow() . ')'),
                'invalidation_reason' => DB::raw('COALESCE(invalidation_reason, ' . $this->quoted(CooInsightInvalidationReason::AuthorizationScopeIntroduced->value) . ')'),
            ]);

        Schema::table('coo_insights', function (Blueprint $table): void {
            $table->char('authorization_scope_fingerprint', 64)->nullable(false)->change();
        });

        // MySQL refuses to redefine a column that a foreign key constrains
        // (error 1832), and whether it refuses depends on the server version
        // and the direction of the change. Dropping both constraints, making
        // the two tenancy columns nullable, and putting the constraints back
        // exactly as they were is therefore the portable way to do this —
        // rather than relying on one server's leniency and discovering the
        // difference on another. The constraints are restored with the same
        // names and the same cascade behaviour, so nothing about referential
        // integrity changes; only nullability does.
        $this->withoutTenancyForeignKeys(function (): void {
            Schema::table('coo_insights', function (Blueprint $table): void {
                $table->unsignedBigInteger('business_id')->nullable()->change();
                $table->unsignedBigInteger('workspace_id')->nullable()->change();
            });
        });

        // Index surrogates. STORED, so the UNIQUE index below can use them;
        // never read by application code.
        Schema::table('coo_insights', function (Blueprint $table): void {
            $table->unsignedBigInteger('actor_key')->storedAs('COALESCE(actor_user_id, 0)');
            $table->string('subject_type_key', 24)->storedAs("COALESCE(subject_type, '')");
            $table->unsignedBigInteger('subject_id_key')->storedAs('COALESCE(subject_id, 0)');
        });

        Schema::table('coo_insights', function (Blueprint $table): void {
            $table->dropUnique('coo_insights_identity_unique');

            $table->unique([
                'scope',
                'authorization_scope_fingerprint',
                'origin',
                'actor_key',
                'kind',
                'subject_type_key',
                'subject_id_key',
                'signal_fingerprint',
                'prompt_version',
                'policy_version',
            ], 'coo_insights_scoped_identity_unique');

            $table->index(
                ['scope', 'authorization_scope_fingerprint', 'origin', 'kind', 'period_key', 'generated_at'],
                'coo_insights_scoped_display_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('coo_insights', function (Blueprint $table): void {
            $table->dropIndex('coo_insights_scoped_display_index');
            $table->dropUnique('coo_insights_scoped_identity_unique');
            $table->dropColumn(['actor_key', 'subject_type_key', 'subject_id_key']);
        });

        // A row whose tenancy is null cannot be represented by the pre-19.A
        // schema at all, so it is removed rather than given a fabricated one.
        DB::table('coo_insights')
            ->where(fn ($query) => $query->whereNull('business_id')->orWhereNull('workspace_id'))
            ->delete();

        $this->withoutTenancyForeignKeys(function (): void {
            Schema::table('coo_insights', function (Blueprint $table): void {
                $table->unsignedBigInteger('business_id')->nullable(false)->change();
                $table->unsignedBigInteger('workspace_id')->nullable(false)->change();
            });
        });

        Schema::table('coo_insights', function (Blueprint $table): void {
            $table->dropColumn([
                'scope',
                'origin',
                'authorization_scope_fingerprint',
                'business_location_id',
                'actor_user_id',
                'audience_user_id',
                'view_as_session_id',
            ]);

            $table->unique(
                ['business_id', 'kind', 'subject_type', 'subject_id', 'signal_fingerprint', 'prompt_version'],
                'coo_insights_identity_unique',
            );
        });
    }

    /**
     * Runs $work with the two tenancy foreign keys dropped, then puts them
     * back exactly as they were — same names, same cascade behaviour. The only
     * thing that changes across the callback is column nullability.
     */
    private function withoutTenancyForeignKeys(callable $work): void
    {
        Schema::table('coo_insights', function (Blueprint $table): void {
            $table->dropForeign('coo_insights_business_id_foreign');
            $table->dropForeign('coo_insights_workspace_id_foreign');
        });

        $work();

        Schema::table('coo_insights', function (Blueprint $table): void {
            $table->foreign('business_id', 'coo_insights_business_id_foreign')
                ->references('id')->on('businesses')->cascadeOnDelete();

            $table->foreign('workspace_id', 'coo_insights_workspace_id_foreign')
                ->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    private function quotedNow(): string
    {
        return $this->quoted(Carbon::now()->toDateTimeString());
    }

    private function quoted(string $value): string
    {
        return (string) DB::connection()->getPdo()->quote($value);
    }
};
