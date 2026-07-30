<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('workspace_membership_businesses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_membership_id')->constrained('workspace_memberships')->restrictOnDelete();
                $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
                $table->timestamps();

                $table->unique(['workspace_membership_id', 'business_id'], 'workspace_membership_businesses_membership_business_unique');
                $table->index('business_id');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('workspace_membership_businesses');
        }
    };
