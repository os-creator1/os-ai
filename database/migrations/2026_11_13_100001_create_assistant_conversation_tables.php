<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ask MotionGrove — durable multi-turn conversations and the approval records
 * for the actions the assistant prepares.
 *
 * Every row is pinned to ONE Workspace, ONE Business and ONE actor at write
 * time, and every read re-derives that triple from the authenticated request —
 * a uid alone never grants access. Additive: four new tables, nothing existing
 * changes meaning, and down() drops only what up() created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assistant_conversations')) {
            Schema::create('assistant_conversations', function (Blueprint $table) {
                $table->id();
                $table->char('uid', 36)->unique();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('title', 120)->nullable();
                // Cost-accounted compaction of the oldest turns (conversation_compaction).
                $table->text('summary')->nullable();
                $table->unsignedBigInteger('summary_through_message_id')->nullable();
                $table->unsignedInteger('message_count')->default(0);
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'user_id', 'last_message_at'], 'assistant_conversations_scope_recent_index');
                // The retention sweep reads by age, oldest first.
                $table->index(['last_message_at'], 'assistant_conversations_retention_index');
            });
        }

        if (! Schema::hasTable('assistant_messages')) {
            Schema::create('assistant_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
                $table->char('uid', 36)->unique();
                // The browser's own id for a submitted question: the same question retried or double-clicked
                // resolves to the SAME row, so it can never be answered (or paid for) twice.
                $table->char('client_message_id', 36)->nullable();
                $table->string('role', 16);
                $table->string('kind', 24)->default('text');
                $table->text('body');
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique(['conversation_id', 'client_message_id'], 'assistant_messages_client_id_unique');
                $table->index(['conversation_id', 'id'], 'assistant_messages_conversation_order_index');
            });
        }

        if (! Schema::hasTable('assistant_action_proposals')) {
            Schema::create('assistant_action_proposals', function (Blueprint $table) {
                $table->id();
                $table->char('uid', 36)->unique();
                // NULLABLE, SET NULL: deleting a conversation (customer request or retention) removes its text, while
                // the proposal row survives as a minimal action-audit fact — scrubbed of its params/preview/result by
                // AssistantRetention. Account deletion still removes it through workspace_id / user_id (CASCADE).
                $table->foreignId('conversation_id')->nullable()->constrained('assistant_conversations')->nullOnDelete();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('tool_key', 48);
                $table->json('params');
                $table->json('preview');
                $table->char('params_hash', 64);
                // The state the preview was computed against; re-derived and compared at approval.
                $table->char('state_fingerprint', 64);
                $table->string('status', 16)->default('pending');
                $table->timestamp('expires_at');
                $table->timestamp('decided_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                // Fenced execution lease: every claim takes a new token and bumps the fence; the executor's own
                // transaction re-checks both under the row lock, so a superseded executor can never commit.
                $table->char('lease_token', 36)->nullable();
                $table->timestamp('lease_expires_at')->nullable();
                $table->unsignedInteger('fence')->default(0);
                $table->timestamp('scrubbed_at')->nullable();
                // One proposal -> one domain effect: the key the executed write is made under.
                $table->char('idempotency_key', 64)->unique();
                $table->json('result')->nullable();
                $table->string('failure_code', 48)->nullable();
                $table->timestamps();

                $table->index(['business_id', 'user_id', 'status'], 'assistant_proposals_scope_status_index');
            });
        }

        if (! Schema::hasTable('assistant_action_audits')) {
            Schema::create('assistant_action_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proposal_id')->constrained('assistant_action_proposals')->cascadeOnDelete();
                $table->string('event', 32);
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->json('detail')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['proposal_id', 'id'], 'assistant_action_audits_proposal_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_action_audits');
        Schema::dropIfExists('assistant_action_proposals');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
