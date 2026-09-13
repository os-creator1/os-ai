<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Opportunities — one deal with one contact, in one stage of one pipeline.
 *
 * TWO INDEPENDENT FACTS, ON PURPOSE.
 *  - `stage_id` is where the deal is in the Business's sales process.
 *  - `contact_status` is whether the Business has been in touch yet:
 *    `no_contact` or `in_contact`. It is NOT a pipeline column, so
 *    "New inquiry + No contact" and "New inquiry + In contact" are both
 *    representable. Customers set it by hand today; canonical inbound
 *    conversation activity may set it later, which is why the source of the
 *    last change is recorded (`contact_status_source`): an automatic update
 *    must be able to tell a customer's own correction apart.
 *
 * `status` is open / won / lost. Winning or losing does not move the deal out of
 * its stage; the board shows open deals by default and filters closed ones.
 *
 * A CONTACT IS NOT AUTOMATICALLY A LEAD. Nothing creates a row here when a
 * contact is created; an opportunity exists only because someone (or, later, a
 * form) created one. If the contact is deleted, the deal and its history stay
 * (`contact_id` becomes null) rather than disappearing with it, and deleting a
 * contact is never blocked by a deal.
 *
 * `value_minor` is the optional deal value in the currency's minor unit, with
 * the Business currency captured at creation so a later currency change does
 * not silently re-denominate existing deals.
 *
 * `source` records how the deal was created (`manual` today; a specific form
 * later), which is what lets the future "form submitted → New inquiry" flow be
 * told apart from hand-entered deals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('crm_pipeline_stages')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('title', 150);
            $table->unsignedBigInteger('value_minor')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('status', 16)->default('open');
            $table->string('contact_status', 16)->default('no_contact');
            $table->string('contact_status_source', 16)->nullable();
            $table->timestamp('contact_status_changed_at')->nullable();
            $table->string('source', 64)->default('manual');
            $table->string('lost_reason', 255)->nullable();
            $table->timestamp('stage_entered_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The board: one pipeline's deals by status, then by column.
            $table->index(['business_id', 'pipeline_id', 'status', 'stage_id'], 'crm_opportunities_board_index');

            // A contact's deals (the profile, and the future inbound update).
            $table->index(['business_id', 'contact_id'], 'crm_opportunities_contact_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunities');
    }
};
