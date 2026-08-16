<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §12, M2 contract §11.4 — append-only, composite-protected on
     * (wallet_id, business_id), mirroring M1's own tenancy-ID integrity
     * pattern. Zero rows inserted by any M2 production code path — no
     * webhook (M3) or admin route (unassigned at M2) exists yet to trigger
     * a real transition (M2 contract §6.G).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_usage_wallet_billing_status_transitions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('wallet_id');
                $table->unsignedBigInteger('business_id');
                $table->string('from_status', 16);
                $table->string('to_status', 16);
                $table->string('source', 24);
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->text('reason');
                $table->timestamp('created_at');

                // Explicit short constraint/index names: the
                // auto-generated name (this table's own long name + column
                // + suffix) exceeds MySQL's 64-character identifier limit,
                // matching M1's own established precedent for this exact
                // problem (platform_feature_usage_classification_transitions).
                $table->foreign(['wallet_id', 'business_id'], 'buwbst_wallet_business_foreign')
                    ->references(['id', 'business_id'])->on('business_usage_wallets')
                    ->restrictOnDelete();

                $table->index(['wallet_id', 'business_id'], 'buwbst_wallet_id_business_id_index');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_usage_wallet_billing_status_transitions');
        }
    };
