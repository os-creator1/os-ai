<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typed Contact values for Business-wide Custom Fields.
 *
 * One row per (definition, contact). The value lives in exactly one typed column
 * chosen by the definition's type (CustomFieldType::column()), so a number is a
 * number and a date is a date — not an unvalidated JSON blob or a string that
 * every reader has to re-parse:
 *
 *   value_text      text, long_text, email, phone, select (option id)
 *   value_number    number, currency
 *   value_date      date
 *   value_datetime  datetime (Business wall-clock; no timezone conversion)
 *   value_bool      boolean
 *   value_json      multi_select (list of option ids)
 *
 * The composite foreign key (definition_id, business_id) is the DB-level
 * backstop that a value can never be stamped with a Business other than its
 * definition's. The Contact's own Business is verified by the writer service
 * (`contacts.business_id` is nullable on legacy rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_values', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('definition_id');
            $table->unsignedBigInteger('contact_id');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->boolean('value_bool')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['definition_id', 'contact_id'], 'cfv_definition_contact_unique');
            $table->index('contact_id', 'cfv_contact_id_index');
            $table->index('business_id', 'cfv_business_id_index');

            $table->foreign(['definition_id', 'business_id'], 'cfv_definition_business_foreign')
                ->references(['id', 'business_id'])->on('custom_field_definitions')->onDelete('cascade');
            $table->foreign('contact_id', 'cfv_contact_id_foreign')
                ->references('id')->on('contacts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
    }
};
