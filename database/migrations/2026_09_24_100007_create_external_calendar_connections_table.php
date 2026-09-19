<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 15 §5.5, Sub-slice A — the per-User external
 * calendar connection.
 *
 * NO ACCESS-TOKEN COLUMN, DELIBERATELY. `refresh_token_encrypted` is the
 * only stored credential: text, nullable, Laravel's built-in `encrypted`
 * cast, and in the model's $hidden. An access token is derived from it in
 * memory per provider operation, used and discarded, never written back —
 * the shape GoogleBusinessProfileConnectionManager::accessTokenFor()
 * (:266-291) already uses, whose only database write is the bookkeeping
 * columns. This mirrors the real precedent:
 * 2026_09_09_120001_create_business_google_connections_table.php:17-21 says
 * verbatim "There is deliberately NO access-token column". Nullable on
 * purpose — a row in `pending` has no refresh token yet. Persisting an
 * access token is not authorized by this contract, and a plaintext
 * credential column never is.
 *
 * ONE CONNECTION PER USER IN TOTAL, NOT ONE PER PROVIDER. Blueprint §12
 * (V1-MASTER-PRODUCT-BLUEPRINT.md:290) is the only authority and reads
 * "Each staff member connects their own Google OR Outlook calendar once,
 * globally to their User identity". `active_user_id` is a STORED generated
 * column that equals user_id only while state is pending or active, and is
 * NULL otherwise; MySQL's unique index ignores NULLs, so the database
 * itself guarantees at most one live connection per User while placing no
 * limit on terminal history rows. STATE is the uniqueness authority, not
 * the timestamps: an in-flight connect holds the slot, which is exactly
 * what refuses a second simultaneous initiation (§5.5's pending lifecycle).
 * The idiom is copied from business_messaging_identities
 * (2026_09_12_100001:49-60), which uses the same
 * `CASE WHEN status IN ('pending','active')` shape for the same purpose,
 * including its three-step create/add-generated-column/add-unique ordering.
 *
 * `user_id` is `cascadeOnDelete` (§5.5): this row holds operational OAuth
 * credentials and a technical slot, not an independently audit-relevant
 * record, and restrictOnDelete would make a User undeletable for the sole
 * reason that they once connected a calendar — while this repository hard-
 * deletes Users (no SoftDeletes on `users`). Deleting a User therefore
 * removes the connection, its busy blocks cascade through it (§5.6) so no
 * encrypted credential or synced cache is orphaned, and the slot is released
 * as a consequence rather than as a separate step. Connection history does
 * not survive deletion of the User.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('external_calendar_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 16);
            $table->string('state', 16)->default('pending');

            // VIRTUAL, not STORED — the ONE deviation from §5.5's literal
            // wording, forced by MySQL and reported with this sub-slice.
            //
            // MySQL refuses a foreign key with ON DELETE CASCADE on a column
            // that a STORED generated column is computed from: declaring
            // `active_user_id` STORED over `user_id` while `user_id` cascades
            // fails with errno 1215 ("Cannot add foreign key constraint"),
            // reproduced directly against this server in both the inline and
            // the later-ALTER form. §5.5 requires BOTH the cascade (so a
            // deleted User's encrypted credentials and connection history go
            // with them) and this conditional-uniqueness column, so one of
            // the two had to give — and the cascade is the one carrying the
            // security and correctness meaning.
            //
            // VIRTUAL preserves every guarantee §5.5 actually asks for:
            // InnoDB supports a UNIQUE secondary index on a virtual column,
            // the value is still computed by MySQL and never writable by the
            // application, and the semantics are identical (equal to user_id
            // while pending/active, NULL otherwise, so terminal history rows
            // stay unlimited). The only thing lost is on-disk materialisation.
            // In-repo precedent for exactly this shape:
            // 2026_09_15_100005:131-137 declares `active_contact_guard` with
            // ->virtualAs() plus a unique index, for the same purpose.
            //
            // Written by MySQL, never by the application: it carries no
            // information of its own, only the conditional uniqueness below.
            $table->unsignedBigInteger('active_user_id')
                ->nullable()
                ->virtualAs("case when `state` in ('pending', 'active') then `user_id` end");

            $table->string('external_account_email', 191)->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->string('granted_scopes', 512)->nullable();
            $table->string('sync_cursor', 255)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_sync_failure_at')->nullable();
            $table->unsignedInteger('sync_failure_count')->default(0);
            $table->string('failure_classification', 64)->nullable();
            $table->string('oauth_state_nonce', 64)->nullable();
            $table->timestamp('oauth_state_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique('oauth_state_nonce', 'ecc_oauth_state_nonce_unique');
            $table->index(['user_id', 'provider'], 'ecc_user_provider_index');

            // THE one-connection-per-User guarantee, enforced by the
            // database rather than by application discipline (§5.5). MySQL's
            // unique index ignores NULLs, so terminal history rows are
            // unlimited while at most one pending-or-active row can exist.
            $table->unique('active_user_id', 'ecc_active_user_unique');

            $table->foreign('user_id', 'ecc_user_foreign')
                ->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_calendar_connections');
    }
};
