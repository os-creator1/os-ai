<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Agency AI Prospecting runtime pass — the minimum campaign runtime
     * configuration needed to actually send: a selected channel and an
     * explicit, user-authored opening message (never AI-generated at
     * send time — see the runtime pass report for why). `channel_id` is
     * nullable because a draft campaign may exist before a channel is
     * chosen; starting a campaign without one is rejected in the
     * application layer, not the schema.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('agency_prospect_campaigns', function (Blueprint $table) {
                $table->foreignId('channel_id')->nullable()->after('workspace_id')
                    ->constrained('agency_prospecting_channels')->nullOnDelete();
                $table->text('opening_message')->nullable();
            });
        }

        public function down(): void
        {
            Schema::table('agency_prospect_campaigns', function (Blueprint $table) {
                $table->dropConstrainedForeignId('channel_id');
                $table->dropColumn('opening_message');
            });
        }
    };
