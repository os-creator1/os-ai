<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE announcement representation.
 *
 * Two lanes created a `platform_announcements` table with different shapes. The canonical one is
 * the Platform Automations shape (severity, channels banner|notification|email, a structured
 * audience, publish_at, delivery counters, a receipts ledger). A database that already holds the
 * earlier Platform Owner shape (status, audience all|tiers + audience_tiers, channels in_app|email,
 * scheduled_at, cancelled_at, created_by, updated_by, delivery_ref) is upgraded IN PLACE here:
 * every row is kept and converted, never dropped.
 *
 *   audience 'all'               -> {"kind":"everyone"}
 *   audience 'tiers' + tiers[]   -> {"kind":"tiers","tiers":[...]}
 *   channels in_app / email      -> banner + notification / email
 *   scheduled_at                 -> publish_at
 *   created_by                   -> created_by_user_id
 *
 * `delivery_ref` is kept (it is part of the canonical table: the opaque reference the delivery runtime
 * returns). The legacy-only cancelled_at and updated_by have no canonical home: status carries
 * "cancelled" and updated_at the last change. A database already in the canonical shape only gains
 * delivery_ref if it lacks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_announcements')) {
            return;
        }

        if (Schema::hasColumn('platform_announcements', 'severity')) {
            // Already canonical; only the delivery reference may be missing (tables created before it existed).
            if (! Schema::hasColumn('platform_announcements', 'delivery_ref')) {
                Schema::table('platform_announcements', function (Blueprint $table): void {
                    $table->string('delivery_ref', 64)->nullable()->after('recipients_done');
                });
            }

            return;
        }

        Schema::table('platform_announcements', function (Blueprint $table): void {
            $table->string('severity', 12)->default('info')->after('body');
            $table->timestamp('publish_at')->nullable()->after('status');
            $table->unsignedInteger('recipients_total')->default(0);
            $table->unsignedInteger('recipients_done')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('source_run_id')->nullable();
            $table->json('audience_canonical')->nullable();
        });

        foreach (DB::table('platform_announcements')->orderBy('id')->get() as $row) {
            $tiers = array_values(array_filter((array) json_decode((string) ($row->audience_tiers ?? '[]'), true)));
            $audience = ($row->audience === 'tiers' && $tiers !== [])
                ? ['kind' => 'tiers', 'tiers' => $tiers]
                : ['kind' => 'everyone'];

            $legacyChannels = (array) json_decode((string) $row->channels, true);
            $channels = [];
            if (in_array('in_app', $legacyChannels, true)) {
                $channels = ['banner', 'notification'];
            }
            if (in_array('email', $legacyChannels, true)) {
                $channels[] = 'email';
            }
            if ($channels === []) {
                $channels = array_values(array_intersect($legacyChannels, ['banner', 'notification', 'email'])) ?: ['banner'];
            }

            DB::table('platform_announcements')->where('id', $row->id)->update([
                'audience_canonical' => json_encode($audience),
                'channels' => json_encode($channels),
                'publish_at' => $row->scheduled_at,
                'created_by_user_id' => $row->created_by,
            ]);
        }

        Schema::table('platform_announcements', function (Blueprint $table): void {
            foreach (['created_by', 'updated_by'] as $column) {
                if (Schema::hasColumn('platform_announcements', $column)) {
                    $table->dropForeign([$column]);
                }
            }
        });

        Schema::table('platform_announcements', function (Blueprint $table): void {
            $table->dropIndex(['status', 'scheduled_at']);
        });

        Schema::table('platform_announcements', function (Blueprint $table): void {
            $table->dropColumn(['audience', 'audience_tiers', 'scheduled_at', 'cancelled_at', 'created_by', 'updated_by']);
        });

        Schema::table('platform_announcements', function (Blueprint $table): void {
            $table->renameColumn('audience_canonical', 'audience');
        });

        Schema::table('platform_announcements', function (Blueprint $table): void {
            $table->index(['status', 'publish_at'], 'pann_status_publish_index');
            $table->foreign('created_by_user_id', 'pann_created_by_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // One-way: the canonical shape is the only supported representation.
    }
};
