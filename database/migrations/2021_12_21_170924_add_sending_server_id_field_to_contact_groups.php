<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\DB;

    class AddSendingServerIdFieldToContactGroups extends Migration
    {
        public function up()
        {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->unsignedBigInteger('sending_server')->nullable();

                // Add foreign key if needed
                // $table->foreign('sending_server')->references('id')->on('sending_servers')->onDelete('cascade');
            });
        }

        public function down()
        {
            Schema::table('contact_groups', function (Blueprint $table) {
                // Drop the foreign key only if it exists
                $sm = DB::select("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                              WHERE TABLE_NAME = 'contact_groups' 
                                AND COLUMN_NAME = 'sending_server' 
                                AND CONSTRAINT_SCHEMA = DATABASE() 
                                AND REFERENCED_TABLE_NAME IS NOT NULL");

                if (count($sm)) {
                    $table->dropForeign($sm[0]->CONSTRAINT_NAME);
                }

                $table->dropColumn('sending_server');
            });
        }

    }
