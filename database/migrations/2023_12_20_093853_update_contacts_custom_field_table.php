<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {
            Schema::table('contacts_custom_field', function (Blueprint $table) {

                if ( ! Schema::hasColumn('contacts_custom_field', 'field_id')) {

                    $table->unsignedBigInteger('field_id')->after('contact_id');
                    $table->foreign('field_id')->references('id')->on('contact_group_fields')->onDelete('cascade');
                }


                $table->dropColumn('name');
                $table->dropColumn('tag');
                $table->dropColumn('type');
                $table->dropColumn('required');
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::table('contacts_custom_field', function (Blueprint $table) {
                $table->string('name');
                $table->string('tag');
                $table->string('type')->default('text');
                $table->boolean('required')->default(false);
                
                if (Schema::hasColumn('contacts_custom_field', 'field_id')) {
                    $table->dropForeign(['field_id']);
                    $table->dropColumn('field_id');
                }

            });
        }

    };
