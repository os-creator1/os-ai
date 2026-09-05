<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Agency AI Prospecting foundation — one configurable-agent-context row
     * per Workspace. Deliberately generic (no Jazmin-specific or any other
     * hardcoded agency's content); every field is a plain nullable string/
     * text so an operator supplies their own agency's context. Not shared
     * with, and never read by, Business Outreach or the legacy chat_boxes/
     * AI-analytics surfaces.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('agency_prospecting_settings', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('workspace_id')->unique()->constrained('workspaces')->restrictOnDelete();
                $table->string('agency_name')->nullable();
                $table->text('offer')->nullable();
                $table->string('niche')->nullable();
                $table->text('value_proposition')->nullable();
                $table->text('pricing_context')->nullable();
                $table->text('qualification_context')->nullable();
                $table->text('geography_context')->nullable();
                $table->string('tone')->nullable();
                $table->text('faqs_objections')->nullable();
                $table->text('booking_context')->nullable();
                $table->text('follow_up_policy')->nullable();
                $table->timestamps();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('agency_prospecting_settings');
        }
    };
